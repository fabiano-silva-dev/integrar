<?php

namespace Tests\Unit;

use App\Models\AgendaAutomacao;
use App\Services\AutomacaoFiscal\AgendaAutomacaoProximaExecucaoService;
use Carbon\Carbon;
use Tests\TestCase;

class AgendaAutomacaoProximaExecucaoServiceTest extends TestCase
{
    public function test_diaria_usa_proximo_horario(): void
    {
        $agenda = $this->agenda(['frequencia' => 'diaria', 'horarios' => ['06:00', '18:00']]);
        $service = new AgendaAutomacaoProximaExecucaoService;

        $proxima = $service->calcular($agenda, Carbon::parse('2026-08-10 10:00:00', 'America/Sao_Paulo'));

        $this->assertSame('2026-08-10 18:00:00', $proxima?->format('Y-m-d H:i:s'));
    }

    public function test_semanal_respeita_dias_da_semana(): void
    {
        $agenda = $this->agenda([
            'frequencia' => 'semanal',
            'horarios' => ['06:00'],
            'dias_semana' => [1],
        ]);
        $service = new AgendaAutomacaoProximaExecucaoService;

        $proxima = $service->calcular($agenda, Carbon::parse('2026-08-10 10:00:00', 'America/Sao_Paulo'));

        $this->assertSame(1, $proxima?->isoWeekday());
        $this->assertSame('2026-08-17 06:00:00', $proxima?->format('Y-m-d H:i:s'));
    }

    public function test_mensal_respeita_dia_do_mes(): void
    {
        $agenda = $this->agenda([
            'frequencia' => 'mensal',
            'horarios' => ['08:00'],
            'dias_mes' => [15],
        ]);
        $service = new AgendaAutomacaoProximaExecucaoService;

        $proxima = $service->calcular($agenda, Carbon::parse('2026-08-16 09:00:00', 'America/Sao_Paulo'));

        $this->assertSame('2026-09-15 08:00:00', $proxima?->format('Y-m-d H:i:s'));
    }

    public function test_intervalo_soma_minutos(): void
    {
        $agenda = $this->agenda([
            'frequencia' => 'intervalo',
            'intervalo' => 45,
        ]);
        $service = new AgendaAutomacaoProximaExecucaoService;

        $proxima = $service->calcular($agenda, Carbon::parse('2026-08-10 10:00:00', 'America/Sao_Paulo'));

        $this->assertSame('2026-08-10 10:45:00', $proxima?->format('Y-m-d H:i:s'));
    }

    public function test_manual_nao_calcula(): void
    {
        $agenda = $this->agenda(['frequencia' => 'manual']);
        $service = new AgendaAutomacaoProximaExecucaoService;

        $this->assertNull($service->calcular($agenda, now()));
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function agenda(array $attrs): AgendaAutomacao
    {
        $agenda = new AgendaAutomacao;
        $agenda->ativo = true;
        $agenda->timezone = 'America/Sao_Paulo';
        $agenda->horarios = ['06:00'];
        $agenda->fill($attrs);

        return $agenda;
    }
}
