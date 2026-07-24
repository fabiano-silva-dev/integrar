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

    /**
     * Certificado A1 ativo do escritório (contador) — empresa_id nulo.
     */
    public function resolverCertificadoEscritorio(): ?CertificadoDigital
    {
        return CertificadoDigital::query()
            ->where('ativo', true)
            ->where('tipo', 'A1')
            ->whereNull('empresa_id')
            ->where(function ($q) {
                $q->whereNull('valido_ate')->orWhere('valido_ate', '>=', now());
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Certificado A1 ativo cujo titular (CNPJ/CPF) corresponde ao documento informado.
     */
    public function resolverAtivoPorDocumento(string $documento): ?CertificadoDigital
    {
        $digits = preg_replace('/\D+/', '', $documento) ?? '';
        if ($digits === '' || (strlen($digits) !== 11 && strlen($digits) !== 14)) {
            return null;
        }

        return CertificadoDigital::query()
            ->where('ativo', true)
            ->where('tipo', 'A1')
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(documento_titular, ''), '.', ''), '/', ''), '-', ''), ' ', '') = ?",
                [$digits]
            )
            ->where(function ($q) {
                $q->whereNull('valido_ate')->orWhere('valido_ate', '>=', now());
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * CNPJs (14 dígitos) com certificado A1 ativo no escritório atual.
     *
     * @return array<string, true>
     */
    public function mapaCnpjsComCertificadoA1(): array
    {
        $mapa = [];

        $certificados = CertificadoDigital::query()
            ->with(['empresa:id,cnpj'])
            ->where('ativo', true)
            ->where('tipo', 'A1')
            ->where(function ($q) {
                $q->whereNull('valido_ate')->orWhere('valido_ate', '>=', now());
            })
            ->get(['id', 'documento_titular', 'empresa_id']);

        foreach ($certificados as $cert) {
            $doc = preg_replace('/\D+/', '', (string) $cert->documento_titular) ?? '';
            if (strlen($doc) === 14) {
                $mapa[$doc] = true;
            }

            $cnpjEmpresa = preg_replace('/\D+/', '', (string) ($cert->empresa?->cnpj ?? '')) ?? '';
            if (strlen($cnpjEmpresa) === 14) {
                $mapa[$cnpjEmpresa] = true;
            }
        }

        return $mapa;
    }

    /**
     * Extrai certificado e chave privada PEM (conteúdo em memória) a partir do A1.
     *
     * @return array{cert: string, key: string}
     */
    public function extrairPem(CertificadoDigital $certificado): array
    {
        $caminho = $this->caminhoAbsoluto($certificado);
        if ($caminho === null || ! is_file($caminho)) {
            throw new RuntimeException('Arquivo do certificado A1 não encontrado no storage.');
        }

        $binario = file_get_contents($caminho);
        if ($binario === false || $binario === '') {
            throw new RuntimeException('Não foi possível ler o certificado A1.');
        }

        return $this->extrairCertEChavePem($binario, (string) $certificado->senha_criptografada);
    }

    /**
     * Extrai certificado e chave privada PEM para mTLS (arquivos temporários).
     * O chamador deve invocar o cleanup ao terminar.
     *
     * @return array{cert: string, key: string, cleanup: \Closure}
     */
    public function materializarCredenciaisMtls(CertificadoDigital $certificado): array
    {
        $creds = $this->extrairPem($certificado);

        $workDir = sys_get_temp_dir() . '/nfe-mtls-' . Str::uuid();
        File::ensureDirectoryExists($workDir, 0700);

        $certPath = $workDir . '/client.crt.pem';
        $keyPath = $workDir . '/client.key.pem';
        File::put($certPath, $creds['cert']);
        File::put($keyPath, $creds['key']);
        chmod($certPath, 0600);
        chmod($keyPath, 0600);

        return [
            'cert' => $certPath,
            'key' => $keyPath,
            'cleanup' => static function () use ($workDir): void {
                File::deleteDirectory($workDir);
            },
        ];
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function extrairCertEChavePem(string $binario, string $senha): array
    {
        $certs = [];
        if (@openssl_pkcs12_read($binario, $certs, $senha) && ! empty($certs['cert']) && ! empty($certs['pkey'])) {
            return [
                'cert' => $certs['cert'],
                'key' => $certs['pkey'],
            ];
        }

        return $this->extrairCertEChavePemViaOpenSsl($binario, $senha);
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function extrairCertEChavePemViaOpenSsl(string $binario, string $senha): array
    {
        $workDir = sys_get_temp_dir() . '/pfx-mtls-' . Str::uuid();
        File::ensureDirectoryExists($workDir, 0700);

        $pfxPath = $workDir . '/cert.pfx';
        $passPath = $workDir . '/pass.txt';
        $certPath = $workDir . '/cert.pem';
        $keyPath = $workDir . '/key.pem';

        try {
            File::put($pfxPath, $binario);
            File::put($passPath, $senha);
            chmod($pfxPath, 0600);
            chmod($passPath, 0600);

            $certArgs = [
                'pkcs12',
                '-in', $pfxPath,
                '-passin', 'file:' . $passPath,
                '-nokeys',
                '-out', $certPath,
            ];
            $keyArgs = [
                'pkcs12',
                '-in', $pfxPath,
                '-passin', 'file:' . $passPath,
                '-nocerts',
                '-nodes',
                '-out', $keyPath,
            ];

            $rCert = $this->runOpenSsl($certArgs);
            if ($rCert['code'] !== 0) {
                $rCert = $this->runOpenSsl([...$certArgs, '-legacy']);
            }
            $rKey = $this->runOpenSsl($keyArgs);
            if ($rKey['code'] !== 0) {
                $rKey = $this->runOpenSsl([...$keyArgs, '-legacy']);
            }

            if ($rCert['code'] !== 0 || $rKey['code'] !== 0) {
                throw new RuntimeException(
                    'Não foi possível extrair certificado e chave do A1 para consulta à SEFAZ.'
                );
            }

            $certPem = File::get($certPath);
            $keyPem = File::get($keyPath);
            if (
                ! is_string($certPem) || ! str_contains($certPem, 'BEGIN CERTIFICATE')
                || ! is_string($keyPem) || ! str_contains($keyPem, 'PRIVATE KEY')
            ) {
                throw new RuntimeException('Credenciais PEM do certificado A1 inválidas.');
            }

            return ['cert' => $certPem, 'key' => $keyPem];
        } finally {
            File::deleteDirectory($workDir);
        }
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
