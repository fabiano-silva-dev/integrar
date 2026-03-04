<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricoPadraoDescricao extends Model
{
    use HasFactory;

    protected $table = 'historicos_padrao_descricoes';

    protected $fillable = [
        'historico_padrao_layout_id',
        'descricao',
        'usar_para_amarracao',
        'total_ocorrencias',
    ];

    protected $casts = [
        'usar_para_amarracao' => 'boolean',
    ];

    public function historicoPadraoLayout(): BelongsTo
    {
        return $this->belongsTo(HistoricoPadraoLayout::class);
    }
}
