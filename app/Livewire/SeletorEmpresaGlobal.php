<?php

namespace App\Livewire;

use App\Models\Empresa;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SeletorEmpresaGlobal extends Component
{
    /**
     * ID da empresa selecionada globalmente.
     */
    public $empresaSelecionada;

    public function mount(): void
    {
        $user = Auth::user();

        $this->empresaSelecionada = session('empresa_selecionada_id')
            ?? ($user ? $user->empresa_id : null)
            ?? Empresa::orderBy('nome')->value('id');

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

        return view('livewire.seletor-empresa-global', [
            'empresas' => $empresas,
            'empresaAtual' => $empresaAtual,
        ]);
    }
}

