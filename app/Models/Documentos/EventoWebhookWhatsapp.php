<?php

namespace App\Models\Documentos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoWebhookWhatsapp extends Model
{

    protected $table = 'eventos_webhook_whatsapp';

    protected $fillable = [
        'empresa_operadora_id',
        'conexao_whatsapp_id',
        'tipo_evento',
        'chave_idempotencia',
        'payload',
        'status',
        'erro',
        'processado_em',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processado_em' => 'datetime',
        ];
    }

    public function conexao(): BelongsTo
    {
        return $this->belongsTo(ConexaoWhatsapp::class, 'conexao_whatsapp_id');
    }
}
