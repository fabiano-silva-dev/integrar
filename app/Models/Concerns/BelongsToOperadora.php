<?php

namespace App\Models\Concerns;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Services\OperadoraContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOperadora
{
    public static function bootBelongsToOperadora(): void
    {
        static::addGlobalScope('operadora', function (Builder $builder) {
            if (!OperadoraContext::isScopeEnabled()) {
                return;
            }

            $id = OperadoraContext::id();

            if ($id !== null) {
                $builder->where(
                    $builder->getModel()->getTable() . '.empresa_operadora_id',
                    $id
                );
            }
        });

        static::creating(function ($model) {
            if ($model->empresa_operadora_id !== null) {
                return;
            }

            if (OperadoraContext::id() !== null) {
                $model->empresa_operadora_id = OperadoraContext::id();

                return;
            }

            if (isset($model->empresa_id) && $model->empresa_id) {
                $model->empresa_operadora_id = Empresa::withoutGlobalScope('operadora')
                    ->where('id', $model->empresa_id)
                    ->value('empresa_operadora_id');
            }
        });
    }

    public function empresaOperadora(): BelongsTo
    {
        return $this->belongsTo(EmpresasOperadora::class, 'empresa_operadora_id');
    }
}
