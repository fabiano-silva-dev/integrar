<?php

namespace App\Http\Controllers\AutomacaoFiscal;

use App\Models\DocumentoFiscal;
use App\Services\AutomacaoFiscal\NfeXmlDownloadService;
use App\Services\OperadoraContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DocumentoFiscalXmlController
{
    public function __invoke(
        Request $request,
        DocumentoFiscal $documento,
        NfeXmlDownloadService $service
    ): Response|RedirectResponse {
        $user = Auth::user();
        abort_unless($user !== null, 403);

        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            return redirect()
                ->back()
                ->with('error', 'Selecione um escritório no menu superior antes de baixar o XML.');
        }

        // Global scope de operadora já isola; garante que o doc existe no tenant.
        $documento = DocumentoFiscal::query()->whereKey($documento->id)->firstOrFail();

        try {
            $resultado = $service->baixar($documento);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->back()
                ->with('error', $e->getMessage());
        }

        return response($resultado['xml'], 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$resultado['nome_arquivo'].'"',
        ]);
    }
}
