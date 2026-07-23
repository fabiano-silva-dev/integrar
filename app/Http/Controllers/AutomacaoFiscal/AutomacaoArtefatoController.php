<?php

namespace App\Http\Controllers\AutomacaoFiscal;

use App\Models\AutomacaoArtefato;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AutomacaoArtefatoController
{
    public function __invoke(Request $request, AutomacaoArtefato $artefato): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user && ($user->isSuperAdmin() || $user->isAdmin()), 403);

        if (!$user->isSuperAdmin() && (int) $user->empresa_operadora_id !== (int) $artefato->empresa_operadora_id) {
            abort(403);
        }

        if (!Storage::exists($artefato->storage_path)) {
            abort(404, 'Artefato não encontrado no storage.');
        }

        $download = $request->boolean('download');
        $disposition = $download ? 'attachment' : 'inline';
        $nome = $artefato->nome_original ?: 'artefato';

        return Storage::response(
            $artefato->storage_path,
            $nome,
            [
                'Content-Type' => $artefato->mime_type ?: 'application/octet-stream',
            ],
            $disposition
        );
    }
}
