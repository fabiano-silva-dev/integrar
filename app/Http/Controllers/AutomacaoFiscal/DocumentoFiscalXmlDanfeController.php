<?php

namespace App\Http\Controllers\AutomacaoFiscal;

use App\Services\AutomacaoFiscal\NfeXmlDownloadProgresso;
use App\Services\OperadoraContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use NFePHP\DA\NFe\Danfe;
use Throwable;

/**
 * Gera o DANFE (PDF) a partir do XML baixado pelo fluxo avulso (token do progresso).
 */
class DocumentoFiscalXmlDanfeController
{
    public function __invoke(Request $request, string $token): Response
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

        $xml = Storage::disk('local')->get($path);
        abort_unless(is_string($xml) && $xml !== '', 404);
        abort_unless(str_contains($xml, '<infNFe'), 404, 'O arquivo baixado não é um XML de NF-e.');

        try {
            $danfe = new Danfe($xml, 'P', 'A4', '', 'S', '');
            $danfe->montaDANFE();
            $pdf = $danfe->printDocument('danfe.pdf', 'S');
        } catch (Throwable $e) {
            report($e);
            abort(422, 'Não foi possível gerar o DANFE: '.$e->getMessage());
        }

        $chave = (string) ($progresso['chave'] ?? 'nfe');
        $nome = $chave.'-danfe.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
        ]);
    }
}
