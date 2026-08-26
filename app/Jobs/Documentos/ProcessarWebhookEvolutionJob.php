<?php

namespace App\Jobs\Documentos;

use App\Models\Documentos\EventoWebhookWhatsapp;
use App\Services\Documentos\WebhookEvolutionService;
use App\Services\OperadoraContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessarWebhookEvolutionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public readonly int $eventoWebhookId)
    {
        $this->onQueue((string) config('documentos.fila', 'documentos'));
    }

    public function handle(WebhookEvolutionService $webhooks): void
    {
        OperadoraContext::disableScope();

        try {
            $evento = EventoWebhookWhatsapp::query()->find($this->eventoWebhookId);

            if ($evento === null || in_array($evento->status, ['processado', 'ignorado'], true)) {
                return;
            }

            $evento->update(['status' => 'processando']);
            $webhooks->processar($evento, (array) $evento->payload);
        } catch (\Throwable $exception) {
            Log::error('Webhook Evolution: falha no job.', [
                'evento_id' => $this->eventoWebhookId,
                'erro' => $exception->getMessage(),
            ]);

            EventoWebhookWhatsapp::query()->whereKey($this->eventoWebhookId)->update([
                'status' => 'falha',
                'erro' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            OperadoraContext::enableScope();
        }
    }
}
