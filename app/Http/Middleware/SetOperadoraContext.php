<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Services\OperadoraContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetOperadoraContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            return $next($request);
        }

        if (! $user->isSuperAdmin() && ! $user->empresa_operadora_id) {
            abort(403, 'Usuário sem escritório vinculado.');
        }

        if (! $user->isSuperAdmin()) {
            session()->forget('operadora_context_id');
        }

        if ($user->isSuperAdmin() && session()->has('operadora_context_id')) {
            $operadora = EmpresasOperadora::find(session('operadora_context_id'));

            if (! $operadora || ! ($operadora->ativo ?? true)) {
                OperadoraContext::clear();
                session()->forget('empresa_selecionada_id');
            }
        }

        $this->validarEmpresaNaSessao($user);

        return $next($request);
    }

    private function validarEmpresaNaSessao($user): void
    {
        $empresaId = session('empresa_selecionada_id');

        if (! $empresaId) {
            return;
        }

        $query = Empresa::withoutGlobalScope('operadora')->where('id', $empresaId);

        $operadoraId = OperadoraContext::id();

        if ($operadoraId !== null) {
            $query->where('empresa_operadora_id', $operadoraId);
        } elseif (! $user->isSuperAdmin()) {
            session()->forget('empresa_selecionada_id');

            return;
        }

        if (! $query->exists()) {
            session()->forget('empresa_selecionada_id');
        }
    }
}
