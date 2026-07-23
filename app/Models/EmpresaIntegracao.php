<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmpresaIntegracao extends Model
{
    use BelongsToOperadora;

    protected $table = 'empresa_integracoes';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_id',
        'portal_integracao_id',
        'ativo',
        'modo_autenticacao',
        'certificado_digital_id',
        'status_configuracao',
        'ultima_validacao_em',
        'ultima_validacao_status',
        'ultima_validacao_mensagem',
        'configuracoes',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'ultima_validacao_em' => 'datetime',
        'configuracoes' => 'array',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function portal(): BelongsTo
    {
        return $this->belongsTo(PortalIntegracao::class, 'portal_integracao_id');
    }

    public function certificadoDigital(): BelongsTo
    {
        return $this->belongsTo(CertificadoDigital::class, 'certificado_digital_id');
    }

    public function recursos(): HasMany
    {
        return $this->hasMany(EmpresaIntegracaoRecurso::class, 'empresa_integracao_id');
    }

    public function credencial(): HasOne
    {
        return $this->hasOne(EmpresaIntegracaoCredencial::class, 'empresa_integracao_id');
    }

    public function execucoes(): HasMany
    {
        return $this->hasMany(AutomacaoExecucao::class, 'empresa_integracao_id');
    }
}
