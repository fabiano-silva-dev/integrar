<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpresaIntegracaoCredencial extends Model
{
    use BelongsToOperadora;

    protected $table = 'empresa_integracao_credenciais';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_integracao_id',
        'usuario_criptografado',
        'segredo_criptografado',
        'dados_autenticacao_criptografados',
        'ativo',
        'validado_em',
        'status_validacao',
    ];

    protected $hidden = [
        'usuario_criptografado',
        'segredo_criptografado',
        'dados_autenticacao_criptografados',
    ];

    protected $casts = [
        'usuario_criptografado' => 'encrypted',
        'segredo_criptografado' => 'encrypted',
        'dados_autenticacao_criptografados' => 'encrypted:array',
        'ativo' => 'boolean',
        'validado_em' => 'datetime',
    ];

    public function empresaIntegracao(): BelongsTo
    {
        return $this->belongsTo(EmpresaIntegracao::class, 'empresa_integracao_id');
    }
}
