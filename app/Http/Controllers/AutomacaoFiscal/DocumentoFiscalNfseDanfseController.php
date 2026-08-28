<?php

namespace App\Http\Controllers\AutomacaoFiscal;

use App\Models\DocumentoFiscal;
use App\Services\AutomacaoFiscal\AnaliseFiscalService;
use App\Services\AutomacaoFiscal\NfseDanfseGenerator;
use App\Services\OperadoraContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentoFiscalNfseDanfseController
{
    public function __invoke(
        Request $request,
        DocumentoFiscal $documento,
        NfseDanfseGenerator $gerador
    ): Response {
        abort_unless(Auth::check(), 403);
        abort_if(OperadoraContext::superAdminPrecisaSelecionarEscritorio(), 403);

        $documento = DocumentoFiscal::query()->whereKey($documento->id)->firstOrFail();
        abort_unless((string) $documento->tipo_documento === 'nfse', 404);
        abort_unless($documento->temXmlPersistido(), 404, 'Baixe o XML antes de visualizar o DANFSe.');

        $xml = Storage::get((string) $documento->xml_storage_path);
        abort_unless(is_string($xml) && $xml !== '', 404);

        try {
            $pdf = $gerador->gerarPdf($xml);
        } catch (Throwable $e) {
            report($e);
            abort(422, 'Não foi possível gerar o DANFSe: '.$e->getMessage());
        }

        $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso) ?? 'nfse';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$chave.'-danfse.pdf"',
        ]);
    }
}
