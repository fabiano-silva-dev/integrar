<?php

namespace App\Services\AutomacaoFiscal\Sefaz;

use App\Models\CertificadoDigital;
use App\Services\AutomacaoFiscal\AnaliseFiscalService;
use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use RuntimeException;

/**
 * Cliente REST da Sefin Nacional — GET /nfse/{chaveAcesso} com mTLS do contribuinte.
 */
class NfseSefinNacionalClient
{
    public const FONTE = 'ws-sefin-nacional';

    public function __construct(
        private readonly CertificadoDigitalService $certificados,
    ) {}

    public function baixarXmlPorChave(string $chaveAcesso, CertificadoDigital $certificado): string
    {
        $chave = $this->normalizarChave($chaveAcesso);
        $url = $this->montarUrl($chave);
        $raw = $this->getJson($url, $certificado);

        return $this->interpretarRespostaHttp($raw['http'], $raw['body'], $chave);
    }

    public function montarUrl(string $chaveAcesso): string
    {
        $chave = $this->normalizarChave($chaveAcesso);
        $base = rtrim((string) config('automacao_fiscal.nfse_sefin_url', 'https://sefin.nfse.gov.br/SefinNacional'), '/');

        return $base.'/nfse/'.$chave;
    }

    public function normalizarChave(string $chaveAcesso): string
    {
        $chave = AnaliseFiscalService::normalizarChaveAcesso($chaveAcesso) ?? '';
        if (strlen($chave) !== 50) {
            throw new RuntimeException('Chave de acesso da NFS-e inválida (esperado 50 dígitos).');
        }

        return $chave;
    }

    public function decodificarXmlGzipBase64(string $b64): string
    {
        $b64 = trim($b64);
        if ($b64 === '') {
            throw new RuntimeException('Sefin Nacional retornou XML vazio.');
        }

        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') {
            throw new RuntimeException('Sefin Nacional: nfseXmlGZipB64 inválido.');
        }

        $xml = str_starts_with(ltrim($bin), '<') ? $bin : @gzdecode($bin);
        if (! is_string($xml) || $xml === '' || ! str_contains($xml, '<')) {
            throw new RuntimeException('Sefin Nacional: não foi possível descompactar o XML da NFS-e.');
        }

        return $xml;
    }

    public function interpretarRespostaHttp(int $http, string $body, string $chaveEsperada = ''): string
    {
        $json = $this->decodificarJson($body);

        if ($http === 401) {
            throw new RuntimeException('Sefin Nacional: certificado de cliente não aceito (HTTP 401).');
        }
        if ($http === 403) {
            throw new RuntimeException(
                'Sefin Nacional: o certificado não é prestador, tomador nem intermediário desta NFS-e (HTTP 403).'
                .$this->sufixoErro($json)
            );
        }
        if ($http === 404) {
            throw new RuntimeException(
                'Sefin Nacional: NFS-e não encontrada no ADN para esta chave (HTTP 404).'
                .$this->sufixoErro($json)
            );
        }
        if ($http >= 400) {
            throw new RuntimeException(
                'Sefin Nacional HTTP '.$http.': '.($this->mensagemErro($json) ?: mb_substr(strip_tags($body), 0, 300))
            );
        }

        $b64 = (string) ($json['nfseXmlGZipB64'] ?? $json['NfseXmlGZipB64'] ?? '');
        $xml = $this->decodificarXmlGzipBase64($b64);

        if ($chaveEsperada !== '' && ! str_contains(preg_replace('/\D+/', '', $xml) ?? '', $chaveEsperada)) {
            $chaveJson = AnaliseFiscalService::normalizarChaveAcesso((string) ($json['chaveAcesso'] ?? '')) ?? '';
            if ($chaveJson !== '' && $chaveJson !== $chaveEsperada) {
                throw new RuntimeException('Sefin Nacional retornou XML de outra chave de acesso.');
            }
        }

        return $xml;
    }

    /**
     * @return array{http: int, body: string}
     */
    private function getJson(string $url, CertificadoDigital $certificado): array
    {
        $mtls = $this->certificados->materializarCredenciaisMtls($certificado);

        try {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Falha ao iniciar cURL para a Sefin Nacional.');
            }

            curl_setopt_array($ch, [
                CURLOPT_HTTPGET => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                ],
                CURLOPT_SSLCERT => $mtls['cert'],
                CURLOPT_SSLKEY => $mtls['key'],
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => (int) config('automacao_fiscal.nfse_sefin_connect_timeout_s', 10),
                CURLOPT_TIMEOUT => (int) config('automacao_fiscal.nfse_sefin_timeout_s', 30),
            ]);

            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                throw new RuntimeException('Falha de comunicação com a Sefin Nacional: '.$error);
            }
            if (! is_string($body) || $body === '') {
                throw new RuntimeException('Sefin Nacional retornou resposta vazia (HTTP '.$http.').');
            }

            return ['http' => $http, 'body' => $body];
        } finally {
            ($mtls['cleanup'])();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodificarJson(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function mensagemErro(array $json): string
    {
        if (isset($json['erros'][0]['descricao']) && is_string($json['erros'][0]['descricao'])) {
            return $json['erros'][0]['descricao'];
        }
        if (isset($json['mensagem']) && is_string($json['mensagem'])) {
            return $json['mensagem'];
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function sufixoErro(array $json): string
    {
        $msg = $this->mensagemErro($json);

        return $msg !== '' ? ' '.$msg : '';
    }
}
