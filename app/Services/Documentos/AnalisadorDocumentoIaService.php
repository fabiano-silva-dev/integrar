<?php

namespace App\Services\Documentos;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnalisadorDocumentoIaService
{
    public function __construct(
        private readonly CredenciaisIaDocumentoService $credenciais,
    ) {}

    /**
     * @return array{saida: array<string, mixed>, origem: string, modelo: string}|null
     */
    public function analisar(
        ?int $operadoraId,
        string $conteudo,
        string $mime,
        string $nomeArquivo,
        ?string $textoExtraido = null,
    ): ?array {
        $chaves = $this->credenciais->credenciais($operadoraId);
        $prompt = $this->prompt($nomeArquivo, $textoExtraido);
        $ehImagem = str_starts_with(strtolower($mime), 'image/');
        $ehPdf = str_contains(strtolower($mime), 'pdf') || str_starts_with($conteudo, '%PDF');

        if ($chaves['gemini'] !== '') {
            foreach ((array) config('documentos.ia.gemini_modelos', []) as $modelo) {
                if (! is_string($modelo) || $this->esgotado('gemini:'.$modelo)) {
                    continue;
                }

                $saida = $this->chamarGemini($chaves['gemini'], $modelo, $prompt, $conteudo, $mime, $ehImagem, $ehPdf);

                if ($saida !== null) {
                    return ['saida' => $saida, 'origem' => 'ia_gemini', 'modelo' => $modelo];
                }
            }
        }

        if ($chaves['groq'] !== '' && $ehImagem) {
            foreach ((array) config('documentos.ia.groq_modelos', []) as $modelo) {
                if (! is_string($modelo) || $this->esgotado('groq:'.$modelo)) {
                    continue;
                }

                $saida = $this->chamarGroq($chaves['groq'], $modelo, $prompt, $conteudo, $mime);

                if ($saida !== null) {
                    return ['saida' => $saida, 'origem' => 'ia_groq', 'modelo' => $modelo];
                }
            }
        }

        if ($chaves['groq'] !== '' && ! $ehImagem && is_string($textoExtraido) && trim($textoExtraido) !== '') {
            foreach ((array) config('documentos.ia.groq_modelos', []) as $modelo) {
                if (! is_string($modelo) || $this->esgotado('groq:'.$modelo)) {
                    continue;
                }

                $saida = $this->chamarGroqTexto($chaves['groq'], $modelo, $prompt);

                if ($saida !== null) {
                    return ['saida' => $saida, 'origem' => 'ia_groq', 'modelo' => $modelo];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function chamarGemini(
        string $apiKey,
        string $modelo,
        string $prompt,
        string $conteudo,
        string $mime,
        bool $ehImagem,
        bool $ehPdf,
    ): ?array {
        $parts = [['text' => $prompt]];

        if ($ehImagem || $ehPdf) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $mime !== '' ? $mime : ($ehPdf ? 'application/pdf' : 'image/jpeg'),
                    'data' => base64_encode($conteudo),
                ],
            ];
        }

        try {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                .$modelo.':generateContent?key='.urlencode($apiKey);

            $response = $this->comRetry(fn () => Http::timeout(60)->post($url, [
                'contents' => [['parts' => $parts]],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                ],
            ]));
        } catch (\Throwable $exception) {
            Log::warning('Gemini: falha ao analisar documento.', ['modelo' => $modelo, 'erro' => $exception->getMessage()]);

            return null;
        }

        if ($response === null) {
            return null;
        }

        if ($this->marcarSeEsgotado('gemini:'.$modelo, $response)) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $texto = $response->json('candidates.0.content.parts.0.text');

        return is_string($texto) ? $this->parseJson($texto) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function chamarGroq(string $apiKey, string $modelo, string $prompt, string $conteudo, string $mime): ?array
    {
        $dataUrl = 'data:'.($mime !== '' ? $mime : 'image/jpeg').';base64,'.base64_encode($conteudo);

        try {
            $response = $this->comRetry(fn () => Http::timeout(60)
                ->withToken($apiKey)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $modelo,
                    'temperature' => 0,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                        ],
                    ]],
                ]));
        } catch (\Throwable $exception) {
            Log::warning('Groq: falha ao analisar imagem.', ['modelo' => $modelo, 'erro' => $exception->getMessage()]);

            return null;
        }

        if ($response === null) {
            return null;
        }

        if ($this->marcarSeEsgotado('groq:'.$modelo, $response)) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $texto = $response->json('choices.0.message.content');

        return is_string($texto) ? $this->parseJson($texto) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function chamarGroqTexto(string $apiKey, string $modelo, string $prompt): ?array
    {
        try {
            $response = $this->comRetry(fn () => Http::timeout(45)
                ->withToken($apiKey)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $modelo,
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Retorne somente JSON válido, sem markdown.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]));
        } catch (\Throwable $exception) {
            Log::warning('Groq: falha ao analisar texto.', ['modelo' => $modelo, 'erro' => $exception->getMessage()]);

            return null;
        }

        if ($response === null || $this->marcarSeEsgotado('groq:'.$modelo, $response) || ! $response->successful()) {
            return null;
        }

        $texto = $response->json('choices.0.message.content');

        return is_string($texto) ? $this->parseJson($texto) : null;
    }

    private function prompt(string $nomeArquivo, ?string $textoExtraido): string
    {
        $trecho = is_string($textoExtraido) && $textoExtraido !== ''
            ? mb_substr($textoExtraido, 0, 8000)
            : '';

        return <<<PROMPT
Você é um especialista em documentos contábeis, fiscais e trabalhistas brasileiros.
Analise o documento e retorne SOMENTE um JSON válido, sem markdown, com estes campos:
- tipo_documento (ex.: danfe, nfc-e, nfs-e, cte, mdfe, comprovante pix, extrato, boleto, aviso prévio, outros)
- empresa_razao_social
- empresa_cnpj
- terceiro_razao_social
- terceiro_cnpj
- numero_documento
- nome_funcionario
- data_emissao (yyyy-mm-dd)
- categoria_arquivo (FISCAL, CONTABIL, FOLHA ou OUTROS)
- ano
- mes (MM)
- sugestao_nome_arquivo (Tipo - Nome - dd.mm.aaaa + extensão)

Nome original informado (não use como critério de tipo): {$nomeArquivo}
Texto extraído (pode estar vazio):
{$trecho}
PROMPT;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $texto): ?array
    {
        $t = trim($texto);

        if (str_starts_with($t, '```')) {
            $t = preg_replace('/^```(?:json)?\s*/i', '', $t) ?? $t;
            $t = preg_replace('/\s*```$/', '', $t) ?? $t;
        }

        $dados = json_decode($t, true);

        if (is_array($dados)) {
            return $dados;
        }

        if (preg_match('/\{[\s\S]*\}/', $t, $m)) {
            $dados = json_decode($m[0], true);

            return is_array($dados) ? $dados : null;
        }

        return null;
    }

    /**
     * @param  callable(): Response  $requisicao
     */
    private function comRetry(callable $requisicao): ?Response
    {
        $response = $requisicao();

        if ($response->serverError()) {
            usleep(400000);
            $response = $requisicao();
        }

        return $response;
    }

    private function marcarSeEsgotado(string $provedorModelo, Response $response): bool
    {
        $corpo = strtolower($response->body());
        $esgotou = in_array($response->status(), [402, 429], true)
            || str_contains($corpo, 'resource_exhausted')
            || str_contains($corpo, 'quota')
            || str_contains($corpo, 'rate_limit')
            || str_contains($corpo, 'rate limit');

        if ($esgotou) {
            Cache::put($this->chaveCache($provedorModelo), true, (int) config('documentos.ia.esgotado_ttl_segundos', 3600));
        }

        return $esgotou;
    }

    private function esgotado(string $provedorModelo): bool
    {
        return Cache::get($this->chaveCache($provedorModelo)) === true;
    }

    private function chaveCache(string $provedorModelo): string
    {
        return 'documentos:ia:esgotado:'.$provedorModelo;
    }
}
