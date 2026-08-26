<?php

namespace App\Services\Documentos;

use App\Jobs\Documentos\ProcessarWebhookEvolutionJob;
use App\Models\Documentos\ConexaoWhatsapp;
use App\Models\Documentos\EventoWebhookWhatsapp;
use Illuminate\Http\Request;

class WebhookEvolutionService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function receber(array $payload, Request $request): ?EventoWebhookWhatsapp
    {
        $tipo = $this->normalizarTipo($payload);
        $instancia = is_string($payload['instance'] ?? null) ? $payload['instance'] : null;
        $conexao = $instancia !== null
            ? ConexaoWhatsapp::withoutGlobalScope('operadora')->where('nome_instancia', $instancia)->first()
            : null;

        if (in_array($tipo, ['messages.upsert', 'messages_upsert'], true)
            && ($conexao === null || ! app(ReceberMidiaWhatsappService::class)->payloadRelevante($conexao, $payload))) {
            return null;
        }

        $chave = $this->chaveIdempotencia($payload, $tipo, $instancia, $request);

        $existente = EventoWebhookWhatsapp::query()->where('chave_idempotencia', $chave)->first();

        if ($existente !== null) {
            return $existente;
        }

        $evento = EventoWebhookWhatsapp::query()->create([
            'empresa_operadora_id' => $conexao?->empresa_operadora_id,
            'conexao_whatsapp_id' => $conexao?->id,
            'tipo_evento' => $tipo,
            'chave_idempotencia' => $chave,
            'payload' => $payload,
            'status' => 'recebido',
        ]);

        ProcessarWebhookEvolutionJob::dispatch($evento->id);

        return $evento;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processar(EventoWebhookWhatsapp $evento, array $payload): void
    {
        $tipo = $this->normalizarTipo($payload);
        $instancia = is_string($payload['instance'] ?? null) ? $payload['instance'] : null;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        if ($instancia === null || $instancia === '') {
            $evento->update(['status' => 'ignorado', 'erro' => 'Instância ausente.', 'processado_em' => now()]);

            return;
        }

        $conexao = ConexaoWhatsapp::withoutGlobalScope('operadora')
            ->where('nome_instancia', $instancia)
            ->first();

        if ($conexao === null) {
            $evento->update(['status' => 'ignorado', 'erro' => 'Instância desconhecida.', 'processado_em' => now()]);

            return;
        }

        if ($evento->conexao_whatsapp_id === null) {
            $evento->update([
                'conexao_whatsapp_id' => $conexao->id,
                'empresa_operadora_id' => $conexao->empresa_operadora_id,
            ]);
        }

        $conexaoService = app(EvolutionConexaoService::class);

        if (in_array($tipo, ['qrcode.updated', 'qrcode_updated'], true)) {
            $conexaoService->processarQrcode($instancia, is_array($data) ? $data : $payload);
            $evento->update(['status' => 'processado', 'processado_em' => now()]);

            return;
        }

        if (in_array($tipo, ['connection.update', 'connection_update'], true)) {
            $estado = is_string($data['state'] ?? null)
                ? $data['state']
                : (is_string($data['instance']['state'] ?? null) ? $data['instance']['state'] : null);
            $conexaoService->processarAtualizacaoConexao($instancia, $estado);
            $evento->update(['status' => 'processado', 'processado_em' => now()]);

            return;
        }

        if (in_array($tipo, ['messages.upsert', 'messages_upsert'], true)) {
            app(ReceberMidiaWhatsappService::class)->processar($conexao, $payload);
            $evento->update(['status' => 'processado', 'processado_em' => now()]);

            return;
        }

        $evento->update(['status' => 'ignorado', 'erro' => 'Tipo não suportado.', 'processado_em' => now()]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalizarTipo(array $payload): string
    {
        $tipo = $payload['event'] ?? $payload['eventType'] ?? $payload['type'] ?? '';
        $tipo = is_string($tipo) ? strtolower($tipo) : '';

        return str_replace('_', '.', $tipo);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function chaveIdempotencia(array $payload, string $tipo, ?string $instancia, Request $request): string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $mensagemId = null;

        if (is_array($data['key'] ?? null) && is_string($data['key']['id'] ?? null)) {
            $mensagemId = $data['key']['id'];
        } elseif (isset($data['messages'][0]['key']['id']) && is_string($data['messages'][0]['key']['id'])) {
            $mensagemId = $data['messages'][0]['key']['id'];
        }

        $base = implode('|', [
            $instancia ?? '',
            $tipo,
            $mensagemId ?? '',
            $mensagemId === null ? json_encode($payload) : '',
            $request->header('eventid') ?? '',
        ]);

        return hash('sha256', $base);
    }
}
