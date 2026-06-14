<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LayoutImportacao extends Model
{
    use HasFactory, BelongsToOperadora;

    protected $table = 'layouts_importacao';

    protected $fillable = [
        'nome',
        'tipo_arquivo',
        'delimitador',
        'tem_cabecalho',
        'configuracoes',
        'empresa_id',
        'empresa_operadora_id',
        'user_id',
    ];

    protected $casts = [
        'tem_cabecalho' => 'boolean',
        'configuracoes' => 'array',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function colunas(): HasMany
    {
        return $this->hasMany(LayoutColuna::class, 'layout_importacao_id');
    }

    public function getColunasOrdenadas()
    {
        return $this->colunas()->orderBy('ordem')->get();
    }

    public function getMapeamentoColunas(): array
    {
        return $this->colunas()
            ->orderBy('ordem')
            ->pluck('campo_lancamento', 'coluna_arquivo')
            ->toArray();
    }

    public function regrasAmarracao(): HasMany
    {
        return $this->hasMany(RegraAmarracaoImportacao::class, 'layout_importacao_id')->orderBy('ordem');
    }

    public function getRegrasAtivas()
    {
        return $this->regrasAmarracao()->where('ativo', true)->orderBy('ordem')->get();
    }
} 