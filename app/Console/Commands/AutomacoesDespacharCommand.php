<?php

namespace App\Console\Commands;

use App\Jobs\AutomacaoFiscal\ExecutarAutomacaoPortalJob;
use App\Models\AutomacaoExecucao;
use App\Models\EmpresaIntegracaoRecurso;
use App\Services\AutomacaoFiscal\AgendaAutomacaoProximaExecucaoService;
use App\Services\OperadoraContext;
use Illuminate\Console\Command;

class AutomacoesDespacharCommand extends Command
{
    protected $signature = 'automacoes:despachar';

    protected $description = 'Despacha execuções de automação fiscal vencidas para a fila';

    public function handle(AgendaAutomacaoProximaExecucaoService $proximas): int
    {
        OperadoraContext::disableScope();

        try {
            $agora = now();
            $vinculos = EmpresaIntegracaoRecurso::withoutGlobalScope('operadora')
                ->with(['empresaIntegracao', 'agenda'])
                ->where('ativo', true)
                ->whereNotNull('agenda_automacao_id')
                ->where(function ($q) use ($agora) {
                    $q->whereNull('next_run_at')
                        ->orWhere('next_run_at', '<=', $agora);
                })
                ->whereHas('empresaIntegracao', fn ($q) => $q->where('ativo', true))
                ->whereHas('agenda', fn ($q) => $q->where('ativo', true)->where('frequencia', '!=', 'manual'))
                ->limit(100)
                ->get();

            $despachadas = 0;

            foreach ($vinculos as $vinculo) {
                $agenda = $vinculo->agenda;
                if (! $agenda) {
                    continue;
                }

                [$periodoInicio, $periodoFim] = $proximas->periodoConsulta(
                    (int) $vinculo->empresa_operadora_id,
                    $agora
                );

                $idempotency = sprintf(
                    'agenda:%d:%s',
                    $vinculo->id,
                    $agora->format('YmdHi')
                );

                $existe = AutomacaoExecucao::withoutGlobalScope('operadora')
                    ->where('empresa_operadora_id', $vinculo->empresa_operadora_id)
                    ->where('idempotency_key', $idempotency)
                    ->exists();

                if ($existe) {
                    continue;
                }

                $execucao = AutomacaoExecucao::create([
                    'empresa_operadora_id' => $vinculo->empresa_operadora_id,
                    'empresa_id' => $vinculo->empresaIntegracao->empresa_id,
                    'empresa_integracao_id' => $vinculo->empresa_integracao_id,
                    'portal_recurso_id' => $vinculo->portal_recurso_id,
                    'agenda_automacao_id' => $vinculo->agenda_automacao_id,
                    'gatilho' => 'agendado',
                    'periodo_inicio' => $periodoInicio->toDateString(),
                    'periodo_fim' => $periodoFim->toDateString(),
                    'status' => 'na_fila',
                    'mensagem_usuario' => 'Execução agendada enfileirada.',
                    'etapa_atual' => 'na_fila',
                    'parametros' => $vinculo->parametros ?? [],
                    'idempotency_key' => $idempotency,
                ]);

                ExecutarAutomacaoPortalJob::dispatch($execucao->id)->onQueue('automacoes');

                $vinculo->update([
                    'last_run_at' => $agora,
                    'next_run_at' => $proximas->calcular($agenda, $agora),
                ]);

                $despachadas++;
            }

            $this->info("Despachadas {$despachadas} execução(ões).");

            return self::SUCCESS;
        } finally {
            OperadoraContext::enableScope();
        }
    }
}
