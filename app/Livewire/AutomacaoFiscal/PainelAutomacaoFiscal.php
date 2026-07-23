<?php

namespace App\Livewire\AutomacaoFiscal;

use App\Models\AutomacaoExecucao;
use App\Models\CertificadoDigital;
use App\Models\EmpresaIntegracao;
use App\Services\OperadoraContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class PainelAutomacaoFiscal extends Component
{
    protected $layout = 'components.layouts.app';

    public function render()
    {
        $precisaSelecionar = OperadoraContext::superAdminPrecisaSelecionarEscritorio();

        $totais = [
            'integracoes_ativas' => 0,
            'execucoes_hoje' => 0,
            'falhas_7d' => 0,
            'certificados_a_vencer' => 0,
        ];
        $ultimas = collect();
        $certificados = collect();

        if (!$precisaSelecionar && OperadoraContext::id()) {
            $totais['integracoes_ativas'] = EmpresaIntegracao::query()->where('ativo', true)->count();
            $totais['execucoes_hoje'] = AutomacaoExecucao::query()->whereDate('created_at', today())->count();
            $totais['falhas_7d'] = AutomacaoExecucao::query()
                ->where('status', 'falha')
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            $avisoDias = 30;
            $totais['certificados_a_vencer'] = CertificadoDigital::query()
                ->where('ativo', true)
                ->whereNotNull('valido_ate')
                ->whereBetween('valido_ate', [now(), now()->addDays($avisoDias)])
                ->count();

            $ultimas = AutomacaoExecucao::query()
                ->with(['empresa', 'portalRecurso.portal'])
                ->orderByDesc('id')
                ->limit(15)
                ->get();

            $certificados = CertificadoDigital::query()
                ->where('ativo', true)
                ->whereNotNull('valido_ate')
                ->where('valido_ate', '<=', now()->addDays($avisoDias))
                ->orderBy('valido_ate')
                ->limit(10)
                ->get();
        }

        return view('livewire.automacao-fiscal.painel-automacao-fiscal', [
            'precisaSelecionarEscritorio' => $precisaSelecionar,
            'totais' => $totais,
            'ultimas' => $ultimas,
            'certificados' => $certificados,
            'podeConfigurar' => Auth::user() && (Auth::user()->isSuperAdmin() || in_array(Auth::user()->role, ['admin', 'gerente'], true)),
            'podeExecutar' => Auth::user() && (Auth::user()->isSuperAdmin() || Auth::user()->isAdmin()),
        ]);
    }
}
