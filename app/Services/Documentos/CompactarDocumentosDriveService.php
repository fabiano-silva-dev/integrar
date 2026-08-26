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
    public function baixar(array $documentoIds, string $nomeZip = 'documentos.zip'): BinaryFileResponse
    {
        $documentos = $this->carregarDocumentos($documentoIds);

        if ($documentos->isEmpty()) {
            throw new \RuntimeException('Nenhum arquivo disponível para baixar.');
        }

        if ($documentos->count() === 1) {
            $unico = $documentos->first();
            $conteudo = $this->conteudoDoDocumento($unico);
            $nome = $this->nomeSeguro((string) $unico->nome_original);
            $caminho = $this->gravarTemp($nome, $conteudo);

            return response()->download($caminho, $nome)->deleteFileAfterSend();
        }

        $zipPath = $this->montarZip($documentos, $nomeZip);

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
    private function montarZip($documentos, string $nomeZip): string
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
            $zip->addFromString($entrada, $this->conteudoDoDocumento($documento));
        }

        $zip->close();

        return $zipPath;
    }

    private function conteudoDoDocumento(DocumentoRecebido $documento): string
    {
        if (is_string($documento->storage_path) && $documento->storage_path !== '' && Storage::exists($documento->storage_path)) {
            return (string) Storage::get($documento->storage_path);
        }

        $conta = ContaGoogle::query()
            ->where('empresa_operadora_id', $documento->empresa_operadora_id)
            ->first();

        if ($conta === null || ! $conta->conectada() || ! is_string($documento->drive_file_id) || $documento->drive_file_id === '') {
            throw new \RuntimeException('Não foi possível baixar '.$documento->nome_original.'.');
        }

        return $this->drive->baixarConteudo($conta, $documento->drive_file_id);
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

    private function gravarTemp(string $nome, string $conteudo): string
    {
        $relative = OperadoraStorage::put(
            'temp',
            'docs-'.uniqid('', true).'-'.$nome,
            $conteudo,
            OperadoraContext::id(),
        );

        return Storage::path($relative);
    }
}
