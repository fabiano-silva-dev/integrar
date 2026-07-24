<?php

namespace App\Jobs\AutomacaoFiscal;

use App\Services\AutomacaoFiscal\ConsultaAvulsa\ConsultaAvulsaService;
use App\Services\AutomacaoFiscal\NfeXmlDownloadProgresso;
use App\Services\OperadoraContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executa uma consulta avulsa (catálogo) na fila automacoes.
 */
class ExecutarConsultaAvulsaJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function __construct(
        public readonly string $token,
        public readonly string $tipo,
        public readonly array $entrada,
        public readonly ?int $operadoraId,
    ) {
        $this->onQueue('automacoes');
    }

    public function handle(ConsultaAvulsaService $service): void
    {
        OperadoraContext::disableScope();

        try {
            NfeXmlDownloadProgresso::adicionarLog(
                $this->token,
                'info',
                'RUN_STARTED',
                'Iniciando consulta avulsa…'
            );

            $saida = $service->executar(
                $this->tipo,
                $this->entrada,
                fn (array $event) => NfeXmlDownloadProgresso::consumirEventoRunner($this->token, $event),
            );

            $resultado = $saida['resultado'] ?? [];
            if (! empty($resultado['xml']) && ! empty($resultado['nome_arquivo'])) {
                $service->persistirXmlNoProgresso($this->token, $resultado);
            } else {
                NfeXmlDownloadProgresso::marcarFalha($this->token, 'Consulta concluída sem arquivo para download.');
            }
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
            $e?->getMessage() ?: 'Falha inesperada na consulta avulsa.'
        );
    }
}
