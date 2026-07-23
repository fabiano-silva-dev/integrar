<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomacaoConsultaSalva extends Model
{
    use BelongsToOperadora;

    protected $table = 'automacao_consultas_salvas';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_id',
        'empresa_integracao_id',
        'portal_recurso_id',
        'nome',
        'parametros',
    ];

    protected $casts = [
        'parametros' => 'array',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function empresaIntegracao(): BelongsTo
    {
        return $this->belongsTo(EmpresaIntegracao::class, 'empresa_integracao_id');
    }

    public function portalRecurso(): BelongsTo
    {
        return $this->belongsTo(PortalRecurso::class, 'portal_recurso_id');
    }
}
