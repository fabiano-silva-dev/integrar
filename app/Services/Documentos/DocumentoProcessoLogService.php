<?php

namespace App\Services\Documentos;

use App\Models\Documentos\ConexaoWhatsapp;
use App\Models\Documentos\DocumentoProcessoLog;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\GrupoWhatsapp;
use App\Services\AutomacaoFiscal\Logs\LogSanitizer;

class DocumentoProcessoLogService
{
    public function ativo(): bool
    {
        return (bool) config('documentos.debug', false);
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    public function registrar(
        string $nivel,
        string $etapa,
        string $mensagem,
        array $contexto = [],
        ?int $operadoraId = null,
        ?int $conexaoId = null,
        ?int $grupoId = null,
        ?int $documentoId = null,
        ?string $mensagemWhatsappId = null,
    ): void {
        if (! $this->ativo()) {
            return;
        }

        DocumentoProcessoLog::query()->create([
            'empresa_operadora_id' => $operadoraId,
            'conexao_whatsapp_id' => $conexaoId,
            'grupo_whatsapp_id' => $grupoId,
            'documento_recebido_id' => $documentoId,
            'mensagem_whatsapp_id' => $mensagemWhatsappId,
            'nivel' => $nivel,
            'etapa' => $etapa,
            'mensagem' => LogSanitizer::sanitizeMessage($mensagem),
            'contexto' => LogSanitizer::sanitize($this->limparContexto($contexto)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    public function doDocumento(
        DocumentoRecebido $documento,
        string $nivel,
        string $etapa,
        string $mensagem,
        array $contexto = [],
    ): void {
        $this->registrar(
            $nivel,
            $etapa,
            $mensagem,
            array_merge([
                'nome_arquivo' => $documento->nome_original,
                'status' => $documento->status?->value,
            ], $contexto),
            operadoraId: $documento->empresa_operadora_id !== null ? (int) $documento->empresa_operadora_id : null,
            conexaoId: $documento->conexao_whatsapp_id !== null ? (int) $documento->conexao_whatsapp_id : null,
            grupoId: $documento->grupo_whatsapp_id !== null ? (int) $documento->grupo_whatsapp_id : null,
            documentoId: $documento->id,
            mensagemWhatsappId: $documento->mensagem_whatsapp_id,
        );
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    public function daConexao(
        ConexaoWhatsapp $conexao,
        string $nivel,
        string $etapa,
        string $mensagem,
        array $contexto = [],
        ?GrupoWhatsapp $grupo = null,
        ?string $mensagemWhatsappId = null,
    ): void {
        if ($grupo !== null) {
            $contexto['grupo_nome'] ??= $grupo->nome;
            $contexto['remote_jid'] ??= $grupo->jid;
        }

        $this->registrar(
            $nivel,
            $etapa,
            $mensagem,
            $contexto,
            operadoraId: $conexao->empresa_operadora_id !== null ? (int) $conexao->empresa_operadora_id : null,
            conexaoId: $conexao->id,
            grupoId: $grupo?->id,
            mensagemWhatsappId: $mensagemWhatsappId,
        );
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    private function limparContexto(array $contexto): array
    {
        $bloqueadas = [
            'base64', 'apikey', 'api_key', 'token', 'access_token', 'refresh_token',
            'authorization', 'cookie', 'media', 'message', 'payload', 'headers',
        ];

        $limpo = [];

        foreach ($contexto as $chave => $valor) {
            if (is_string($chave) && in_array(strtolower($chave), $bloqueadas, true)) {
                continue;
            }

            if (is_array($valor)) {
                $limpo[$chave] = $this->limparContexto($valor);

                continue;
            }

            if (is_string($valor) && strlen($valor) > $this->limiteContexto((string) $chave)) {
                $limpo[$chave] = mb_substr($valor, 0, $this->limiteContexto((string) $chave)).'…';

                continue;
            }

            $limpo[$chave] = $valor;
        }

        return $limpo;
    }

    private function limiteContexto(string $chave): int
    {
        if (in_array(strtolower($chave), ['prompt', 'resposta', 'resposta_ia'], true)) {
            return 24000;
        }

        return 2000;
    }
}
