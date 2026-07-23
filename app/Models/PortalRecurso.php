<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortalRecurso extends Model
{
    protected $table = 'portal_recursos';

    protected $fillable = [
        'portal_integracao_id',
        'codigo',
        'nome',
        'descricao',
        'ativo',
        'parametros_schema',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'parametros_schema' => 'array',
    ];

    public function portal(): BelongsTo
    {
        return $this->belongsTo(PortalIntegracao::class, 'portal_integracao_id');
    }

    public function empresaIntegracaoRecursos(): HasMany
    {
        return $this->hasMany(EmpresaIntegracaoRecurso::class, 'portal_recurso_id');
    }
}
