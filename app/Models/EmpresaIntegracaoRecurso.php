<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpresaIntegracaoRecurso extends Model
{
    use BelongsToOperadora;

    protected $table = 'empresa_integracao_recursos';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_integracao_id',
        'portal_recurso_id',
        'ativo',
        'agenda_automacao_id',
        'parametros',
        'next_run_at',
        'last_run_at',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'parametros' => 'array',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public function empresaIntegracao(): BelongsTo
    {
        return $this->belongsTo(EmpresaIntegracao::class, 'empresa_integracao_id');
    }

    public function portalRecurso(): BelongsTo
    {
        return $this->belongsTo(PortalRecurso::class, 'portal_recurso_id');
    }

    public function agenda(): BelongsTo
    {
        return $this->belongsTo(AgendaAutomacao::class, 'agenda_automacao_id');
    }
}
