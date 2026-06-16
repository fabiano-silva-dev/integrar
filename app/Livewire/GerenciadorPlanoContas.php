<?php

namespace App\Livewire;

use App\Models\Empresa;
use App\Models\ImportacaoPlanoConta;
use App\Models\PlanoConta;
use App\Services\Importacao\ImportadorPlanoContasService;
use App\Services\OperadoraContext;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GerenciadorPlanoContas extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public $empresa_id = null;
    public $filtroBusca = '';
    public $filtroAtivo = '';
    public $filtroTipo = '';
    public $filtroClassificacao = '';

    public $modalAberto = false;
    public $editandoId = null;
    public $codigo = '';
    public $codigo_reduzido = '';
    public $classificacao = '';
    public $descricao = '';
    public $tipo = '';
    public $natureza = '';
    public $nivel = '';
    public $codigo_pai = '';
    public $aceita_lancamento = true;
    public $ativo = true;

    public $mostrarHistorico = false;

    protected $queryString = [
        'filtroBusca' => ['except' => ''],
        'filtroAtivo' => ['except' => ''],
        'filtroTipo' => ['except' => ''],
        'filtroClassificacao' => ['except' => ''],
    ];

    protected function rules(): array
    {
        $empresaId = $this->empresa_id;
        $editandoId = $this->editandoId;

        return [
            'codigo' => [
                'required',
                'string',
                'max:50',
                function ($attribute, $value, $fail) use ($empresaId, $editandoId) {
                    $codigo = PlanoConta::normalizarCodigo($value);
                    $query = PlanoConta::where('empresa_id', $empresaId)->where('codigo', $codigo);
                    if ($editandoId) {
                        $query->where('id', '!=', $editandoId);
                    }
                    if ($query->exists()) {
                        $fail('Já existe uma conta com este código nesta empresa.');
                    }
                },
            ],
            'codigo_reduzido' => 'nullable|string|max:20',
            'classificacao' => 'nullable|string|max:50',
            'descricao' => 'required|string|max:255',
            'tipo' => 'nullable|in:analitica,sintetica',
            'natureza' => 'nullable|in:devedora,credora',
            'nivel' => 'nullable|integer|min:1|max:20',
            'codigo_pai' => 'nullable|string|max:50',
            'aceita_lancamento' => 'boolean',
            'ativo' => 'boolean',
        ];
    }

    public function mount(): void
    {
        $this->empresa_id = session('empresa_selecionada_id');
    }

    public function limparFiltros(): void
    {
        $this->filtroBusca = '';
        $this->filtroAtivo = '';
        $this->filtroTipo = '';
        $this->filtroClassificacao = '';
        $this->resetPage();
    }

    public function updatedFiltroClassificacao(): void
    {
        $this->resetPage();
    }

    public function abrirModal(?int $contaId = null): void
    {
        if ($contaId) {
            if (!$this->empresa_id) {
                return;
            }

            $conta = PlanoConta::where('empresa_id', $this->empresa_id)->findOrFail($contaId);
            $this->editandoId = $conta->id;
            $this->codigo = $conta->codigo;
            $this->codigo_reduzido = $conta->codigo_reduzido ?? '';
            $this->classificacao = $conta->classificacao ?? '';
            $this->descricao = $conta->descricao;
            $this->tipo = $conta->tipo ?? '';
            $this->natureza = $conta->natureza ?? '';
            $this->nivel = $conta->nivel ? (string) $conta->nivel : '';
            $this->codigo_pai = $conta->codigo_pai ?? '';
            $this->aceita_lancamento = $conta->aceita_lancamento;
            $this->ativo = $conta->ativo;
        } else {
            $this->limparFormulario();
        }

        $this->modalAberto = true;
    }

    public function salvar(): void
    {
        if (!$this->empresa_id) {
            $this->addError('codigo', 'Selecione uma empresa no cabeçalho antes de cadastrar contas.');
            return;
        }

        $empresa = OperadoraContext::resolveEmpresa($this->empresa_id);
        if (!$empresa) {
            $this->addError('codigo', 'Empresa inválida para o escritório atual.');
            return;
        }

        $this->validate();

        $codigo = PlanoConta::normalizarCodigo($this->codigo);
        $tipo = $this->tipo ?: null;
        $nivel = $this->nivel !== '' ? (int) $this->nivel : PlanoConta::inferirNivel($codigo);
        $codigoPai = PlanoConta::normalizarCodigo($this->codigo_pai);
        if ($codigoPai === '') {
            $codigoPai = PlanoConta::inferirCodigoPai($codigo);
        }

        $payload = [
            'codigo' => $codigo,
            'codigo_reduzido' => PlanoConta::normalizarCodigo($this->codigo_reduzido) ?: null,
            'classificacao' => PlanoConta::normalizarCodigo($this->classificacao) ?: null,
            'descricao' => trim($this->descricao),
            'tipo' => $tipo,
            'natureza' => $this->natureza ?: null,
            'nivel' => $nivel,
            'codigo_pai' => $codigoPai,
            'aceita_lancamento' => $this->aceita_lancamento,
            'ativo' => $this->ativo,
            'empresa_id' => $empresa->id,
        ];

        if ($this->editandoId) {
            $conta = PlanoConta::where('empresa_id', $empresa->id)->findOrFail($this->editandoId);
            $conta->update($payload);
            session()->flash('message', 'Conta atualizada com sucesso.');
        } else {
            PlanoConta::create($payload);
            session()->flash('message', 'Conta cadastrada com sucesso.');
        }

        $this->fecharModal();
    }

    public function toggleAtivo(int $contaId): void
    {
        if (!$this->empresa_id) {
            return;
        }

        $conta = PlanoConta::where('empresa_id', $this->empresa_id)->findOrFail($contaId);
        $conta->update(['ativo' => !$conta->ativo]);
    }

    public function fecharModal(): void
    {
        $this->modalAberto = false;
        $this->editandoId = null;
        $this->limparFormulario();
    }

    public function toggleHistorico(): void
    {
        $this->mostrarHistorico = !$this->mostrarHistorico;
    }

    public function baixarModelo(): StreamedResponse
    {
        $service = new ImportadorPlanoContasService();
        $conteudo = $service->conteudoModeloCsv();

        return response()->streamDownload(function () use ($conteudo) {
            echo $conteudo;
        }, 'modelo_plano_contas.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function limparFormulario(): void
    {
        $this->codigo = '';
        $this->codigo_reduzido = '';
        $this->classificacao = '';
        $this->descricao = '';
        $this->tipo = '';
        $this->natureza = '';
        $this->nivel = '';
        $this->codigo_pai = '';
        $this->aceita_lancamento = true;
        $this->ativo = true;
    }

    private function getContasQuery()
    {
        $query = PlanoConta::query();

        if ($this->empresa_id) {
            $query->where('empresa_id', $this->empresa_id);
        } else {
            $query->whereRaw('1 = 0');
        }

        if ($this->filtroBusca !== '') {
            $busca = '%' . $this->filtroBusca . '%';
            $query->where(function ($q) use ($busca) {
                if (ctype_digit(trim($this->filtroBusca))) {
                    $q->where('codigo', 'like', $busca)
                        ->orWhere('codigo_reduzido', 'like', $busca);
                } else {
                    $q->where('codigo', 'like', $busca)
                        ->orWhere('codigo_reduzido', 'like', $busca)
                        ->orWhere('descricao', 'like', $busca)
                        ->orWhere('classificacao', 'like', $busca);
                }
            });
        }

        if ($this->filtroAtivo !== '') {
            $query->where('ativo', $this->filtroAtivo);
        }

        if ($this->filtroTipo !== '') {
            $query->where('tipo', $this->filtroTipo);
        }

        if ($this->filtroClassificacao !== '') {
            $prefixo = $this->filtroClassificacao;
            $query->where(function ($q) use ($prefixo) {
                $q->where('classificacao', $prefixo)
                    ->orWhere('classificacao', 'like', $prefixo . '.%');
            });
        }

        return $query
            ->orderByRaw('COALESCE(classificacao, codigo)')
            ->orderBy('descricao')
            ->orderBy('codigo');
    }

    public function render()
    {
        $contas = $this->getContasQuery()->paginate(20);
        $empresaAtual = $this->empresa_id ? Empresa::find($this->empresa_id) : null;

        $historico = collect();
        if ($this->mostrarHistorico && $this->empresa_id) {
            $historico = ImportacaoPlanoConta::where('empresa_id', $this->empresa_id)
                ->with('user')
                ->orderByDesc('created_at')
                ->limit(15)
                ->get();
        }

        $classificacoesSinteticas = collect();
        if ($this->empresa_id) {
            $classificacoesSinteticas = PlanoConta::where('empresa_id', $this->empresa_id)
                ->where('tipo', 'sintetica')
                ->whereNotNull('classificacao')
                ->where('classificacao', '!=', '')
                ->orderBy('classificacao')
                ->get(['classificacao', 'descricao', 'nivel']);
        }

        return view('livewire.gerenciador-plano-contas', [
            'contas' => $contas,
            'empresaAtual' => $empresaAtual,
            'historico' => $historico,
            'classificacoesSinteticas' => $classificacoesSinteticas,
        ]);
    }
}
