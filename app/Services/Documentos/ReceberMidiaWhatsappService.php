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
        private readonly DocumentoProcessoLogService $logs,
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
            if ($this->avaliarMensagem($conexao, $dados)['aceita']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function registrarIgnorados(ConexaoWhatsapp $conexao, array $payload): void
    {
        foreach ($this->extrairMensagens($payload) as $dados) {
            $avaliacao = $this->avaliarMensagem($conexao, $dados);

            if ($avaliacao['aceita'] || ! $avaliacao['deve_logar']) {
                continue;
            }

            $this->logIgnorado($conexao, $avaliacao);
        }
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
     * @return array{
     *     aceita: bool,
     *     deve_logar: bool,
     *     motivo: ?string,
     *     grupo: ?GrupoWhatsapp,
     *     mensagem_id: ?string,
     *     remote_jid: ?string,
     *     tem_midia: bool,
     *     eh_grupo: bool,
     *     nome_arquivo: ?string
     * }
     */
    private function avaliarMensagem(ConexaoWhatsapp $conexao, array $dados): array
    {
        $chave = is_array($dados['key'] ?? null) ? $dados['key'] : [];
        $remoteJid = is_string($chave['remoteJid'] ?? null) ? $chave['remoteJid'] : null;
        $mensagemId = is_string($chave['id'] ?? null) ? $chave['id'] : null;
        $temMidia = $this->adaptador->mensagemTemMidia($dados);
        $metadados = $temMidia ? $this->adaptador->metadadosMidiaDaMensagem($dados) : [];
        $nomeArquivo = is_string($metadados['nome_arquivo'] ?? null) ? $metadados['nome_arquivo'] : null;
        $ehGrupo = is_string($remoteJid) && str_contains($remoteJid, '@g.us');

        $base = [
            'aceita' => false,
            'deve_logar' => false,
            'motivo' => null,
            'grupo' => null,
            'mensagem_id' => $mensagemId,
            'remote_jid' => $remoteJid,
            'tem_midia' => $temMidia,
            'eh_grupo' => $ehGrupo,
            'nome_arquivo' => $nomeArquivo,
        ];

        if ($remoteJid === null || $mensagemId === null) {
            return array_merge($base, [
                'motivo' => 'sem_identificacao',
                'deve_logar' => $temMidia || $ehGrupo,
            ]);
        }

        if (! $ehGrupo) {
            return array_merge($base, [
                'motivo' => 'conversa_particular',
                'deve_logar' => $temMidia,
            ]);
        }

        if (! $temMidia) {
            return array_merge($base, [
                'motivo' => 'grupo_sem_midia',
                'deve_logar' => true,
            ]);
        }

        $grupo = GrupoWhatsapp::withoutGlobalScope('operadora')
            ->where('conexao_whatsapp_id', $conexao->id)
            ->where('jid', $remoteJid)
            ->first();

        if ($grupo === null) {
            return array_merge($base, [
                'motivo' => 'grupo_nao_cadastrado',
                'deve_logar' => true,
            ]);
        }

        $base['grupo'] = $grupo;

        if (! $grupo->monitorar) {
            return array_merge($base, [
                'motivo' => 'grupo_nao_monitorado',
                'deve_logar' => true,
            ]);
        }

        if ($grupo->idsEmpresas() === []) {
            return array_merge($base, [
                'motivo' => 'grupo_sem_empresa',
                'deve_logar' => true,
            ]);
        }

        return array_merge($base, ['aceita' => true]);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function processarMensagem(ConexaoWhatsapp $conexao, array $dados): void
    {
        $avaliacao = $this->avaliarMensagem($conexao, $dados);

        if (! $avaliacao['aceita']) {
            if ($avaliacao['deve_logar']) {
                $this->logIgnorado($conexao, $avaliacao);
            }

            return;
        }

        /** @var GrupoWhatsapp $grupo */
        $grupo = $avaliacao['grupo'];
        $remoteJid = $avaliacao['remote_jid'];
        $mensagemId = $avaliacao['mensagem_id'];

        if ($mensagemId === null) {
            return;
        }

        $existente = DocumentoRecebido::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $conexao->empresa_operadora_id)
            ->where('mensagem_whatsapp_id', $mensagemId)
            ->first();

        if ($existente !== null) {
            $this->logs->daConexao(
                $conexao,
                'aviso',
                'duplicado',
                'Mensagem já recebida (mesmo ID do WhatsApp).',
                [
                    'nome_arquivo' => $existente->nome_original,
                    'documento_id' => $existente->id,
                    'remote_jid' => $remoteJid,
                ],
                $grupo,
                $mensagemId,
            );

            return;
        }

        $this->logs->daConexao(
            $conexao,
            'info',
            'baixar_midia',
            'Baixando arquivo do WhatsApp.',
            [
                'nome_arquivo' => $avaliacao['nome_arquivo'],
                'remote_jid' => $remoteJid,
            ],
            $grupo,
            $mensagemId,
        );

        $midia = $this->adaptador->baixarMidia($conexao, $dados);

        if ($midia === null) {
            Log::warning('WhatsApp: mídia do grupo não baixada.', [
                'grupo_id' => $grupo->id,
                'mensagem_id' => $mensagemId,
            ]);
            $this->logs->daConexao(
                $conexao,
                'erro',
                'baixar_midia',
                'Não foi possível baixar a mídia do grupo.',
                [
                    'nome_arquivo' => $avaliacao['nome_arquivo'],
                    'remote_jid' => $remoteJid,
                ],
                $grupo,
                $mensagemId,
            );

            return;
        }

        $binario = base64_decode($midia['base64'], true);

        if ($binario === false || $binario === '') {
            $this->logs->daConexao(
                $conexao,
                'erro',
                'baixar_midia',
                'Mídia baixada está vazia ou ilegível.',
                [
                    'nome_arquivo' => $avaliacao['nome_arquivo'] ?? $midia['nome_arquivo'] ?? null,
                    'remote_jid' => $remoteJid,
                ],
                $grupo,
                $mensagemId,
            );

            return;
        }

        $maxBytes = (int) config('documentos.max_anexo_bytes', 80 * 1024 * 1024);

        if (strlen($binario) > $maxBytes) {
            Log::warning('WhatsApp: anexo acima do limite.', [
                'mensagem_id' => $mensagemId,
                'bytes' => strlen($binario),
            ]);
            $this->logs->daConexao(
                $conexao,
                'aviso',
                'ignorado',
                'Arquivo acima do limite permitido.',
                [
                    'bytes' => strlen($binario),
                    'limite_bytes' => $maxBytes,
                    'nome_arquivo' => $midia['nome_arquivo'] ?? $avaliacao['nome_arquivo'],
                    'remote_jid' => $remoteJid,
                ],
                $grupo,
                $mensagemId,
            );

            return;
        }

        $metadados = $this->adaptador->metadadosMidiaDaMensagem($dados);
        $nomeOriginal = $this->nomeArquivo($midia['nome_arquivo'] ?? $metadados['nome_arquivo'] ?? null, $midia['mime']);
        $hash = hash('sha256', $binario);

        $idsEmpresas = $grupo->idsEmpresas();
        $duplicado = DocumentoRecebido::withoutGlobalScope('operadora')
            ->where('hash_sha256', $hash)
            ->where('status', '!=', StatusDocumentoRecebido::Excluido)
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
            'tamanho_bytes' => strlen($binario),
            'erro_mensagem' => $duplicado !== null ? 'Arquivo duplicado (mesmo conteúdo já recebido).' : null,
            'metadados' => [
                'caption' => $metadados['caption'],
                'remote_jid' => $remoteJid,
                'timestamp' => $dados['messageTimestamp'] ?? null,
                'empresa_ids_grupo' => $idsEmpresas,
            ],
        ]);

        $this->logs->doDocumento(
            $documento,
            'info',
            'arquivo_local',
            'Arquivo gravado no servidor.',
            [
                'bytes' => strlen($binario),
                'mime' => $midia['mime'],
                'remote_jid' => $remoteJid,
                'grupo_nome' => $grupo->nome,
            ],
        );

        if ($documento->status === StatusDocumentoRecebido::Ignorado) {
            $this->logs->doDocumento(
                $documento,
                'aviso',
                'duplicado',
                'Arquivo duplicado (mesmo conteúdo já recebido).',
                [
                    'documento_original_id' => $duplicado?->id,
                    'grupo_nome' => $grupo->nome,
                ],
            );

            return;
        }

        ArquivarDocumentoRecebidoJob::dispatch($documento->id);
        $this->logs->doDocumento(
            $documento,
            'info',
            'enfileirado',
            'Arquivo na fila para classificar e enviar ao Drive.',
            ['grupo_nome' => $grupo->nome],
        );
    }

    /**
     * @param  array{
     *     motivo: ?string,
     *     grupo: ?GrupoWhatsapp,
     *     mensagem_id: ?string,
     *     remote_jid: ?string,
     *     tem_midia: bool,
     *     eh_grupo: bool,
     *     nome_arquivo: ?string
     * }  $avaliacao
     */
    private function logIgnorado(ConexaoWhatsapp $conexao, array $avaliacao): void
    {
        $grupo = $avaliacao['grupo'];
        $nomeGrupo = $grupo?->nome;
        $jid = $avaliacao['remote_jid'];

        $mensagem = match ($avaliacao['motivo']) {
            'sem_identificacao' => 'Mensagem sem identificador.',
            'conversa_particular' => 'Arquivo em conversa particular — só grupos monitorados entram em Recebidos.',
            'grupo_sem_midia' => $nomeGrupo
                ? "Mensagem de texto no grupo {$nomeGrupo} (sem arquivo)."
                : 'Mensagem de texto no grupo (sem arquivo).',
            'grupo_nao_cadastrado' => 'Grupo ainda não está na lista sincronizada.',
            'grupo_nao_monitorado' => $nomeGrupo
                ? "Grupo {$nomeGrupo} sem monitoramento."
                : 'Grupo sem monitoramento.',
            'grupo_sem_empresa' => $nomeGrupo
                ? "Grupo {$nomeGrupo} sem empresa vinculada."
                : 'Grupo sem empresa vinculada.',
            default => 'Mensagem ignorada.',
        };

        $this->logs->daConexao(
            $conexao,
            'aviso',
            'ignorado',
            $mensagem,
            [
                'motivo' => $avaliacao['motivo'],
                'remote_jid' => $jid,
                'nome_arquivo' => $avaliacao['nome_arquivo'],
                'tem_midia' => $avaliacao['tem_midia'],
                'eh_grupo' => $avaliacao['eh_grupo'],
                'grupo_nome' => $nomeGrupo,
            ],
            $grupo,
            $avaliacao['mensagem_id'],
        );
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
