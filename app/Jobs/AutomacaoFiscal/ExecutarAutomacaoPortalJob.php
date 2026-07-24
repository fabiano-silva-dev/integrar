<?php

namespace App\Jobs\AutomacaoFiscal;

use App\Models\AutomacaoExecucao;
use App\Services\AutomacaoFiscal\AutomacaoExecucaoService;
use App\Services\OperadoraContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ExecutarAutomacaoPortalJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 5;

    public function __construct(public readonly int $execucaoId)
    {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $operadoraId = AutomacaoExecucao::withoutGlobalScope('operadora')
            ->whereKey($this->execucaoId)
            ->value('empresa_operadora_id') ?? 'global';

        // Certificado/sessão e-CAC: uma execução por escritório por vez.
        return [
            (new WithoutOverlapping('automacao-portal-'.$operadoraId))
                ->releaseAfter(20)
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(AutomacaoExecucaoService $service): void
    {
        OperadoraContext::disableScope();

        try {
            $execucao = AutomacaoExecucao::withoutGlobalScope('operadora')->find($this->execucaoId);
            if (!$execucao) {
                return;
            }

            $service->executar($execucao);
        } finally {
            OperadoraContext::enableScope();
        }
    }
}
