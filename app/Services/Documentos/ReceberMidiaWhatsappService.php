<?php

namespace App\Services\Documentos;

use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Jobs\Documentos\ArquivarDocumentoRecebidoJob;
use App\Models\Documentos\ConexaoWhatsapp;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\GrupoWhatsapp;
use App\Services\OperadoraStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReceberMidiaWhatsappService
{
    public function __construct(
        private readonly EvolutionAdaptador $adaptador,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processar(ConexaoWhatsapp $conexao, array $payload): void
    {
        foreach ($this->extrairMensagens($payload) as $dados) {
            $this->processarMensagem($conexao, $dados);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadRelevante(ConexaoWhatsapp $conexao, array $payload): bool
    {
        foreach ($this->extrairMensagens($payload) as $dados) {
            if ($this->mensagemCandidata($conexao, $dados) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extrairMensagens(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        if (isset($data['messages']) && is_array($data['messages'])) {
            return array_values(array_filter($data['messages'], 'is_array'));
        }

        if (isset($data['key']) && is_array($data['key'])) {
            return [$data];
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function mensagemCandidata(ConexaoWhatsapp $conexao, array $dados): ?GrupoWhatsapp
    {
        $chave = is_array($dados['key'] ?? null) ? $dados['key'] : [];
        $remoteJid = is_string($chave['remoteJid'] ?? null) ? $chave['remoteJid'] : null;
        $mensagemId = is_string($chave['id'] ?? null) ? $chave['id'] : null;

        if ($remoteJid === null || $mensagemId === null) {
            return null;
        }

        if (! str_contains($remoteJid, '@g.us')) {
            return null;
        }

        if (! $this->adaptador->mensagemTemMidia($dados)) {
            return null;
        }

        $grupo = GrupoWhatsapp::withoutGlobalScope('operadora')
            ->where('conexao_whatsapp_id', $conexao->id)
            ->where('jid', $remoteJid)
            ->first();

        if ($grupo === null || ! $grupo->podeMonitorar()) {
            return null;
        }

        return $grupo;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function processarMensagem(ConexaoWhatsapp $conexao, array $dados): void
    {
        $grupo = $this->mensagemCandidata($conexao, $dados);

        if ($grupo === null) {
            return;
        }

        $chave = is_array($dados['key'] ?? null) ? $dados['key'] : [];
        $remoteJid = is_string($chave['remoteJid'] ?? null) ? $chave['remoteJid'] : null;
        $mensagemId = is_string($chave['id'] ?? null) ? $chave['id'] : null;

        if ($mensagemId === null) {
            return;
        }

        $existente = DocumentoRecebido::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $conexao->empresa_operadora_id)
            ->where('mensagem_whatsapp_id', $mensagemId)
            ->first();

        if ($existente !== null) {
            return;
        }

        $midia = $this->adaptador->baixarMidia($conexao, $dados);

        if ($midia === null) {
            Log::warning('WhatsApp: mídia do grupo não baixada.', [
                'grupo_id' => $grupo->id,
                'mensagem_id' => $mensagemId,
            ]);

            return;
        }

        $binario = base64_decode($midia['base64'], true);

        if ($binario === false || $binario === '') {
            return;
        }

        $maxBytes = (int) config('documentos.max_anexo_bytes', 80 * 1024 * 1024);

        if (strlen($binario) > $maxBytes) {
            Log::warning('WhatsApp: anexo acima do limite.', [
                'mensagem_id' => $mensagemId,
                'bytes' => strlen($binario),
            ]);

            return;
        }

        $metadados = $this->adaptador->metadadosMidiaDaMensagem($dados);
        $nomeOriginal = $this->nomeArquivo($midia['nome_arquivo'] ?? $metadados['nome_arquivo'] ?? null, $midia['mime']);
        $hash = hash('sha256', $binario);

        $idsEmpresas = $grupo->idsEmpresas();
        $duplicado = DocumentoRecebido::withoutGlobalScope('operadora')
            ->where('hash_sha256', $hash)
            ->where(function ($query) use ($grupo, $idsEmpresas) {
                $query->where('grupo_whatsapp_id', $grupo->id);
                if ($idsEmpresas !== []) {
                    $query->orWhereIn('empresa_id', $idsEmpresas);
                }
            })
            ->first();

        $operadoraId = (int) $conexao->empresa_operadora_id;
        $arquivoStorage = Str::uuid()->toString().'-'.$this->sanitizarNome($nomeOriginal);
        $storagePath = OperadoraStorage::put('documentos/inbox', $arquivoStorage, $binario, $operadoraId);

        $documento = DocumentoRecebido::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadoraId,
            'empresa_id' => count($idsEmpresas) === 1 ? $idsEmpresas[0] : null,
            'conexao_whatsapp_id' => $conexao->id,
            'grupo_whatsapp_id' => $grupo->id,
            'mensagem_whatsapp_id' => $mensagemId,
            'nome_original' => $nomeOriginal,
            'mime' => $midia['mime'],
            'hash_sha256' => $hash,
            'status' => $duplicado !== null
                ? StatusDocumentoRecebido::Ignorado
                : StatusDocumentoRecebido::Recebido,
            'storage_path' => $storagePath,
            'erro_mensagem' => $duplicado !== null ? 'Arquivo duplicado (mesmo conteúdo já recebido).' : null,
            'metadados' => [
                'caption' => $metadados['caption'],
                'remote_jid' => $remoteJid,
                'timestamp' => $dados['messageTimestamp'] ?? null,
                'empresa_ids_grupo' => $idsEmpresas,
            ],
        ]);

        if ($documento->status === StatusDocumentoRecebido::Recebido) {
            ArquivarDocumentoRecebidoJob::dispatch($documento->id);
        }
    }

    private function nomeArquivo(?string $informado, ?string $mime): string
    {
        if (is_string($informado) && $informado !== '') {
            return $this->sanitizarNome($informado);
        }

        $ext = match (true) {
            str_contains((string) $mime, 'pdf') => 'pdf',
            str_contains((string) $mime, 'xml') => 'xml',
            str_contains((string) $mime, 'png') => 'png',
            str_contains((string) $mime, 'jpeg'), str_contains((string) $mime, 'jpg') => 'jpg',
            str_contains((string) $mime, 'webp') => 'webp',
            default => 'bin',
        };

        return 'documento-whatsapp.'.$ext;
    }

    private function sanitizarNome(string $nome): string
    {
        $nome = basename(str_replace(["\0", '/'], '', $nome));
        $nome = preg_replace('/[^\w.\-\(\) áàâãéêíóôõúçÁÀÂÃÉÊÍÓÔÕÚÇ]+/u', '_', $nome) ?? $nome;

        return mb_substr($nome !== '' ? $nome : 'documento', 0, 180);
    }

    /**
     * Recupera mídias recentes de um grupo monitorado (webhook perdido ou enviado pelo próprio número).
     */
    public function processarMensagensRecentesDoGrupo(ConexaoWhatsapp $conexao, GrupoWhatsapp $grupo, int $limite = 40): int
    {
        if (! $grupo->podeMonitorar()) {
            return 0;
        }

        $antes = DocumentoRecebido::withoutGlobalScope('operadora')
            ->where('grupo_whatsapp_id', $grupo->id)
            ->count();

        foreach ($this->adaptador->listarMensagensChat($conexao, $grupo->jid, $limite) as $dados) {
            $this->processarMensagem($conexao, $dados);
        }

        $depois = DocumentoRecebido::withoutGlobalScope('operadora')
            ->where('grupo_whatsapp_id', $grupo->id)
            ->count();

        return max(0, $depois - $antes);
    }
}
