<?php

namespace App\Services\Importacao;

use App\Livewire\GerenciadorRegrasAmarracao;
use App\Models\PlanoConta;

class ValidadorRegraAmarracaoService
{
    public const TIPOS_BUSCA = ['contains', 'starts_with', 'ends_with', 'exact'];

    /**
     * @return list<string>
     */
    public function layoutsValidos(): array
    {
        return array_values(array_filter(
            array_keys(GerenciadorRegrasAmarracao::getLayoutsAvancado()),
            fn (string $k) => $k !== ''
        ));
    }

    public function normalizarTipoBusca(?string $tipo): string
    {
        if ($tipo === null || trim($tipo) === '') {
            return 'starts_with';
        }

        $valor = mb_strtolower(trim($tipo));
        $valor = str_replace([' ', '-'], '_', $valor);

        $aliases = [
            'comeca_com' => 'starts_with',
            'começa_com' => 'starts_with',
            'inicia_com' => 'starts_with',
            'termina_com' => 'ends_with',
            'contem' => 'contains',
            'contém' => 'contains',
            'exato' => 'exact',
            'igual' => 'exact',
        ];

        $valor = $aliases[$valor] ?? $valor;

        return in_array($valor, self::TIPOS_BUSCA, true) ? $valor : $valor;
    }

    public function tipoBuscaValido(?string $tipo): bool
    {
        return in_array($this->normalizarTipoBusca($tipo), self::TIPOS_BUSCA, true);
    }

    public function layoutValido(?string $layout): bool
    {
        if ($layout === null || trim($layout) === '') {
            return false;
        }

        return in_array(trim($layout), $this->layoutsValidos(), true);
    }

    public function normalizarAtivo(?string $valor, bool $padrao = true): bool
    {
        if ($valor === null || trim($valor) === '') {
            return $padrao;
        }

        $v = mb_strtolower(trim($valor));

        return in_array($v, ['1', 'sim', 's', 'true', 'yes', 'y', 'ativo'], true);
    }

    /**
     * Retorna aviso se a conta não existir no plano (quando houver plano cadastrado).
     */
    public function avisoContaContrapartida(?string $conta, int $empresaId): ?string
    {
        $conta = trim((string) $conta);
        if ($conta === '') {
            return null;
        }

        $temPlano = PlanoConta::where('empresa_id', $empresaId)->where('ativo', true)->exists();
        if (!$temPlano) {
            return null;
        }

        $existe = PlanoConta::where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->where(function ($q) use ($conta) {
                $q->where('codigo', $conta)->orWhere('codigo_reduzido', $conta);
            })
            ->exists();

        if ($existe) {
            return null;
        }

        return "Conta contra-partida não encontrada no plano de contas: {$conta}";
    }
}
