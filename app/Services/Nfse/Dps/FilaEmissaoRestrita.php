<?php

namespace App\Services\Nfse\Dps;

use App\Jobs\Nfse\TransmitirDpsRestritaJob;
use App\Models\Empresa;
use App\Models\Nfse\DpsLocal;
use App\Models\Nfse\FilaHomologacao;
use App\Models\Nfse\Lote;
use App\Models\Nfse\TransmissaoRestrita;
use App\Models\User;
use App\Services\Nfse\AcessoEmissaoNfse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class FilaEmissaoRestrita
{
    public const LIMITE = 20;
    public const FILA = 'nfse-homologacao';

    public function __construct(private AcessoEmissaoNfse $acessos, private EmissaoRestrita $emissao) {}

    /** Aprovação coletiva e autorização de fila são atômicas; a rede só ocorre no worker. */
    public function aprovar(int $empresaId, string $mes, array $itens): FilaHomologacao
    {
        $empresa = $this->empresa($empresaId);
        Validator::make(['mes' => $mes, 'itens' => $itens], [
            'mes' => ['required', 'date_format:Y-m', 'regex:/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D'],
            'itens' => 'required|array|min:1|max:'.self::LIMITE,
            'itens.*' => 'required|array:id,hash',
            'itens.*.id' => 'required|integer|min:1|distinct',
            'itens.*.hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ])->validate();
        $fila = DB::transaction(function () use ($empresa, $mes, $itens) {
            $empresa = Empresa::query()->where('empresa_operadora_id', $empresa->empresa_operadora_id)
                ->where('ativo', true)->lockForUpdate()->findOrFail($empresa->id);
            $fila = new FilaHomologacao(['usuario_id' => auth()->id(), 'competencia' => $mes.'-01', 'quantidade' => count($itens)]);
            $fila->empresa_operadora_id = $empresa->empresa_operadora_id;
            $fila->empresa_id = $empresa->id;
            $fila->save();
            foreach ($itens as $item) {
                $dps = DpsLocal::daEmpresa($empresa)->where('ambiente', 2)->lockForUpdate()->findOrFail($item['id']);
                Lote::daEmpresa($empresa)->where('competencia', $mes.'-01')->where('status', 'preparado')->findOrFail($dps->lote_id);
                $r = $this->emissao->aprovar($empresa->id, $dps->id, $item['hash']);
                if ($r->status !== 'aprovada_teste' || $r->envio_iniciado_em !== null || $r->fila_homologacao_id !== null) {
                    $this->falhar('Uma DPS selecionada já foi enviada ou está em outra fila.');
                }
                $r->update(['fila_homologacao_id' => $fila->id, 'fila_status' => 'pendente']);
            }

            return $fila;
        });
        // Callback após a transação mais externa: nunca enfileira um registro não confirmado.
        $this->despachar($fila);

        return $fila;
    }

    public function reenfileirarPendentes(int $empresaId, int $filaId): void
    {
        $empresa = $this->empresa($empresaId);
        $fila = FilaHomologacao::daEmpresa($empresa)->findOrFail($filaId);
        // Apenas pendentes sem tentativa. Bloqueados ou interrompidos não são repetidos.
        $this->despachar($fila);
    }

    private function despachar(FilaHomologacao $fila): void
    {
        $ids = TransmissaoRestrita::query()->where('empresa_operadora_id', $fila->empresa_operadora_id)
            ->where('empresa_id', $fila->empresa_id)->where('fila_homologacao_id', $fila->id)
            ->where('fila_status', 'pendente')->where('status', 'aprovada_teste')->whereNull('envio_iniciado_em')->pluck('id');
        foreach ($ids as $id) {
            DB::afterCommit(static function () use ($fila, $id) {
                try {
                    Bus::dispatch((new TransmitirDpsRestritaJob((int) $fila->empresa_operadora_id, (int) $fila->empresa_id, $fila->id, (int) $id))
                        ->onConnection('database')->onQueue(self::FILA));
                } catch (Throwable) {
                    // Registro pendente pode ser reenfileirado manualmente, sem repetir POST.
                }
            });
        }
    }

    /** Worker: IDs são cruzados com registros persistidos; não usa sessão do navegador. */
    public function processar(int $tenant, int $empresaId, int $filaId, int $transmissaoId): void
    {
        $fila = FilaHomologacao::withoutGlobalScope('operadora')->where('empresa_operadora_id', $tenant)
            ->where('empresa_id', $empresaId)->find($filaId);
        if (! $fila) {
            return;
        }
        $autor = User::query()->where('empresa_operadora_id', $tenant)->where('ativo', true)
            ->whereIn('role', ['admin', 'gerente'])->find($fila->usuario_id);
        $guard = Auth::guard();
        $anterior = $guard->user();
        try {
            if ($autor) {
                $guard->setUser($autor);
            }
            $claimed = DB::transaction(function () use ($tenant, $empresaId, $filaId, $transmissaoId, $autor) {
                // Mesmo sem usuário válido, somente o item exato pode ser bloqueado.
                $r = TransmissaoRestrita::withoutGlobalScope('operadora')->where('empresa_operadora_id', $tenant)
                    ->where('empresa_id', $empresaId)->where('fila_homologacao_id', $filaId)->lockForUpdate()->find($transmissaoId);
                if (! $r || $r->fila_status !== 'pendente' || $r->status !== 'aprovada_teste' || $r->envio_iniciado_em !== null) {
                    return false;
                }
                if (! $autor || ! $autor->podeAcessarSistema() || ! config('automacao_fiscal.nfse_dps_fila_enabled', false)) {
                    $r->update(['fila_status' => 'bloqueado', 'fila_finalizada_em' => now()]);
                    return false;
                }
                $r->update(['fila_status' => 'processando', 'fila_iniciada_em' => now()]);
                return true;
            });
            if (! $claimed) {
                return;
            }
            $status = 'bloqueado';
            try {
                $this->empresa($empresaId);
                $this->emissao->enviar($empresaId, $transmissaoId, $filaId);
                $status = 'concluido';
            } catch (Throwable) {
                // Mesmo falhas anteriores ao envio exigem revisão; não há retry automático.
            }
            TransmissaoRestrita::withoutGlobalScope('operadora')->where('empresa_operadora_id', $tenant)
                ->where('empresa_id', $empresaId)->where('fila_homologacao_id', $filaId)->whereKey($transmissaoId)
                ->where('fila_status', 'processando')->update(['fila_status' => $status, 'fila_finalizada_em' => now()]);
        } finally {
            if ($anterior) {
                $guard->setUser($anterior);
            } else {
                $guard->forgetUser();
            }
        }
    }

    private function empresa(int $empresaId): Empresa
    {
        $empresa = $this->acessos->empresa($empresaId);
        abort_unless(config('queue.connections.database.driver') === 'database' && (int) config('queue.connections.database.retry_after') > 75, 403, 'Configure a fila database com retry_after superior a 75 segundos.');
        $doc = preg_replace('/\D/', '', (string) $empresa->cnpj);
        $piloto = (string) config('automacao_fiscal.nfse_dps_restrita_prestador', '');
        abort_unless($piloto !== '' && hash_equals($piloto, $doc)
            && config('automacao_fiscal.nfse_dps_local_enabled', false)
            && config('automacao_fiscal.nfse_dps_restrita_enabled', false)
            && config('automacao_fiscal.nfse_dps_fila_enabled', false), 403, 'Fila restrita desabilitada.');
        return $empresa;
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['fila' => $mensagem]);
    }
}
