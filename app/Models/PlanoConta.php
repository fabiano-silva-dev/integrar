<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanoConta extends Model
{
    use HasFactory, BelongsToOperadora;

    protected $table = 'plano_contas';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_id',
        'codigo',
        'codigo_reduzido',
        'classificacao',
        'descricao',
        'tipo',
        'natureza',
        'nivel',
        'codigo_pai',
        'aceita_lancamento',
        'ativo',
    ];

    protected $casts = [
        'aceita_lancamento' => 'boolean',
        'ativo' => 'boolean',
        'nivel' => 'integer',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public static function normalizarCodigo(?string $codigo): string
    {
        return trim((string) $codigo);
    }

    public static function inferirNivel(string $codigo): int
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            return 1;
        }

        if (str_contains($codigo, '.')) {
            return substr_count($codigo, '.') + 1;
        }

        return 1;
    }

    public static function inferirCodigoPai(string $codigo): ?string
    {
        $codigo = trim($codigo);
        if ($codigo === '' || !str_contains($codigo, '.')) {
            return null;
        }

        $pai = preg_replace('/\.[^.]+$/', '', $codigo);

        return $pai !== '' ? $pai : null;
    }

    public static function normalizarTipo(?string $tipo): ?string
    {
        if ($tipo === null || trim($tipo) === '') {
            return null;
        }

        $valor = mb_strtolower(trim($tipo));

        if (in_array($valor, ['a', 'analitica', 'analítica', 'analitico', 'analítico'], true)) {
            return 'analitica';
        }

        if (in_array($valor, ['s', 'sintetica', 'sintética', 'sintetico', 'sintético'], true)) {
            return 'sintetica';
        }

        return $valor;
    }

    public static function normalizarNatureza(?string $natureza): ?string
    {
        if ($natureza === null || trim($natureza) === '') {
            return null;
        }

        $valor = mb_strtolower(trim($natureza));

        if (in_array($valor, ['d', 'devedora', 'devedor'], true)) {
            return 'devedora';
        }

        if (in_array($valor, ['c', 'credora', 'credor'], true)) {
            return 'credora';
        }

        return $valor;
    }

    public static function normalizarAceitaLancamento(?string $valor, ?string $tipo): bool
    {
        if ($valor !== null && trim($valor) !== '') {
            $v = mb_strtolower(trim($valor));

            return in_array($v, ['1', 'sim', 's', 'true', 'yes', 'y'], true);
        }

        return self::normalizarTipo($tipo) !== 'sintetica';
    }
}
