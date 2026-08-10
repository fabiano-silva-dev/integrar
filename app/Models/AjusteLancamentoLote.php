<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AjusteLancamentoLote extends Model
{
    use BelongsToOperadora;

    public const STATUS_APLICADO = 'aplicado';
    public const STATUS_REVERTIDO = 'revertido';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_id',
        'importacao_id',
        'user_id',
        'usuario_nome',
        'filtros',
        'alteracoes',
        'total_lancamentos',
        'total_campos',
        'status',
        'revertido_em',
        'revertido_por_user_id',
        'revertido_por_nome',
    ];

    protected $casts = [
        'filtros' => 'array',
        'alteracoes' => 'array',
        'total_lancamentos' => 'integer',
        'total_campos' => 'integer',
        'revertido_em' => 'datetime',
    ];

    public function itens(): HasMany
    {
        return $this->hasMany(AjusteLancamentoItem::class);
    }

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(Importacao::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function estaAplicado(): bool
    {
        return $this->status === self::STATUS_APLICADO;
    }

    public function resumoAlteracoes(): string
    {
        $alts = $this->alteracoes ?? [];
        $partes = [];

        if (!empty($alts['conta'])) {
            $partes[] = 'conta → ' . $alts['conta'];
        }
        if (!empty($alts['historico'])) {
            $partes[] = 'histórico';
        }
        if (!empty($alts['terceiro_nome']) || !empty($alts['terceiro_id'])) {
            $partes[] = 'terceiro → ' . ($alts['terceiro_nome'] ?? ('#' . $alts['terceiro_id']));
        }

        return $partes === [] ? '—' : implode(', ', $partes);
    }
}
