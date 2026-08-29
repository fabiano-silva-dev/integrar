<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\AgendaAutomacao;
use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\EmpresaIntegracao;
use App\Models\EmpresaIntegracaoRecurso;
use App\Models\PortalIntegracao;
use App\Models\PortalRecurso;
use App\Services\OperadoraContext;
use Illuminate\Support\Facades\DB;

class EmpresaIntegracaoService
{
    /**
     * @param  array<int, array{portal_codigo: string, ativo: bool, certificado_digital_id?: int|null, recursos: array<string, array{ativo: bool, agenda_automacao_id?: int|null, parametros?: array<string, mixed>}>}>  $portais
     */
    public function sincronizar(Empresa $empresa, array $portais): void
    {
        $operadoraId = (int) $empresa->empresa_operadora_id;
        $contextoId = OperadoraContext::id();

        if ($contextoId !== null && $operadoraId !== (int) $contextoId) {
            abort(403, 'Empresa fora do escritório atual.');
        }

        DB::transaction(function () use ($empresa, $portais, $operadoraId) {
            foreach ($portais as $portalConfig) {
                $portal = PortalIntegracao::query()
                    ->where('codigo', $portalConfig['portal_codigo'])
                    ->where('ativo', true)
                    ->first();

                if (! $portal) {
                    continue;
                }

                $integracao = EmpresaIntegracao::query()->updateOrCreate(
                    [
                        'empresa_operadora_id' => $empresa->empresa_operadora_id,
                        'empresa_id' => $empresa->id,
                        'portal_integracao_id' => $portal->id,
                    ],
                    [
                        'ativo' => (bool) ($portalConfig['ativo'] ?? false),
                        'modo_autenticacao' => 'certificado_a1',
                        'certificado_digital_id' => $this->resolverCertificadoId(
                            $portalConfig['certificado_digital_id'] ?? null,
                            $operadoraId
                        ),
                        'status_configuracao' => ($portalConfig['ativo'] ?? false) ? 'configurado' : 'pendente',
                    ]
                );

                $recursosInput = $portalConfig['recursos'] ?? [];

                foreach ($portal->recursos()->where('ativo', true)->get() as $recurso) {
                    /** @var PortalRecurso $recurso */
                    if ($recurso->codigo === 'validar_acesso') {
                        continue;
                    }

                    $cfg = $recursosInput[$recurso->codigo] ?? ['ativo' => false];

                    EmpresaIntegracaoRecurso::query()->updateOrCreate(
                        [
                            'empresa_integracao_id' => $integracao->id,
                            'portal_recurso_id' => $recurso->id,
                        ],
                        [
                            'empresa_operadora_id' => $empresa->empresa_operadora_id,
                            'ativo' => (bool) ($cfg['ativo'] ?? false),
                            'agenda_automacao_id' => $this->resolverAgendaId(
                                $cfg['agenda_automacao_id'] ?? null,
                                $operadoraId
                            ),
                            'parametros' => $cfg['parametros'] ?? null,
                        ]
                    );
                }
            }
        });
    }

    private function resolverCertificadoId(mixed $id, int $operadoraId): ?int
    {
        if ($id === null || $id === '' || $id === false) {
            return null;
        }

        return CertificadoDigital::query()
            ->where('empresa_operadora_id', $operadoraId)
            ->findOrFail((int) $id)
            ->id;
    }

    private function resolverAgendaId(mixed $id, int $operadoraId): ?int
    {
        if ($id === null || $id === '' || $id === false) {
            return null;
        }

        return AgendaAutomacao::query()
            ->where('empresa_operadora_id', $operadoraId)
            ->findOrFail((int) $id)
            ->id;
    }
}
