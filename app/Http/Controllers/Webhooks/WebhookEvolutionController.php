<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Documentos\WebhookEvolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookEvolutionController extends Controller
{
    public function __invoke(Request $request, WebhookEvolutionService $webhooks): JsonResponse
    {
        if (! $this->apikeyValida($request)) {
            $semChave = (string) config('evolution.api_key', '') === '';
            Log::warning($semChave
                ? 'Webhook Evolution: API key não configurada.'
                : 'Webhook Evolution: apikey inválida ou ausente.');
            app(\App\Services\Documentos\DocumentoProcessoLogService::class)->registrar(
                'erro',
                'webhook',
                $semChave
                    ? 'Webhook recusado: Evolution não configurada.'
                    : 'Webhook recusado: apikey inválida ou ausente.',
            );

            return response()->json([
                'mensagem' => $semChave ? 'Evolution não configurada.' : 'Não autorizado.',
            ], $semChave && app()->environment('production') ? 503 : 401);
        }

        $evento = $webhooks->receber($request->all(), $request);

        return response()->json([
            'recebido' => true,
            'evento_id' => $evento?->id,
        ]);
    }

    private function apikeyValida(Request $request): bool
    {
        $esperada = (string) config('evolution.api_key', '');

        if ($esperada === '') {
            return ! app()->environment('production');
        }

        $recebida = $request->header('apikey')
            ?? $request->header('Authorization')
            ?? $request->query('apikey');

        if (is_string($recebida) && str_starts_with($recebida, 'Bearer ')) {
            $recebida = substr($recebida, 7);
        }

        return is_string($recebida) && hash_equals($esperada, $recebida);
    }
}
