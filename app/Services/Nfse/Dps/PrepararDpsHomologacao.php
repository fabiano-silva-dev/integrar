<?php

namespace App\Services\Nfse\Dps;

use App\Models\Nfse\AcessoEmissao;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\Lote;
use App\Models\Nfse\Nota;
use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use App\Services\Nfse\AcessoEmissaoNfse;
use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Serviço interno, sem rota nem transmissão. Não reserva numeração fiscal.
 * Usado pelo coordenador NumeracaoDpsHomologacao com número reservado de teste e dados fiscais explícitos.
 */
class PrepararDpsHomologacao
{
    public function __construct(
        private AcessoEmissaoNfse $acessos,
        private CertificadoDigitalService $certificados,
        private GeradorDps $gerador,
        private AssinadorDps $assinador,
        private ValidadorDps $validador,
    ) {}

    public function preparar(int $empresaId, int $notaId, string $numeroTeste, array $fiscal): string
    {
        $empresa = $this->acessos->empresa($empresaId);
        abort_unless(config('automacao_fiscal.nfse_dps_local_enabled', false), 403, 'Preparação de DPS local desabilitada.');
        $nota = Nota::daEmpresa($empresa)->where('status', 'em_lote')->findOrFail($notaId);
        $lote = Lote::daEmpresa($empresa)->where('status', 'preparado')->findOrFail($nota->lote_id);
        $item = collect($lote->snapshot)->firstWhere('id', $nota->id);
        $cfg = Configuracao::daEmpresa($empresa)->where('habilitado_preparo', true)->firstOrFail();
        $a = AcessoEmissao::daEmpresa($empresa)->firstOrFail();
        $doc = preg_replace('/\D/', '', (string) $empresa->cnpj);
        if (! $item || $nota->versao !== (int) $item['versao'] + 1 || $a->canal !== 'api'
            || ! in_array($a->modo, ['certificado_proprio', 'certificado_representante'], true)
            || $a->documento_prestador !== $doc || ($a->procuracao_ate && $a->procuracao_ate->toDateString() < now()->toDateString())
            || preg_replace('/\D/', '', (string) data_get($nota->snapshot, 'prestador.cnpj')) !== $doc) {
            throw new RuntimeException('Nota, lote ou configuração de acesso incompatível.');
        }
        $cert = $this->acessos->certificados($empresa, $a->modo)->find($a->certificado_digital_id);
        if (! $cert || ! $cert->fingerprint || ($a->modo === 'certificado_proprio' && preg_replace('/\D/', '', (string) $cert->documento_titular) !== $doc)) {
            throw new RuntimeException('Certificado selecionado indisponível ou incompatível.');
        }
        $regime = match ($cfg->regime_tributario) {
            'normal' => '1', 'mei' => '2', 'simples_nacional' => '3',
            default => throw new RuntimeException('Regime tributário não configurado.'),
        };
        $v = Validator::make($fiscal, [
            'municipio_prestacao' => 'required|digits:7',
            'regApTribSN' => 'nullable|in:1,2,3',
            'escopo_confirmado' => 'required|accepted',
            'ibscbs_nao_exigido' => 'required|accepted',
            'percentuais_tributos' => 'required|array',
            'percentuais_tributos.pTotTribFed' => 'required|string',
            'percentuais_tributos.pTotTribEst' => 'required|string',
            'percentuais_tributos.pTotTribMun' => 'required|string',
        ])->validate();
        $t = $nota->snapshot['tomador'];
        $s = $nota->snapshot['servico'];
        $end = array_filter([
            'municipio' => $t['codigo_municipio_ibge'] ?? null, 'cep' => $t['cep'] ?? null,
            'logradouro' => $t['logradouro'] ?? null, 'numero' => $t['numero'] ?? null,
            'complemento' => $t['complemento'] ?? null, 'bairro' => $t['bairro'] ?? null,
        ], fn ($x) => $x !== null && $x !== '');
        $dados = [
            'prestador' => $doc, 'tomador' => (string) $t['documento'], 'nome_tomador' => $t['nome'],
            'im_prestador' => data_get($nota->snapshot, 'prestador.inscricao_municipal'),
            'im_tomador' => $t['inscricao_municipal'] ?? null,
            'municipio_emissor' => (string) data_get($nota->snapshot, 'prestador.codigo_municipio_ibge'),
            'municipio_prestacao' => (string) $v['municipio_prestacao'], 'serie' => ltrim((string) $cfg->serie_dps, '0'), 'numero' => $numeroTeste,
            'competencia' => $nota->competencia->toDateString(), 'emissao' => new DateTimeImmutable(now()->toIso8601String()),
            'opSimpNac' => $regime, 'regApTribSN' => isset($v['regApTribSN']) ? (string) $v['regApTribSN'] : null,
            'escopo_confirmado' => true, 'ibscbs_nao_exigido' => true, 'percentuais_tributos' => $v['percentuais_tributos'],
            'endereco_tomador' => $end, 'valor' => $nota->valor, 'descricao' => $nota->descricao,
            'codigo_tributacao_nacional' => $s['codigo_tributacao_nacional'], 'codigo_tributacao_municipal' => $s['codigo_tributacao_municipal'] ?? null,
            'nbs' => $s['nbs'] ?? null,
        ];
        $xml = $this->gerador->gerar($dados);
        // Confere a instalação antes de abrir a chave; o XSD pode exigir Signature.
        // A validação integral deve ocorrer sobre o XML assinado.
        $this->validador->garantirDisponivel();
        $pem = $this->certificados->extrairPem($cert);
        try {
            $fingerprint = openssl_x509_fingerprint($pem['cert'], 'sha256');
            if (! is_string($fingerprint) || ! hash_equals(strtolower($cert->fingerprint), strtolower($fingerprint))) {
                throw new RuntimeException('Arquivo do certificado difere da referência cadastrada.');
            }
            $assinado = $this->assinador->assinar($xml, $pem['cert'], $pem['key'], (string) config('automacao_fiscal.nfse_dps_algoritmo', ''));
            $this->validador->validar($assinado);
            return $assinado;
        } finally {
            unset($pem);
        }
    }
}
