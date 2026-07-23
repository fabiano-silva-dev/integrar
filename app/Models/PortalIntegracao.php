<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortalIntegracao extends Model
{
    protected $table = 'portais_integracao';

    protected $fillable = [
        'codigo',
        'nome',
        'driver',
        'ativo',
        'modos_autenticacao',
        'configuracoes_publicas',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'modos_autenticacao' => 'array',
        'configuracoes_publicas' => 'array',
    ];

    public function recursos(): HasMany
    {
        return $this->hasMany(PortalRecurso::class, 'portal_integracao_id');
    }

    public function empresaIntegracoes(): HasMany
    {
        return $this->hasMany(EmpresaIntegracao::class, 'portal_integracao_id');
    }
}
