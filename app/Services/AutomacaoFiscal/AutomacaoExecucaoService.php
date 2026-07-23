<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\AutomacaoExecucao;
use App\Models\EmpresaIntegracao;
use App\Models\EmpresaIntegracaoRecurso;
use App\Models\PortalRecurso;
use App\Services\AutomacaoFiscal\Logs\AutomacaoLogService;
use App\Services\OperadoraContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class AutomacaoExecucaoService
{
    public function __construct(
        private readonly PortalDriverResolver $resolver,
        private readonly AutomacaoLogService $logs
    ) {
    }

    /**
     * @param  array<string, mixed>  $parametros
     */
    public function enfileirarManual(
        EmpresaIntegracaoRecurso $vinculo,
        ?Carbon $periodoInicio = null,
        ?Carbon $periodoFim = null,
        ?int $userId = null,
        array $parametros = []
    ): AutomacaoExecucao {
        $vinculo->loadMissing(['empresaIntegracao.portal', 'portalRecurso']);

        $integracao = $vinculo->empresaIntegracao;
        if (!$integracao || !$vinculo->ativo || !$integracao->ativo) {
            throw new RuntimeException('Integração ou recurso inativo.');
        }

        $params = $parametros !== [] ? $parametros : (array) ($vinculo->parametros ?? []);
        $inicioRaw = $params['periodo_inicial'] ?? $params['periodo_inicio'] ?? null;
        $fimRaw = $params['periodo_final'] ?? $params['periodo_fim'] ?? null;
        $inicio = $periodoInicio
            ?: ($inicioRaw ? Carbon::parse($inicioRaw) : now()->startOfMonth());
        $fim = $periodoFim
            ?: ($fimRaw ? Carbon::parse($fimRaw) : now());

        if ($inicio->greaterThan($fim)) {
            throw new RuntimeException('Período inicial não pode ser maior que o período final.');
        }

        if ($inicio->diffInDays($fim) > 31) {
            throw new RuntimeException('O período da consulta não pode ultrapassar 31 dias.');
        }

        $execucao = AutomacaoExecucao::create([
            'empresa_operadora_id' => $vinculo->empresa_operadora_id,
            'empresa_id' => $integracao->empresa_id,
            'empresa_integracao_id' => $integracao->id,
            'portal_recurso_id' => $vinculo->portal_recurso_id,
            'agenda_automacao_id' => $vinculo->agenda_automacao_id,
            'solicitado_por_user_id' => $userId,
            'gatilho' => 'manual',
            'periodo_inicio' => $inicio->toDateString(),
            'periodo_fim' => $fim->toDateString(),
            'status' => 'na_fila',
            'mensagem_usuario' => 'Execução enfileirada.',
            'etapa_atual' => 'na_fila',
            'parametros' => $params,
            'idempotency_key' => 'manual:' . $vinculo->id . ':' . Str::uuid(),
        ]);

        $this->despacharJob($execucao->id);

        return $execucao;
    }

    public function enfileirarValidacaoAcesso(
        EmpresaIntegracao $integracao,
        ?int $userId = null
    ): AutomacaoExecucao {
        $integracao->loadMissing('portal');

        if (!$integracao->ativo) {
            throw new RuntimeException('Integração inativa. Ative o portal na empresa antes de testar.');
        }

        $recurso = PortalRecurso::query()
            ->where('portal_integracao_id', $integracao->portal_integracao_id)
            ->where('codigo', 'validar_acesso')
            ->first();

        if (!$recurso) {
            throw new RuntimeException('Recurso validar_acesso não encontrado no catálogo.');
        }

        $vinculo = EmpresaIntegracaoRecurso::query()->firstOrCreate(
            [
                'empresa_integracao_id' => $integracao->id,
                'portal_recurso_id' => $recurso->id,
            ],
            [
                'empresa_operadora_id' => $integracao->empresa_operadora_id,
                'ativo' => true,
                'parametros' => null,
            ]
        );

        if (!$vinculo->ativo) {
            $vinculo->update(['ativo' => true]);
        }

        $execucao = AutomacaoExecucao::create([
            'empresa_operadora_id' => $integracao->empresa_operadora_id,
            'empresa_id' => $integracao->empresa_id,
            'empresa_integracao_id' => $integracao->id,
            'portal_recurso_id' => $recurso->id,
            'solicitado_por_user_id' => $userId,
            'gatilho' => 'manual',
            'periodo_inicio' => null,
            'periodo_fim' => null,
            'status' => 'na_fila',
            'mensagem_usuario' => 'Validação de acesso enfileirada.',
            'etapa_atual' => 'na_fila',
            'parametros' => [],
            'idempotency_key' => 'validar:' . $integracao->id . ':' . Str::uuid(),
        ]);

        $this->despacharJob($execucao->id);

        return $execucao;
    }

    /**
     * Em modo simulado (fake), executa na hora para o admin ver o andamento
     * sem precisar de `queue:work`. Em modo real, vai para a fila.
     */
    private function despacharJob(int $execucaoId): void
    {
        $job = new \App\Jobs\AutomacaoFiscal\ExecutarAutomacaoPortalJob($execucaoId);
        $job->onQueue('automacoes');

        if (config('automacao_fiscal.fake_mode', true)) {
            dispatch_sync($job);

            return;
        }

        dispatch($job);
    }

    /**
     * @param  array<string, mixed>  $parametros
     */
    public function salvarParametrosRecurso(EmpresaIntegracaoRecurso $vinculo, array $parametros): void
    {
        $vinculo->update(['parametros' => $parametros]);
    }

    public function garantirVinculo(EmpresaIntegracao $integracao, PortalRecurso $recurso): EmpresaIntegracaoRecurso
    {
        $vinculo = EmpresaIntegracaoRecurso::query()->firstOrCreate(
            [
                'empresa_integracao_id' => $integracao->id,
                'portal_recurso_id' => $recurso->id,
            ],
            [
                'empresa_operadora_id' => $integracao->empresa_operadora_id,
                'ativo' => true,
                'parametros' => null,
            ]
        );

        if (!$vinculo->ativo) {
            $vinculo->update(['ativo' => true]);
        }

        return $vinculo->fresh();
    }

    public function executar(AutomacaoExecucao $execucao): AutomacaoExecucao
    {
        $lock = Cache::lock('automacao-execucao:' . $execucao->id, 900);

        if (!$lock->get()) {
            throw new RuntimeException('Execução já em andamento.');
        }

        try {
            OperadoraContext::disableScope();

            /** @var AutomacaoExecucao $execucao */
            $execucao = AutomacaoExecucao::withoutGlobalScope('operadora')
                ->with(['empresaIntegracao.portal', 'empresaIntegracao.certificadoDigital', 'portalRecurso', 'empresa'])
                ->findOrFail($execucao->id);

            if (in_array($execucao->status, ['sucesso', 'sucesso_parcial', 'executando'], true)) {
                return $execucao;
            }

            $inicio = microtime(true);
            // Commit imediato para a UI (poll) ver "executando" e logs ao vivo.
            $execucao->update([
                'status' => 'executando',
                'etapa_atual' => 'inicio',
                'iniciada_em' => now(),
                'mensagem_usuario' => 'Executando consulta no portal.',
            ]);

            $this->logs->registrar($execucao, 'info', 'Execução iniciada', 'inicio', [
                'parametros' => $execucao->parametros,
            ]);

            try {
                $portal = $execucao->empresaIntegracao->portal;
                $driver = $this->resolver->resolve($portal);
                $resultado = $driver->executar($execucao);

                foreach ($resultado->logs as $log) {
                    $this->logs->registrar(
                        $execucao,
                        $log['nivel'] ?? 'info',
                        $log['mensagem'] ?? '',
                        $log['etapa'] ?? null,
                        $log['contexto'] ?? null
                    );
                }

                $artifacts = $resultado->metadados['artifacts'] ?? [];
                if (is_array($artifacts) && $artifacts !== []) {
                    app(AutomacaoArtefatoService::class)->persistirDoRunner($execucao, $artifacts);
                }

                $duracao = (int) round((microtime(true) - $inicio) * 1000);
                $execucao->update([
                    'status' => $resultado->status,
                    'etapa_atual' => 'finalizado',
                    'mensagem_usuario' => $resultado->mensagemUsuario,
                    'quantidade_encontrada' => $resultado->quantidadeEncontrada,
                    'quantidade_importada' => $resultado->quantidadeImportada,
                    'quantidade_ignorada' => $resultado->quantidadeIgnorada,
                    'quantidade_erros' => $resultado->quantidadeErros,
                    'finalizada_em' => now(),
                    'duracao_ms' => $duracao,
                ]);

                if ($resultado->status === 'sucesso' || $resultado->status === 'sucesso_parcial') {
                    $execucao->empresaIntegracao?->update([
                        'ultima_validacao_em' => now(),
                        'ultima_validacao_status' => 'ok',
                        'ultima_validacao_mensagem' => $resultado->mensagemUsuario,
                        'status_configuracao' => 'configurado',
                    ]);
                }
            } catch (\Throwable $e) {
                $duracao = (int) round((microtime(true) - $inicio) * 1000);
                $this->logs->registrar($execucao, 'error', $e->getMessage(), 'erro');
                $execucao->update([
                    'status' => 'falha',
                    'etapa_atual' => 'erro',
                    'mensagem_usuario' => 'A consulta falhou. Tente novamente ou contate o suporte.',
                    'quantidade_erros' => 1,
                    'finalizada_em' => now(),
                    'duracao_ms' => $duracao,
                ]);
            }

            return $execucao->fresh();
        } finally {
            OperadoraContext::enableScope();
            $lock->release();
        }
    }
}
