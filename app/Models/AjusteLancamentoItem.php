<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AjusteLancamentoItem extends Model
{
    use BelongsToOperadora;

    protected $table = 'ajuste_lancamento_itens';

    protected $fillable = [
        'ajuste_lancamento_lote_id',
        'empresa_operadora_id',
        'lancamento_id',
        'campo_alterado',
        'valor_anterior',
        'valor_novo',
        'tipo_alteracao',
    ];

    public function lote(): BelongsTo
    {
        return $this->belongsTo(AjusteLancamentoLote::class, 'ajuste_lancamento_lote_id');
    }

    public function lancamento(): BelongsTo
    {
        return $this->belongsTo(Lancamento::class);
    }
}
