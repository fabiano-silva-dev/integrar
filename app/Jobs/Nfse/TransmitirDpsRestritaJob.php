<?php

namespace App\Jobs\Nfse;

use App\Services\Nfse\Dps\FilaEmissaoRestrita;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TransmitirDpsRestritaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 75;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $operadoraId,
        public readonly int $empresaId,
        public readonly int $filaId,
        public readonly int $transmissaoId,
    ) {}

    public function handle(FilaEmissaoRestrita $service): void
    {
        $service->processar($this->operadoraId, $this->empresaId, $this->filaId, $this->transmissaoId);
    }
}
