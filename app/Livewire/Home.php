<?php

namespace App\Livewire;

use App\Models\ConversaoExtrato;
use App\Models\Importacao;
use App\Services\OperadoraContext;
use App\Services\OperadoraStorage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class Home extends Component
{
    public function render()
    {
        $user = Auth::user();
        $podeVerEstatisticas = $user && ($user->isSuperAdmin() || $user->isEscritorioAdmin() || $user->isGerente());
        $precisaSelecionarEscritorio = OperadoraContext::superAdminPrecisaSelecionarEscritorio();

        return view('livewire.home', [
            'podeVerEstatisticas' => $podeVerEstatisticas,
            'precisaSelecionarEscritorio' => $precisaSelecionarEscritorio,
            'totalImportacoesRecentes' => $podeVerEstatisticas && ! $precisaSelecionarEscritorio
                ? $this->contarImportacoesRecentes()
                : 0,
            'totalExportacoesMes' => $podeVerEstatisticas && ! $precisaSelecionarEscritorio
                ? $this->contarExportacoesDoMes()
                : 0,
            'totalConversoesRecentes' => $podeVerEstatisticas && ! $precisaSelecionarEscritorio
                ? $this->contarConversoesRecentes()
                : 0,
        ]);
    }

    private function contarImportacoesRecentes(): int
    {
        $query = Importacao::query()
            ->where('created_at', '>=', now()->startOfMonth());

        $empresaId = session('empresa_selecionada_id');
        if ($empresaId) {
            $query->where('empresa_id', (int) $empresaId);
        }

        return $query->count();
    }

    private function contarExportacoesDoMes(): int
    {
        if (! OperadoraContext::id()) {
            return 0;
        }

        $inicioMes = now()->startOfMonth()->timestamp;
        $total = 0;

        foreach (Storage::files(OperadoraStorage::diskPath('exports')) as $arquivo) {
            if (! str_starts_with(basename($arquivo), 'exportacao_')) {
                continue;
            }

            if (Storage::lastModified($arquivo) >= $inicioMes) {
                $total++;
            }
        }

        return $total;
    }

    private function contarConversoesRecentes(): int
    {
        $query = ConversaoExtrato::query()
            ->where('created_at', '>=', now()->startOfMonth());

        $empresaId = session('empresa_selecionada_id');
        if ($empresaId) {
            $query->where('empresa_id', (int) $empresaId);
        }

        return $query->count();
    }
}
