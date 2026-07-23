<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomacaoArtefato extends Model
{
    use BelongsToOperadora;

    protected $table = 'automacao_artefatos';

    protected $fillable = [
        'empresa_operadora_id',
        'automacao_execucao_id',
        'tipo',
        'nome_original',
        'storage_path',
        'mime_type',
        'tamanho',
        'hash_sha256',
        'metadados',
        'retencao_ate',
    ];

    protected $casts = [
        'tamanho' => 'integer',
        'metadados' => 'array',
        'retencao_ate' => 'datetime',
    ];

    public function execucao(): BelongsTo
    {
        return $this->belongsTo(AutomacaoExecucao::class, 'automacao_execucao_id');
    }
}
