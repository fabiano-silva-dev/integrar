<?php

namespace App\Services\AutomacaoFiscal\Sefaz;

use App\Models\CertificadoDigital;
use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use RuntimeException;

/**
 * Cliente do Web Service SEFAZ-RS "Integração Contabilista" (NfeIntegracao / nfeIntegracaoContab).
 *
 * Permite baixar 1 XML por chave (solDFe/chAcesso) com o certificado A1 do escritório,
 * desde que exista autorização eletrônica do contribuinte consultado.
 *
 * @see docs/doc_webservice_contabilista_v2.00_new.zip
 */
class NfeIntegracaoContabilistaClient
{
    public function __construct(
        private readonly CertificadoDigitalService $certificados,
    ) {}

    /**
     * @return array{xml: string, c_stat: string, x_motivo: string, fonte: string}
     */
    public function baixarXmlPorChave(
        string $chaveAcesso,
        string $cnpjConsultado,
        CertificadoDigital $certificadoEscritorio,
        string $modelo = '55',
    ): array {
        $chave = preg_replace('/\D+/', '', $chaveAcesso) ?? '';
        $cnpj = preg_replace('/\D+/', '', $cnpjConsultado) ?? '';

        if (strlen($chave) !== 44) {
            throw new RuntimeException('Chave de acesso inválida para o WS Contabilista.');
        }
        if (strlen($cnpj) !== 14) {
            throw new RuntimeException('CNPJ consultado inválido para o WS Contabilista.');
        }
        if (! $certificadoEscritorio->ehDoEscritorio()) {
            throw new RuntimeException('O WS Contabilista exige o certificado A1 do escritório (contador).');
        }

        $dadosMsg = $this->montarDistNFeRsPorChave($chave, $cnpj, $modelo);
        $envelope = $this->montarEnvelopeSoap($dadosMsg);
        $raw = $this->postSoap($envelope, $certificadoEscritorio);

        return $this->interpretarResposta($raw, $chave);
    }

    public function montarDistNFeRsPorChave(string $chave, string $cnpj, string $modelo = '55'): string
    {
        $tpAmb = (string) config('automacao_fiscal.nfe_contabilista_tp_amb', '1');
        $verAplic = (string) config('automacao_fiscal.nfe_contabilista_ver_aplic', 'IntegrarExpert1.0');
        $modelo = $modelo === '65' ? '65' : '55';

        return '<distNFeRS versao="1.00" xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<tpAmb>'.$this->esc($tpAmb).'</tpAmb>'
            .'<verAplic>'.$this->esc($verAplic).'</verAplic>'
            .'<cUF>43</cUF>'
            .'<CNPJ>'.$this->esc($cnpj).'</CNPJ>'
            .'<mod>'.$modelo.'</mod>'
            .'<solDFe>'
            .'<chAcesso>'.$this->esc($chave).'</chAcesso>'
            .'</solDFe>'
            .'</distNFeRS>';
    }

    public function montarEnvelopeSoap(string $distNFeRs): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            .' xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">'
            .'<soap12:Body>'
            .'<nfeIntegracaoContab xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NfeIntegracao">'
            .'<nfeDadosMsg>'.$distNFeRs.'</nfeDadosMsg>'
            .'</nfeIntegracaoContab>'
            .'</soap12:Body>'
            .'</soap12:Envelope>';
    }

    /**
     * @return array{xml: string, c_stat: string, x_motivo: string, fonte: string}
     */
    public function interpretarResposta(string $soapXml, string $chaveEsperada): array
    {
        $cStat = $this->primeiraTag($soapXml, 'cStat') ?? '';
        $xMotivo = $this->primeiraTag($soapXml, 'xMotivo') ?? '';

        if ($cStat === '118') {
            $loteB64 = $this->primeiraTag($soapXml, 'loteDistComp');
            if ($loteB64 === null || $loteB64 === '') {
                throw new RuntimeException('WS Contabilista retornou cStat=118 sem loteDistComp.');
            }

            $xml = $this->extrairNfeProcDoLote($loteB64, $chaveEsperada);
            if ($xml === null) {
                throw new RuntimeException('WS Contabilista retornou lote sem nfeProc da chave solicitada.');
            }

            return [
                'xml' => $xml,
                'c_stat' => $cStat,
                'x_motivo' => $xMotivo,
                'fonte' => 'ws-contabilista-rs',
            ];
        }

        if ($cStat === '117') {
            throw new RuntimeException('WS Contabilista: nenhum DF-e localizado (cStat=117). '.$xMotivo);
        }

        if ($cStat !== '') {
            throw new RuntimeException("WS Contabilista rejeitou: {$cStat} — {$xMotivo}");
        }

        throw new RuntimeException('WS Contabilista: resposta SOAP sem cStat reconhecível.');
    }

    public function extrairNfeProcDoLote(string $loteBase64, string $chaveEsperada): ?string
    {
        $bin = base64_decode($loteBase64, true);
        if ($bin === false || $bin === '') {
            return null;
        }

        $xmlLote = $this->descompactarSeNecessario($bin);
        if ($xmlLote === null || $xmlLote === '') {
            return null;
        }

        if (! str_contains($xmlLote, '<')) {
            return null;
        }

        // Preferir proc com schema de NF-e e a chave pedida.
        if (preg_match_all('/<proc\b([^>]*)>(.*?)<\/proc>/is', $xmlLote, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $attrs = $m[1];
                $inner = $m[2];
                $schema = '';
                if (preg_match('/\bschema\s*=\s*"([^"]+)"/i', $attrs, $sm)) {
                    $schema = strtolower($sm[1]);
                }
                $chAttr = '';
                if (preg_match('/\bchAcesso\s*=\s*"([^"]+)"/i', $attrs, $cm)) {
                    $chAttr = preg_replace('/\D+/', '', $cm[1]) ?? '';
                }

                $ehNfe = str_contains($schema, 'procnfe') || str_contains($inner, '<nfeProc') || str_contains($inner, '<NFe');
                if (! $ehNfe) {
                    continue;
                }
                if ($chAttr !== '' && $chAttr !== $chaveEsperada) {
                    continue;
                }
                if ($chAttr === '' && ! str_contains($inner, $chaveEsperada)) {
                    continue;
                }

                $nfeProc = $this->extrairNfeProc($inner);
                if ($nfeProc !== null) {
                    return $nfeProc;
                }
            }
        }

        return $this->extrairNfeProc($xmlLote);
    }

    private function postSoap(string $envelope, CertificadoDigital $certificado): string
    {
        $url = (string) config(
            'automacao_fiscal.nfe_contabilista_url',
            'https://nfe-rs-integracao.sefazvirtual.rs.gov.br/ws/NfeIntegracao/NfeIntegracao.asmx'
        );

        $mtls = $this->certificados->materializarCredenciaisMtls($certificado);

        try {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Falha ao iniciar cURL para o WS Contabilista.');
            }

            $timeout = (int) config('automacao_fiscal.nfe_contabilista_timeout_s', 12);
            $connectTimeout = (int) config('automacao_fiscal.nfe_contabilista_connect_timeout_s', 5);

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $envelope,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/soap+xml; charset=utf-8',
                    'SOAPAction: "http://www.portalfiscal.inf.br/nfe/wsdl/NfeIntegracao/nfeIntegracaoContab"',
                ],
                CURLOPT_SSLCERT => $mtls['cert'],
                CURLOPT_SSLKEY => $mtls['key'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => max(3, $connectTimeout),
                CURLOPT_TIMEOUT => max(5, $timeout),
            ]);

            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                throw new RuntimeException('Falha de comunicação com o WS Contabilista: '.$error);
            }
            if (! is_string($body) || $body === '') {
                throw new RuntimeException('WS Contabilista retornou resposta vazia (HTTP '.$http.').');
            }
            if ($http >= 400) {
                throw new RuntimeException('WS Contabilista HTTP '.$http.': '.mb_substr(strip_tags($body), 0, 300));
            }

            return $body;
        } finally {
            ($mtls['cleanup'])();
        }
    }

    private function descompactarSeNecessario(string $bin): string
    {
        if (str_starts_with($bin, '<') || str_starts_with(ltrim($bin), '<')) {
            return $bin;
        }

        // Gzip (doc oficial)
        $gz = @gzdecode($bin);
        if (is_string($gz) && $gz !== '') {
            return $gz;
        }

        // Alguns ambientes usam zip
        if (str_starts_with($bin, "PK")) {
            $tmp = tempnam(sys_get_temp_dir(), 'lote');
            if ($tmp === false) {
                return $bin;
            }
            file_put_contents($tmp, $bin);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) === true) {
                $out = '';
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    $content = $zip->getFromIndex($i);
                    if (is_string($content) && str_contains($content, '<')) {
                        $out = $content;
                        break;
                    }
                    if (is_string($name) && str_ends_with(strtolower($name), '.xml') && is_string($content)) {
                        $out = $content;
                        break;
                    }
                }
                $zip->close();
                @unlink($tmp);
                if ($out !== '') {
                    return $out;
                }
            }
            @unlink($tmp);
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
