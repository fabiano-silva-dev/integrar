<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AutomacaoExecucao extends Model
{
    use BelongsToOperadora;

    protected $table = 'automacao_execucoes';

    protected $fillable = [
        'uuid',
        'empresa_operadora_id',
        'empresa_id',
        'empresa_integracao_id',
        'portal_recurso_id',
        'agenda_automacao_id',
        'solicitado_por_user_id',
        'gatilho',
        'periodo_inicio',
        'periodo_fim',
        'status',
        'etapa_atual',
        'mensagem_usuario',
        'parametros',
        'quantidade_encontrada',
        'quantidade_importada',
        'quantidade_ignorada',
        'quantidade_erros',
        'iniciada_em',
        'finalizada_em',
        'duracao_ms',
        'tentativa',
        'idempotency_key',
    ];

    protected $casts = [
        'periodo_inicio' => 'date',
        'periodo_fim' => 'date',
        'iniciada_em' => 'datetime',
        'finalizada_em' => 'datetime',
        'parametros' => 'array',
        'quantidade_encontrada' => 'integer',
        'quantidade_importada' => 'integer',
        'quantidade_ignorada' => 'integer',
        'quantidade_erros' => 'integer',
        'duracao_ms' => 'integer',
        'tentativa' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

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

    public function agenda(): BelongsTo
    {
        return $this->belongsTo(AgendaAutomacao::class, 'agenda_automacao_id');
    }

    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por_user_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AutomacaoExecucaoLog::class, 'automacao_execucao_id');
    }

    public function artefatos(): HasMany
    {
        return $this->hasMany(AutomacaoArtefato::class, 'automacao_execucao_id');
    }
}
