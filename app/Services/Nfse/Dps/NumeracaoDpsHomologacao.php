<?php

namespace App\Services\Nfse\Dps;

use App\Models\Empresa;
use App\Models\Nfse\AcessoEmissao;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\DpsLocal;
use App\Models\Nfse\DpsSequencia;
use App\Models\Nfse\Lote;
use App\Models\Nfse\Nota;
use App\Rules\CnpjOuCpfValido;
use App\Services\Nfse\AcessoEmissaoNfse;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Coordenação transacional exclusivamente local, sem autorização ou tráfego fiscal. */
class NumeracaoDpsHomologacao
{
    public const AMBIENTE = 2;
    public const MAXIMO = 999999999999999;

    public function __construct(
        private AcessoEmissaoNfse $acessos,
        private PrepararDpsHomologacao $preparador,
    ) {}

    /**
     * O último número conhecido do ambiente de teste precisa ser informado.
     * Zero somente quando o responsável confirmou que a série nunca foi usada.
     */
    public function inicializar(int $empresaId, string $ultimoNumero): DpsSequencia
    {
        $empresa = $this->empresa($empresaId);
        Validator::make(['ultimo_numero' => $ultimoNumero], ['ultimo_numero' => ['required', 'regex:/^(?:0|[1-9]\d{0,14})$/D']])->validate();
        if ((int) $ultimoNumero >= self::MAXIMO) {
            $this->falhar('Numeração da série esgotada.');
        }

        return DB::transaction(function () use ($empresa, $ultimoNumero) {
            $empresa = $this->bloquear($empresa);
            [$cfg, $documento, $serie] = $this->identidade($empresa);
            if ($this->sequencias($empresa, $documento, $serie)->lockForUpdate()->first()) {
                $this->falhar('A sequência já foi inicializada. Não é permitido reiniciar a numeração.');
            }
            $r = new DpsSequencia([
                'documento_prestador' => $documento, 'ambiente' => self::AMBIENTE, 'serie' => $serie,
                'numero_inicial' => (int) $ultimoNumero, 'ultimo_numero' => (int) $ultimoNumero, 'usuario_id' => auth()->id(),
            ]);
            $r->empresa_id = $empresa->id;
            $r->empresa_operadora_id = $empresa->empresa_operadora_id;
            $r->save();

            return $r;
        });
    }

    public function preparar(int $empresaId, int $notaId, array $fiscal): DpsLocal
    {
        $empresa = $this->empresa($empresaId);
        $fiscal = $this->validarFiscal($fiscal);

        return DB::transaction(function () use ($empresa, $notaId, $fiscal) {
            $empresa = $this->bloquear($empresa);
            [$cfg, $documento, $serie] = $this->identidade($empresa);
            $nota = Nota::daEmpresa($empresa)->where('status', 'em_lote')->lockForUpdate()->findOrFail($notaId);
            $lote = Lote::daEmpresa($empresa)->where('status', 'preparado')->lockForUpdate()->findOrFail($nota->lote_id);
            $item = collect($lote->snapshot)->firstWhere('id', $nota->id);
            $municipio = (string) data_get($nota->snapshot, 'prestador.codigo_municipio_ibge');
            if (! $item || $nota->versao !== (int) $item['versao'] + 1
                || preg_replace('/\D/', '', (string) data_get($nota->snapshot, 'prestador.cnpj')) !== $documento
                || (string) $empresa->codigo_municipio_ibge !== $municipio || ! preg_match('/^[1-9]\d{6}$/D', $municipio)) {
                $this->falhar('Lote, documento ou município do prestador foi alterado. Revise a conferência.');
            }
            $a = AcessoEmissao::daEmpresa($empresa)->lockForUpdate()->first();
            if (! $a || $a->canal !== 'api' || ! in_array($a->modo, ['certificado_proprio', 'certificado_representante'], true)
                || $a->documento_prestador !== $documento || ($a->procuracao_ate && $a->procuracao_ate->toDateString() < now()->toDateString())) {
                $this->falhar('Configure um acesso por certificado compatível com o prestador.');
            }
            $cert = $this->acessos->certificados($empresa, $a->modo)->lockForUpdate()->find($a->certificado_digital_id);
            if (! $cert || ! $cert->fingerprint || ($a->modo === 'certificado_proprio' && preg_replace('/\D/', '', (string) $cert->documento_titular) !== $documento)) {
                $this->falhar('Certificado selecionado indisponível ou incompatível.');
            }
            $hash = self::hash([
                'nota' => $nota->only(['id', 'versao', 'tomador_id', 'servico_id', 'competencia', 'valor', 'descricao', 'snapshot']),
                'lote' => $lote->id,
                'config' => $cfg->only(['regime_tributario', 'serie_dps']),
                'acesso' => $a->only(['id', 'modo', 'canal', 'documento_prestador', 'certificado_digital_id', 'procuracao_ate']),
                'certificado' => $cert->only(['id', 'fingerprint', 'documento_titular', 'valido_de', 'valido_ate']),
                'fiscal' => $fiscal, 'ambiente' => self::AMBIENTE, 'leiaute' => GeradorDps::VERSAO,
                'algoritmo' => config('automacao_fiscal.nfse_dps_algoritmo'),
            ]);
            $existente = DpsLocal::daEmpresa($empresa)->where('nota_id', $nota->id)
                ->where('versao_nota', $nota->versao)->where('ambiente', self::AMBIENTE)->lockForUpdate()->first();
            if ($existente) {
                if (! hash_equals($existente->config_hash, $hash)) {
                    $this->falhar('A DPS local já foi preparada com outros dados. Revise a nota e prepare um novo lote; não sobrescreva o histórico.');
                }
                if (! hash_equals($existente->xml_hash, hash('sha256', $existente->xml_criptografado))) {
                    throw new RuntimeException('Integridade da DPS local não confirmada.');
                }
                return $existente;
            }
            $seq = $this->sequencias($empresa, $documento, $serie)->lockForUpdate()->first();
            if (! $seq) {
                $this->falhar('Inicialize a sequência com o último número conhecido em produção restrita.');
            }
            if ($seq->ultimo_numero >= self::MAXIMO) {
                $this->falhar('Numeração da série esgotada.');
            }
            $numero = $seq->ultimo_numero + 1;
            $id = GeradorDps::id($municipio, $documento, $serie, (string) $numero);
            // O preparador não tem cliente HTTP. Qualquer falha reverte a operação inteira.
            $xml = $this->preparador->preparar($empresa->id, $nota->id, (string) $numero, $fiscal);
            $this->conferirIdentidade($xml, $id, $documento, $serie, (string) $numero, $municipio);
            $dps = new DpsLocal([
                'sequencia_id' => $seq->id, 'nota_id' => $nota->id, 'lote_id' => $lote->id,
                'certificado_id' => $cert->id, 'versao_nota' => $nota->versao, 'ambiente' => self::AMBIENTE,
                'numero' => $numero, 'id_dps' => $id, 'config_hash' => $hash, 'xml_hash' => hash('sha256', $xml),
                'xml_criptografado' => $xml, 'status' => 'preparada_local', 'usuario_id' => auth()->id(),
            ]);
            $dps->empresa_id = $empresa->id;
            $dps->empresa_operadora_id = $empresa->empresa_operadora_id;
            $dps->save();
            $seq->ultimo_numero = $numero;
            $seq->save();

            return $dps;
        });
    }

    private function empresa(int $id): Empresa
    {
        $empresa = $this->acessos->empresa($id);
        abort_unless(config('automacao_fiscal.nfse_dps_local_enabled', false), 403, 'Preparação de DPS local desabilitada.');

        return $empresa;
    }

    private function bloquear(Empresa $empresa): Empresa
    {
        return Empresa::query()->where('empresa_operadora_id', $empresa->empresa_operadora_id)
            ->where('ativo', true)->lockForUpdate()->findOrFail($empresa->id);
    }

    private function identidade(Empresa $empresa): array
    {
        $cfg = Configuracao::daEmpresa($empresa)->where('habilitado_preparo', true)->lockForUpdate()->firstOrFail();
        $documento = preg_replace('/\D/', '', (string) $empresa->cnpj);
        $serie = ltrim((string) $cfg->serie_dps, '0');
        Validator::make(['documento' => $documento, 'serie' => $serie], [
            'documento' => ['required', 'regex:/^(?:\d{11}|\d{14})$/D', new CnpjOuCpfValido],
            'serie' => ['required', 'regex:/^[1-9]\d{0,4}$/D'],
        ])->validate();

        return [$cfg, $documento, $serie];
    }

    private function sequencias(Empresa $empresa, string $documento, string $serie): Builder
    {
        return DpsSequencia::daEmpresa($empresa)->where('documento_prestador', $documento)
            ->where('ambiente', self::AMBIENTE)->where('serie', $serie);
    }

    private function validarFiscal(array $fiscal): array
    {
        $v = Validator::make($fiscal, [
            'municipio_prestacao' => 'required|digits:7', 'regApTribSN' => 'nullable|in:1,2,3',
            'escopo_confirmado' => 'required|accepted', 'ibscbs_nao_exigido' => 'required|accepted',
            'percentuais_tributos' => 'required|array:pTotTribFed,pTotTribEst,pTotTribMun',
            'percentuais_tributos.pTotTribFed' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,2})\.\d{2}$/D', 'numeric', 'max:100'],
            'percentuais_tributos.pTotTribEst' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,2})\.\d{2}$/D', 'numeric', 'max:100'],
            'percentuais_tributos.pTotTribMun' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,2})\.\d{2}$/D', 'numeric', 'max:100'],
        ])->validate();

        return [
            'municipio_prestacao' => (string) $v['municipio_prestacao'],
            'regApTribSN' => isset($v['regApTribSN']) ? (string) $v['regApTribSN'] : null,
            'escopo_confirmado' => true, 'ibscbs_nao_exigido' => true,
            'percentuais_tributos' => $v['percentuais_tributos'],
        ];
    }

    private static function hash(array $dados): string
    {
        $ordenar = function (array $v) use (&$ordenar): array {
            if (! array_is_list($v)) {
                ksort($v);
            }
            foreach ($v as &$item) {
                if (is_array($item)) {
                    $item = $ordenar($item);
                }
            }
            return $v;
        };

        return hash('sha256', json_encode($ordenar($dados), JSON_THROW_ON_ERROR));
    }

    private function conferirIdentidade(string $xml, string $id, string $documento, string $serie, string $numero, string $municipio): void
    {
        $dom = new DOMDocument;
        if ($xml === '' || strlen($xml) > 1048576 || stripos($xml, '<!DOCTYPE') !== false || ! @$dom->loadXML($xml, LIBXML_NONET) || $dom->doctype) {
            throw new RuntimeException('DPS local inválida.');
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('n', GeradorDps::NS);
        $xp->registerNamespace('s', AssinadorDps::NS);
        $inf = '/n:DPS/n:infDPS';
        $docTag = strlen($documento) === 14 ? 'CNPJ' : 'CPF';
        $campos = [
            $inf.'/@Id' => $id, $inf.'/n:tpAmb' => '2', $inf.'/n:serie' => $serie,
            $inf.'/n:nDPS' => $numero, $inf.'/n:cLocEmi' => $municipio, $inf.'/n:prest/n:'.$docTag => $documento,
            '/n:DPS/s:Signature/s:SignedInfo/s:Reference/@URI' => '#'.$id,
        ];
        foreach ($campos as $caminho => $esperado) {
            $nodes = $xp->query($caminho);
            if (! $nodes || $nodes->length !== 1 || $nodes->item(0)->nodeValue !== $esperado) {
                throw new RuntimeException('Identidade da DPS não coincide com a reserva local.');
            }
        }
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['dps' => $mensagem]);
    }
}
