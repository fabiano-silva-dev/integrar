<?php

namespace App\Livewire;

use App\Models\Empresa;
use App\Models\HistoricoPadraoLayout;
use App\Models\RegraAmarracaoDescricao;
use App\Services\Importacao\ExportadorRegrasAmarracaoService;
use App\Services\OperadoraContext;
use App\Services\PlanoContaResolver;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public $sugestoesContaContrapartida = [];

    /** Cópia de outra empresa */
    public $modalCopiar = false;
    public $empresa_origem_id = null;
    public $estrategia_copia = 'adicionar_atualizar';
    public $previewCopia = null;

    public static function getLayoutsAvancado(): array
    {
        return [
            '' => 'Selecione o layout',
            'dominio' => 'Domínio (TXT)',
            'grafeno' => 'Grafeno (PDF)',
            'sicoob' => 'Sicoob (PDF)',
            'caixa_federal' => 'Caixa Econômica Federal (PDF)',
            'caixa' => 'Caixa Internet Banking (PDF)',
            'ofx' => 'Formato OFX',
            'registros' => 'Connectere > Contas Financeiras > Diário (CSV)',
            'sicredi' => 'SICREDI (PDF)',
            'banrisul' => 'Banrisul (PDF) - Conta corrente',
            'santander' => 'Santander (PDF)',
            'itau' => 'Itaú (PDF)',
            'bradesco' => 'Bradesco (PDF)',
            'cresol' => 'Cresol (PDF)',
            'banco_brasil' => 'Banco do Brasil (PDF)',
        ];
    }

    public function mount()
    {
        $this->empresa_id = session('empresa_selecionada_id');

        $layout = request()->query('layout');
        if (is_string($layout) && array_key_exists($layout, static::getLayoutsAvancado())) {
            $this->layout_avancado = $layout;
        }
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

        $resolver = new PlanoContaResolver();
        $contaContrapartida = trim($this->edit_conta_contrapartida ?? '');
        if ($contaContrapartida !== '') {
            $contaContrapartida = $resolver->resolverParaArmazenamento($regra->empresa_id, $contaContrapartida);
        }

        $regra->update([
            'parte_digitavel' => trim($this->edit_parte_digitavel ?? '') ?: null,
            'conta_contrapartida' => $contaContrapartida !== '' ? $contaContrapartida : null,
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
        $this->sugestoesContaContrapartida = [];
    }

    public function updatedEditContaContrapartida($valor): void
    {
        if (!$this->empresa_id || !(new PlanoContaResolver())->empresaTemPlanoAtivo((int) $this->empresa_id)) {
            $this->sugestoesContaContrapartida = [];
            return;
        }

        $this->sugestoesContaContrapartida = (new PlanoContaResolver())->buscar((int) $this->empresa_id, (string) $valor);
    }

    public function selecionarContaContrapartida(string $codigo): void
    {
        $this->edit_conta_contrapartida = $codigo;
        $this->sugestoesContaContrapartida = [];
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

    public function baixarModelo(): StreamedResponse
    {
        $service = new ExportadorRegrasAmarracaoService();
        $conteudo = $service->conteudoModeloCsv();

        return response()->streamDownload(function () use ($conteudo) {
            echo $conteudo;
        }, 'modelo_regras_amarracao.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportarRegras(string $formato = 'csv'): StreamedResponse|BinaryFileResponse|null
    {
        if (!$this->empresa_id || !$this->layout_avancado) {
            $this->addError('layout_avancado', 'Selecione empresa e layout antes de exportar.');
            return null;
        }

        OperadoraContext::resolveEmpresa($this->empresa_id);

        $service = new ExportadorRegrasAmarracaoService();
        $sufixoLayout = str_replace(['/', ' '], '_', $this->layout_avancado);
        $timestamp = date('Y-m-d_His');

        if ($formato === 'xlsx') {
            $path = $service->exportarXlsx((int) $this->empresa_id, $this->layout_avancado);
            $nome = "regras_amarracao_{$sufixoLayout}_{$timestamp}.xlsx";

            return response()->download($path, $nome, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $conteudo = $service->exportarCsv((int) $this->empresa_id, $this->layout_avancado);
        $nome = "regras_amarracao_{$sufixoLayout}_{$timestamp}.csv";

        return response()->streamDownload(function () use ($conteudo) {
            echo $conteudo;
        }, $nome, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function abrirModalCopiar(): void
    {
        if (!$this->empresa_id || !$this->layout_avancado) {
            return;
        }

        $this->empresa_origem_id = null;
        $this->estrategia_copia = 'adicionar_atualizar';
        $this->previewCopia = null;
        $this->modalCopiar = true;
    }

    public function fecharModalCopiar(): void
    {
        $this->modalCopiar = false;
        $this->empresa_origem_id = null;
        $this->previewCopia = null;
    }

    public function gerarPreviewCopia(): void
    {
        $this->validate([
            'empresa_origem_id' => 'required|integer|different:empresa_id',
            'estrategia_copia' => 'required|in:' . implode(',', array_keys(ExportadorRegrasAmarracaoService::ESTRATEGIAS_COPIA)),
        ], [
            'empresa_origem_id.different' => 'A empresa de origem deve ser diferente da empresa atual.',
        ]);

        if (!$this->empresa_id || !$this->layout_avancado) {
            return;
        }

        OperadoraContext::resolveEmpresa((int) $this->empresa_origem_id);
        OperadoraContext::resolveEmpresa((int) $this->empresa_id);

        $service = new ExportadorRegrasAmarracaoService();
        $this->previewCopia = $service->analisarCopia(
            (int) $this->empresa_origem_id,
            (int) $this->empresa_id,
            $this->layout_avancado,
            $this->estrategia_copia
        );
    }

    public function confirmarCopia(): void
    {
        if (!$this->previewCopia || !$this->empresa_id || !$this->layout_avancado || !$this->empresa_origem_id) {
            return;
        }

        OperadoraContext::resolveEmpresa((int) $this->empresa_origem_id);
        OperadoraContext::resolveEmpresa((int) $this->empresa_id);

        $service = new ExportadorRegrasAmarracaoService();
        $resultado = $service->copiar(
            (int) $this->empresa_origem_id,
            (int) $this->empresa_id,
            $this->layout_avancado,
            $this->estrategia_copia
        );

        session()->flash('message', sprintf(
            'Cópia concluída: %d novas, %d atualizadas, %d ignoradas%s.',
            $resultado['copiadas'],
            $resultado['atualizadas'],
            $resultado['ignoradas'],
            $resultado['removidas'] > 0 ? ", {$resultado['removidas']} removidas" : ''
        ));

        $this->fecharModalCopiar();
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
            'empresasCopia' => $empresas->where('id', '!=', $this->empresa_id)->values(),
            'layouts' => $layouts,
            'empresaAtual' => $empresaAtual,
            'estrategiasCopia' => ExportadorRegrasAmarracaoService::ESTRATEGIAS_COPIA,
            'regraEmEdicao' => $this->editando && $this->regraId ? RegraAmarracaoDescricao::find($this->regraId) : null,
            'empresaTemPlano' => $this->empresa_id
                ? (new PlanoContaResolver())->empresaTemPlanoAtivo((int) $this->empresa_id)
                : false,
        ]);
    }
}
