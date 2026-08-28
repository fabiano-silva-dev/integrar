<?php

namespace App\Http\Controllers\AutomacaoFiscal;

use App\Models\DocumentoFiscal;
use App\Models\Empresa;
use App\Models\PortalIntegracao;
use App\Services\AutomacaoFiscal\AnaliseFiscalService;
use App\Services\OperadoraContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class DocumentoFiscalNfsePeriodoZipController
{
    public function __invoke(
        Request $request,
        int $empresa,
        int $portal,
        string $competencia
    ): BinaryFileResponse|RedirectResponse {
        abort_unless(Auth::check(), 403);
        abort_if(OperadoraContext::superAdminPrecisaSelecionarEscritorio(), 403);

        $empresaModel = Empresa::query()->whereKey($empresa)->firstOrFail();
        $portalModel = PortalIntegracao::query()->whereKey($portal)->firstOrFail();
        abort_unless($portalModel->codigo === 'nfse_nacional', 404);

        $tipoListagem = AnaliseFiscalService::normalizarTipoListagem($request->query('listagem'));

        $docs = app(AnaliseFiscalService::class)
            ->queryDocumentos($empresaModel->id, $portalModel->id, $competencia, $tipoListagem)
            ->where('tipo_documento', 'nfse')
            ->orderBy('numero')
            ->get();

        $comXml = $docs->filter(fn (DocumentoFiscal $doc) => $doc->temXmlPersistido());
        if ($comXml->isEmpty()) {
            return redirect()
                ->route('automacao-fiscal.analise', array_filter([
                    'empresa' => $empresaModel->id,
                    'portal' => $portalModel->id,
                    'competencia' => $competencia,
                    'listagem' => $tipoListagem,
                ]))
                ->with('error', 'Nenhum XML baixado neste período ainda.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'nfse-zip-');
        abort_unless(is_string($tmp), 500);
        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new ZipArchive();
        abort_unless($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500);

        foreach ($comXml as $doc) {
            $xml = Storage::get((string) $doc->xml_storage_path);
            if (! is_string($xml) || $xml === '') {
                continue;
            }
            $chave = AnaliseFiscalService::normalizarChaveAcesso($doc->chave_acesso) ?? (string) $doc->id;
            $zip->addFromString($chave.'-nfse.xml', $xml);
        }
        $zip->close();

        $sufixoTipo = $tipoListagem ? '-'.$tipoListagem : '';
        $nome = 'nfse-'.$competencia.$sufixoTipo.'-'.$empresaModel->id.'.zip';

        return response()->download($zipPath, $nome, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
