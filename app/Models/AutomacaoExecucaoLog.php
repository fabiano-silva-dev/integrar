<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomacaoExecucaoLog extends Model
{
    use BelongsToOperadora;

    public $timestamps = false;

    protected $table = 'automacao_execucao_logs';

    protected $fillable = [
        'empresa_operadora_id',
        'automacao_execucao_id',
        'nivel',
        'etapa',
        'mensagem',
        'contexto_sanitizado',
        'ocorrido_em',
    ];

    protected $casts = [
        'contexto_sanitizado' => 'array',
        'ocorrido_em' => 'datetime',
    ];

    public function execucao(): BelongsTo
    {
        return $this->belongsTo(AutomacaoExecucao::class, 'automacao_execucao_id');
    }
}
