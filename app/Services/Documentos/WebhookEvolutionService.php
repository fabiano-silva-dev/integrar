<?php

namespace App\Services\Documentos;

use App\Jobs\Documentos\ProcessarWebhookEvolutionJob;
use App\Models\Documentos\ConexaoWhatsapp;
use App\Models\Documentos\EventoWebhookWhatsapp;
use Illuminate\Http\Request;

class WebhookEvolutionService
{
    public function __construct(
        private readonly DocumentoProcessoLogService $logs,
    ) {}

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
            if ($this->logs->ativo()) {
                if ($conexao !== null) {
                    app(ReceberMidiaWhatsappService::class)->registrarIgnorados($conexao, $payload);
                } else {
                    $this->logInstanciaDesconhecida($payload, $instancia);
                }
            }

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
            $this->logs->registrar(
                'aviso',
                'ignorado',
                'Webhook sem instância.',
                ['tipo_evento' => $tipo],
                operadoraId: $evento->empresa_operadora_id !== null ? (int) $evento->empresa_operadora_id : null,
                conexaoId: $evento->conexao_whatsapp_id !== null ? (int) $evento->conexao_whatsapp_id : null,
            );

            return;
        }

        $conexao = ConexaoWhatsapp::withoutGlobalScope('operadora')
            ->where('nome_instancia', $instancia)
            ->first();

        if ($conexao === null) {
            $evento->update(['status' => 'ignorado', 'erro' => 'Instância desconhecida.', 'processado_em' => now()]);
            $this->logInstanciaDesconhecida($payload, $instancia);

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
        $this->logs->daConexao(
            $conexao,
            'aviso',
            'ignorado',
            'Tipo de evento não suportado.',
            ['tipo_evento' => $tipo],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logInstanciaDesconhecida(array $payload, ?string $instancia): void
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $chave = is_array($data['key'] ?? null) ? $data['key'] : [];
        $remoteJid = is_string($chave['remoteJid'] ?? null) ? $chave['remoteJid'] : null;
        $mensagemId = is_string($chave['id'] ?? null) ? $chave['id'] : null;
        $ehGrupo = is_string($remoteJid) && str_contains($remoteJid, '@g.us');
        $temMidia = app(EvolutionAdaptador::class)->mensagemTemMidia($data);
        $metadados = $temMidia ? app(EvolutionAdaptador::class)->metadadosMidiaDaMensagem($data) : [];

        if (! $ehGrupo && ! $temMidia) {
            return;
        }

        $this->logs->registrar(
            'aviso',
            'ignorado',
            'Instância do WhatsApp desconhecida.',
            [
                'instancia' => $instancia,
                'remote_jid' => $remoteJid,
                'nome_arquivo' => $metadados['nome_arquivo'] ?? null,
                'tem_midia' => $temMidia,
                'eh_grupo' => $ehGrupo,
            ],
            mensagemWhatsappId: $mensagemId,
        );
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
