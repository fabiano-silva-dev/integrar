<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportacaoPlanoConta extends Model
{
    use BelongsToOperadora;

    protected $table = 'importacoes_plano_contas';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_id',
        'user_id',
        'arquivo_original',
        'formato',
        'estrategia',
        'total_linhas',
        'contas_novas',
        'contas_atualizadas',
        'contas_inativadas',
        'linhas_erro',
        'relatorio_erros',
        'status',
    ];

    protected $casts = [
        'relatorio_erros' => 'array',
        'total_linhas' => 'integer',
        'contas_novas' => 'integer',
        'contas_atualizadas' => 'integer',
        'contas_inativadas' => 'integer',
        'linhas_erro' => 'integer',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
