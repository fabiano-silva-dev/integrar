<?php

namespace App\Models\Concerns;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Importacao;
use App\Services\OperadoraContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

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

            $operadoraId = static::resolveOperadoraIdFromAttributes($model->getAttributes());

            if ($operadoraId === null) {
                throw new RuntimeException(
                    static::class . ': não foi possível determinar empresa_operadora_id.'
                );
            }

            $model->empresa_operadora_id = $operadoraId;
        });
    }

    /**
     * Insert em lote com preenchimento de empresa_operadora_id.
     * Model::insert() ignora eventos Eloquent — use este método em importações em batch.
     */
    public static function insertMany(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        $enriched = [];

        foreach ($rows as $row) {
            $enriched[] = static::ensureOperadoraIdOnRow($row);
        }

        return static::insert($enriched);
    }

    public static function resolveOperadoraIdFromAttributes(array $attributes): ?int
    {
        if (!empty($attributes['empresa_operadora_id'])) {
            return (int) $attributes['empresa_operadora_id'];
        }

        if (OperadoraContext::id() !== null) {
            return OperadoraContext::id();
        }

        if (!empty($attributes['empresa_id'])) {
            $operadoraId = Empresa::withoutGlobalScope('operadora')
                ->where('id', $attributes['empresa_id'])
                ->value('empresa_operadora_id');

            if ($operadoraId !== null) {
                return (int) $operadoraId;
            }
        }

        if (!empty($attributes['importacao_id'])) {
            $operadoraId = Importacao::withoutGlobalScope('operadora')
                ->where('id', $attributes['importacao_id'])
                ->value('empresa_operadora_id');

            if ($operadoraId !== null) {
                return (int) $operadoraId;
            }
        }

        return null;
    }

    protected static function ensureOperadoraIdOnRow(array $row): array
    {
        if (!empty($row['empresa_operadora_id'])) {
            return $row;
        }

        $operadoraId = static::resolveOperadoraIdFromAttributes($row);

        if ($operadoraId === null) {
            throw new RuntimeException(
                static::class . ': não foi possível determinar empresa_operadora_id para insert em lote.'
            );
        }

        $row['empresa_operadora_id'] = $operadoraId;

        return $row;
    }

    public function empresaOperadora(): BelongsTo
    {
        return $this->belongsTo(EmpresasOperadora::class, 'empresa_operadora_id');
    }
}
