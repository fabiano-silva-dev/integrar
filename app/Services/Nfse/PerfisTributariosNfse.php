<?php

namespace App\Services\Nfse;

use App\Models\Empresa;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\Nota;
use App\Models\Nfse\PerfilTributario;
use App\Models\Nfse\Servico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Configuração declarada pelo responsável fiscal, não motor de cálculo ou enquadramento. */
class PerfisTributariosNfse
{
    public const RETENCOES = ['irrf', 'pis', 'cofins', 'csll', 'inss'];

    public function __construct(private AcessoEmissaoNfse $acessos) {}

    public function cadastrar(int $empresaId, ?int $servicoId, string $vigencia, array $dados): PerfilTributario
    {
        $empresa = $this->acessos->empresa($empresaId);
        $v = Validator::make([...$dados, 'vigencia' => $vigencia], [
            'vigencia' => ['required', 'date_format:Y-m', 'regex:/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D'],
            'regime' => 'required|in:mei,simples_nacional,normal',
            'regApTribSN' => 'nullable|required_if:regime,simples_nacional|prohibited_unless:regime,simples_nacional|in:1,2,3',
            'municipio_prestacao' => ['required', 'string', 'regex:/^[1-9]\d{6}$/D'],
            'iss' => 'required|in:tributado,imune,isento,nao_incidente,exportacao,suspenso,outro',
            'iss_retido' => 'required|in:nao,tomador,intermediario,indefinido',
            'aliquota_iss' => $this->percentual(false),
            'regime_especial' => 'required|in:nao,sim,indefinido',
            'deducoes_beneficios' => 'required|in:nao,sim,indefinido',
            'retencoes' => 'required|array:'.implode(',', self::RETENCOES),
            'retencoes.*' => 'required|in:nao,sim,indefinido',
            'ibscbs' => 'required|in:dispensado,exigido,indefinido',
            'cst' => 'nullable|string|digits:3|required_if:ibscbs,exigido',
            'cclass_trib' => 'nullable|string|digits:6|required_if:ibscbs,exigido',
            'ind_op' => 'nullable|string|digits:6',
            'percentuais_tributos' => 'required|array:pTotTribFed,pTotTribEst,pTotTribMun',
            'percentuais_tributos.pTotTribFed' => $this->percentual(true),
            'percentuais_tributos.pTotTribEst' => $this->percentual(true),
            'percentuais_tributos.pTotTribMun' => $this->percentual(true),
            'fundamento' => 'required|string|max:2000',
        ])->validate();
        foreach (self::RETENCOES as $campo) {
            if (! array_key_exists($campo, $v['retencoes'])) {
                $this->falhar('Informe a situação de todas as retenções federais.');
            }
        }
        $v['cst'] = $v['cst'] ?? null;
        $v['cclass_trib'] = $v['cclass_trib'] ?? null;
        $v['ind_op'] = $v['ind_op'] ?? null;
        if (($v['cst'] === null) !== ($v['cclass_trib'] === null)
            || ($v['cst'] !== null && substr((string) $v['cclass_trib'], 0, 3) !== (string) $v['cst'])) {
            $this->falhar('Informe CST e cClassTrib coerentes. A conferência na tabela oficial ainda é necessária.');
        }

        return DB::transaction(function () use ($empresa, $servicoId, $v) {
            $empresa = Empresa::query()->where('empresa_operadora_id', $empresa->empresa_operadora_id)
                ->where('ativo', true)->lockForUpdate()->findOrFail($empresa->id);
            if ($servicoId !== null) {
                Servico::daEmpresa($empresa)->where('ativo', true)->findOrFail($servicoId);
            }
            $escopo = $servicoId === null ? 'empresa' : 'servico:'.$servicoId;
            $data = $v['vigencia'].'-01';
            if (PerfilTributario::daEmpresa($empresa)->where('escopo', $escopo)->where('vigencia', $data)->exists()) {
                $this->falhar('Já existe uma versão para este escopo e mês. O histórico não pode ser sobrescrito.');
            }
            $perfil = new PerfilTributario([
                'servico_id' => $servicoId, 'usuario_id' => auth()->id(), 'escopo' => $escopo, 'vigencia' => $data,
                'dados' => array_diff_key($v, ['vigencia' => true]),
            ]);
            $perfil->empresa_operadora_id = $empresa->empresa_operadora_id;
            $perfil->empresa_id = $empresa->id;
            $perfil->save();

            return $perfil;
        });
    }

    public function vigente(Empresa $empresa, Nota $nota): ?PerfilTributario
    {
        $empresa = $this->acessos->empresa($empresa->id);
        abort_unless((int) $nota->empresa_id === $empresa->id && (int) $nota->empresa_operadora_id === (int) $empresa->empresa_operadora_id, 403);
        $base = PerfilTributario::daEmpresa($empresa)->where('vigencia', '<=', $nota->competencia->toDateString());
        // Um perfil específico, mesmo incompleto/incompatível, nunca cai silenciosamente no geral.
        return (clone $base)->where('escopo', 'servico:'.$nota->servico_id)->where('servico_id', $nota->servico_id)->latest('vigencia')->first()
            ?? (clone $base)->where('escopo', 'empresa')->whereNull('servico_id')->latest('vigencia')->first();
    }

    public function impedimentos(PerfilTributario $perfil, ?Configuracao $cfg): array
    {
        $d = $perfil->dados;
        $erros = [];
        if (! $cfg || $cfg->regime_tributario !== ($d['regime'] ?? null)) {
            $erros[] = 'Regime do perfil diverge da configuração da empresa.';
        }
        if (($d['iss'] ?? null) !== 'tributado' || ($d['iss_retido'] ?? null) !== 'nao'
            || ($d['regime_especial'] ?? null) !== 'nao' || ($d['deducoes_beneficios'] ?? null) !== 'nao'
            || filled($d['aliquota_iss'] ?? null)) {
            $erros[] = 'Tratamento do ISS fora do gerador simplificado (inclui alíquota explícita).';
        }
        foreach (self::RETENCOES as $campo) {
            if (($d['retencoes'][$campo] ?? null) !== 'nao') {
                $erros[] = 'Retenção federal exigida ou indefinida.';
                break;
            }
        }
        if (($d['ibscbs'] ?? null) !== 'dispensado' || filled($d['cst'] ?? null) || filled($d['cclass_trib'] ?? null) || filled($d['ind_op'] ?? null)) {
            $erros[] = 'IBS/CBS exige revisão ou leiaute ainda não implementado.';
        }

        return $erros;
    }

    public function sugestao(PerfilTributario $perfil): array
    {
        $d = $perfil->dados;

        return [
            'municipio_prestacao' => (string) $d['municipio_prestacao'],
            'regApTribSN' => isset($d['regApTribSN']) ? (string) $d['regApTribSN'] : null,
            'percentuais_tributos' => $d['percentuais_tributos'],
            'escopo_confirmado' => false, 'ibscbs_nao_exigido' => false,
        ];
    }

    public function conferir(PerfilTributario $perfil, Configuracao $cfg, array $fiscal): void
    {
        $erros = $this->impedimentos($perfil, $cfg);
        if ($erros !== []) {
            $this->falhar(implode(' ', $erros));
        }
        $esperado = $this->sugestao($perfil);
        foreach (['municipio_prestacao', 'regApTribSN', 'percentuais_tributos'] as $campo) {
            $a = $esperado[$campo];
            $b = $fiscal[$campo] ?? null;
            if (is_array($a) && is_array($b)) {
                ksort($a);
                ksort($b);
            }
            if ($a !== $b) {
                $this->falhar('Os dados fiscais divergem do perfil vigente. Revise a configuração antes de preparar.');
            }
        }
    }

    private function percentual(bool $obrigatorio): array
    {
        return [$obrigatorio ? 'required' : 'nullable', 'string', 'regex:/^(?:0|[1-9]\d{0,2})\.\d{2}$/D', 'numeric', 'max:100'];
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['perfil_tributario' => $mensagem]);
    }
}
