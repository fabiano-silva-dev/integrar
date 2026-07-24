<?php

namespace App\Services\AutomacaoFiscal\Sefaz;

use App\Models\CertificadoDigital;
use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use RuntimeException;

/**
 * Cliente NFeDistribuicaoDFe (Ambiente Nacional) — download por chave (consChNFe).
 *
 * Exige A1 do destinatário (interessado). Após ciência da operação, a SEFAZ libera o XML completo.
 */
class NfeDistribuicaoDfeClient
{
    /** @var array<string, string> */
    private const UF_IBGE = [
        'AC' => '12', 'AL' => '27', 'AP' => '16', 'AM' => '13', 'BA' => '29', 'CE' => '23',
        'DF' => '53', 'ES' => '32', 'GO' => '52', 'MA' => '21', 'MT' => '51', 'MS' => '50',
        'MG' => '31', 'PA' => '15', 'PB' => '25', 'PR' => '41', 'PE' => '26', 'PI' => '22',
        'RJ' => '33', 'RN' => '24', 'RS' => '43', 'RO' => '11', 'RR' => '14', 'SC' => '42',
        'SP' => '35', 'SE' => '28', 'TO' => '17',
    ];

    public function __construct(
        private readonly CertificadoDigitalService $certificados,
    ) {}

    /**
     * @return array{
     *   xml: ?string,
     *   c_stat: string,
     *   x_motivo: string,
     *   tem_resumo: bool,
     *   fonte: string
     * }
     */
    public function baixarXmlPorChave(
        string $chaveAcesso,
        string $cnpjDestinatario,
        CertificadoDigital $certificado,
        ?string $ufAutor = null,
    ): array {
        $chave = preg_replace('/\D+/', '', $chaveAcesso) ?? '';
        $cnpj = preg_replace('/\D+/', '', $cnpjDestinatario) ?? '';

        if (strlen($chave) !== 44) {
            throw new RuntimeException('Chave de acesso inválida para DistDFe.');
        }
        if (strlen($cnpj) !== 14) {
            throw new RuntimeException('CNPJ do destinatário inválido para DistDFe.');
        }

        $dadosMsg = $this->montarDistDfePorChave($chave, $cnpj, $ufAutor);
        $envelope = $this->montarEnvelopeSoap($dadosMsg);
        $raw = $this->postSoap($envelope, $certificado);

        return $this->interpretarResposta($raw, $chave);
    }

    public function montarDistDfePorChave(string $chave, string $cnpj, ?string $ufAutor = null): string
    {
        $tpAmb = (string) config('automacao_fiscal.nfe_distdfe_tp_amb', '1');
        $cUf = $this->resolverCodigoUf($ufAutor);

        return '<distDFeInt versao="1.01" xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<tpAmb>'.$this->esc($tpAmb).'</tpAmb>'
            .'<cUFAutor>'.$this->esc($cUf).'</cUFAutor>'
            .'<CNPJ>'.$this->esc($cnpj).'</CNPJ>'
            .'<consChNFe>'
            .'<chNFe>'.$this->esc($chave).'</chNFe>'
            .'</consChNFe>'
            .'</distDFeInt>';
    }

    public function montarEnvelopeSoap(string $distDfeInt): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            .' xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">'
            .'<soap12:Body>'
            .'<nfeDistDFeInteresse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">'
            .'<nfeDadosMsg>'.$distDfeInt.'</nfeDadosMsg>'
            .'</nfeDistDFeInteresse>'
            .'</soap12:Body>'
            .'</soap12:Envelope>';
    }

    /**
     * @return array{
     *   xml: ?string,
     *   c_stat: string,
     *   x_motivo: string,
     *   tem_resumo: bool,
     *   fonte: string
     * }
     */
    public function interpretarResposta(string $soapXml, string $chaveEsperada): array
    {
        $cStat = $this->primeiraTag($soapXml, 'cStat') ?? '';
        $xMotivo = $this->primeiraTag($soapXml, 'xMotivo') ?? '';

        if ($cStat === '138') {
            $extraido = $this->extrairDoLote($soapXml, $chaveEsperada);

            return [
                'xml' => $extraido['xml'],
                'c_stat' => $cStat,
                'x_motivo' => $xMotivo,
                'tem_resumo' => $extraido['tem_resumo'],
                'fonte' => 'ws-distdfe-an',
            ];
        }

        if ($cStat === '137') {
            return [
                'xml' => null,
                'c_stat' => $cStat,
                'x_motivo' => $xMotivo !== '' ? $xMotivo : 'Nenhum documento localizado',
                'tem_resumo' => false,
                'fonte' => 'ws-distdfe-an',
            ];
        }

        if ($cStat === '656') {
            throw new RuntimeException('DistDFe: consumo indevido (cStat=656). Aguarde cerca de 1 hora antes de nova consulta.');
        }

        if ($cStat !== '') {
            throw new RuntimeException("DistDFe rejeitou: {$cStat} — {$xMotivo}");
        }

        throw new RuntimeException('DistDFe: resposta SOAP sem cStat reconhecível.');
    }

    /**
     * @return array{xml: ?string, tem_resumo: bool}
     */
    public function extrairDoLote(string $soapXml, string $chaveEsperada): array
    {
        $temResumo = false;
        $nfeProc = null;

        if (! preg_match_all('/<docZip\b([^>]*)>([\s\S]*?)<\/docZip>/i', $soapXml, $matches, PREG_SET_ORDER)) {
            return ['xml' => null, 'tem_resumo' => false];
        }

        foreach ($matches as $m) {
            $attrs = $m[1];
            $b64 = trim($m[2]);
            $schema = '';
            if (preg_match('/\bschema\s*=\s*"([^"]+)"/i', $attrs, $sm)) {
                $schema = strtolower($sm[1]);
            }

            $bin = base64_decode($b64, true);
            if ($bin === false || $bin === '') {
                continue;
            }
            $xmlDoc = $this->descompactarSeNecessario($bin);
            if ($xmlDoc === '' || ! str_contains($xmlDoc, '<')) {
                continue;
            }

            if (str_contains($schema, 'resnfe') || str_contains($xmlDoc, '<resNFe')) {
                $temResumo = true;
                continue;
            }

            if (
                str_contains($schema, 'procnfe')
                || str_contains($xmlDoc, '<nfeProc')
                || str_contains($xmlDoc, '<NFe')
            ) {
                if (! str_contains($xmlDoc, $chaveEsperada) && $chaveEsperada !== '') {
                    continue;
                }
                $nfeProc = $this->extrairNfeProc($xmlDoc) ?? $xmlDoc;
            }
        }

        return ['xml' => $nfeProc, 'tem_resumo' => $temResumo];
    }

    public function resolverCodigoUf(?string $uf): string
    {
        $uf = strtoupper(trim((string) $uf));
        if ($uf !== '' && isset(self::UF_IBGE[$uf])) {
            return self::UF_IBGE[$uf];
        }
        if (preg_match('/^\d{2}$/', $uf)) {
            return $uf;
        }

        return (string) config('automacao_fiscal.nfe_distdfe_cuf_autor', '43');
    }

    private function postSoap(string $envelope, CertificadoDigital $certificado): string
    {
        $url = (string) config(
            'automacao_fiscal.nfe_distdfe_url',
            'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx'
        );

        $mtls = $this->certificados->materializarCredenciaisMtls($certificado);

        try {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Falha ao iniciar cURL para DistDFe.');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $envelope,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/soap+xml; charset=utf-8',
                ],
                CURLOPT_SSLCERT => $mtls['cert'],
                CURLOPT_SSLKEY => $mtls['key'],
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => (int) config('automacao_fiscal.nfe_distdfe_timeout_s', 60),
            ]);

            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                throw new RuntimeException('Falha de comunicação com DistDFe: '.$error);
            }
            if (! is_string($body) || $body === '') {
                throw new RuntimeException('DistDFe retornou resposta vazia (HTTP '.$http.').');
            }
            if ($http >= 400) {
                throw new RuntimeException('DistDFe HTTP '.$http.': '.mb_substr(strip_tags($body), 0, 300));
            }

            return $body;
        } finally {
            ($mtls['cleanup'])();
        }
    }

    private function descompactarSeNecessario(string $bin): string
    {
        if (str_starts_with(ltrim($bin), '<')) {
            return $bin;
        }

        $gz = @gzdecode($bin);
        if (is_string($gz) && $gz !== '') {
            return $gz;
        }

        return $bin;
    }

    private function extrairNfeProc(string $xml): ?string
    {
        if (preg_match('/<nfeProc\b[\s\S]*?<\/nfeProc>/i', $xml, $m)) {
            return $m[0];
        }
        if (preg_match('/<NFe\b[\s\S]*?<\/NFe>/i', $xml, $m)) {
            return $m[0];
        }

        return null;
    }

    private function primeiraTag(string $xml, string $tag): ?string
    {
        if (preg_match('/<'.$tag.'\b[^>]*>([\s\S]*?)<\/'.$tag.'>/i', $xml, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return null;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
