<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\CertificadoDigital;
use App\Services\OperadoraStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class CertificadoDigitalService
{
    public function armazenar(
        UploadedFile $arquivo,
        string $senha,
        string $nome,
        int $operadoraId,
        ?int $empresaId = null
    ): CertificadoDigital {
        $extensao = strtolower($arquivo->getClientOriginalExtension() ?: '');
        if (!in_array($extensao, ['pfx', 'p12'], true)) {
            throw new RuntimeException('Envie um certificado A1 no formato PFX ou P12.');
        }

        $binario = file_get_contents($arquivo->getRealPath());
        if ($binario === false || $binario === '') {
            throw new RuntimeException('Não foi possível ler o arquivo do certificado.');
        }

        $senha = trim($senha);
        if ($senha === '') {
            throw new RuntimeException('Informe a senha do certificado.');
        }

        $metadados = $this->extrairMetadados($binario, $senha);

        $nomeFisico = Str::uuid()->toString() . '.' . $extensao;
        $relative = OperadoraStorage::put(
            'automacao-fiscal/certificados',
            $nomeFisico,
            $binario,
            $operadoraId
        );

        return CertificadoDigital::create([
            'empresa_operadora_id' => $operadoraId,
            'empresa_id' => $empresaId,
            'nome' => $nome,
            'tipo' => 'A1',
            'arquivo_path' => $relative,
            'senha_criptografada' => $senha,
            'fingerprint' => $metadados['fingerprint'],
            'serial' => $metadados['serial'],
            'titular' => $metadados['titular'],
            'documento_titular' => $metadados['documento_titular'],
            'emissor' => $metadados['emissor'],
            'valido_de' => $metadados['valido_de'],
            'valido_ate' => $metadados['valido_ate'],
            'ativo' => true,
            'validado_em' => now(),
            'status_validacao' => 'valido',
        ]);
    }

    public function desativar(CertificadoDigital $certificado): void
    {
        $certificado->update([
            'ativo' => false,
            'status_validacao' => 'inativo',
        ]);
    }

    /**
     * @return array{
     *     fingerprint: ?string,
     *     serial: ?string,
     *     titular: ?string,
     *     documento_titular: ?string,
     *     emissor: ?string,
     *     valido_de: ?\DateTimeInterface,
     *     valido_ate: ?\DateTimeInterface
     * }
     */
    public function extrairMetadados(string $binario, string $senha): array
    {
        $pem = $this->extrairCertificadoPem($binario, $senha);

        $parsed = openssl_x509_parse($pem);
        if ($parsed === false) {
            throw new RuntimeException('Certificado inválido ou sem metadados legíveis.');
        }

        $validoDe = isset($parsed['validFrom_time_t'])
            ? \Carbon\Carbon::createFromTimestamp((int) $parsed['validFrom_time_t'])
            : null;
        $validoAte = isset($parsed['validTo_time_t'])
            ? \Carbon\Carbon::createFromTimestamp((int) $parsed['validTo_time_t'])
            : null;

        if ($validoAte && $validoAte->isPast()) {
            throw new RuntimeException('Este certificado está vencido.');
        }

        $fingerprint = null;
        $resource = openssl_x509_read($pem);
        if ($resource !== false) {
            $fingerprint = openssl_x509_fingerprint($resource, 'sha256') ?: null;
        }

        $titular = $parsed['subject']['CN'] ?? ($parsed['subject']['commonName'] ?? null);
        $documento = $this->extrairDocumentoTitular($parsed['subject'] ?? []);

        if (is_string($titular) && $documento !== null) {
            $titular = trim(preg_replace('/\s*:\s*' . preg_quote($documento, '/') . '\s*$/', '', $titular) ?? $titular);
            $titular = $titular !== '' ? $titular : null;
        }

        return [
            'fingerprint' => $fingerprint,
            'serial' => isset($parsed['serialNumberHex'])
                ? (string) $parsed['serialNumberHex']
                : (isset($parsed['serialNumber']) ? (string) $parsed['serialNumber'] : null),
            'titular' => is_string($titular) ? $titular : null,
            'documento_titular' => $documento,
            'emissor' => isset($parsed['issuer']['CN']) ? (string) $parsed['issuer']['CN'] : null,
            'valido_de' => $validoDe,
            'valido_ate' => $validoAte,
        ];
    }

    public function caminhoAbsoluto(CertificadoDigital $certificado): ?string
    {
        if (!Storage::exists($certificado->arquivo_path)) {
            return null;
        }

        return Storage::path($certificado->arquivo_path);
    }

    private function extrairCertificadoPem(string $binario, string $senha): string
    {
        $certs = [];
        if (@openssl_pkcs12_read($binario, $certs, $senha) && !empty($certs['cert'])) {
            return $certs['cert'];
        }

        // ICP-Brasil / PFX antigos usam RC2 — OpenSSL 3 precisa de -legacy (como no runner Node).
        return $this->extrairCertificadoPemViaOpenSsl($binario, $senha);
    }

    private function extrairCertificadoPemViaOpenSsl(string $binario, string $senha): string
    {
        $workDir = sys_get_temp_dir() . '/pfx-parse-' . Str::uuid();
        File::ensureDirectoryExists($workDir, 0700);

        $pfxPath = $workDir . '/cert.pfx';
        $passPath = $workDir . '/pass.txt';
        $certPath = $workDir . '/cert.pem';

        try {
            File::put($pfxPath, $binario);
            File::put($passPath, $senha);
            chmod($pfxPath, 0600);
            chmod($passPath, 0600);

            $args = [
                'pkcs12',
                '-in', $pfxPath,
                '-passin', 'file:' . $passPath,
                '-nokeys',
                '-out', $certPath,
            ];

            $resultado = $this->runOpenSsl($args);
            if ($resultado['code'] !== 0) {
                $resultado = $this->runOpenSsl([...$args, '-legacy']);
            }

            if ($resultado['code'] !== 0) {
                $stderr = strtolower($resultado['stderr']);
                if (
                    str_contains($stderr, 'mac verify failure')
                    || str_contains($stderr, 'invalid password')
                    || str_contains($stderr, 'bad decrypt')
                    || str_contains($stderr, 'password required')
                ) {
                    throw new RuntimeException('Senha incorreta ou arquivo PFX inválido.');
                }

                throw new RuntimeException(
                    'Não foi possível abrir o certificado A1. Se for ICP-Brasil, confira se o OpenSSL com suporte -legacy está instalado.'
                );
            }

            if (!is_file($certPath)) {
                throw new RuntimeException('OpenSSL não gerou o certificado PEM.');
            }

            $pem = File::get($certPath);
            if (!is_string($pem) || !str_contains($pem, 'BEGIN CERTIFICATE')) {
                throw new RuntimeException('Não foi possível extrair o certificado do PFX.');
            }

            return $pem;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * @param  list<string>  $args
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runOpenSsl(array $args): array
    {
        $process = new Process(['openssl', ...$args]);
        $process->setTimeout(30);
        $process->run();

        return [
            'code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function extrairDocumentoTitular(array $subject): ?string
    {
        $campos = ['serialNumber', 'OID.2.5.4.5', 'SN', 'CN', 'commonName'];

        foreach ($campos as $key) {
            if (empty($subject[$key]) || ! is_string($subject[$key])) {
                continue;
            }

            $documento = $this->extrairDocumentoDeTexto($subject[$key]);
            if ($documento !== null) {
                return $documento;
            }
        }

        return null;
    }

    private function extrairDocumentoDeTexto(string $valor): ?string
    {
        // ICP-Brasil: "NOME:CNPJ" ou "NOME:CPF"
        if (preg_match('/:(\d{11}|\d{14})\b/', $valor, $matches)) {
            return $matches[1];
        }

        // Prefixo explícito: CNPJ:xx / CPF:xx
        if (preg_match('/\b(?:CNPJ|CPF)\s*[:=]?\s*(\d[\d.\-\/]*)/i', $valor, $matches)) {
            $digits = preg_replace('/\D/', '', $matches[1]);
            if (strlen($digits) === 11 || strlen($digits) === 14) {
                return $digits;
            }
        }

        $digits = preg_replace('/\D/', '', $valor);
        if (strlen($digits) === 11 || strlen($digits) === 14) {
            return $digits;
        }

        return null;
    }
}
