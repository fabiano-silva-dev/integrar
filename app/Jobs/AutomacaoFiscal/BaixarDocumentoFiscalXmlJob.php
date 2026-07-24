<?php

namespace App\Jobs\AutomacaoFiscal;

use App\Models\DocumentoFiscal;
use App\Services\AutomacaoFiscal\NfeXmlDownloadProgresso;
use App\Services\AutomacaoFiscal\NfeXmlDownloadService;
use App\Services\OperadoraContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Download avulso de XML NF-e em fila — o modal abre na hora; a automação roda no worker.
 */
class BaixarDocumentoFiscalXmlJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public readonly string $token,
        public readonly int $documentoId,
        public readonly ?int $operadoraId,
    ) {
        $this->onQueue('automacoes');
    }

    public function handle(NfeXmlDownloadService $service): void
    {
        OperadoraContext::disableScope();

        try {
            $documento = DocumentoFiscal::withoutGlobalScope('operadora')
                ->whereKey($this->documentoId)
                ->first();

            if (! $documento) {
                NfeXmlDownloadProgresso::marcarFalha($this->token, 'Documento fiscal não encontrado.');

                return;
            }

            if (
                $this->operadoraId
                && (int) $documento->empresa_operadora_id !== (int) $this->operadoraId
            ) {
                NfeXmlDownloadProgresso::marcarFalha($this->token, 'Documento fora do escritório selecionado.');

                return;
            }

            NfeXmlDownloadProgresso::adicionarLog(
                $this->token,
                'info',
                'RUN_STARTED',
                'Iniciando download do XML (DistDFe/ciência → WS Contabilista)…'
            );

            $resultado = $service->baixar(
                $documento,
                fn (array $event) => NfeXmlDownloadProgresso::consumirEventoRunner($this->token, $event),
            );

            $path = NfeXmlDownloadProgresso::gravarXml($this->token, $resultado['xml']);
            NfeXmlDownloadProgresso::marcarSucesso(
                $this->token,
                $path,
                $resultado['nome_arquivo'],
                $resultado['fonte'] ?? null
            );
        } catch (Throwable $e) {
            report($e);
            NfeXmlDownloadProgresso::marcarFalha($this->token, $e->getMessage());
        } finally {
            OperadoraContext::enableScope();
        }
    }

    public function failed(?Throwable $e): void
    {
        NfeXmlDownloadProgresso::marcarFalha(
            $this->token,
            $e?->getMessage() ?: 'Falha inesperada no download do XML.'
        );
    }
}
