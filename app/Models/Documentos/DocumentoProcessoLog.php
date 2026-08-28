<?php

namespace App\Models\Documentos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoProcessoLog extends Model
{
    protected $table = 'documentos_processo_logs';

    protected $fillable = [
        'empresa_operadora_id',
        'conexao_whatsapp_id',
        'grupo_whatsapp_id',
        'documento_recebido_id',
        'mensagem_whatsapp_id',
        'nivel',
        'etapa',
        'mensagem',
        'contexto',
    ];

    protected function casts(): array
    {
        return [
            'contexto' => 'array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function etapas(): array
    {
        return [
            'webhook' => 'Webhook',
            'ignorado' => 'Ignorado',
            'baixar_midia' => 'Baixar mídia',
            'arquivo_local' => 'Arquivo local',
            'duplicado' => 'Duplicado',
            'enfileirado' => 'Fila',
            'classificar' => 'Classificar',
            'llamaparse' => 'Leitura do PDF',
            'ia' => 'IA',
            'pendente' => 'Pendente',
            'drive' => 'Google Drive',
            'enviado_drive' => 'Enviado ao Drive',
            'acesso' => 'Acesso',
            'oauth' => 'OAuth',
            'mover' => 'Mover',
            'excluir' => 'Excluir',
            'erro' => 'Erro',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function niveis(): array
    {
        return [
            'info' => 'Info',
            'aviso' => 'Aviso',
            'erro' => 'Erro',
        ];
    }

    public function rotuloEtapa(): string
    {
        return self::etapas()[$this->etapa] ?? $this->etapa;
    }

    public function rotuloNivel(): string
    {
        return self::niveis()[$this->nivel] ?? $this->nivel;
    }

    public function grupoNome(): string
    {
        $contexto = $this->contexto ?? [];
        $nome = $contexto['grupo_nome'] ?? null;

        if (is_string($nome) && $nome !== '') {
            return $nome;
        }

        return $this->grupo?->nome ?: (string) ($contexto['remote_jid'] ?? '—');
    }

    public function nomeArquivo(): string
    {
        $contexto = $this->contexto ?? [];
        $nome = $contexto['nome_arquivo'] ?? null;

        return is_string($nome) && $nome !== '' ? $nome : '—';
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoWhatsapp::class, 'grupo_whatsapp_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoRecebido::class, 'documento_recebido_id');
    }
}
