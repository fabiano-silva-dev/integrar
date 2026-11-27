<?php

namespace App\Models\Nfse;

use App\Models\Concerns\BelongsToOperadora;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class RegistroNfse extends Model
{
    use BelongsToOperadora;

    public function scopeDaEmpresa(Builder $query, Empresa $empresa): Builder
    {
        return $query->where('empresa_operadora_id', $empresa->empresa_operadora_id)
            ->where('empresa_id', $empresa->id);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
