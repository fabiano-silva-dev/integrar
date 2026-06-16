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
        'cnpj',
        'codigo_sistema',
        'codigo_conta_banco',
        'empresa_operadora_id',
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
}
