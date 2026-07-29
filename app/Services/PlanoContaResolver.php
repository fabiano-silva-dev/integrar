<?php

namespace App\Services;

use App\Models\PlanoConta;

class PlanoContaResolver
{
    public function empresaTemPlanoAtivo(int $empresaId): bool
    {
        return PlanoConta::where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->exists();
    }

    /**
     * Mapeia códigos armazenados nos lançamentos (com zeros à esquerda removidos)
     * para a descrição da conta no plano ativo.
     *
     * @param  list<string|null>  $codigosArmazenados
     * @return array<string, string> codigo_armazenado => descricao
     */
    public function mapearDescricoes(int $empresaId, array $codigosArmazenados): array
    {
        $codigos = array_values(array_unique(array_filter(array_map(
            static fn ($c) => trim((string) $c),
            $codigosArmazenados
        ), static fn (string $c) => $c !== '')));

        if ($codigos === [] || !$this->empresaTemPlanoAtivo($empresaId)) {
            return [];
        }

        $indice = [];
        PlanoConta::query()
            ->where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->get(['codigo', 'codigo_reduzido', 'descricao'])
            ->each(function (PlanoConta $conta) use (&$indice): void {
                foreach ($this->chavesCodigo($conta->codigo, $conta->codigo_reduzido) as $chave) {
                    $indice[$chave] = $conta->descricao;
                }
            });

        $resultado = [];
        foreach ($codigos as $codigo) {
            if (isset($indice[$codigo])) {
                $resultado[$codigo] = $indice[$codigo];
            }
        }

        return $resultado;
    }

    /**
     * @return list<array{id: int, codigo: string, codigo_reduzido: ?string, descricao: string, rotulo: string}>
     */
    public function buscar(int $empresaId, string $termo, int $limit = 10): array
    {
        $termo = trim($termo);
        if (mb_strlen($termo) < 1 || !$this->empresaTemPlanoAtivo($empresaId)) {
            return [];
        }

        $termoLike = '%' . $termo . '%';
        $termoNorm = $this->normalizarTexto($termo);

        return PlanoConta::query()
            ->where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->where(function ($query) use ($termoLike) {
                $query->where('codigo', 'like', $termoLike)
                    ->orWhere('codigo_reduzido', 'like', $termoLike)
                    ->orWhere('descricao', 'like', $termoLike);
            })
            ->orderByRaw(
                'CASE
                    WHEN LOWER(descricao) = ? THEN 0
                    WHEN codigo = ? THEN 1
                    WHEN codigo_reduzido = ? THEN 2
                    ELSE 3
                END',
                [$termoNorm, $termo, $termo]
            )
            ->orderBy('descricao')
            ->orderBy('codigo')
            ->limit($limit)
            ->get(['id', 'codigo', 'codigo_reduzido', 'descricao'])
            ->map(function (PlanoConta $conta): array {
                return [
                    'id' => $conta->id,
                    'codigo' => $conta->codigo,
                    'codigo_reduzido' => $conta->codigo_reduzido,
                    'descricao' => $conta->descricao,
                    'rotulo' => $this->formatarRotulo($conta),
                ];
            })
            ->all();
    }

    /**
     * Resolve código, reduzido ou nome para o código da conta no plano.
     * Sem plano cadastrado ou sem correspondência: retorna o valor informado (trimado).
     */
    public function resolver(?int $empresaId, ?string $entrada): ?string
    {
        $entrada = trim((string) $entrada);
        if ($entrada === '') {
            return null;
        }

        if (!$empresaId || !$this->empresaTemPlanoAtivo($empresaId)) {
            return $entrada;
        }

        $codigoNorm = PlanoConta::normalizarCodigo($entrada);

        $porCodigo = PlanoConta::where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->where('codigo', $codigoNorm)
            ->value('codigo');
        if ($porCodigo) {
            return $porCodigo;
        }

        $porReduzido = PlanoConta::where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->where('codigo_reduzido', $codigoNorm)
            ->value('codigo');
        if ($porReduzido) {
            return $porReduzido;
        }

        $termoNorm = $this->normalizarTexto($entrada);
        $porNome = PlanoConta::where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->get(['codigo', 'descricao'])
            ->first(function (PlanoConta $conta) use ($termoNorm): bool {
                return $this->normalizarTexto($conta->descricao) === $termoNorm;
            });

        return $porNome ? $porNome->codigo : $entrada;
    }

    public function resolverParaArmazenamento(?int $empresaId, ?string $entrada): string
    {
        $resolvido = $this->resolver($empresaId, $entrada) ?? '';

        if ($resolvido === '') {
            return '';
        }

        return ltrim($resolvido, '0') ?: '0';
    }

    public function contaExisteNoPlano(int $empresaId, string $conta): bool
    {
        $conta = trim($conta);
        if ($conta === '') {
            return false;
        }

        if (!$this->empresaTemPlanoAtivo($empresaId)) {
            return true;
        }

        $codigo = $this->resolver($empresaId, $conta);

        return PlanoConta::where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->where('codigo', $codigo)
            ->exists();
    }

    private function formatarRotulo(PlanoConta $conta): string
    {
        $partes = [$conta->codigo];
        if (filled($conta->codigo_reduzido)) {
            $partes[] = '(' . $conta->codigo_reduzido . ')';
        }
        $partes[] = '—';
        $partes[] = $conta->descricao;

        return implode(' ', $partes);
    }

    /**
     * @return list<string>
     */
    private function chavesCodigo(string $codigo, ?string $codigoReduzido): array
    {
        $chaves = [
            $codigo,
            ltrim($codigo, '0') ?: '0',
        ];

        if (filled($codigoReduzido)) {
            $chaves[] = $codigoReduzido;
            $chaves[] = ltrim($codigoReduzido, '0') ?: '0';
        }

        return array_values(array_unique($chaves));
    }

    private function normalizarTexto(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = str_replace(
            ['á', 'à', 'ã', 'â', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ü', 'ç'],
            ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'u', 'c'],
            $texto
        );

        return preg_replace('/\s+/', ' ', $texto) ?? $texto;
    }
}
