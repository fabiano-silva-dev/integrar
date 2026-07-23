<?php

namespace App\Jobs\AutomacaoFiscal;

use App\Models\AutomacaoExecucao;
use App\Services\AutomacaoFiscal\AutomacaoExecucaoService;
use App\Services\OperadoraContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecutarAutomacaoPortalJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public readonly int $execucaoId)
    {
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
