<?php

namespace App\Http\Controllers\AutomacaoFiscal;

use App\Services\AutomacaoFiscal\NfseDanfseGenerator;
use App\Services\AutomacaoFiscal\NfeXmlDownloadProgresso;
use App\Services\OperadoraContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Gera o DANFSe a partir do XML do fluxo avulso (token do progresso).
 */
class DocumentoFiscalXmlDanfseController
{
    public function __invoke(Request $request, string $token, NfseDanfseGenerator $gerador): Response
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
        $xml = null;
        if ($path !== '' && Storage::disk('local')->exists($path)) {
            $xml = Storage::disk('local')->get($path);
        } elseif ($path !== '' && Storage::exists($path)) {
            $xml = Storage::get($path);
        }
        abort_unless(is_string($xml) && $xml !== '', 404);

        try {
            $pdf = $gerador->gerarPdf($xml);
        } catch (Throwable $e) {
            report($e);
            abort(422, 'Não foi possível gerar o DANFSe: '.$e->getMessage());
        }

        $chave = (string) ($progresso['chave'] ?? 'nfse');
        $nome = $chave.'-danfse.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
        ]);
    }
}
