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
        abort_unless($path !== '' && Storage::disk('local')->exists($path), 404);

        $nome = (string) ($progresso['nome_arquivo'] ?? 'nfe.xml');

        return Storage::disk('local')->download($path, $nome, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }
}
