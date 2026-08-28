<?php

namespace App\Http\Controllers\Documentos;

use App\Http\Controllers\Controller;
use App\Services\Documentos\DocumentoDriveArquivoService;
use App\Services\Documentos\DocumentoDriveException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DocumentoDriveArquivoController extends Controller
{
    public function download(Request $request, int $documento, DocumentoDriveArquivoService $service): Response
    {
        try {
            return $service->download($documento, $request);
        } catch (DocumentoDriveException $exception) {
            return $this->respostaErro($exception);
        }
    }

    public function visualizar(Request $request, int $documento, DocumentoDriveArquivoService $service): Response
    {
        try {
            return $service->visualizar($documento, $request);
        } catch (DocumentoDriveException $exception) {
            return $this->respostaErro($exception);
        }
    }

    private function respostaErro(DocumentoDriveException $exception): Response
    {
        return response($exception->getMessage(), $exception->status)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
