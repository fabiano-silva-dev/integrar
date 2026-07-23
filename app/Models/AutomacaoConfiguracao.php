<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;

class AutomacaoConfiguracao extends Model
{
    use BelongsToOperadora;

    protected $table = 'automacao_configuracoes';

    protected $fillable = [
        'empresa_operadora_id',
        'timezone',
        'periodo_padrao_dias',
        'max_execucoes_simultaneas',
        'politica_tentativas',
        'retencao_logs_dias',
        'retencao_artefatos_dias',
        'aviso_certificado_dias',
    ];

    protected $casts = [
        'periodo_padrao_dias' => 'integer',
        'max_execucoes_simultaneas' => 'integer',
        'politica_tentativas' => 'integer',
        'retencao_logs_dias' => 'integer',
        'retencao_artefatos_dias' => 'integer',
        'aviso_certificado_dias' => 'integer',
    ];

    public static function defaults(): array
    {
        return [
            'timezone' => 'America/Sao_Paulo',
            'periodo_padrao_dias' => 31,
            'max_execucoes_simultaneas' => 1,
            'politica_tentativas' => 3,
            'retencao_logs_dias' => 90,
            'retencao_artefatos_dias' => 30,
            'aviso_certificado_dias' => 30,
        ];
    }

    public static function forOperadora(int $operadoraId): self
    {
        return static::query()->firstOrCreate(
            ['empresa_operadora_id' => $operadoraId],
            static::defaults()
        );
    }
}
