<?php

namespace App\Services\Documentos;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TestarCredencialIaDocumentoService
{
    /**
     * @return array{ok: bool, mensagem: string}
     */
    public function testar(string $provedor, string $chave): array
    {
        $chave = trim($chave);

        if ($chave === '') {
            $nome = match ($provedor) {
                'gemini' => 'Gemini',
                'groq' => 'Groq',
                'llama_cloud' => 'LlamaParse',
                default => 'provedor',
            };

            return ['ok' => false, 'mensagem' => 'Informe a chave do '.$nome.' para testar.'];
        }

        return match ($provedor) {
            'gemini' => $this->testarGemini($chave),
            'groq' => $this->testarGroq($chave),
            'llama_cloud' => $this->testarLlamaParse($chave),
            default => ['ok' => false, 'mensagem' => 'Provedor desconhecido.'],
        };
    }

    /**
     * @return array{ok: bool, mensagem: string}
     */
    private function testarGemini(string $chave): array
    {
        try {
            $response = Http::timeout(20)->get(
                'https://generativelanguage.googleapis.com/v1beta/models',
                ['key' => $chave, 'pageSize' => 1],
            );
        } catch (\Throwable) {
            return ['ok' => false, 'mensagem' => 'Não deu para falar com o Gemini agora. Tente de novo.'];
        }

        if ($response->successful()) {
            return ['ok' => true, 'mensagem' => 'Gemini aceitou a chave.'];
        }

        return $this->interpretarFalha('Gemini', $response);
    }

    /**
     * @return array{ok: bool, mensagem: string}
     */
    private function testarGroq(string $chave): array
    {
        try {
            $response = Http::timeout(20)
                ->withToken($chave)
                ->get('https://api.groq.com/openai/v1/models');
        } catch (\Throwable) {
            return ['ok' => false, 'mensagem' => 'Não deu para falar com o Groq agora. Tente de novo.'];
        }

        if ($response->successful()) {
            return ['ok' => true, 'mensagem' => 'Groq aceitou a chave.'];
        }

        return $this->interpretarFalha('Groq', $response);
    }

    /**
     * Valida no mesmo endpoint de parsing do n8n (job).
     * 404 = chave ok (job inexistente). 401 = chave ou região errada.
     *
     * @return array{ok: bool, mensagem: string}
     */
    private function testarLlamaParse(string $chave): array
    {
        $chave = preg_replace('/^Bearer\s+/i', '', $chave) ?? $chave;
        $headers = [
            'Authorization' => 'Bearer '.$chave,
            'accept' => 'application/json',
        ];
        $jobId = '00000000-0000-0000-0000-000000000000';
        $ultimo401 = null;

        foreach ($this->basesLlamaParse() as $base) {
            try {
                $response = Http::timeout(20)
                    ->withHeaders($headers)
                    ->get($base.'/parsing/job/'.$jobId);
            } catch (\Throwable) {
                continue;
            }

            if (in_array($response->status(), [200, 404], true)) {
                return ['ok' => true, 'mensagem' => 'LlamaParse aceitou a chave.'];
            }

            if ($response->status() === 402) {
                return [
                    'ok' => true,
                    'mensagem' => 'LlamaParse aceitou a chave. Os créditos do mês acabaram — o PDF escaneado será pulado até o próximo ciclo.',
                ];
            }

            if ($response->status() === 401 || $this->chaveInvalida($response)) {
                $ultimo401 = $response;

                continue;
            }

            return $this->interpretarFalha('LlamaParse', $response);
        }

        if ($ultimo401 !== null) {
            return ['ok' => false, 'mensagem' => 'Esta chave do LlamaParse não foi aceita.'];
        }

        return ['ok' => false, 'mensagem' => 'Não deu para falar com o LlamaParse agora. Tente de novo.'];
    }

    /**
     * @return list<string>
     */
    private function basesLlamaParse(): array
    {
        $principal = rtrim((string) config('documentos.ia.llama_parse_url'), '/');
        $bases = [$principal];

        foreach ([
            'https://api.cloud.llamaindex.ai/api/v1',
            'https://api.cloud.eu.llamaindex.ai/api/v1',
        ] as $extra) {
            if (! in_array($extra, $bases, true)) {
                $bases[] = $extra;
            }
        }

        return $bases;
    }

    /**
     * @return array{ok: bool, mensagem: string}
     */
    private function interpretarFalha(string $nome, Response $response): array
    {
        if ($this->chaveInvalida($response) || in_array($response->status(), [400, 401, 403], true)) {
            return ['ok' => false, 'mensagem' => 'Esta chave do '.$nome.' não foi aceita.'];
        }

        if ($response->status() === 429 || str_contains(strtolower($response->body()), 'resource_exhausted') || str_contains(strtolower($response->body()), 'quota')) {
            return [
                'ok' => true,
                'mensagem' => 'A chave do '.$nome.' está certa, mas a cota do período acabou.',
            ];
        }

        if ($response->status() === 402) {
            return [
                'ok' => true,
                'mensagem' => 'A chave do '.$nome.' está certa, mas os créditos do mês acabaram.',
            ];
        }

        return ['ok' => false, 'mensagem' => 'Não deu para falar com o '.$nome.' agora. Tente de novo.'];
    }

    private function chaveInvalida(Response $response): bool
    {
        $corpo = strtolower($response->body());

        return str_contains($corpo, 'api_key_invalid')
            || str_contains($corpo, 'invalid api key')
            || str_contains($corpo, 'incorrect api key')
            || str_contains($corpo, 'invalid_api_key')
            || str_contains($corpo, 'unauthorized')
            || str_contains($corpo, 'unauthenticated');
    }
}
