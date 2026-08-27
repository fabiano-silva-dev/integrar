<?php

namespace App\Livewire\Documentos;

use App\Services\OperadoraContext;
use Illuminate\Support\Facades\Auth;

trait AutorizaModuloDocumentos
{
    protected function garantirAcessoDocumentos(): void
    {
        $user = Auth::user();

        if (! $user || (! $user->isSuperAdmin() && ! in_array($user->role, ['admin', 'gerente'], true))) {
            abort(403, 'Sem permissão para o módulo Documentos.');
        }
    }

    protected function garantirAcessoLogDocumentos(): void
    {
        $user = Auth::user();

        if (! $user || ! $user->podeVerLogDocumentos()) {
            abort(403, 'Sem permissão para o registro de documentos.');
        }
    }

    protected function precisaSelecionarEscritorio(): bool
    {
        return OperadoraContext::superAdminPrecisaSelecionarEscritorio();
    }
}
