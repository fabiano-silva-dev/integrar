<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\AutomacaoExecucao;
use App\Models\AutomacaoExecucaoLog;
use Illuminate\Support\Collection;

class ExecucaoProgressoPresenter
{
    /**
     * @return list<array{key: string, label: string, match: list<string>}>
     */
    public function etapasPipeline(): array
    {
        return [
            [
                'key' => 'queue',
                'label' => 'Na fila',
                'match' => ['na_fila', 'JOB_STARTED', 'JOB_QUEUED'],
            ],
            [
                'key' => 'start',
                'label' => 'Início da execução',
                'match' => ['inicio', 'RUN_STARTED', 'FAKE_STEP'],
            ],
            [
                'key' => 'certificate',
                'label' => 'Certificado',
                'match' => ['certificado', 'CERTIFICATE_CONFIGURED', 'CERTIFICATE_REQUEST_SUSPECTED'],
            ],
            [
                'key' => 'browser',
                'label' => 'Navegador',
                'match' => ['navegador', 'BROWSER_STARTED', 'CONTEXT_CREATED', 'PAGE_OPENED'],
            ],
            [
                'key' => 'navigate',
                'label' => 'Navegação no portal',
                'match' => [
                    'navegacao',
                    'NAVIGATION_STARTED',
                    'NAVIGATION_FINISHED',
                    'REDIRECT_OBSERVED',
                    'POPUP_OPENED',
                    'FRAME_ATTACHED',
                ],
            ],
            [
                'key' => 'auth',
                'label' => 'Autenticação / consulta',
                'match' => [
                    'coleta',
                    'autenticacao',
                    'AUTHENTICATION_CONFIRMED',
                    'ROLE_SELECTION_DETECTED',
                    'MANUAL_CONFIRMATION_DETECTED',
                    'SEFAZ_RESPONSE',
                    'SCREENSHOT_SAVED',
                    'extract',
                    'EXTRACT_STARTED',
                    'EXTRACT_FINISHED',
                ],
            ],
            [
                'key' => 'finish',
                'label' => 'Finalização',
                'match' => [
                    'fim',
                    'finalizado',
                    'erro',
                    'TRACE_SAVED',
                    'RUN_FINISHED',
                    'RUN_FAILED',
                    'JOB_FINISHED',
                    'JOB_FAILED',
                ],
            ],
        ];
    }

    public function labelStatus(string $status): string
    {
        return match ($status) {
            'na_fila' => 'Na fila',
            'executando' => 'Em execução',
            'sucesso' => 'Sucesso',
            'sucesso_parcial' => 'Sucesso parcial',
            'falha' => 'Falhou',
            'cancelado' => 'Cancelado',
            default => $status,
        };
    }

    public function labelEvento(?string $etapa, string $mensagem): string
    {
        if (!$etapa) {
            return $mensagem;
        }

        $labels = [
            'JOB_STARTED' => 'Job enfileirado',
            'JOB_QUEUED' => 'Na fila',
            'RUN_STARTED' => 'Execução iniciada',
            'CERTIFICATE_CONFIGURED' => 'Certificado A1 configurado',
            'CERTIFICATE_REQUEST_SUSPECTED' => 'Possível pedido de certificado',
            'BROWSER_STARTED' => 'Navegador iniciado',
            'CONTEXT_CREATED' => 'Contexto do browser criado',
            'PAGE_OPENED' => 'Página aberta',
            'NAVIGATION_STARTED' => 'Navegando no portal',
            'NAVIGATION_FINISHED' => 'Página carregada',
            'REDIRECT_OBSERVED' => 'Redirecionamento observado',
            'POPUP_OPENED' => 'Popup aberto',
            'FRAME_ATTACHED' => 'Frame anexado',
            'AUTHENTICATION_CONFIRMED' => 'Autenticação confirmada',
            'ROLE_SELECTION_DETECTED' => 'Seleção de perfil detectada',
            'MANUAL_CONFIRMATION_DETECTED' => 'CAPTCHA / confirmação manual',
            'SEFAZ_RESPONSE' => 'Resposta da SEFAZ',
            'SCREENSHOT_SAVED' => 'Screenshot salvo',
            'TRACE_SAVED' => 'Trace salvo',
            'FAKE_STEP' => 'Etapa simulada',
            'EXTRACT_STARTED' => 'Coleta iniciada',
            'EXTRACT_FINISHED' => 'Coleta finalizada',
            'RUN_FINISHED' => 'Execução finalizada',
            'RUN_FAILED' => 'Execução falhou',
            'JOB_FINISHED' => 'Job finalizado',
            'JOB_FAILED' => 'Job falhou',
            'inicio' => 'Início',
            'coleta' => 'Coleta',
            'fim' => 'Fim',
            'erro' => 'Erro',
            'na_fila' => 'Na fila',
            'finalizado' => 'Finalizado',
        ];

        return $labels[$etapa] ?? str_replace('_', ' ', $etapa);
    }

    public function emAndamento(string $status): bool
    {
        return in_array($status, ['na_fila', 'executando'], true);
    }

    /**
     * @param  object{status: string, etapa_atual?: ?string}|AutomacaoExecucao  $execucao
     * @param  Collection<int, object>|iterable<object>  $logs
     * @return list<array{key: string, label: string, detail: ?string, state: string}>
     */
    public function montarPipeline(object $execucao, iterable $logs): array
    {
        $logs = collect($logs);
        $status = (string) $execucao->status;
        $seen = $logs->pluck('etapa')->filter()->map(fn ($e) => (string) $e)->unique()->all();

        if ($status === 'na_fila' || $status === 'executando') {
            $seen[] = 'na_fila';
        }
        if (! empty($execucao->etapa_atual)) {
            $seen[] = (string) $execucao->etapa_atual;
        }
        if (in_array($status, ['sucesso', 'sucesso_parcial'], true)) {
            $seen[] = 'RUN_FINISHED';
            $seen[] = 'finalizado';
        }
        if ($status === 'falha') {
            $seen[] = 'RUN_FAILED';
            $seen[] = 'erro';
        }

        $seenSet = array_fill_keys($seen, true);
        $hasError = $status === 'falha'
            || $logs->contains(fn ($log) => (($log->nivel ?? null) === 'error')
                || in_array((string) ($log->etapa ?? ''), ['RUN_FAILED', 'JOB_FAILED', 'erro'], true));
        $hasWarn = $logs->contains(fn ($log) => (($log->nivel ?? null) === 'warning')
            || in_array((string) ($log->etapa ?? ''), [
                'MANUAL_CONFIRMATION_DETECTED',
                'ROLE_SELECTION_DETECTED',
                'CERTIFICATE_REQUEST_SUSPECTED',
            ], true));
        $terminal = ! $this->emAndamento($status);
        $activeAssigned = false;

        $pipeline = [];
        foreach ($this->etapasPipeline() as $step) {
            $matched = collect($step['match'])->contains(fn ($m) => isset($seenSet[$m]));
            $lastMessage = $logs
                ->reverse()
                ->first(fn ($log) => in_array((string) ($log->etapa ?? ''), $step['match'], true))
                ?->mensagem ?? null;

            if ($matched) {
                if ($step['key'] === 'finish' && $hasError) {
                    $pipeline[] = ['key' => $step['key'], 'label' => $step['label'], 'detail' => $lastMessage, 'state' => 'error'];
                    continue;
                }
                if ($step['key'] === 'auth' && $hasWarn && $terminal) {
                    $pipeline[] = ['key' => $step['key'], 'label' => $step['label'], 'detail' => $lastMessage, 'state' => 'warn'];
                    continue;
                }
                $pipeline[] = ['key' => $step['key'], 'label' => $step['label'], 'detail' => $lastMessage, 'state' => 'done'];
                continue;
            }

            if (! $terminal && ! $activeAssigned) {
                $activeAssigned = true;
                $pipeline[] = ['key' => $step['key'], 'label' => $step['label'], 'detail' => $lastMessage, 'state' => 'active'];
                continue;
            }

            if ($terminal && $step['key'] === 'finish') {
                if ($hasError) {
                    $pipeline[] = ['key' => $step['key'], 'label' => $step['label'], 'detail' => null, 'state' => 'error'];
                    continue;
                }
                if (in_array($status, ['sucesso', 'sucesso_parcial'], true)) {
                    $pipeline[] = ['key' => $step['key'], 'label' => $step['label'], 'detail' => null, 'state' => 'done'];
                    continue;
                }
                if ($hasWarn) {
                    $pipeline[] = ['key' => $step['key'], 'label' => $step['label'], 'detail' => null, 'state' => 'warn'];
                    continue;
                }
            }

            $pipeline[] = ['key' => $step['key'], 'label' => $step['label'], 'detail' => null, 'state' => 'pending'];
        }

        return $pipeline;
    }

    /**
     * Pipeline a partir de logs em array (consultas avulsas / cache).
     *
     * @param  list<array{eventType?: string, level?: string, message?: string, at?: string}>  $logs
     * @return list<array{key: string, label: string, detail: ?string, state: string}>
     */
    public function montarPipelineDeEventos(string $status, array $logs): array
    {
        $statusNorm = match ($status) {
            'running', 'na_fila', 'executando' => $status === 'running' ? 'executando' : $status,
            'succeeded', 'sucesso', 'sucesso_parcial' => 'sucesso',
            'failed', 'falha' => 'falha',
            default => $status,
        };

        $fake = new class($statusNorm)
        {
            public string $status;

            public ?string $etapa_atual = null;

            public function __construct(string $status)
            {
                $this->status = $status;
            }
        };

        $logModels = collect($logs)->map(function (array $log) {
            return new class($log)
            {
                public string $etapa;

                public string $mensagem;

                public string $nivel;

                public function __construct(array $log)
                {
                    $this->etapa = (string) ($log['eventType'] ?? '');
                    $this->mensagem = (string) ($log['message'] ?? '');
                    $level = (string) ($log['level'] ?? 'info');
                    $this->nivel = $level === 'warn' ? 'warning' : $level;
                }
            };
        });

        return $this->montarPipeline($fake, $logModels);
    }

    public function labelStatusAvulso(string $status): string
    {
        return match ($status) {
            'idle' => 'Aguardando',
            'running', 'executando' => 'Em execução',
            'succeeded', 'sucesso' => 'Sucesso',
            'failed', 'falha' => 'Falhou',
            default => $this->labelStatus($status),
        };
    }

    /**
     * Origem do XML baixado: webservice do contador (escritório) ou do destinatário.
     */
    public static function labelFonteDownload(?string $fonte): ?string
    {
        return match ($fonte) {
            'ws-distdfe-an' => 'DistDFe Ambiente Nacional — certificado A1 do destinatário (cliente)',
            'ws-contabilista-rs' => 'WS Contabilista SEFAZ-RS — certificado A1 do escritório (contador)',
            default => $fonte !== null && $fonte !== '' ? $fonte : null,
        };
    }
}
