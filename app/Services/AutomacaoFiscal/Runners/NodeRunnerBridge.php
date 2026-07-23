<?php

namespace App\Services\AutomacaoFiscal\Runners;

use App\Models\AutomacaoExecucao;
use App\Models\CertificadoDigital;
use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class NodeRunnerBridge
{
    /**
     * @param  array<string, mixed>  $params
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @param  (callable(array<string, mixed>): void)|null  $onArtifact
     * @return array{status: string, result: array<string, mixed>, events: list<array<string, mixed>>, artifacts: list<array<string, mixed>>, exit_code: int}
     */
    public function executar(
        AutomacaoExecucao $execucao,
        string $portal,
        string $operation,
        array $params = [],
        string $mode = 'fake',
        ?CertificadoDigital $certificado = null,
        ?callable $onEvent = null,
        ?callable $onArtifact = null
    ): array {
        $runnerDir = base_path('scripts/automacao-fiscal/runner');
        if (!is_dir($runnerDir)) {
            throw new RuntimeException('Runner de automação fiscal não encontrado em scripts/automacao-fiscal/runner.');
        }

        $workDir = storage_path('app/automacao-fiscal-runner/' . $execucao->uuid);
        File::ensureDirectoryExists($workDir);

        $inputPath = $workDir . '/input.json';
        $passwordFile = null;

        $payload = [
            'runId' => $execucao->uuid,
            'portal' => $portal,
            'operation' => $operation,
            'mode' => $mode,
            'params' => $params === [] ? new \stdClass() : $params,
        ];

        File::put($inputPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => getenv('HOME') ?: '/root',
            'LANG' => getenv('LANG') ?: 'C.UTF-8',
            'NODE_ENV' => 'production',
            'PLAYWRIGHT_BROWSERS_PATH' => getenv('PLAYWRIGHT_BROWSERS_PATH') ?: '',
            'RUNNER_INTERNAL_TOKEN' => (string) (config('automacao_fiscal.runner_token') ?: Str::random(32)),
            'PLATFORM_BASE_URL' => config('app.url', 'http://localhost'),
            'AUTOMATION_FAKE_MODE' => $mode === 'fake' ? 'true' : 'false',
            'AUTOMATION_HEADLESS' => 'true',
            'AUTOMATION_TIMEOUT_MS' => (string) config('automacao_fiscal.timeout_ms', 300000),
            'AUTOMATION_ARTIFACT_DIR' => $workDir . '/artifacts',
            'ECAC_RS_MODE' => $mode,
            'ECAC_RS_ENTRY_URL' => (string) config(
                'automacao_fiscal.ecac_rs_entry_url',
                'https://www.sefaz.rs.gov.br/Login/LoginCertACRS.aspx?codTpLogin=1'
            ),
            'ECAC_RS_CERT_ORIGINS' => config('automacao_fiscal.ecac_rs_cert_origins', 'https://www.sefaz.rs.gov.br'),
            'ECAC_RS_ALLOWED_HOST_SUFFIXES' => 'rs.gov.br',
            'NFSE_EMISSOR_MODE' => $mode,
            'NFSE_EMISSOR_ENTRY_URL' => (string) config(
                'automacao_fiscal.nfse_entry_url',
                'https://www.nfse.gov.br/EmissorNacional/Login'
            ),
            'NFSE_EMISSOR_CERT_ORIGINS' => config('automacao_fiscal.nfse_cert_origins', 'https://certificado.nfse.gov.br'),
            'NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES' => 'nfse.gov.br',
        ];

        if ($certificado && $mode === 'certificate') {
            $abs = app(CertificadoDigitalService::class)->caminhoAbsoluto($certificado);
            if (!$abs) {
                throw new RuntimeException('Arquivo do certificado digital não encontrado no storage.');
            }
            $passwordFile = $workDir . '/cert-password.txt';
            File::put($passwordFile, (string) $certificado->senha_criptografada);
            $env['ECAC_A1_PFX_FILE'] = $abs;
            $env['ECAC_A1_PASSWORD_FILE'] = $passwordFile;
            $env['ECAC_RS_MODE'] = 'certificate';
            $env['NFSE_EMISSOR_MODE'] = 'certificate';
        } else {
            $env['ECAC_A1_PFX_FILE'] = $workDir . '/missing.pfx';
            $env['ECAC_A1_PASSWORD_FILE'] = $workDir . '/missing-password.txt';
        }

        if ($env['PLAYWRIGHT_BROWSERS_PATH'] === '') {
            unset($env['PLAYWRIGHT_BROWSERS_PATH']);
        }

        $command = $this->resolverComando($runnerDir, $inputPath);
        $processEnv = array_merge(
            array_filter($_ENV, static fn ($v) => is_string($v)),
            $env
        );

        $process = new Process(
            $command,
            $runnerDir,
            $processEnv,
            null,
            (float) config('automacao_fiscal.timeout_ms', 120000) / 1000 + 30
        );

        $stdout = '';
        $stderr = '';
        $stderrPending = '';
        $events = [];
        $artifacts = [];

        $process->start();

        while ($process->isRunning()) {
            $stdout .= $process->getIncrementalOutput();
            $chunk = $process->getIncrementalErrorOutput();
            if ($chunk !== '') {
                $stderr .= $chunk;
                $stderrPending .= $chunk;
                $this->drenarNdjson($stderrPending, $events, $artifacts, $onEvent, $onArtifact);
            }
            usleep(150_000);
        }

        $stdout .= $process->getIncrementalOutput();
        $chunk = $process->getIncrementalErrorOutput();
        if ($chunk !== '') {
            $stderr .= $chunk;
            $stderrPending .= $chunk;
        }
        $this->drenarNdjson($stderrPending, $events, $artifacts, $onEvent, $onArtifact, true);

        if ($passwordFile && File::exists($passwordFile)) {
            File::delete($passwordFile);
        }

        $stdout = trim($stdout);
        $stderr = trim($stderr);

        File::put($workDir . '/stdout.log', $stdout);
        File::put($workDir . '/stderr.log', $stderr);

        $result = $this->parseStdoutResult($stdout, $stderr, $process->getExitCode(), $workDir);

        // Garante persistência mesmo quando o NDJSON do stderr truncou artefatos grandes.
        $artifactsDir = $workDir . '/artifacts/' . $execucao->uuid;
        if (is_dir($artifactsDir)) {
            $fromDisk = $this->listarArtefatosDoDisco($artifactsDir);
            foreach ($fromDisk as $artifact) {
                $artifacts[] = $artifact;
                if ($onArtifact) {
                    $onArtifact($artifact);
                }
            }
        }

        return [
            'status' => $result['status'] ?? ($process->isSuccessful() ? 'succeeded' : 'failed'),
            'result' => $result,
            'events' => $events,
            'artifacts' => $artifacts,
            'exit_code' => $process->getExitCode() ?? 1,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listarArtefatosDoDisco(string $dir): array
    {
        $out = [];
        foreach (File::files($dir) as $file) {
            $nome = $file->getFilename();
            $ext = strtolower($file->getExtension());
            $tipo = match (true) {
                in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) => 'screenshot',
                $ext === 'zip' => 'trace',
                $ext === 'json' => 'diagnostic-log',
                in_array($ext, ['txt', 'csv'], true) => 'download',
                default => 'download',
            };
            $mime = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                'zip' => 'application/zip',
                'json' => 'application/json',
                'txt', 'csv' => 'text/plain',
                default => 'application/octet-stream',
            };
            $out[] = [
                'type' => 'artifact',
                'artifactType' => $tipo,
                'filename' => $nome,
                'absolutePath' => $file->getPathname(),
                'mimeType' => $mime,
                'sha256' => hash_file('sha256', $file->getPathname()) ?: null,
                'size' => $file->getSize(),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<array<string, mixed>>  $artifacts
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @param  (callable(array<string, mixed>): void)|null  $onArtifact
     */
    private function drenarNdjson(
        string &$buffer,
        array &$events,
        array &$artifacts,
        ?callable $onEvent,
        ?callable $onArtifact,
        bool $flushRemainder = false
    ): void {
        while (true) {
            $pos = strpos($buffer, "\n");
            if ($pos === false) {
                break;
            }

            $line = trim(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);
            $this->consumirLinhaNdjson($line, $events, $artifacts, $onEvent, $onArtifact);
        }

        if ($flushRemainder && trim($buffer) !== '') {
            $this->consumirLinhaNdjson(trim($buffer), $events, $artifacts, $onEvent, $onArtifact);
            $buffer = '';
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<array<string, mixed>>  $artifacts
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @param  (callable(array<string, mixed>): void)|null  $onArtifact
     */
    private function consumirLinhaNdjson(
        string $line,
        array &$events,
        array &$artifacts,
        ?callable $onEvent,
        ?callable $onArtifact
    ): void {
        if ($line === '') {
            return;
        }

        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            return;
        }

        $type = $decoded['type'] ?? null;
        if ($type === 'event') {
            $events[] = $decoded;
            if ($onEvent) {
                $onEvent($decoded);
            }
        } elseif ($type === 'artifact') {
            $artifacts[] = $decoded;
            if ($onArtifact) {
                $onArtifact($decoded);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolverComando(string $runnerDir, string $inputPath): array
    {
        $tsx = $runnerDir . '/node_modules/.bin/tsx';
        $cliTs = $runnerDir . '/src/cli.ts';
        $cliJs = $runnerDir . '/dist/cli.js';

        if (is_file($tsx) && is_file($cliTs)) {
            return [$tsx, $cliTs, '--input', $inputPath];
        }

        if (is_file($cliJs)) {
            return ['node', $cliJs, '--input', $inputPath];
        }

        throw new RuntimeException(
            'Runner Node não está instalado. Em scripts/automacao-fiscal/runner execute: npm ci && npx playwright install chromium'
        );
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function parseStdoutResult(
        string $stdout,
        string $stderr = '',
        ?int $exitCode = null,
        ?string $workDir = null
    ): array {
        // Preferência: result.json (não sofre truncamento do pipe ~4KB do Process).
        if ($workDir) {
            $resultFile = $workDir.'/result.json';
            if (is_readable($resultFile)) {
                $decoded = json_decode((string) file_get_contents($resultFile), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        if ($stdout !== '') {
            $lines = preg_split('/\R/', $stdout) ?: [];
            foreach (array_reverse($lines) as $line) {
                $line = trim((string) $line);
                if ($line === '' || ! str_contains($line, '"type":"result"')) {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            $last = trim((string) end($lines));
            $decoded = json_decode($last, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            // Exit 0 + artefatos no disco: trata como sucesso mesmo com stdout truncado.
            if ($exitCode === 0 && $workDir && is_dir($workDir.'/artifacts')) {
                return [
                    'status' => 'succeeded',
                    'errorMessage' => null,
                    'resultData' => [
                        'message' => 'Resultado recuperado sem JSON completo (stdout truncado).',
                        'stdoutTruncated' => true,
                    ],
                ];
            }

            return ['status' => 'failed', 'errorMessage' => 'Saída inválida do runner'];
        }

        $stderrErro = $this->extrairErroStderr($stderr);
        if ($stderrErro !== null) {
            return [
                'status' => 'failed',
                'errorMessage' => $stderrErro,
                'resultData' => ['technicalMessage' => $stderrErro, 'exitCode' => $exitCode],
            ];
        }

        return [
            'status' => 'failed',
            'errorMessage' => 'Runner não retornou resultado'
                . ($exitCode !== null ? " (exit {$exitCode})" : ''),
        ];
    }

    private function extrairErroStderr(string $stderr): ?string
    {
        if ($stderr === '') {
            return null;
        }

        foreach (array_reverse(preg_split('/\R/', $stderr) ?: []) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            if (($decoded['type'] ?? null) === 'error' && !empty($decoded['message'])) {
                return (string) $decoded['message'];
            }
            if (!empty($decoded['errorMessage'])) {
                return (string) $decoded['errorMessage'];
            }
        }

        $trecho = mb_substr(trim($stderr), 0, 500);

        return $trecho !== '' ? $trecho : null;
    }
}
