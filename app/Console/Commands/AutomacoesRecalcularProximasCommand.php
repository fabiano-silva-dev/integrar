<?php

namespace App\Console\Commands;

use App\Models\EmpresaIntegracaoRecurso;
use App\Services\OperadoraContext;
use Illuminate\Console\Command;

class AutomacoesRecalcularProximasCommand extends Command
{
    protected $signature = 'automacoes:recalcular-proximas-execucoes';

    protected $description = 'Recalcula next_run_at dos recursos com agenda ativa';

    public function handle(): int
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
                if (!$vinculo->agenda || !$vinculo->agenda->ativo) {
                    continue;
                }

                $horario = $vinculo->agenda->horarios[0] ?? '06:00';
                [$h, $m] = array_pad(explode(':', $horario), 2, '0');
                $proxima = now()->copy()->setTime((int) $h, (int) $m);
                if ($proxima->lessThanOrEqualTo(now())) {
                    $proxima->addDay();
                }

                $vinculo->update(['next_run_at' => $proxima]);
                $atualizados++;
            }

            $this->info("Recalculados {$atualizados} recurso(s).");

            return self::SUCCESS;
        } finally {
            OperadoraContext::enableScope();
        }
    }
}
