<?php

namespace App\Services\Nfse;

use App\Models\Empresa;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\Nota;
use App\Models\Nfse\Recorrencia;
use App\Models\Nfse\RecorrenciaPausa;
use App\Models\Nfse\RecorrenciaReajuste;
use App\Models\Nfse\Servico;
use App\Models\Nfse\Tomador;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecorrenciasNfse
{
    public const LIMITE = 200;

    public function __construct(private PreparacaoNfse $preparo) {}

    public function cadastrar(int $empresaId, array $dados): Recorrencia
    {
        $empresa = $this->preparo->empresa($empresaId);
        $dados['referencia'] = trim((string) ($dados['referencia'] ?? ''));
        $v = Validator::make($dados, [
            'referencia' => 'required|string|max:100',
            'tomador_id' => 'required|integer|min:1',
            'servico_id' => 'required|integer|min:1',
            'inicio' => 'required|date_format:Y-m',
            'fim' => 'nullable|date_format:Y-m|after_or_equal:inicio',
            'dia' => 'required|integer|min:1|max:31',
            ...$this->regrasPreco(),
        ])->validate();
        $inicio = $this->mes($v['inicio'])->toDateString();
        $fim = filled($v['fim'] ?? null) ? $this->mes($v['fim'])->toDateString() : null;
        return DB::transaction(function () use ($empresa, $v, $inicio, $fim) {
            $empresa = $this->bloquear($empresa);
            Tomador::daEmpresa($empresa)->where('ativo', true)->lockForUpdate()->findOrFail($v['tomador_id']);
            Servico::daEmpresa($empresa)->where('ativo', true)->lockForUpdate()->findOrFail($v['servico_id']);
            if (Recorrencia::daEmpresa($empresa)->where('referencia', $v['referencia'])->lockForUpdate()->first()) {
                $this->falhar('Já existe um contrato com esta referência.');
            }
            $r = new Recorrencia([
                'referencia' => $v['referencia'], 'tomador_id' => $v['tomador_id'], 'servico_id' => $v['servico_id'],
                'inicio' => $inicio, 'fim' => $fim, 'dia' => $v['dia'],
            ]);
            $this->gravar($empresa, $r);
            $this->novoPreco($empresa, $r, $inicio, $v);
            return $r;
        });
    }

    public function reajustar(int $empresaId, int $id, string $mes, array $dados): void
    {
        $empresa = $this->preparo->empresa($empresaId);
        $vigencia = $this->mes($mes)->toDateString();
        $v = Validator::make($dados, $this->regrasPreco())->validate();
        DB::transaction(function () use ($empresa, $id, $vigencia, $v) {
            $empresa = $this->bloquear($empresa);
            $r = Recorrencia::daEmpresa($empresa)->lockForUpdate()->findOrFail($id);
            $this->validarVigencia($r, $vigencia);
            if (RecorrenciaReajuste::daEmpresa($empresa)->where('recorrencia_id', $id)->where('vigencia', $vigencia)->lockForUpdate()->first()) {
                $this->falhar('Já existe uma condição de preço para esta competência. Não é possível sobrescrever o histórico.');
            }
            $this->novoPreco($empresa, $r, $vigencia, $v);
        });
    }

    public function pausar(int $empresaId, int $id, string $mes, bool $pausada): void
    {
        $empresa = $this->preparo->empresa($empresaId);
        $competencia = $this->mes($mes)->toDateString();
        DB::transaction(function () use ($empresa, $id, $competencia, $pausada) {
            $empresa = $this->bloquear($empresa);
            $r = Recorrencia::daEmpresa($empresa)->lockForUpdate()->findOrFail($id);
            $this->validarVigencia($r, $competencia);
            if (Nota::daEmpresa($empresa)->where('recorrencia_id', $id)->where('recorrencia_competencia', $competencia)->lockForUpdate()->first()) {
                $this->falhar('Esta competência já tem histórico de nota. Use a central de conferência.');
            }
            $pausa = RecorrenciaPausa::daEmpresa($empresa)->where('recorrencia_id', $id)->where('competencia', $competencia)->lockForUpdate()->first()
                ?? new RecorrenciaPausa(['recorrencia_id' => $id, 'competencia' => $competencia]);
            $pausa->pausada = $pausada;
            $this->gravar($empresa, $pausa);
        });
    }

    public function encerrar(int $empresaId, int $id, string $mes): void
    {
        $empresa = $this->preparo->empresa($empresaId);
        $fim = $this->mes($mes)->toDateString();
        DB::transaction(function () use ($empresa, $id, $fim) {
            $empresa = $this->bloquear($empresa);
            $r = Recorrencia::daEmpresa($empresa)->lockForUpdate()->findOrFail($id);
            $this->validarVigencia($r, $fim);
            if (Nota::daEmpresa($empresa)->where('recorrencia_id', $id)->where('recorrencia_competencia', '>', $fim)->lockForUpdate()->first()) {
                $this->falhar('Existem notas posteriores ao encerramento informado.');
            }
            $r->fim = $fim;
            $r->save();
        });
    }

    public function previa(int $empresaId, string $mes): array
    {
        return $this->montarPrevia($this->preparo->empresa($empresaId), $this->mes($mes), false);
    }

    public function gerar(int $empresaId, string $mes, string $conferencia): int
    {
        $empresa = $this->preparo->empresa($empresaId);
        $inicio = $this->mes($mes);
        return DB::transaction(function () use ($empresa, $inicio, $conferencia) {
            $empresa = $this->bloquear($empresa);
            $previa = $this->montarPrevia($empresa, $inicio, true);
            if (! hash_equals($previa['conferencia'], $conferencia) || $previa['aptas'] === 0) {
                $this->falhar('A previsão mudou ou não há itens aptos. Confira novamente.');
            }
            foreach ($previa['linhas'] as $linha) {
                if ($linha['status'] !== 'apta') {
                    continue;
                }
                $nota = $this->preparo->salvarRascunho($empresa->id, (string) Str::uuid(), $linha['dados']);
                $nota->recorrencia_id = $linha['id'];
                $nota->recorrencia_competencia = $inicio->toDateString();
                $nota->save();
            }
            return $previa['aptas'];
        });
    }

    private function montarPrevia(Empresa $empresa, CarbonImmutable $inicio, bool $lock): array
    {
        $mes = $inicio->toDateString();
        $q = Recorrencia::daEmpresa($empresa)->where('inicio', '<=', $mes)
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>=', $mes))->orderBy('id')->limit(self::LIMITE + 1);
        $contratos = ($lock ? $q->lockForUpdate() : $q)->get();
        if ($contratos->count() > self::LIMITE) {
            $this->falhar('Mais de 200 contratos vigentes. A geração em volumes maiores ainda não está habilitada.');
        }
        $linhas = [];
        foreach ($contratos as $r) {
            $t = Tomador::daEmpresa($empresa)->where('id', $r->tomador_id);
            $s = Servico::daEmpresa($empresa)->where('id', $r->servico_id);
            $p = RecorrenciaReajuste::daEmpresa($empresa)->where('recorrencia_id', $r->id)->where('vigencia', '<=', $mes)->orderByDesc('vigencia');
            $pausa = RecorrenciaPausa::daEmpresa($empresa)->where('recorrencia_id', $r->id)->where('competencia', $mes);
            $nota = Nota::daEmpresa($empresa)->where('recorrencia_id', $r->id)->where('recorrencia_competencia', $mes);
            $externa = Nota::daEmpresa($empresa)->whereNull('recorrencia_id')->where('status', '!=', 'descartada')
                ->where('tomador_id', $r->tomador_id)->where('servico_id', $r->servico_id)
                ->whereBetween('competencia', [$mes, $inicio->endOfMonth()->toDateString()]);
            $tomador = ($lock ? $t->lockForUpdate() : $t)->first();
            $servico = ($lock ? $s->lockForUpdate() : $s)->first();
            $preco = ($lock ? $p->lockForUpdate() : $p)->first();
            $pausada = ($lock ? $pausa->lockForUpdate() : $pausa)->first()?->pausada;
            $existente = ($lock ? $nota->lockForUpdate() : $nota)->first();
            $outraOrigem = ($lock ? $externa->lockForUpdate() : $externa)->first(['id']);
            $data = $inicio->day(min($r->dia, $inicio->daysInMonth))->toDateString();
            $descricao = strtr($preco?->descricao ?? '', ['{mes}' => $inicio->format('m'), '{ano}' => $inicio->format('Y')]);
            $status = match (true) {
                $existente !== null => 'gerada',
                $pausada === true => 'pausada',
                ! $tomador?->ativo || ! $servico?->ativo || ! $preco || trim($descricao) === '' || mb_strlen($descricao) > 2000 => 'impedida',
                $outraOrigem !== null => 'outra_origem',
                default => 'apta',
            };
            $linhas[] = [
                'id' => $r->id, 'referencia' => $r->referencia, 'tomador' => $tomador?->nome ?? 'Indisponível',
                'servico' => $servico?->nome ?? 'Indisponível', 'valor' => $preco?->valor,
                'descricao' => $descricao, 'data' => $data, 'status' => $status,
                'nota_id' => $existente?->id, 'nota_status' => $existente?->status,
                'dados' => $status === 'apta' ? ['tomador_id' => $r->tomador_id, 'servico_id' => $r->servico_id, 'competencia' => $data, 'descricao' => $descricao, 'valor' => $preco->valor] : null,
                'cadastro_hash' => hash('sha256', json_encode([$tomador?->getAttributes(), $servico?->getAttributes()], JSON_THROW_ON_ERROR)),
            ];
        }
        $aptas = array_filter($linhas, fn ($l) => $l['status'] === 'apta');
        $total = ConferenciaNfse::total(array_map(fn ($l) => (object) ['valor' => $l['valor']], $aptas));
        $resultado = ['mes' => $mes, 'linhas' => $linhas, 'aptas' => count($aptas), 'total' => $total];
        $resultado['conferencia'] = hash('sha256', json_encode($resultado, JSON_THROW_ON_ERROR));
        return $resultado;
    }

    private function mes(string $mes): CarbonImmutable
    {
        Validator::make(['mes' => $mes], ['mes' => ['required', 'date_format:Y-m', 'regex:/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D']])->validate();
        return CarbonImmutable::createFromFormat('!Y-m', $mes);
    }

    private function validarVigencia(Recorrencia $r, string $mes): void
    {
        if ($mes < $r->inicio->toDateString() || ($r->fim && $mes > $r->fim->toDateString())) {
            $this->falhar('Competência fora da vigência do contrato.');
        }
    }

    private function regrasPreco(): array
    {
        return ['valor' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/D', 'numeric', 'gt:0'], 'descricao' => 'required|string|max:2000'];
    }

    private function novoPreco(Empresa $empresa, Recorrencia $r, string $vigencia, array $v): void
    {
        $this->gravar($empresa, new RecorrenciaReajuste(['recorrencia_id' => $r->id, 'vigencia' => $vigencia, 'valor' => $v['valor'], 'descricao' => $v['descricao']]));
    }

    private function gravar(Empresa $empresa, Model $registro): void
    {
        $registro->empresa_id = $empresa->id;
        $registro->empresa_operadora_id = $empresa->empresa_operadora_id;
        $registro->usuario_id = auth()->id();
        $registro->save();
    }

    private function bloquear(Empresa $empresa): Empresa
    {
        $empresa = Empresa::query()->where('empresa_operadora_id', $empresa->empresa_operadora_id)->where('ativo', true)->lockForUpdate()->findOrFail($empresa->id);
        if (! Configuracao::daEmpresa($empresa)->where('habilitado_preparo', true)->lockForUpdate()->first(['id'])) {
            $this->falhar('Habilite a preparação de NFS-e para esta empresa.');
        }
        return $empresa;
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['recorrencia' => $mensagem]);
    }
}
