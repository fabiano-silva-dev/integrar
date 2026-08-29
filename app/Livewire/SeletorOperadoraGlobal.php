<?php

namespace App\Livewire;

use App\Models\EmpresasOperadora;
use App\Services\OperadoraContext;
use Livewire\Component;

class SeletorOperadoraGlobal extends Component
{
    public $operadoraSelecionada;

    public function mount(): void
    {
        $this->operadoraSelecionada = session('operadora_context_id');
    }

    public function updatedOperadoraSelecionada($value): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403, 'Apenas super admin pode trocar de escritório.');
        }

        if ($value) {
            OperadoraContext::set((int) $value);
        } else {
            OperadoraContext::clear();
        }

        session()->save();
        $this->redirect(request()->fullUrl(), navigate: false);
    }

    public function render()
    {
        $operadoras = collect();
        $operadoraAtual = null;

        if (auth()->user()?->isSuperAdmin()) {
            $operadoras = EmpresasOperadora::where('ativo', true)
                ->orderBy('nome_fantasia')
                ->orderBy('razao_social')
                ->get();

            $operadoraAtual = $operadoras->firstWhere('id', $this->operadoraSelecionada);
        }

        return view('livewire.seletor-operadora-global', [
            'operadoras' => $operadoras,
            'operadoraAtual' => $operadoraAtual,
        ]);
    }
}
