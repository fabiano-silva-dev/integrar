<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ImportacaoEmpresa extends Model
{
    use BelongsToOperadora;

    protected $table = 'importacoes_empresas';

    protected $fillable = [
        'uuid',
        'empresa_operadora_id',
        'user_id',
        'nome_arquivo',
        'storage_path',
        'status',
        'total_linhas',
        'linhas_validas',
        'linhas_com_erro',
        'linhas_gravadas',
        'mapeamento_colunas',
        'mensagem',
    ];

    protected $casts = [
        'total_linhas' => 'integer',
        'linhas_validas' => 'integer',
        'linhas_com_erro' => 'integer',
        'linhas_gravadas' => 'integer',
        'mapeamento_colunas' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ImportacaoEmpresaItem::class, 'importacao_empresa_id');
    }
}
