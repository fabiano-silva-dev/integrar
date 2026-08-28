<?php

namespace App\Services\Documentos;

use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Models\Documentos\ContaGoogle;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Empresa;
use App\Services\OperadoraContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentoDriveArquivoService
{
    public function __construct(
        private readonly GoogleDriveService $drive,
        private readonly DocumentoProcessoLogService $logs,
    ) {}

    public function download(int $documentoId, Request $request): StreamedResponse
    {
        return $this->responder($documentoId, $request, inline: false);
    }

    public function visualizar(int $documentoId, Request $request): StreamedResponse
    {
        return $this->responder($documentoId, $request, inline: true);
    }

    private function responder(int $documentoId, Request $request, bool $inline): StreamedResponse
    {
        OperadoraContext::requireId();

        $documento = DocumentoRecebido::query()->findOrFail($documentoId);
        $empresaId = $this->empresaIdDoPedido($request, $documento);
        $fileId = $documento->driveFileIdParaEmpresa($empresaId);

        if ($documento->status === StatusDocumentoRecebido::Excluido || $fileId === null) {
            $this->logs->deAcesso(
                $documento,
                'Este documento ainda não possui arquivo disponível no Google Drive.',
                ['drive_file_id' => $fileId],
                'aviso',
            );

            throw DocumentoDriveException::semArquivo();
        }

        $conta = ContaGoogle::query()
            ->where('empresa_operadora_id', $documento->empresa_operadora_id)
            ->first();

        if ($conta === null || ! $conta->conectada()) {
            $this->logs->deAcesso(
                $documento,
                'A conta Google deste escritório não está conectada.',
                ['drive_file_id' => $fileId],
                'aviso',
                'oauth',
            );

            throw DocumentoDriveException::contaDesconectada();
        }

        try {
            $arquivo = $this->drive->streamArquivo($conta, $fileId);
        } catch (DocumentoDriveException $exception) {
            $this->logs->deAcesso(
                $documento,
                $exception->getMessage(),
                ['drive_file_id' => $fileId],
                $exception->status >= 500 ? 'erro' : 'aviso',
                $exception->status === 409 ? 'oauth' : 'acesso',
            );

            throw $exception;
        }

        $nome = $this->nomeSeguro((string) ($documento->nome_original ?: $arquivo['nome'] ?: 'arquivo'));
        $mime = $this->mimeSeguro((string) ($documento->mime ?: $arquivo['mime'] ?: 'application/octet-stream'));
        $disposition = $inline && $this->podeInline($mime, $nome) ? 'inline' : 'attachment';

        $this->logs->deAcesso(
            $documento,
            $disposition === 'inline'
                ? 'Documento visualizado pelo usuário.'
                : 'Documento baixado pelo usuário.',
            ['drive_file_id' => $fileId, 'disposition' => $disposition],
        );

        $headers = [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        if (is_int($arquivo['tamanho']) && $arquivo['tamanho'] > 0) {
            $headers['Content-Length'] = (string) $arquivo['tamanho'];
        }

        $body = $arquivo['body'];

        return response()->streamDownload(
            function () use ($body) {
                $this->escreverCorpo($body);
            },
            $nome,
            $headers,
            $disposition,
        );
    }

    private function empresaIdDoPedido(Request $request, DocumentoRecebido $documento): int
    {
        $informado = $request->integer('empresa');

        if ($informado > 0) {
            $empresa = Empresa::query()->find($informado);

            if ($empresa !== null) {
                return (int) $empresa->id;
            }
        }

        return (int) $documento->empresa_id;
    }

    private function podeInline(string $mime, string $nome): bool
    {
        $mime = strtolower(trim($mime));

        if (str_starts_with($mime, 'image/') && ! str_contains($mime, 'svg')) {
            return true;
        }

        if (in_array($mime, [
            'application/pdf',
            'text/plain',
            'application/xml',
            'text/xml',
            'application/json',
        ], true)) {
            return true;
        }

        $ext = strtolower((string) pathinfo($nome, PATHINFO_EXTENSION));

        return in_array($ext, ['pdf', 'xml', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true)
            && ($mime === 'application/octet-stream' || $mime === '');
    }

    private function mimeSeguro(string $mime): string
    {
        $mime = trim($mime);

        return $mime !== '' ? $mime : 'application/octet-stream';
    }

    private function nomeSeguro(string $nome): string
    {
        $limpo = str_replace(['\\', '/', "\0"], '_', $nome);
        $limpo = trim($limpo);

        return $limpo !== '' ? $limpo : 'arquivo';
    }

    private function escreverCorpo(mixed $body): void
    {
        $out = fopen('php://output', 'wb');

        if ($out === false) {
            return;
        }

        try {
            if (is_string($body)) {
                fwrite($out, $body);

                return;
            }

            if (is_object($body) && method_exists($body, 'eof') && method_exists($body, 'read')) {
                if (method_exists($body, 'rewind')) {
                    try {
                        $body->rewind();
                    } catch (\Throwable) {
                    }
                }

                while (! $body->eof()) {
                    fwrite($out, $body->read(262144));
                }

                return;
            }

            fwrite($out, (string) $body);
        } finally {
            fclose($out);
        }
    }
}
