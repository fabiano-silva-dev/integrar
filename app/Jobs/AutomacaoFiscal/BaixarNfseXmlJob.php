<?php

namespace App\Jobs\AutomacaoFiscal;

use App\Models\DocumentoFiscal;
use App\Services\AutomacaoFiscal\NfeXmlDownloadProgresso;
use App\Services\AutomacaoFiscal\NfseXmlDownloadService;
use App\Services\OperadoraContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Download persistente do XML da NFS-e (Sefin Nacional) — uma nota por job.
 */
class BaixarNfseXmlJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(
        public readonly int $documentoId,
        public readonly ?int $operadoraId,
        public readonly ?string $token = null,
        public readonly bool $forcar = false,
    ) {
        $this->onQueue('automacoes');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('nfse-xml-'.$this->documentoId))
                ->releaseAfter(10)
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(NfseXmlDownloadService $service): void
    {
        OperadoraContext::disableScope();

        try {
            $documento = DocumentoFiscal::withoutGlobalScope('operadora')
                ->whereKey($this->documentoId)
                ->first();

            if (! $documento) {
                $this->falharProgresso('Documento fiscal não encontrado.');

                return;
            }

            if (
                $this->operadoraId
                && (int) $documento->empresa_operadora_id !== (int) $this->operadoraId
            ) {
                $this->falharProgresso('Documento fora do escritório selecionado.');

                return;
            }

            $this->logProgresso('RUN_STARTED', 'Iniciando download do XML na Sefin Nacional…');

            $resultado = $service->baixar(
                $documento,
                function (array $event): void {
                    if ($this->token) {
                        NfeXmlDownloadProgresso::consumirEventoRunner($this->token, $event);
                    }
                },
                $this->forcar,
            );

            if ($this->token) {
                $path = (string) ($resultado['storage_path'] ?? '');
                if ($path === '') {
                    $path = NfeXmlDownloadProgresso::gravarXml($this->token, $resultado['xml']);
                }
                NfeXmlDownloadProgresso::marcarSucesso(
                    $this->token,
                    $path,
                    $resultado['nome_arquivo'],
                    $resultado['fonte'] ?? null
                );
            }
        } catch (Throwable $e) {
            report($e);
            $documento = DocumentoFiscal::withoutGlobalScope('operadora')->find($this->documentoId);
            if ($documento) {
                $service->registrarErro($documento, $e->getMessage());
            }
            $this->falharProgresso($e->getMessage());
        } finally {
            OperadoraContext::enableScope();
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->falharProgresso($e?->getMessage() ?: 'Falha inesperada no download do XML da NFS-e.');
    }

    private function logProgresso(string $eventType, string $message): void
    {
        if (! $this->token) {
            return;
        }

        NfeXmlDownloadProgresso::adicionarLog($this->token, 'info', $eventType, $message);
    }

    private function falharProgresso(string $mensagem): void
    {
        if (! $this->token) {
            return;
        }

        NfeXmlDownloadProgresso::marcarFalha($this->token, $mensagem);
    }
}
