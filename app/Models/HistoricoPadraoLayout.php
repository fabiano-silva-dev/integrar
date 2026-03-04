<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricoPadraoLayout extends Model
{
    use HasFactory;

    protected $table = 'historicos_padrao_layout';

    protected $fillable = [
        'layout_avancado',
        'nome_sugerido',
        'empresa_id',
    ];

    /**
     * Extrai a parte "padrão" da descrição (sem números, sem trecho após DOC:).
     * Usado na importação e na tela de históricos padrão.
     */
    public static function extrairParteRepetida(?string $historico): ?string
    {
        if ($historico === null) {
            return null;
        }
        $historico = trim($historico);
        if ($historico === '') {
            return null;
        }
        if (preg_match('/^(.+?)\s+DOC:/iu', $historico, $m)) {
            $historico = trim($m[1]);
        }
        $parts = preg_split('/\s+/', $historico);
        if (!is_array($parts) || count($parts) === 0) {
            return $historico;
        }
        $base = [];
        foreach ($parts as $word) {
            if (preg_match('/\d/', $word)) {
                break;
            }
            $base[] = $word;
        }
        if (empty($base)) {
            return $parts[0];
        }
        return trim(implode(' ', $base));
    }

    /**
     * Sincroniza históricos padrão a partir de uma contagem [ descricao => total ].
     * Cria o registro mãe do layout se não existir; adiciona/atualiza descrições.
     * Novas descrições entram com usar_para_amarracao = true para aparecerem automaticamente
     * nas Regras de Amarração por Descrição, permitindo ao usuário configurá-las.
     */
    public static function syncFromContagem(string $layoutAvancado, ?int $empresaId, array $contagem, string $nomeSugerido = null): void
    {
        if (empty($contagem)) {
            return;
        }
        $nomesLayout = [
            'dominio' => 'Domínio (TXT)',
            'grafeno' => 'Grafeno (PDF)',
            'sicoob' => 'Sicoob (PDF)',
            'caixa_federal' => 'Caixa Econômica Federal (PDF)',
            'ofx' => 'Formato OFX',
            'registros' => 'Connectere > Contas Financeiras > Diário (CSV)',
            'sicredi' => 'SICREDI (PDF)',
        ];
        $nome = $nomeSugerido ?? ($nomesLayout[$layoutAvancado] ?? $layoutAvancado);

        $layout = self::firstOrCreate(
            [
                'layout_avancado' => $layoutAvancado,
                'empresa_id' => $empresaId,
            ],
            [
                'nome_sugerido' => $nome,
            ]
        );

        foreach ($contagem as $descricao => $total) {
            $descricao = mb_substr(trim($descricao), 0, 500);
            if ($descricao === '') {
                continue;
            }
            $existente = HistoricoPadraoDescricao::where('historico_padrao_layout_id', $layout->id)
                ->where('descricao', $descricao)
                ->first();
            if ($existente) {
                $existente->update(['total_ocorrencias' => $total]);
            } else {
                HistoricoPadraoDescricao::create([
                    'historico_padrao_layout_id' => $layout->id,
                    'descricao' => $descricao,
                    'total_ocorrencias' => $total,
                    'usar_para_amarracao' => true, // Novas descrições da importação entram automaticamente para amarração
                ]);
            }
        }
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function descricoes(): HasMany
    {
        return $this->hasMany(HistoricoPadraoDescricao::class, 'historico_padrao_layout_id');
    }

    public function descricoesParaAmarracao(): HasMany
    {
        return $this->hasMany(HistoricoPadraoDescricao::class, 'historico_padrao_layout_id')
            ->where('usar_para_amarracao', true);
    }
}
