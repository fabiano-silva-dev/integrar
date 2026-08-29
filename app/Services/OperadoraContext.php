<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use Illuminate\Support\Facades\Auth;

class OperadoraContext
{
    private static bool $scopeEnabled = true;

    public static function enableScope(): void
    {
        self::$scopeEnabled = true;
    }

    public static function disableScope(): void
    {
        self::$scopeEnabled = false;
    }

    public static function isScopeEnabled(): bool
    {
        return self::$scopeEnabled;
    }

    public static function id(): ?int
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        if (! $user->isSuperAdmin()) {
            return $user->empresa_operadora_id;
        }

        if (session()->has('operadora_context_id')) {
            return (int) session('operadora_context_id');
        }

        return null;
    }

    public static function set(?int $operadoraId): void
    {
        $user = Auth::user();

        if (! $user || ! $user->isSuperAdmin()) {
            abort(403, 'Apenas super admin pode trocar de escritório.');
        }

        if ($operadoraId === null) {
            session()->forget('operadora_context_id');
            session()->forget('empresa_selecionada_id');
        } else {
            session(['operadora_context_id' => $operadoraId]);
        }
    }

    public static function clear(): void
    {
        session()->forget('operadora_context_id');
    }

    public static function isSuperAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->isSuperAdmin();
    }

    public static function requireId(): int
    {
        $id = self::id();

        if ($id === null) {
            abort(403, 'Contexto de escritório não definido.');
        }

        return $id;
    }

    public static function operadoraAtual(): ?EmpresasOperadora
    {
        $id = self::id();

        return $id ? EmpresasOperadora::find($id) : null;
    }

    public static function hasOperadoraContext(): bool
    {
        return self::id() !== null;
    }

    public static function superAdminPrecisaSelecionarEscritorio(): bool
    {
        return self::isSuperAdmin() && ! self::hasOperadoraContext();
    }

    public static function resolveEmpresa(?int $empresaId): Empresa
    {
        if (! $empresaId) {
            abort(422, 'Selecione uma empresa.');
        }

        $empresa = Empresa::find($empresaId);

        if (! $empresa) {
            abort(404, 'Empresa não encontrada.');
        }

        return $empresa;
    }

    public static function resolveEmpresaDaSessao(): ?Empresa
    {
        $id = session('empresa_selecionada_id');

        return $id ? Empresa::find((int) $id) : null;
    }
}
