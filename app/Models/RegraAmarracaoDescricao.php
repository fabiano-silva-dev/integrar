<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class RegraAmarracaoDescricao extends Model
{
    use HasFactory;

    protected $table = 'regras_amarracoes_descricoes';

    protected $fillable = [
        'empresa_id',
        'layout_avancado',
        'palavra_chave',
        'parte_digitavel',
        'tipo_busca',
        'conta_debito',
        'conta_credito',
        'conta_contrapartida',
        'centro_custo',
        'ativo',
        'prioridade',
        'descricao',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * Aplica regras de amarração por descrição para uma empresa e layout.
     * Regras mais específicas (palavra_chave mais longa) são tentadas primeiro.
     * Retorna o resultado da primeira regra que bater, ou null.
     */
    public static function aplicarRegrasParaEmpresaLayout(int $empresaId, ?string $layoutAvancado, string $descricao, ?float $valor = null): ?array
    {
        $debug = config('app.debug') || env('REGRAS_AMARRACAO_DEBUG', false);

        $regras = self::where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->where(function ($q) use ($layoutAvancado) {
                $q->where('layout_avancado', $layoutAvancado)
                    ->orWhereNull('layout_avancado');
            })
            ->orderByRaw("CASE WHEN parte_digitavel IS NOT NULL AND TRIM(parte_digitavel) != '' THEN 1 ELSE 0 END DESC")
            ->orderByRaw('LENGTH(palavra_chave) DESC')
            ->orderBy('prioridade', 'desc')
            ->get();

        if ($debug) {
            Log::channel('single')->info('[RegrasAmarracao] aplicarRegras', [
                'empresa_id' => $empresaId,
                'layout' => $layoutAvancado,
                'historico' => mb_substr($descricao, 0, 80) . (mb_strlen($descricao) > 80 ? '...' : ''),
                'valor' => $valor,
                'regras_ordem' => $regras->map(fn ($r) => [
                    'id' => $r->id,
                    'palavra_chave' => $r->palavra_chave,
                    'parte_digitavel' => $r->parte_digitavel,
                    'tipo_busca' => $r->tipo_busca,
                ])->toArray(),
            ]);
        }

        foreach ($regras as $regra) {
            $resultado = $regra->aplicarRegra($descricao, $valor, $debug);
            if ($resultado) {
                if ($debug) {
                    Log::channel('single')->info('[RegrasAmarracao] regra aplicada', [
                        'regra_id' => $regra->id,
                        'palavra_chave' => $regra->palavra_chave,
                        'parte_digitavel' => $regra->parte_digitavel,
                    ]);
                }
                return $resultado;
            }
        }

        return null;
    }

    /**
     * Aplica a regra ao histórico. Se conta_contrapartida estiver preenchida e $valor
     * for informado, define débito/crédito pelo sinal: valor > 0 = entrada no banco
     * (crédito na contrapartida), valor < 0 = saída (débito na contrapartida).
     */
    public function aplicarRegra(string $descricao, ?float $valor = null, bool $debug = false): ?array
    {
        if (!$this->ativo) {
            return null;
        }

        $descricaoNorm = self::normalizarParaBusca($descricao);
        $palavraChaveNorm = self::normalizarParaBusca($this->palavra_chave ?? '');

        $match = false;
        switch ($this->tipo_busca) {
            case 'contains':
                $match = str_contains($descricaoNorm, $palavraChaveNorm);
                break;
            case 'starts_with':
                $match = str_starts_with($descricaoNorm, $palavraChaveNorm);
                break;
            case 'ends_with':
                $match = str_ends_with($descricaoNorm, $palavraChaveNorm);
                break;
            case 'exact':
                $match = $descricaoNorm === $palavraChaveNorm;
                break;
        }

        if ($debug) {
            Log::channel('single')->info('[RegrasAmarracao] tentativa regra', [
                'regra_id' => $this->id,
                'palavra_chave' => $this->palavra_chave,
                'parte_digitavel' => $this->parte_digitavel,
                'tipo_busca' => $this->tipo_busca,
                'match_palavra_chave' => $match,
            ]);
        }

        if (!$match) {
            return null;
        }

        $parteDigitavelOk = true;
        if (!empty(trim($this->parte_digitavel ?? ''))) {
            $parteDigitavelOk = self::historicoContemParteDigitavel($descricao, $this->parte_digitavel);
            if ($debug) {
                Log::channel('single')->info('[RegrasAmarracao] checagem parte_digitavel', [
                    'regra_id' => $this->id,
                    'parte_digitavel' => $this->parte_digitavel,
                    'historico_normalizado' => mb_substr($descricaoNorm, 0, 100),
                    'contem_parte_digitavel' => $parteDigitavelOk,
                ]);
            }
            if (!$parteDigitavelOk) {
                return null;
            }
        }

        $contaDebito = $this->conta_debito;
        $contaCredito = $this->conta_credito;

        if (!empty(trim($this->conta_contrapartida ?? '')) && $valor !== null) {
            if ($valor > 0) {
                $contaCredito = $this->conta_contrapartida;
                $contaDebito = null;
            } else {
                $contaDebito = $this->conta_contrapartida;
                $contaCredito = null;
            }
        }

        return [
            'conta_debito' => $contaDebito,
            'conta_credito' => $contaCredito,
            'centro_custo' => $this->centro_custo,
            'historico' => trim($this->descricao ?? '') ?: null,
        ];
    }

    /**
     * Normaliza texto para comparação: lowercase, remove acentos, colapsa espaços.
     * Facilita match entre "PEDÁGIO" e "PEDAGIO", "PAGAMENTO" e "pagamento", etc.
     */
    private static function normalizarParaBusca(string $texto): string
    {
        $t = mb_strtolower(trim($texto), 'UTF-8');
        $acentos = ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n'];
        $t = strtr($t, $acentos);
        return preg_replace('/\s+/', ' ', $t);
    }

    /**
     * Verifica se o histórico contém a parte digitável.
     * Usa busca flexível: (1) substring exata após normalização, (2) versão só alfanumérica
     * para lidar com "RAH5I65" vs "RAH 5I65" ou "RAH5-I65", e (3) normalização de
     * caracteres confundíveis (I/1/l, O/0) comum em PDFs/OCR.
     */
    private static function historicoContemParteDigitavel(string $historico, string $parteDigitavel): bool
    {
        $parteDigitavel = trim($parteDigitavel);
        if ($parteDigitavel === '') {
            return true;
        }

        $histNorm = self::normalizarParaBusca($historico);
        $parteNorm = self::normalizarParaBusca($parteDigitavel);

        if (str_contains($histNorm, $parteNorm)) {
            return true;
        }

        $histApenasAlfanum = preg_replace('/[^a-z0-9]/i', '', $histNorm);
        $parteApenasAlfanum = preg_replace('/[^a-z0-9]/i', '', $parteNorm);

        if ($parteApenasAlfanum === '') {
            return false;
        }

        if (str_contains($histApenasAlfanum, $parteApenasAlfanum)) {
            return true;
        }

        $parteRelaxada = self::normalizarCaracteresConfundiveis($parteApenasAlfanum);
        $histRelaxada = self::normalizarCaracteresConfundiveis($histApenasAlfanum);

        return str_contains($histRelaxada, $parteRelaxada);
    }

    /**
     * Normaliza caracteres facilmente confundidos (I/1/l, O/0) em códigos alfanuméricos.
     */
    private static function normalizarCaracteresConfundiveis(string $texto): string
    {
        $t = mb_strtolower($texto, 'UTF-8');
        $map = ['1' => 'i', 'l' => 'i', '0' => 'o'];
        return strtr($t, $map);
    }
} 