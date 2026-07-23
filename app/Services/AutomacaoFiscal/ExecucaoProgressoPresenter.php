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
                'match' => ['na_fila', 'JOB_STARTED'],
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
     * @param  Collection<int, AutomacaoExecucaoLog>|iterable<AutomacaoExecucaoLog>  $logs
     * @return list<array{key: string, label: string, detail: ?string, state: string}>
     */
    public function montarPipeline(AutomacaoExecucao $execucao, iterable $logs): array
    {
        $logs = collect($logs);
        $status = (string) $execucao->status;
        $seen = $logs->pluck('etapa')->filter()->map(fn ($e) => (string) $e)->unique()->all();

        if ($status === 'na_fila' || $status === 'executando') {
            $seen[] = 'na_fila';
        }
        if ($execucao->etapa_atual) {
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
            || $logs->contains(fn (AutomacaoExecucaoLog $log) => $log->nivel === 'error'
                || in_array((string) $log->etapa, ['RUN_FAILED', 'JOB_FAILED', 'erro'], true));
        $hasWarn = $logs->contains(fn (AutomacaoExecucaoLog $log) => $log->nivel === 'warning'
            || in_array((string) $log->etapa, [
                'MANUAL_CONFIRMATION_DETECTED',
                'ROLE_SELECTION_DETECTED',
                'CERTIFICATE_REQUEST_SUSPECTED',
            ], true));
        $terminal = !$this->emAndamento($status);
        $activeAssigned = false;

        $pipeline = [];
        foreach ($this->etapasPipeline() as $step) {
            $matched = collect($step['match'])->contains(fn ($m) => isset($seenSet[$m]));
            $lastMessage = $logs
                ->reverse()
                ->first(fn (AutomacaoExecucaoLog $log) => in_array((string) $log->etapa, $step['match'], true))
                ?->mensagem;

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

            if (!$terminal && !$activeAssigned) {
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
}
