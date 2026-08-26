<?php

namespace App\Services\Documentos;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LlamaParseAdaptador
{
    public function __construct(
        private readonly CredenciaisIaDocumentoService $credenciais,
    ) {}

    public function extrairMarkdown(?int $operadoraId, string $conteudo, string $nomeArquivo): ?string
    {
        $chave = $this->credenciais->credenciais($operadoraId)['llama_cloud'];

        if ($chave === '' || $this->esgotado()) {
            return null;
        }

        $base = rtrim((string) config('documentos.ia.llama_parse_url'), '/');
        $tmp = tempnam(sys_get_temp_dir(), 'llamaparse');

        if ($tmp === false) {
            return null;
        }

        file_put_contents($tmp, $conteudo);

        try {
            $upload = $this->comRetry(fn () => Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$chave,
                    'accept' => 'application/json',
                ])
                ->attach('file', file_get_contents($tmp) ?: '', $nomeArquivo !== '' ? $nomeArquivo : 'documento.pdf')
                ->post($base.'/parsing/upload'));

            if ($upload === null || $this->marcarSeEsgotado($upload)) {
                return null;
            }

            if (! $upload->successful()) {
                Log::warning('LlamaParse: upload recusado.', ['status' => $upload->status()]);

                return null;
            }

            $jobId = $upload->json('id');

            if (! is_string($jobId) || $jobId === '') {
                return null;
            }

            $job = $this->aguardarJob($base, $chave, $jobId);

            if ($job === null) {
                return null;
            }

            $markdown = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$chave,
                    'accept' => 'application/json',
                ])
                ->get($base.'/parsing/job/'.$jobId.'/result/markdown');

            if ($this->marcarSeEsgotado($markdown) || ! $markdown->successful()) {
                return null;
            }

            $texto = $markdown->json('markdown');

            return is_string($texto) && trim($texto) !== '' ? $texto : null;
        } catch (\Throwable $exception) {
            Log::warning('LlamaParse: falha ao extrair markdown.', ['erro' => $exception->getMessage()]);

            return null;
        } finally {
            @unlink($tmp);
        }
    }

    private function aguardarJob(string $base, string $chave, string $jobId): ?array
    {
        for ($i = 0; $i < 25; $i++) {
            $status = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$chave,
                    'accept' => 'application/json',
                ])
                ->get($base.'/parsing/job/'.$jobId);

            if ($this->marcarSeEsgotado($status) || ! $status->successful()) {
                return null;
            }

            $estado = strtoupper((string) $status->json('status'));

            if ($estado === 'SUCCESS') {
                $json = $status->json();

                return is_array($json) ? $json : null;
            }

            if (in_array($estado, ['ERROR', 'FAILED', 'CANCELLED'], true)) {
                return null;
            }

            if (! app()->environment('testing')) {
                sleep(2);
            }
        }

        $this->liberarJob($base, $chave, $jobId);

        return null;
    }

    /**
     * @param  callable(): Response  $requisicao
     */
    private function comRetry(callable $requisicao): ?Response
    {
        try {
            $response = $requisicao();
        } catch (\Throwable $exception) {
            Log::warning('LlamaParse: timeout no upload.', ['erro' => $exception->getMessage()]);

            return null;
        }

        if ($response->serverError()) {
            usleep(400000);
            try {
                $response = $requisicao();
            } catch (\Throwable) {
                return $response;
            }
        }

        return $response;
    }

    private function liberarJob(string $base, string $chave, string $jobId): void
    {
        try {
            Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$chave,
                    'accept' => 'application/json',
                ])
                ->delete($base.'/parsing/job/'.$jobId);
        } catch (\Throwable) {
            // Job segue no provedor; o worker não espera mais.
        }
    }

    private function marcarSeEsgotado(Response $response): bool
    {
        $corpo = strtolower($response->body());
        $esgotou = in_array($response->status(), [402, 429], true)
            || str_contains($corpo, 'exceeded the maximum number of credits')
            || str_contains($corpo, 'resource_exhausted');

        if ($esgotou) {
            Cache::put($this->chaveCache(), true, $this->ttl($response));
        }

        return $esgotou;
    }

    private function esgotado(): bool
    {
        return Cache::get($this->chaveCache()) === true;
    }

    private function chaveCache(): string
    {
        return 'documentos:ia:esgotado:llamaparse';
    }

    private function ttl(Response $response): int
    {
        if ($response->status() === 402) {
            return max(3600, (int) now()->endOfDay()->diffInSeconds(now()));
        }

        return (int) config('documentos.ia.esgotado_ttl_segundos', 3600);
    }
}
