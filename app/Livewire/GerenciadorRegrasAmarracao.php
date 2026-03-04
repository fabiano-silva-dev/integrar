<?php

namespace App\Livewire;

use App\Models\Empresa;
use App\Models\HistoricoPadraoLayout;
use App\Models\RegraAmarracaoDescricao;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class GerenciadorRegrasAmarracao extends Component
{
    use WithPagination;

    public $empresa_id = null;
    public $layout_avancado = '';

    /** Pesquisa e ordenação */
    public $busca = '';
    public $ordenacao = 'palavra_chave';
    public $direcao = 'asc';
    public $perPage = 50;

    /** Edição de regra existente */
    public $editando = false;
    public $regraId = null;
    public $edit_palavra_chave = '';
    public $edit_parte_digitavel = '';
    public $edit_conta_contrapartida = '';
    public $edit_descricao = '';
    public $edit_ativo = true;

    public static function getLayoutsAvancado(): array
    {
        return [
            '' => 'Selecione o layout',
            'dominio' => 'Domínio (TXT)',
            'grafeno' => 'Grafeno (PDF)',
            'sicoob' => 'Sicoob (PDF)',
            'caixa_federal' => 'Caixa Econômica Federal (PDF)',
            'ofx' => 'Formato OFX',
            'registros' => 'Connectere > Contas Financeiras > Diário (CSV)',
            'sicredi' => 'SICREDI (PDF)',
        ];
    }

    public function mount()
    {
        $user = Auth::user();

        $this->empresa_id = $this->empresa_id
            ?? session('empresa_selecionada_id')
            ?? ($user ? $user->empresa_id : null)
            ?? Empresa::orderBy('nome')->value('id');
    }

    /** Retorna o registro de histórico padrão para o layout (e opcionalmente empresa) selecionado */
    public function getHistoricoPadraoLayoutProperty()
    {
        if (!$this->layout_avancado || !$this->empresa_id) {
            return null;
        }
        return HistoricoPadraoLayout::where('layout_avancado', $this->layout_avancado)
            ->where(function ($q) {
                $q->where('empresa_id', $this->empresa_id)->orWhereNull('empresa_id');
            })
            ->orderByRaw('empresa_id IS NULL ASC')
            ->first();
    }

    /**
     * Garante que existe ao menos uma regra (vazia) por descrição padrão do layout.
     * Usa todas as descrições (incluindo novas da importação), para que novas descrições
     * detectadas na importação apareçam automaticamente para o usuário configurar.
     */
    protected function ensureRegrasVazias(): void
    {
        $historico = $this->historicoPadraoLayout;
        if (!$historico || !$this->empresa_id || !$this->layout_avancado) {
            return;
        }
        $descricoes = $historico->descricoes()->orderBy('descricao')->get();
        foreach ($descricoes as $desc) {
            $existe = RegraAmarracaoDescricao::where('empresa_id', $this->empresa_id)
                ->where('layout_avancado', $this->layout_avancado)
                ->where('palavra_chave', $desc->descricao)
                ->where('tipo_busca', 'starts_with')
                ->whereNull('parte_digitavel')
                ->exists();
            if (!$existe) {
                RegraAmarracaoDescricao::create([
                    'empresa_id' => $this->empresa_id,
                    'layout_avancado' => $this->layout_avancado,
                    'palavra_chave' => $desc->descricao,
                    'parte_digitavel' => null,
                    'tipo_busca' => 'starts_with',
                    'conta_contrapartida' => null,
                    'conta_debito' => null,
                    'conta_credito' => null,
                    'prioridade' => 0,
                    'ativo' => true,
                ]);
            }
        }
    }

    public function editarRegra(int $id)
    {
        $regra = RegraAmarracaoDescricao::find($id);
        if (!$regra) {
            return;
        }
        $this->regraId = $regra->id;
        $this->edit_palavra_chave = $regra->palavra_chave ?? '';
        $this->edit_parte_digitavel = $regra->parte_digitavel ?? '';
        $this->edit_conta_contrapartida = $regra->conta_contrapartida ?? $regra->conta_credito ?? $regra->conta_debito ?? '';
        $this->edit_descricao = $regra->descricao ?? '';
        $this->edit_ativo = $regra->ativo;
        $this->editando = true;
    }

    public function atualizarRegra()
    {
        $regra = RegraAmarracaoDescricao::find($this->regraId);
        if (!$regra) {
            return;
        }
        $this->validate([
            'edit_parte_digitavel' => 'nullable|string|max:255',
            'edit_conta_contrapartida' => 'nullable|string|max:255',
            'edit_descricao' => 'nullable|string|max:500',
        ]);

        $regra->update([
            'parte_digitavel' => trim($this->edit_parte_digitavel ?? '') ?: null,
            'conta_contrapartida' => trim($this->edit_conta_contrapartida ?? '') ?: null,
            'descricao' => trim($this->edit_descricao ?? '') ?: null,
            'conta_debito' => null,
            'conta_credito' => null,
            'ativo' => $this->edit_ativo,
        ]);

        session()->flash('message', 'Regra atualizada com sucesso.');
        $this->cancelarEdicao();
    }

    public function cancelarEdicao()
    {
        $this->editando = false;
        $this->regraId = null;
        $this->edit_palavra_chave = '';
        $this->edit_parte_digitavel = '';
        $this->edit_conta_contrapartida = '';
        $this->edit_descricao = '';
        $this->edit_ativo = true;
    }

    public function excluir($id)
    {
        $regra = RegraAmarracaoDescricao::find($id);
        if ($regra) {
            $regra->delete();
            session()->flash('message', 'Regra excluída com sucesso.');
        }
    }

    public function toggleAtivo($id)
    {
        $regra = RegraAmarracaoDescricao::find($id);
        if ($regra) {
            $regra->update(['ativo' => !$regra->ativo]);
        }
    }

    /**
     * Duplica uma regra para personalizar com parte digitável específica.
     * A cópia mantém palavra_chave, conta_contrapartida e descrição; parte_digitavel fica vazia para o usuário preencher.
     * Abre a edição da nova regra para adicionar a parte digitável.
     */
    public function duplicarRegra(int $id)
    {
        $original = RegraAmarracaoDescricao::find($id);
        if (!$original || !$this->empresa_id || !$this->layout_avancado) {
            return;
        }
        $nova = RegraAmarracaoDescricao::create([
            'empresa_id' => $original->empresa_id,
            'layout_avancado' => $original->layout_avancado,
            'palavra_chave' => $original->palavra_chave,
            'parte_digitavel' => null,
            'tipo_busca' => $original->tipo_busca ?? 'starts_with',
            'conta_contrapartida' => $original->conta_contrapartida,
            'conta_debito' => $original->conta_debito,
            'conta_credito' => $original->conta_credito,
            'centro_custo' => $original->centro_custo,
            'prioridade' => $original->prioridade ?? 0,
            'descricao' => $original->descricao,
            'ativo' => true,
        ]);
        session()->flash('message', 'Regra duplicada. Preencha a parte digitável para especializar (ex.: CPF, parte do nome).');
        $this->editarRegra($nova->id);
    }

    public function ordenar(string $campo)
    {
        if ($this->ordenacao === $campo) {
            $this->direcao = $this->direcao === 'asc' ? 'desc' : 'asc';
        } else {
            $this->ordenacao = $campo;
            $this->direcao = 'asc';
        }
        $this->resetPage('regras_page');
    }

    public function updatedBusca()
    {
        $this->resetPage('regras_page');
    }

    public function updatedOrdenacao()
    {
        $this->resetPage('regras_page');
    }

    public function updatedDirecao()
    {
        $this->resetPage('regras_page');
    }

    public function updatedPerPage()
    {
        $this->resetPage('regras_page');
    }

    public function render()
    {
        $empresas = Empresa::orderBy('nome')->get();
        $layouts = self::getLayoutsAvancado();
        $empresaAtual = $empresas->firstWhere('id', $this->empresa_id);

        if ($this->empresa_id && $this->layout_avancado) {
            $this->ensureRegrasVazias();
        }

        $regrasLista = RegraAmarracaoDescricao::query()
            ->when($this->empresa_id && $this->layout_avancado, function ($q) {
                $q->where('empresa_id', $this->empresa_id)
                    ->where(function ($q2) {
                        $q2->where('layout_avancado', $this->layout_avancado)->orWhereNull('layout_avancado');
                    })
                    ->when(trim($this->busca) !== '', function ($q2) {
                        $termo = '%' . trim($this->busca) . '%';
                        $q2->where(function ($q3) use ($termo) {
                            $q3->where('palavra_chave', 'like', $termo)
                                ->orWhere('parte_digitavel', 'like', $termo)
                                ->orWhere('conta_contrapartida', 'like', $termo)
                                ->orWhere('conta_debito', 'like', $termo)
                                ->orWhere('conta_credito', 'like', $termo);
                        });
                    });
            }, function ($q) {
                $q->whereRaw('1 = 0');
            })
            ->when($this->empresa_id && $this->layout_avancado, function ($q) {
                $campo = in_array($this->ordenacao, ['palavra_chave', 'parte_digitavel', 'conta_contrapartida', 'descricao', 'ativo', 'id']) ? $this->ordenacao : 'palavra_chave';
                $q->orderBy($campo, $this->direcao === 'desc' ? 'desc' : 'asc');
            })
            ->paginate((int) max(1, min(100, $this->perPage)), ['*'], 'regras_page');

        return view('livewire.gerenciador-regras-amarracao', [
            'regras' => $regrasLista,
            'empresas' => $empresas,
            'layouts' => $layouts,
            'empresaAtual' => $empresaAtual,
            'regraEmEdicao' => $this->editando && $this->regraId ? RegraAmarracaoDescricao::find($this->regraId) : null,
        ]);
    }
}
