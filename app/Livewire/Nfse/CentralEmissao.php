<?php

namespace App\Livewire\Nfse;

use App\Models\Empresa;
use App\Models\Nfse\Configuracao;
use App\Models\Nfse\Nota;
use App\Models\Nfse\Servico;
use App\Models\Nfse\Tomador;
use App\Services\Nfse\CatalogoMunicipalNfse;
use App\Services\Nfse\CatalogoServicosNfse;
use App\Services\Nfse\PreparacaoNfse;
use App\Services\OperadoraContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class CentralEmissao extends Component
{
    use WithFileUploads;
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
    public string $buscaCodigoTributario = '';
    #[Locked]
    public ?string $pesquisaTributaria = null;
    public $tabelaMunicipal = null;
    public array $nota = [];
    public string $competencia = '';

    #[Locked]
    public string $situacaoInicial = 'rascunho';

    public function mount(): void
    {
        $id = OperadoraContext::id();
        if ($id !== null) {
            $empresa = Empresa::query()->where('empresa_operadora_id', $id)->where('ativo', true)
                ->find(session('empresa_selecionada_id'));
            $this->empresaId = $empresa?->id;
        }
        $mes = request()->query('mes');
        $this->competencia = is_string($mes) && preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $mes) ? $mes : now()->format('Y-m');
        $this->novaNota();
        $aba = request()->query('aba');
        if ($this->empresaId && is_string($aba) && in_array($aba, ['notas', 'importar', 'conferencia', 'recorrencias', 'configuracao'], true)) {
            $this->mudarAba($aba);
            if ($aba === 'conferencia' && request()->query('situacao') === 'em_lote') {
                $this->situacaoInicial = 'em_lote';
            }
        }
    }

    private function empresa(): Empresa
    {
        abort_unless($this->empresaId, 422, 'Selecione uma empresa no cabeçalho.');
        return app(PreparacaoNfse::class)->empresa($this->empresaId);
    }

    public function mudarAba(string $aba): void
    {
        $empresa = $this->empresa();
        abort_unless(in_array($aba, ['notas', 'tomadores', 'servicos', 'importar', 'conferencia', 'recorrencias', 'configuracao'], true), 404);
        if ($aba === 'configuracao') {
            abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        }
        $this->aba = $aba;
        $this->situacaoInicial = 'rascunho';
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
        $this->fecharPesquisaTributaria();
        $this->reset('tabelaMunicipal');
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
        $this->fecharPesquisaTributaria();
        $this->form = $registro->only($registro->getFillable());
        $this->resetValidation();
    }

    public function abrirPesquisaTributaria(string $tipo): void
    {
        $this->empresa();
        abort_unless($this->aba === 'servicos' && in_array($tipo, ['nacional', 'nbs'], true), 404);
        $this->pesquisaTributaria = $tipo;
        $this->buscaCodigoTributario = '';
    }

    public function fecharPesquisaTributaria(): void
    {
        $this->pesquisaTributaria = null;
        $this->buscaCodigoTributario = '';
    }

    public function selecionarCodigoTributario(string $tipo, string $codigo): void
    {
        $this->empresa();
        abort_unless($this->aba === 'servicos' && $tipo === $this->pesquisaTributaria && in_array($tipo, ['nacional', 'nbs'], true), 404);
        $descricao = app(CatalogoServicosNfse::class)->descricao($tipo, $codigo);
        abort_unless($descricao !== null, 422, 'Código inexistente no Anexo B.');
        if ($tipo === 'nacional') {
            if (($this->form['codigo_tributacao_nacional'] ?? '') !== $codigo) {
                $this->form['codigo_tributacao_municipal'] = '';
                // O NBS pode corresponder a outro serviço. Não selecionar automaticamente.
                $this->form['nbs'] = '';
            }
            $this->form['codigo_tributacao_nacional'] = $codigo;
        } else {
            $this->form['nbs'] = $codigo;
        }
        $this->fecharPesquisaTributaria();
    }

    public function limparNbs(): void
    {
        $this->empresa();
        abort_unless($this->aba === 'servicos', 404);
        $this->form['nbs'] = '';
    }

    public function importarTabelaMunicipal(): void
    {
        $empresa = $this->empresa();
        abort_unless($this->aba === 'servicos' && app(PreparacaoNfse::class)->podeConfigurar(), 403);
        $this->validate(['tabelaMunicipal' => 'required|file|max:2048|extensions:csv,txt']);
        $csv = file_get_contents($this->tabelaMunicipal->getRealPath(), false, null, 0, 2097153);
        $quantidade = app(CatalogoMunicipalNfse::class)->importar($empresa->id, $csv === false ? '' : $csv);
        $this->reset('tabelaMunicipal');
        session()->flash('nfse_mensagem', $quantidade.' códigos complementares carregados para o município da empresa.');
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
        $data = preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $this->competencia) && $this->competencia !== now()->format('Y-m')
            ? $this->competencia.'-01' : now()->toDateString();
        $this->nota = ['tomador_id' => '', 'servico_id' => '', 'competencia' => $data, 'descricao' => '', 'valor' => ''];
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
        $codigosTributarios = $codigosMunicipais = [];
        $descricaoNacional = $descricaoNbs = null;
        $catalogoErro = false;
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
        if ($empresa && $this->aba === 'servicos') {
            try {
                $catalogo = app(CatalogoServicosNfse::class);
                $descricaoNacional = $catalogo->descricao('nacional', (string) ($this->form['codigo_tributacao_nacional'] ?? ''));
                $descricaoNbs = $catalogo->descricao('nbs', (string) ($this->form['nbs'] ?? ''));
                if ($this->pesquisaTributaria !== null) {
                    $codigosTributarios = $catalogo->buscar($this->pesquisaTributaria, $this->buscaCodigoTributario);
                }
            } catch (\RuntimeException) {
                $catalogoErro = true;
            }
            $codigosMunicipais = app(CatalogoMunicipalNfse::class)->consultar($empresa->id, (string) ($this->form['codigo_tributacao_nacional'] ?? ''));
        }
        return view('livewire.nfse.central-emissao', compact('empresa', 'registros', 'tomadores', 'servicos', 'configuracao',
            'codigosTributarios', 'codigosMunicipais', 'descricaoNacional', 'descricaoNbs', 'catalogoErro'))
            ->layout($this->layout);
    }
}
