<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportacaoEmpresaItem extends Model
{
    use BelongsToOperadora;

    protected $table = 'importacao_empresa_itens';

    protected $fillable = [
        'empresa_operadora_id',
        'importacao_empresa_id',
        'numero_linha',
        'dados_brutos',
        'dados_normalizados',
        'status',
        'mensagem_erro',
        'empresa_id',
    ];

    protected $casts = [
        'numero_linha' => 'integer',
        'dados_brutos' => 'array',
        'dados_normalizados' => 'array',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(ImportacaoEmpresa::class, 'importacao_empresa_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
