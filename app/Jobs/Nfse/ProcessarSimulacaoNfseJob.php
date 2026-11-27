<?php

namespace App\Jobs\Nfse;

use App\Services\Nfse\ProcessamentoSimuladoNfse;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessarSimulacaoNfseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $operadoraId, public readonly int $empresaId, public readonly int $simulacaoId) {}

    public function handle(ProcessamentoSimuladoNfse $service): void
    {
        $service->processar($this->operadoraId, $this->empresaId, $this->simulacaoId);
    }
}
