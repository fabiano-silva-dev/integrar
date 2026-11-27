<?php

namespace App\Services\Nfse\Dps;

use App\Models\CertificadoDigital;
use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use RuntimeException;

class TransporteSefinRestrita
{
    public const BASE = 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional';

    public function __construct(private CertificadoDigitalService $certificados) {}

    public function enviar(string $xml, CertificadoDigital $certificado): array
    {
        // Defesa adicional: nem mesmo o transporte aceita DPS de produção ou substituição.
        RetornoSefinRestrita::validarDps($xml);
        $gzip = gzencode($xml);
        if ($gzip === false) {
            throw new RuntimeException('Falha ao compactar DPS.');
        }
        return $this->requisitar('POST', '/nfse', $certificado, json_encode(['dpsXmlGZipB64' => base64_encode($gzip)], JSON_THROW_ON_ERROR));
    }

    public function consultarDps(string $id, CertificadoDigital $certificado): array
    {
        if (! preg_match('/^DPS\d{42}$/D', $id)) {
            throw new RuntimeException('Identificador DPS inválido.');
        }
        return $this->requisitar('GET', '/dps/'.$id, $certificado);
    }

    public function consultarNfse(string $chave, CertificadoDigital $certificado): array
    {
        if (! RetornoSefinRestrita::chaveValida($chave)) {
            throw new RuntimeException('Chave NFS-e inválida.');
        }
        return $this->requisitar('GET', '/nfse/'.$chave, $certificado);
    }

    private function requisitar(string $metodo, string $path, CertificadoDigital $certificado, ?string $payload = null): array
    {
        abort_unless(config('automacao_fiscal.nfse_dps_restrita_enabled', false), 403);
        // Nunca efetuar rede enquanto a tentativa ainda estiver numa transação externa.
        if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0) {
            throw new RuntimeException('Tentativa ainda não persistida fora da transação.');
        }
        $mtls = $this->certificados->materializarCredenciaisMtls($certificado);
        $ch = null;
        try {
            $pem = file_get_contents($mtls['cert']);
            $fp = is_string($pem) ? openssl_x509_fingerprint($pem, 'sha256') : false;
            if (! is_string($fp) || ! hash_equals(strtolower((string) $certificado->fingerprint), strtolower($fp))) {
                throw new RuntimeException('Certificado mTLS diverge da referência.');
            }
            $ch = curl_init(self::BASE.$path);
            if ($ch === false) {
                throw new RuntimeException('Não foi possível iniciar comunicação.');
            }
            $body = '';
            $opcoes = [
                CURLOPT_CUSTOMREQUEST => $metodo,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSLCERT => $mtls['cert'],
                CURLOPT_SSLKEY => $mtls['key'],
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 12582912) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];
            if ($payload !== null) {
                $opcoes[CURLOPT_POSTFIELDS] = $payload;
            }
            if (! curl_setopt_array($ch, $opcoes) || curl_exec($ch) === false || curl_errno($ch) !== 0) {
                throw new RuntimeException('Comunicação não confirmada.');
            }
            return ['http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
        } finally {
            if ($ch instanceof \CurlHandle) {
                curl_close($ch);
            }
            ($mtls['cleanup'])();
        }
    }
}
