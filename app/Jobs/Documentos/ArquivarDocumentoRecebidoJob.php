<?php

namespace App\Jobs\Documentos;

use App\Models\Documentos\DocumentoRecebido;
use App\Services\Documentos\ArquivarDocumentoService;
use App\Services\Documentos\DocumentoProcessoLogService;
use App\Services\OperadoraContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ArquivarDocumentoRecebidoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $documentoId)
    {
        $this->onQueue((string) config('documentos.fila', 'documentos'));
    }

    public function handle(ArquivarDocumentoService $arquivar): void
    {
        OperadoraContext::disableScope();

        try {
            $documento = DocumentoRecebido::query()->find($this->documentoId);

            if ($documento === null) {
                return;
            }

            $arquivar->arquivar($documento);
        } catch (\Throwable $exception) {
            $documento = DocumentoRecebido::query()->find($this->documentoId);

            if ($documento !== null) {
                app(DocumentoProcessoLogService::class)->doDocumento(
                    $documento,
                    'erro',
                    'erro',
                    'Falha ao arquivar: '.$exception->getMessage(),
                );
            } else {
                app(DocumentoProcessoLogService::class)->registrar(
                    'erro',
                    'erro',
                    'Falha ao arquivar documento inexistente: '.$exception->getMessage(),
                    ['documento_id' => $this->documentoId],
                );
            }

            throw $exception;
        } finally {
            OperadoraContext::enableScope();
        }
    }
}
