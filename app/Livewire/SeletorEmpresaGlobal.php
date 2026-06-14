<?php

namespace App\Livewire;

use App\Models\Empresa;
use App\Services\OperadoraContext;
use Livewire\Component;

class SeletorEmpresaGlobal extends Component
{
    public $empresaSelecionada;

    public function mount(): void
    {
        $this->empresaSelecionada = session('empresa_selecionada_id');

        if ($this->empresaSelecionada) {
            session(['empresa_selecionada_id' => $this->empresaSelecionada]);
        }
    }

    public function updatedEmpresaSelecionada($value): void
    {
        if ($value) {
            session(['empresa_selecionada_id' => (int) $value]);
        } else {
            session()->forget('empresa_selecionada_id');
        }
        session()->save();

        $this->redirect(request()->fullUrl(), navigate: false);
    }

    public function render()
    {
        $empresas = Empresa::orderBy('nome')->get();
        $empresaAtual = $empresas->firstWhere('id', $this->empresaSelecionada);

        if ($this->empresaSelecionada && !$empresaAtual) {
            session()->forget('empresa_selecionada_id');
            $this->empresaSelecionada = null;
        }

        return view('livewire.seletor-empresa-global', [
            'empresas' => $empresas,
            'empresaAtual' => $empresaAtual,
        ]);
    }
}
