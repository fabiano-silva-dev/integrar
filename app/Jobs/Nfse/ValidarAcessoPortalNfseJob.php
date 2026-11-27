<?php

namespace App\Jobs\Nfse;

use App\Models\Nfse\ValidacaoPortal;
use App\Services\Nfse\LogTesteAcessoNfse;
use App\Services\Nfse\ValidacaoPortalNfse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ValidarAcessoPortalNfseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 100;

    public bool $failOnTimeout = true;

    // Apenas identificadores; nenhuma credencial ou modelo serializado na fila.
    public function __construct(public int $operadoraId, public int $empresaId, public int $validacaoId)
    {
        $this->onConnection('database')->onQueue('default');
    }

    public function handle(ValidacaoPortalNfse $service): void
    {
        $service->processar($this->operadoraId, $this->empresaId, $this->validacaoId);
    }

    public function failed(?\Throwable $exception): void
    {
        ValidacaoPortal::withoutGlobalScope('operadora')->where('empresa_operadora_id', $this->operadoraId)
            ->where('empresa_id', $this->empresaId)->whereKey($this->validacaoId)->whereIn('status', ['pendente', 'iniciada'])
            ->update(['status' => 'nao_confirmada', 'codigo' => 'UNAVAILABLE', 'concluida_em' => now(), 'diagnostico_tecnico' => LogTesteAcessoNfse::json(['etapa' => 'worker_interrompido', 'codigo' => 'WORKER_INTERROMPIDO'])]);
    }
}
