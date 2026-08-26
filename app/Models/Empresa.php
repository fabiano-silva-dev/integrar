<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Model
{
    use HasFactory, BelongsToOperadora;

    protected $fillable = [
        'nome',
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'inscricao_estadual',
        'inscricao_municipal',
        'uf',
        'codigo_municipio_ibge',
        'municipio',
        'codigo_sistema',
        'codigo_conta_banco',
        'pasta_drive_nome',
        'ativo',
        'empresa_operadora_id',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function importacoes(): HasMany
    {
        return $this->hasMany(Importacao::class);
    }

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class);
    }

    public function planoContas(): HasMany
    {
        return $this->hasMany(PlanoConta::class);
    }

    public function integracoes(): HasMany
    {
        return $this->hasMany(EmpresaIntegracao::class);
    }

    public function certificadosDigitais(): HasMany
    {
        return $this->hasMany(CertificadoDigital::class);
    }

    public function gruposWhatsapp(): HasMany
    {
        return $this->hasMany(\App\Models\Documentos\GrupoWhatsapp::class);
    }

    public function pastasDrive(): HasMany
    {
        return $this->hasMany(\App\Models\Documentos\EmpresaPastaDrive::class);
    }
}
