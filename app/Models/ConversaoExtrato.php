<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversaoExtrato extends Model
{
    use BelongsToOperadora;

    protected $table = 'conversoes_extrato';

    protected $fillable = [
        'user_id',
        'empresa_id',
        'empresa_operadora_id',
        'familia_layout',
        'layout',
        'nome_arquivo_origem',
        'nome_arquivo_ofx',
        'status',
        'erro_mensagem',
        'total_lancamentos',
        'data_inicial',
        'data_final',
        'metadados',
    ];

    protected $casts = [
        'total_lancamentos' => 'integer',
        'data_inicial' => 'date',
        'data_final' => 'date',
        'metadados' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
