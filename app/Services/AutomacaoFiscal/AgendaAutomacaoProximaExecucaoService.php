<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\AgendaAutomacao;
use App\Models\AutomacaoConfiguracao;
use Carbon\Carbon;

class AgendaAutomacaoProximaExecucaoService
{
    public function calcular(AgendaAutomacao $agenda, Carbon $referencia): ?Carbon
    {
        if (! $agenda->ativo || $agenda->frequencia === 'manual') {
            return null;
        }

        $tz = $agenda->timezone ?: 'America/Sao_Paulo';
        $agora = $referencia->copy()->timezone($tz);

        return match ($agenda->frequencia) {
            'semanal' => $this->proximaNaGrade($agenda, $agora, fn (Carbon $dia) => in_array($dia->isoWeekday(), $this->diasSemana($agenda), true)),
            'mensal' => $this->proximaNaGrade($agenda, $agora, fn (Carbon $dia) => in_array($dia->day, $this->diasMes($agenda, $dia), true)),
            'intervalo' => $agora->copy()->addMinutes($this->intervaloMinutos($agenda)),
            default => $this->proximaNaGrade($agenda, $agora, fn () => true),
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodoConsulta(int $operadoraId, Carbon $referencia): array
    {
        $config = AutomacaoConfiguracao::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $operadoraId)
            ->first();

        $dias = max(1, (int) ($config?->periodo_padrao_dias ?? 31));
        $tz = $config?->timezone ?: 'America/Sao_Paulo';
        $fim = $referencia->copy()->timezone($tz)->startOfDay();
        $inicio = $fim->copy()->subDays($dias);

        return [$inicio, $fim];
    }

    /**
     * @param  callable(Carbon): bool  $diaPermitido
     */
    private function proximaNaGrade(AgendaAutomacao $agenda, Carbon $agora, callable $diaPermitido): Carbon
    {
        $horarios = $this->horarios($agenda);
        $cursor = $agora->copy()->startOfDay();

        for ($i = 0; $i < 400; $i++) {
            if ($diaPermitido($cursor)) {
                foreach ($horarios as [$hora, $minuto]) {
                    $candidato = $cursor->copy()->setTime($hora, $minuto, 0);
                    if ($candidato->greaterThan($agora)) {
                        return $candidato;
                    }
                }
            }

            $cursor->addDay();
        }

        $primeiro = $horarios[0];

        return $agora->copy()->addDay()->setTime($primeiro[0], $primeiro[1], 0);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function horarios(AgendaAutomacao $agenda): array
    {
        $lista = $agenda->horarios ?? [];
        if (! is_array($lista) || $lista === []) {
            $lista = ['06:00'];
        }

        $saida = [];
        foreach ($lista as $horario) {
            [$h, $m] = array_pad(explode(':', (string) $horario), 2, '0');
            $saida[] = [(int) $h, (int) $m];
        }

        usort($saida, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $saida;
    }

    /**
     * @return list<int>
     */
    private function diasSemana(AgendaAutomacao $agenda): array
    {
        $dias = $agenda->dias_semana ?? [];
        if (! is_array($dias) || $dias === []) {
            return [1, 2, 3, 4, 5, 6, 7];
        }

        $normalizados = [];
        foreach ($dias as $dia) {
            $n = (int) $dia;
            $normalizados[] = $n === 0 ? 7 : $n;
        }

        return array_values(array_unique($normalizados));
    }

    /**
     * @return list<int>
     */
    private function diasMes(AgendaAutomacao $agenda, Carbon $dia): array
    {
        $dias = $agenda->dias_mes ?? [];
        if (! is_array($dias) || $dias === []) {
            $dias = [1];
        }

        $ultimo = $dia->daysInMonth;
        $normalizados = [];
        foreach ($dias as $item) {
            $n = (int) $item;
            if ($n < 1) {
                continue;
            }
            $normalizados[] = min($n, $ultimo);
        }

        return array_values(array_unique($normalizados === [] ? [1] : $normalizados));
    }

    private function intervaloMinutos(AgendaAutomacao $agenda): int
    {
        $minutos = (int) ($agenda->intervalo ?? 60);

        return max(5, $minutos);
    }
}
