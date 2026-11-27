<?php

namespace App\Livewire\Nfse;

use App\Models\Empresa;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\Nota;
use App\Models\Nfse\Servico;
use App\Models\Nfse\Tomador;
use App\Services\Nfse\PreparacaoNfse;
use App\Services\OperadoraContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class CentralEmissao extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    #[Locked]
    public ?int $empresaId = null;

    #[Locked]
    public ?int $editandoId = null;

    #[Locked]
    public string $intencao = '';

    #[Locked]
    public string $aba = 'notas';

    public string $busca = '';
    public array $form = [];
    public array $nota = [];
    public string $competencia = '';

    public function mount(): void
    {
        $id = OperadoraContext::id();
        if ($id !== null) {
            $empresa = Empresa::query()->where('empresa_operadora_id', $id)->where('ativo', true)
                ->find(session('empresa_selecionada_id'));
            $this->empresaId = $empresa?->id;
        }
        $this->competencia = now()->format('Y-m');
        $this->novaNota();
    }

    private function empresa(): Empresa
    {
        abort_unless($this->empresaId, 422, 'Selecione uma empresa no cabeçalho.');
        return app(PreparacaoNfse::class)->empresa($this->empresaId);
    }

    public function mudarAba(string $aba): void
    {
        $empresa = $this->empresa();
        abort_unless(in_array($aba, ['notas', 'tomadores', 'servicos', 'configuracao'], true), 404);
        if ($aba === 'configuracao') {
            abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        }
        $this->aba = $aba;
        $this->busca = '';
        $this->resetPage();
        $this->novoCadastro();
        if ($aba === 'configuracao') {
            $registro = Configuracao::daEmpresa($empresa)->first();
            $this->form = $registro?->only($registro->getFillable()) ?? ['habilitado_preparo' => false, 'regime_tributario' => '', 'serie_dps' => ''];
        }
    }

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function updatedCompetencia(): void
    {
        $this->resetPage();
    }

    public function novoCadastro(): void
    {
        $this->resetValidation();
        $this->editandoId = null;
        $this->form = match ($this->aba) {
            'tomadores' => array_fill_keys((new Tomador)->getFillable(), ''),
            'servicos' => array_fill_keys((new Servico)->getFillable(), ''),
            default => [],
        };
        if (in_array($this->aba, ['tomadores', 'servicos'], true)) {
            $this->form['ativo'] = true;
        }
    }

    public function editar(int $id): void
    {
        $empresa = $this->empresa();
        $registro = match ($this->aba) {
            'tomadores' => Tomador::daEmpresa($empresa)->findOrFail($id),
            'servicos' => Servico::daEmpresa($empresa)->findOrFail($id),
            default => abort(404),
        };
        $this->editandoId = $registro->id;
        $this->form = $registro->only($registro->getFillable());
        $this->resetValidation();
    }

    public function salvarCadastro(): void
    {
        $empresa = $this->empresa();
        $service = app(PreparacaoNfse::class);
        $dados = array_map(fn ($valor) => $valor === '' ? null : $valor, $this->form);
        match ($this->aba) {
            'tomadores' => $service->salvarTomador($empresa->id, $this->editandoId, $dados),
            'servicos' => $service->salvarServico($empresa->id, $this->editandoId, $dados),
            'configuracao' => $service->salvarConfiguracao($empresa->id, $dados),
            default => abort(404),
        };
        if ($this->aba !== 'configuracao') {
            $this->novoCadastro();
        }
        session()->flash('nfse_mensagem', 'Cadastro salvo.');
    }

    public function novaNota(): void
    {
        $this->intencao = (string) Str::uuid();
        $this->nota = ['tomador_id' => '', 'servico_id' => '', 'competencia' => now()->toDateString(), 'descricao' => '', 'valor' => ''];
        $this->resetValidation();
    }

    public function updatedNotaServicoId($id): void
    {
        if (! $id) {
            return;
        }
        $servico = Servico::daEmpresa($this->empresa())->where('ativo', true)->findOrFail($id);
        $this->nota['descricao'] = $servico->descricao;
        $this->nota['valor'] = $servico->valor_padrao ?? '';
    }

    public function salvarNota(): void
    {
        app(PreparacaoNfse::class)->salvarRascunho($this->empresa()->id, $this->intencao, $this->nota);
        $this->novaNota();
        $this->resetPage();
        session()->flash('nfse_mensagem', 'Rascunho salvo. Nenhum documento fiscal foi transmitido.');
    }

    public function render()
    {
        $empresa = $this->empresaId ? $this->empresa() : null;
        $registros = null;
        $tomadores = collect();
        $servicos = collect();
        $configuracao = null;
        if ($empresa) {
            $configuracao = Configuracao::daEmpresa($empresa)->first();
            if ($this->aba === 'notas') {
                $tomadores = Tomador::daEmpresa($empresa)->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'documento']);
                $servicos = Servico::daEmpresa($empresa)->where('ativo', true)->orderBy('nome')->get(['id', 'nome']);
                $query = Nota::daEmpresa($empresa)->latest('id');
                if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->competencia)) {
                    $inicio = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $this->competencia);
                    $query->whereBetween('competencia', [$inicio->toDateString(), $inicio->endOfMonth()->toDateString()]);
                } else {
                    $query->whereRaw('1 = 0');
                }
                $registros = $query->paginate(20);
            } elseif (in_array($this->aba, ['tomadores', 'servicos'], true)) {
                $query = $this->aba === 'tomadores' ? Tomador::daEmpresa($empresa) : Servico::daEmpresa($empresa);
                $registros = $query->where(function ($q) {
                    $q->where('nome', 'like', '%'.$this->busca.'%');
                    $q->orWhere($this->aba === 'tomadores' ? 'documento' : 'codigo', 'like', '%'.$this->busca.'%');
                })->orderBy('nome')->paginate(20);
            }
        }
        return view('livewire.nfse.central-emissao', compact('empresa', 'registros', 'tomadores', 'servicos', 'configuracao'))
            ->layout($this->layout);
    }
}
