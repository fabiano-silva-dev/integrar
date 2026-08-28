<?php

namespace App\Http\Controllers\AutomacaoFiscal;

use App\Models\DocumentoFiscal;
use App\Services\AutomacaoFiscal\AnaliseFiscalService;
use App\Services\OperadoraContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentoFiscalNfseXmlController
{
    public function __invoke(Request $request, DocumentoFiscal $documento): StreamedResponse
    {
        abort_unless(Auth::check(), 403);
        abort_if(OperadoraContext::superAdminPrecisaSelecionarEscritorio(), 403);

        $documento = DocumentoFiscal::query()->whereKey($documento->id)->firstOrFail();
        abort_unless((string) $documento->tipo_documento === 'nfse', 404);
        abort_unless($documento->temXmlPersistido(), 404, 'XML desta NFS-e ainda não foi baixado.');

        $path = (string) $documento->xml_storage_path;
        $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso) ?? 'nfse';

        return Storage::download($path, $chave.'-nfse.xml', [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }
}
