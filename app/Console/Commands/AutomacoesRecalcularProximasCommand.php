<?php

namespace App\Console\Commands;

use App\Models\EmpresaIntegracaoRecurso;
use App\Services\AutomacaoFiscal\AgendaAutomacaoProximaExecucaoService;
use App\Services\OperadoraContext;
use Illuminate\Console\Command;

class AutomacoesRecalcularProximasCommand extends Command
{
    protected $signature = 'automacoes:recalcular-proximas-execucoes';

    protected $description = 'Recalcula next_run_at dos recursos com agenda ativa';

    public function handle(AgendaAutomacaoProximaExecucaoService $proximas): int
    {
        OperadoraContext::disableScope();

        try {
            $atualizados = 0;
            $vinculos = EmpresaIntegracaoRecurso::withoutGlobalScope('operadora')
                ->with('agenda')
                ->where('ativo', true)
                ->whereNotNull('agenda_automacao_id')
                ->get();

            foreach ($vinculos as $vinculo) {
                if (! $vinculo->agenda || ! $vinculo->agenda->ativo) {
                    continue;
                }

                $vinculo->update([
                    'next_run_at' => $proximas->calcular($vinculo->agenda, now()),
                ]);
                $atualizados++;
            }

            $this->info("Recalculados {$atualizados} recurso(s).");

            return self::SUCCESS;
        } finally {
            OperadoraContext::enableScope();
        }
    }
}
