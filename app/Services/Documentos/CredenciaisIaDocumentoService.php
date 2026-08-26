<?php

namespace App\Services\Documentos;

use App\Models\Documentos\ConfiguracaoIaDocumento;

class CredenciaisIaDocumentoService
{
    /**
     * @return array{gemini: string, groq: string, llama_cloud: string}
     */
    public function credenciais(?int $operadoraId = null): array
    {
        $cfg = $operadoraId !== null
            ? ConfiguracaoIaDocumento::daOperadora($operadoraId)
            : ConfiguracaoIaDocumento::daOperadora();

        return [
            'gemini' => $this->primeiroPreenchido(
                $cfg?->gemini_api_key,
                (string) config('documentos.ia.gemini_api_key', ''),
            ),
            'groq' => $this->primeiroPreenchido(
                $cfg?->groq_api_key,
                (string) config('documentos.ia.groq_api_key', ''),
            ),
            'llama_cloud' => $this->primeiroPreenchido(
                $cfg?->llama_cloud_api_key,
                (string) config('documentos.ia.llama_cloud_api_key', ''),
            ),
        ];
    }

    /**
     * @return array{gemini: bool, groq: bool, llama_cloud: bool}
     */
    public function status(?int $operadoraId = null): array
    {
        $chaves = $this->credenciais($operadoraId);

        return [
            'gemini' => $chaves['gemini'] !== '',
            'groq' => $chaves['groq'] !== '',
            'llama_cloud' => $chaves['llama_cloud'] !== '',
        ];
    }

    public function salvar(
        int $operadoraId,
        string $gemini,
        string $groq,
        string $llamaCloud,
    ): ConfiguracaoIaDocumento {
        $existente = ConfiguracaoIaDocumento::daOperadora($operadoraId);

        $geminiFinal = $gemini !== '' ? $gemini : (string) ($existente?->gemini_api_key ?? '');
        $groqFinal = $groq !== '' ? $groq : (string) ($existente?->groq_api_key ?? '');
        $llamaFinal = $llamaCloud !== '' ? $llamaCloud : (string) ($existente?->llama_cloud_api_key ?? '');

        return ConfiguracaoIaDocumento::withoutGlobalScope('operadora')->updateOrCreate(
            ['empresa_operadora_id' => $operadoraId],
            [
                'gemini_api_key' => $geminiFinal !== '' ? $geminiFinal : null,
                'groq_api_key' => $groqFinal !== '' ? $groqFinal : null,
                'llama_cloud_api_key' => $llamaFinal !== '' ? $llamaFinal : null,
                'configurado_em' => now(),
            ],
        );
    }

    private function primeiroPreenchido(mixed $cadastro, string $fallback): string
    {
        if (is_string($cadastro) && trim($cadastro) !== '') {
            return trim($cadastro);
        }

        return trim($fallback);
    }
}
