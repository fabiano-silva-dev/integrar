<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgendaAutomacao extends Model
{
    use BelongsToOperadora;

    protected $table = 'agendas_automacao';

    protected $fillable = [
        'empresa_operadora_id',
        'nome',
        'ativo',
        'timezone',
        'frequencia',
        'intervalo',
        'dias_semana',
        'dias_mes',
        'horarios',
        'politica_periodo_consulta',
        'parametros_periodo',
        'executar_atrasadas',
        'limite_execucoes_atrasadas',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'intervalo' => 'integer',
        'dias_semana' => 'array',
        'dias_mes' => 'array',
        'horarios' => 'array',
        'parametros_periodo' => 'array',
        'executar_atrasadas' => 'boolean',
        'limite_execucoes_atrasadas' => 'integer',
    ];

    public function empresaIntegracaoRecursos(): HasMany
    {
        return $this->hasMany(EmpresaIntegracaoRecurso::class, 'agenda_automacao_id');
    }
}
