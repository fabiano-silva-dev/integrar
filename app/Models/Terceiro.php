<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Terceiro extends Model
{
    use HasFactory, BelongsToOperadora;

    protected $fillable = [
        'nome',
        'cnpj_cpf',
        'tipo',
        'observacoes',
        'ativo',
        'empresa_operadora_id',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class);
    }
}
