<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmpresasOperadora extends Model
{
    use HasFactory;

    protected $fillable = [
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'inscricao_estadual',
        'telefone',
        'email',
        'responsavel',
        'logo',
        'configuracoes',
        'ativo',
        'plano',
        'limite_empresas',
        'limite_usuarios',
        'subdominio',
    ];

    protected $casts = [
        'configuracoes' => 'array',
        'ativo' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'empresa_operadora_id');
    }

    public function empresas(): HasMany
    {
        return $this->hasMany(Empresa::class, 'empresa_operadora_id');
    }
}
