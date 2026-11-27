<?php

namespace App\Services\Nfse;

use App\Jobs\Nfse\ProcessarSimulacaoNfseJob;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Nfse\Lote;
use App\Models\Nfse\Nota;
use App\Models\Nfse\Simulacao;
use App\Models\Nfse\SimulacaoItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Simulador local. Não possui cliente HTTP, credenciais nem integração fiscal. */
class ProcessamentoSimuladoNfse
{
    public const CENARIOS = ['sucesso', 'misto', 'incerto'];

    public function __construct(private AcessoEmissaoNfse $acesso) {}

    public function aprovar(int $empresaId, int $loteId, string $cenario): Simulacao
    {
        $empresa = $this->acesso->empresa($empresaId);
        abort_unless(in_array($cenario, self::CENARIOS, true), 422);
        $sim = DB::transaction(function () use ($empresa, $loteId, $cenario) {
            $empresa = $this->lockEmpresa((int) $empresa->empresa_operadora_id, $empresa->id);
            $lote = Lote::daEmpresa($empresa)->lockForUpdate()->findOrFail($loteId);
            if ($lote->status !== 'preparado' || ! $this->itensValidos($empresa, $lote)) {
                $this->falhar('Lote desatualizado ou não preparado. Revise a conferência.');
            }
            $existente = Simulacao::daEmpresa($empresa)->where('lote_id', $loteId)->lockForUpdate()->first();
            if ($existente) {
                $this->falhar('O lote já possui uma simulação. Consulte o histórico ou reenvie a pendente.');
            }
            $sim = new Simulacao(['lote_id' => $loteId, 'cenario' => $cenario, 'status' => 'pendente',
                'snapshot' => ['lote_hash' => $this->hashLote($lote)], 'usuario_id' => auth()->id()]);
            $sim->empresa_id = $empresa->id;
            $sim->empresa_operadora_id = $empresa->empresa_operadora_id;
            $sim->save();
            return $sim;
        });
        $this->enfileirar($sim);
        return $sim;
    }

    public function reenfileirar(int $empresaId, int $id): void
    {
        $empresa = $this->acesso->empresa($empresaId);
        $sim = Simulacao::daEmpresa($empresa)->where('status', 'pendente')->findOrFail($id);
        $this->enfileirar($sim);
    }

    private function enfileirar(Simulacao $sim): void
    {
        // Em caso de indisponibilidade da fila, o registro pendente permanece recuperável.
        ProcessarSimulacaoNfseJob::dispatch((int) $sim->empresa_operadora_id, (int) $sim->empresa_id, $sim->id)->afterCommit();
    }

    public function processar(int $tenant, int $empresaId, int $simulacaoId): void
    {
        DB::transaction(function () use ($tenant, $empresaId, $simulacaoId) {
            // O worker não depende de Auth, sessão ou escopo global implícito.
            $empresa = Empresa::withoutGlobalScope('operadora')->where('empresa_operadora_id', $tenant)->lockForUpdate()->find($empresaId);
            if (! $empresa) {
                return;
            }
            $sim = Simulacao::withoutGlobalScope('operadora')->daEmpresa($empresa)->lockForUpdate()->find($simulacaoId);
            if (! $sim || $sim->status !== 'pendente') {
                return; // Reentrega do job não duplica itens.
            }
            $autor = User::query()->where('empresa_operadora_id', $tenant)->where('ativo', true)->whereIn('role', ['admin', 'gerente'])->find($sim->usuario_id);
            $lote = Lote::withoutGlobalScope('operadora')->daEmpresa($empresa)->lockForUpdate()->find($sim->lote_id);
            if (! $empresa->ativo || ! EmpresasOperadora::query()->whereKey($tenant)->where('ativo', true)->exists()
                || ! $autor || ! $lote || $lote->status !== 'preparado' || ! hash_equals($sim->snapshot['lote_hash'], $this->hashLote($lote))
                || ! ($notas = $this->itensValidos($empresa, $lote))) {
                $sim->update(['status' => 'cancelada', 'concluida_em' => now()]);
                return;
            }
            foreach ($notas as $i => $nota) {
                [$status, $mensagem] = self::resultado($sim->cenario, $i);
                $item = new SimulacaoItem(['simulacao_id' => $sim->id, 'nota_id' => $nota->id, 'status' => $status, 'mensagem' => $mensagem]);
                $item->empresa_id = $empresaId;
                $item->empresa_operadora_id = $tenant;
                $item->save();
            }
            $sim->update(['status' => 'concluida', 'concluida_em' => now()]);
        });
    }

    public static function resultado(string $cenario, int $indice): array
    {
        if ($cenario === 'incerto' || ($cenario === 'misto' && $indice === 1)) {
            return ['simulada_incerta', 'Timeout fictício: consultar resultado simulado antes de repetir.'];
        }
        if ($cenario === 'misto' && $indice === 0) {
            return ['simulada_rejeitada', 'Rejeição fictícia: revisar rascunho em um novo lote.'];
        }
        return ['simulada_ok', 'Sucesso exclusivamente simulado, sem autorização fiscal.'];
    }

    public function reconciliar(int $empresaId, int $id): void
    {
        $empresa = $this->acesso->empresa($empresaId);
        DB::transaction(function () use ($empresa, $id) {
            $empresa = $this->lockEmpresa((int) $empresa->empresa_operadora_id, $empresa->id);
            $item = SimulacaoItem::daEmpresa($empresa)->lockForUpdate()->findOrFail($id);
            if ($item->status !== 'simulada_incerta') {
                $this->falhar('Somente resultados incertos podem ser reconciliados.');
            }
            Simulacao::daEmpresa($empresa)->where('status', 'concluida')->findOrFail($item->simulacao_id);
            // Simula uma consulta de resultado, nunca reenvia ou autoriza uma nota.
            $item->update(['status' => 'simulada_ok', 'mensagem' => 'Consulta fictícia recuperou o sucesso simulado. Sem autorização fiscal.',
                'reconciliado_por' => auth()->id(), 'reconciliado_em' => now()]);
        });
    }

    private function lockEmpresa(int $tenant, int $empresaId): Empresa
    {
        return Empresa::query()->where('empresa_operadora_id', $tenant)->where('ativo', true)->lockForUpdate()->findOrFail($empresaId);
    }

    private function hashLote(Lote $lote): string
    {
        return hash('sha256', (string) $lote->getRawOriginal('snapshot'));
    }

    private function itensValidos(Empresa $empresa, Lote $lote): ?array
    {
        $snapshot = $lote->snapshot;
        if (! is_array($snapshot) || count($snapshot) !== (int) $lote->quantidade || count($snapshot) < 1 || count($snapshot) > 200) {
            return null;
        }
        $notas = Nota::withoutGlobalScope('operadora')->daEmpresa($empresa)->where('lote_id', $lote->id)->orderBy('id')->lockForUpdate()->get();
        if ($notas->count() !== count($snapshot)) {
            return null;
        }
        $versoes = array_column($snapshot, 'versao', 'id');
        foreach ($notas as $nota) {
            if (! isset($versoes[$nota->id]) || $nota->status !== 'em_lote' || $nota->versao !== (int) $versoes[$nota->id] + 1) {
                return null;
            }
        }
        return $notas->values()->all();
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['simulacao' => $mensagem]);
    }
}
