<?php

namespace App\Services\Documentos;

use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Models\Documentos\ContaGoogle;
use App\Models\Documentos\DocumentoRecebido;
use App\Services\OperadoraContext;
use App\Services\OperadoraStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class CompactarDocumentosDriveService
{
    public function __construct(
        private readonly GoogleDriveService $drive,
    ) {}

    /**
     * @param  list<int>  $documentoIds
     */
    public function baixar(array $documentoIds, string $nomeZip = 'documentos.zip', ?int $empresaId = null): BinaryFileResponse
    {
        $documentos = $this->carregarDocumentos($documentoIds);

        if ($documentos->isEmpty()) {
            throw new \RuntimeException('Nenhum arquivo disponível para baixar.');
        }

        if ($documentos->count() === 1) {
            $unico = $documentos->first();
            $caminho = $this->materializar($unico, $empresaId);
            $nome = $this->nomeSeguro((string) $unico->nome_original);

            return response()->download($caminho, $nome)->deleteFileAfterSend();
        }

        $zipPath = $this->montarZip($documentos, $nomeZip, $empresaId);

        return response()->download($zipPath, $nomeZip)->deleteFileAfterSend();
    }

    /**
     * @param  list<int>  $documentoIds
     * @return \Illuminate\Support\Collection<int, DocumentoRecebido>
     */
    public function carregarDocumentos(array $documentoIds)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $documentoIds))));

        if ($ids === []) {
            return collect();
        }

        return DocumentoRecebido::query()
            ->whereIn('id', $ids)
            ->whereNotNull('drive_file_id')
            ->where('status', StatusDocumentoRecebido::EnviadoDrive)
            ->orderBy('ano')
            ->orderBy('tipo_documento')
            ->orderBy('nome_original')
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, DocumentoRecebido>  $documentos
     */
    private function montarZip($documentos, string $nomeZip, ?int $empresaId): string
    {
        $nomeArquivo = $this->nomeSeguro($nomeZip);
        if (! str_ends_with(strtolower($nomeArquivo), '.zip')) {
            $nomeArquivo .= '.zip';
        }

        $zipPath = OperadoraStorage::tempDirectory(OperadoraContext::id())
            .'/docs-'.str_replace('.', '', uniqid('', true)).'-'.$nomeArquivo;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível montar o arquivo compactado.');
        }

        $usados = [];
        $temps = [];

        try {
            foreach ($documentos as $documento) {
                $entrada = $this->caminhoZip($documento);
                $base = $entrada;
                $n = 2;
                while (isset($usados[$entrada])) {
                    $ext = pathinfo($base, PATHINFO_EXTENSION);
                    $stem = $ext !== '' ? substr($base, 0, -(strlen($ext) + 1)) : $base;
                    $entrada = $ext !== '' ? "{$stem}_{$n}.{$ext}" : "{$stem}_{$n}";
                    $n++;
                }
                $usados[$entrada] = true;
                $caminho = $this->materializar($documento, $empresaId);
                $temps[] = $caminho;
                $zip->addFile($caminho, $entrada);
            }

            $zip->close();
        } catch (\Throwable $exception) {
            $zip->close();
            foreach ($temps as $temp) {
                @unlink($temp);
            }
            @unlink($zipPath);

            throw $exception;
        }

        foreach ($temps as $temp) {
            @unlink($temp);
        }

        return $zipPath;
    }

    private function materializar(DocumentoRecebido $documento, ?int $empresaId): string
    {
        $nome = $this->nomeSeguro((string) $documento->nome_original);
        $caminho = OperadoraStorage::tempDirectory(OperadoraContext::id())
            .'/docs-'.str_replace('.', '', uniqid('', true)).'-'.$nome;

        if (is_string($documento->storage_path) && $documento->storage_path !== '' && Storage::exists($documento->storage_path)) {
            $origem = Storage::path($documento->storage_path);
            if (! copy($origem, $caminho)) {
                throw new \RuntimeException('Não foi possível preparar '.$documento->nome_original.'.');
            }

            return $caminho;
        }

        $fileId = $documento->driveFileIdParaEmpresa($empresaId);

        if ($fileId === null) {
            throw DocumentoDriveException::semArquivo();
        }

        $conta = ContaGoogle::query()
            ->where('empresa_operadora_id', $documento->empresa_operadora_id)
            ->first();

        if ($conta === null || ! $conta->conectada()) {
            throw DocumentoDriveException::contaDesconectada();
        }

        try {
            $this->drive->gravarArquivo($conta, $fileId, $caminho);
        } catch (DocumentoDriveException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Não foi possível baixar '.$documento->nome_original.'.');
        }

        return $caminho;
    }

    private function caminhoZip(DocumentoRecebido $documento): string
    {
        $partes = array_filter([
            $documento->ano ? (string) $documento->ano : null,
            $documento->tipo_documento?->pastaDrive(),
            $this->nomeSeguro((string) $documento->nome_original),
        ]);

        return implode('/', $partes);
    }

    private function nomeSeguro(string $nome): string
    {
        $limpo = str_replace(['\\', '/', "\0"], '_', $nome);
        $limpo = trim($limpo);

        return $limpo !== '' ? $limpo : 'arquivo';
    }
}
