<?php

namespace App\Services\Nfse;

use App\Models\Empresa;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\ConferenciaEvento;
use App\Models\Nfse\Lote;
use App\Models\Nfse\Nota;
use App\Models\Nfse\Servico;
use App\Models\Nfse\Tomador;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConferenciaNfse
{
    public const LIMITE = 200;

    public function __construct(private PreparacaoNfse $preparo) {}

    public function consulta(int $empresaId, string $mes): Builder
    {
        $empresa = $this->preparo->empresa($empresaId);
        Validator::make(['mes' => $mes], ['mes' => ['required', 'date_format:Y-m', 'regex:/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D']])->validate();
        $inicio = CarbonImmutable::createFromFormat('!Y-m', $mes);
        return Nota::daEmpresa($empresa)->whereBetween('competencia', [$inicio->toDateString(), $inicio->endOfMonth()->toDateString()]);
    }

    public function selecionadas(int $empresaId, string $mes, array $versoes, bool $bloquear = false): Collection
    {
        Validator::make(['ids' => array_keys($versoes), 'versoes' => array_values($versoes)], ['ids' => 'required|array|min:1|max:'.self::LIMITE, 'ids.*' => 'required|integer|min:1', 'versoes.*' => 'required|integer|min:1'])->validate();
        $query = $this->consulta($empresaId, $mes)->whereIn('id', array_keys($versoes))->orderBy('id');
        $notas = ($bloquear ? $query->lockForUpdate() : $query)->get();
        if ($notas->count() !== count($versoes) || $notas->contains(fn ($n) => $n->status !== 'rascunho' || $n->lote_id !== null || (int) $n->versao !== (int) $versoes[$n->id])) {
            $this->falhar('A seleção mudou ou contém notas indisponíveis. Selecione novamente.');
        }
        return $notas;
    }

    public function editar(int $empresaId, string $mes, array $versoes, array $dados): void
    {
        $dados = Validator::make($dados, [
            'servico_id' => 'sometimes|required|integer|min:1',
            'tomador_id' => 'sometimes|required|integer|min:1',
            'competencia' => ['sometimes', 'required', 'date_format:Y-m-d', 'regex:/^[1-9]\d{3}-\d{2}-\d{2}$/D'],
            'descricao' => 'sometimes|required|string|max:2000',
            'valor' => ['sometimes', 'required', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'numeric', 'gt:0'],
        ])->validate();
        if (count($versoes) > 1 && (isset($dados['tomador_id']) || isset($dados['competencia']))) {
            $this->falhar('Tomador e competência somente podem ser alterados individualmente.');
        }
        if ($dados === []) {
            $this->falhar('Informe pelo menos um campo para alterar.');
        }
        DB::transaction(function () use ($empresaId, $mes, $versoes, $dados) {
            $empresa = $this->bloquear($empresaId);
            foreach ($this->selecionadas($empresaId, $mes, $versoes, true) as $nota) {
                $antes = $this->estado($nota);
                $nota->fill($dados);
                $nota->snapshot = $this->snapshotAtual($empresa, $nota);
                $this->salvarEvento($empresa, $nota, 'editar', $antes);
            }
        });
    }

    public function descartar(int $empresaId, string $mes, array $versoes): void
    {
        DB::transaction(function () use ($empresaId, $mes, $versoes) {
            $empresa = $this->bloquear($empresaId);
            foreach ($this->selecionadas($empresaId, $mes, $versoes, true) as $nota) {
                $antes = $this->estado($nota);
                $nota->status = 'descartada';
                $this->salvarEvento($empresa, $nota, 'descartar', $antes);
            }
        });
    }

    public function alertas(Empresa $empresa, Nota $nota, bool $bloquear = false): array
    {
        $alertas = [];
        $inicio = CarbonImmutable::parse($nota->competencia)->startOfMonth();
        $query = Nota::daEmpresa($empresa)->where('id', '!=', $nota->id)->where('status', '!=', 'descartada')
            ->whereBetween('competencia', [$inicio->toDateString(), $inicio->endOfMonth()->toDateString()])
            ->where('tomador_id', $nota->tomador_id)->where('servico_id', $nota->servico_id)
            ->where('valor', $nota->valor)->where('descricao', $nota->descricao);
        if (($bloquear ? $query->lockForUpdate() : $query)->first(['id'])) {
            $alertas[] = 'Possível duplicidade na competência';
        }
        return $alertas;
    }

    public function anterior(Empresa $empresa, Nota $nota): ?string
    {
        $inicio = CarbonImmutable::parse($nota->competencia)->startOfMonth()->subMonth();
        $valores = Nota::daEmpresa($empresa)->where('status', '!=', 'descartada')->whereBetween('competencia', [$inicio->toDateString(), $inicio->endOfMonth()->toDateString()])
            ->where('tomador_id', $nota->tomador_id)->where('servico_id', $nota->servico_id)->limit(2)->get(['valor']);
        return $valores->count() === 1 ? $valores->first()->valor : null;
    }

    public function prepararLote(int $empresaId, string $mes, array $versoes, string $justificativa): Lote
    {
        return DB::transaction(function () use ($empresaId, $mes, $versoes, $justificativa) {
            $empresa = $this->bloquear($empresaId);
            $notas = $this->selecionadas($empresaId, $mes, $versoes, true);
            $itens = [];
            $temAlertas = false;
            foreach ($notas as $nota) {
                if (self::ordenarSnapshot($nota->snapshot) !== self::ordenarSnapshot($this->snapshotAtual($empresa, $nota))) {
                    $this->falhar('A nota #'.$nota->id.' possui cadastro alterado. Edite e salve para revisar o snapshot.');
                }
                $alertas = $this->alertas($empresa, $nota, true);
                $temAlertas = $temAlertas || $alertas !== [];
                $itens[] = $this->estado($nota) + ['id' => $nota->id, 'alertas' => $alertas];
            }
            $justificativa = trim($justificativa);
            if ($temAlertas) {
                Validator::make(['justificativa' => $justificativa], ['justificativa' => 'required|string|min:10|max:1000'])->validate();
            }
            $lote = new Lote(['competencia' => $mes.'-01', 'status' => 'preparado', 'quantidade' => $notas->count(), 'valor_total' => self::total($notas), 'snapshot' => $itens, 'justificativa_alertas' => $temAlertas ? $justificativa : null, 'usuario_id' => auth()->id()]);
            $lote->empresa_id = $empresa->id;
            $lote->empresa_operadora_id = $empresa->empresa_operadora_id;
            $lote->save();
            foreach ($notas as $nota) {
                $antes = $this->estado($nota);
                $nota->status = 'em_lote';
                $nota->lote_id = $lote->id;
                $this->salvarEvento($empresa, $nota, 'preparar_lote', $antes);
            }
            return $lote;
        });
    }

    public function desfazerLote(int $empresaId, int $loteId): void
    {
        DB::transaction(function () use ($empresaId, $loteId) {
            $empresa = $this->bloquear($empresaId, false);
            $lote = Lote::daEmpresa($empresa)->lockForUpdate()->findOrFail($loteId);
            if ($lote->status !== 'preparado') {
                $this->falhar('Somente lotes preparados podem ser desfeitos.');
            }
            $notas = Nota::daEmpresa($empresa)->where('lote_id', $lote->id)->orderBy('id')->lockForUpdate()->get();
            if ($notas->count() !== (int) $lote->quantidade || $notas->contains(fn ($n) => $n->status !== 'em_lote')) {
                $this->falhar('Lote contém itens em situação incompatível.');
            }
            foreach ($notas as $nota) {
                $antes = $this->estado($nota);
                $nota->status = 'rascunho';
                $nota->lote_id = null;
                $this->salvarEvento($empresa, $nota, 'desfazer_lote', $antes);
            }
            $lote->update(['status' => 'desfeito', 'desfeito_por' => auth()->id(), 'desfeito_em' => now()]);
        });
    }

    public static function total(iterable $notas): string
    {
        $centavos = 0;
        foreach ($notas as $nota) {
            $centavos += (int) str_replace('.', '', $nota->valor);
        }
        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function formatarValor(string $valor): string
    {
        [$inteiro, $decimal] = array_pad(explode('.', $valor, 2), 2, '00');
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $inteiro).','.str_pad($decimal, 2, '0');
    }

    private function bloquear(int $id, bool $exigirHabilitacao = true): Empresa
    {
        $empresa = $this->preparo->empresa($id);
        $empresa = Empresa::query()->where('empresa_operadora_id', $empresa->empresa_operadora_id)->where('ativo', true)->lockForUpdate()->findOrFail($id);
        if ($exigirHabilitacao && ! Configuracao::daEmpresa($empresa)->where('habilitado_preparo', true)->lockForUpdate()->first(['id'])) {
            $this->falhar('Preparação desabilitada para esta empresa.');
        }
        return $empresa;
    }

    private function snapshotAtual(Empresa $empresa, Nota $nota): array
    {
        $tomador = Tomador::daEmpresa($empresa)->where('ativo', true)->lockForUpdate()->findOrFail($nota->tomador_id);
        $servico = Servico::daEmpresa($empresa)->where('ativo', true)->lockForUpdate()->findOrFail($nota->servico_id);
        return $this->preparo->snapshot($empresa, $tomador, $servico);
    }

    private static function ordenarSnapshot(array $snapshot): array
    {
        ksort($snapshot);
        foreach ($snapshot as &$valor) {
            if (is_array($valor)) {
                $valor = self::ordenarSnapshot($valor);
            }
        }
        return $snapshot;
    }

    private function estado(Nota $nota): array
    {
        return $nota->only(['tomador_id', 'servico_id', 'competencia', 'descricao', 'valor', 'status', 'snapshot', 'lote_id', 'versao']);
    }

    private function salvarEvento(Empresa $empresa, Nota $nota, string $acao, array $antes): void
    {
        $nota->versao = (int) $nota->versao + 1;
        $nota->save();
        $evento = new ConferenciaEvento(['nota_id' => $nota->id, 'usuario_id' => auth()->id(), 'acao' => $acao, 'antes' => $antes, 'depois' => $this->estado($nota)]);
        $evento->empresa_id = $empresa->id;
        $evento->empresa_operadora_id = $empresa->empresa_operadora_id;
        $evento->save();
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['conferencia' => $mensagem]);
    }
}
