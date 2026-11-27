<?php

namespace App\Livewire\Nfse;

use App\Models\EmpresasOperadora;
use App\Services\Nfse\PainelNfse;
use App\Services\Nfse\PreparacaoNfse;
use App\Services\OperadoraContext;
use Livewire\Component;
use Livewire\WithPagination;

class Painel extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public string $mes = '';
    public string $busca = '';
    public string $filtro = 'todas';

    public function mount(): void
    {
        abort_unless(auth()->user()?->podeAcessarSistema(), 403);
        $this->mes = now()->format('Y-m');
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, ['mes', 'busca', 'filtro'], true)) {
            $this->resetPage();
            $this->resetValidation();
        }
    }

    public function filtrar(string $filtro): void
    {
        abort_unless(in_array($filtro, ['todas', 'habilitadas', 'rascunhos', 'lotes', 'previstas', 'recorrencias', 'importacoes', 'certificado'], true), 422);
        $this->filtro = $filtro;
        $this->resetPage();
    }

    public function abrirEmpresa(int $id, string $aba, string $situacao = 'rascunho')
    {
        abort_unless(in_array($aba, ['notas', 'importar', 'conferencia', 'recorrencias', 'configuracao'], true), 422);
        abort_unless(in_array($situacao, ['rascunho', 'em_lote'], true), 422);
        $preparo = app(PreparacaoNfse::class);
        $empresa = $preparo->empresa($id);
        abort_unless(EmpresasOperadora::query()->whereKey(OperadoraContext::requireId())->where('ativo', true)->exists(), 403);
        if ($aba === 'configuracao') {
            abort_unless($preparo->podeConfigurar(), 403);
        }
        $this->validate(['mes' => ['required', 'date_format:Y-m', 'regex:/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D']]);
        session(['empresa_selecionada_id' => $empresa->id]);
        return redirect()->route('nfse.central', ['mes' => $this->mes, 'aba' => $aba, 'situacao' => $situacao]);
    }

    public function render()
    {
        abort_unless(auth()->user()?->podeAcessarSistema(), 403);
        $semContexto = OperadoraContext::id() === null;
        $mesValido = (bool) preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $this->mes);
        $resumo = $empresas = null;
        if (! $semContexto && $mesValido) {
            $service = app(PainelNfse::class);
            $consulta = $service->consulta($this->mes);
            $resumo = $service->resumo($consulta);
            $empresas = $service->filtrar($consulta, $this->busca, $this->filtro)
                ->orderBy('empresa')->orderBy('id')->paginate(20);
        }

        return view('livewire.nfse.painel', compact('semContexto', 'mesValido', 'resumo', 'empresas'))->layout($this->layout);
    }
}
