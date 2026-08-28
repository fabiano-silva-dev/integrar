<?php

namespace App\Http\Controllers\AutomacaoFiscal;

use App\Services\AutomacaoFiscal\NfeXmlDownloadProgresso;
use App\Services\OperadoraContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentoFiscalXmlArquivoController
{
    public function __invoke(Request $request, string $token): StreamedResponse
    {
        abort_unless(Auth::check(), 403);
        abort_if(OperadoraContext::superAdminPrecisaSelecionarEscritorio(), 403);

        $progresso = NfeXmlDownloadProgresso::obter($token);
        abort_unless(is_array($progresso), 404);
        abort_unless(($progresso['status'] ?? '') === 'succeeded', 404);

        $operadoraId = OperadoraContext::id();
        if (
            $operadoraId
            && isset($progresso['empresa_operadora_id'])
            && (int) $progresso['empresa_operadora_id'] !== (int) $operadoraId
        ) {
            abort(403);
        }

        $path = (string) ($progresso['storage_path'] ?? '');
        $nome = (string) ($progresso['nome_arquivo'] ?? 'nfe.xml');
        $headers = ['Content-Type' => 'application/xml; charset=utf-8'];

        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->download($path, $nome, $headers);
        }

        abort_unless($path !== '' && Storage::exists($path), 404);

        return Storage::download($path, $nome, $headers);
    }
}
