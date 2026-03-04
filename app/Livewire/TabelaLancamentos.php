<?php

namespace App\Livewire;

use App\Models\Lancamento;
use App\Models\AlteracaoLog;
use App\Models\Importacao;
use App\Models\RegraAmarracaoDescricao;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Log;

class TabelaLancamentos extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public $filtroData = '';
    public $filtroHistorico = '';
    public $filtroTerceiro = '';
    public $filtroImportacao = '';
    public $filtroCodigoFilial = '';
    public $filtroContaDebito = '';
    public $filtroContaCredito = '';
    public $filtroContaAmbas = '';
    public $filtroValor = '';
    public $filtroConferido = '';
    public $perPage = 50;
    
    // Edição inline
    public $editandoId = null;
    public $editandoCampo = '';
    public $valorEditando = '';
    
    // Ordenação
    public $ordenacao = 'data';
    public $direcao = 'asc';

    // Propriedades para novo lançamento
    public $modalNovoLancamento = false;
    public $novoLancamento = [
        'importacao_id' => '',
        'data' => '',
        'conta_debito' => '',
        'conta_credito' => '',
        'valor' => '',
        'nome_empresa' => '',
        'historico' => '',
        'codigo_filial_matriz' => '',
        'arquivo_origem' => '',
    ];
    
    // Propriedades para menu de ações
    public $menuAcoesAberto = null;
    public $modalEditarLancamento = false;
    public $lancamentoEditando = null;
    public $dadosEdicao = [
        'data' => '',
        'conta_debito' => '',
        'conta_credito' => '',
        'valor' => '',
        'nome_empresa' => '',
        'historico' => '',
        'codigo_filial_matriz' => '',
        'arquivo_origem' => '',
    ];

    protected $queryString = [
        'filtroData' => ['except' => ''],
        'filtroHistorico' => ['except' => ''],
        'filtroTerceiro' => ['except' => ''],
        'filtroImportacao' => ['except' => ''],
        'filtroCodigoFilial' => ['except' => ''],
        'filtroContaDebito' => ['except' => ''],
        'filtroContaCredito' => ['except' => ''],
        'filtroContaAmbas' => ['except' => ''],
        'filtroValor' => ['except' => ''],
        'filtroConferido' => ['except' => ''],
        'ordenacao' => ['except' => 'data'],
        'direcao' => ['except' => 'asc'],
    ];

    public function mount()
    {
        // Se foi passado um ID de importação na URL, filtrar por ela
        if (request()->has('importacao')) {
            $this->filtroImportacao = request()->get('importacao');
        }
        
        // Validar ordenação inicial
        $camposValidos = ['data', 'nome_empresa', 'conta_debito', 'conta_credito', 'valor', 'historico', 'codigo_filial_matriz'];
        if (!in_array($this->ordenacao, $camposValidos)) {
            $this->ordenacao = 'data';
            $this->direcao = 'asc';
        }
        
        // Se há filtro de importação, garantir ordenação crescente por data
        if (!empty($this->filtroImportacao)) {
            $this->ordenacao = 'data';
            $this->direcao = 'asc';
        }
        
        // Definir data padrão para novo lançamento
        $this->novoLancamento['data'] = now()->format('Y-m-d');
    }

    public function atualizarFiltros()
    {
        // Preservar estado de edição atual
        $editandoId = $this->editandoId;
        $editandoCampo = $this->editandoCampo;
        $valorEditando = $this->valorEditando;
        
        $this->resetPage();
        
        // Restaurar estado de edição após reset da página
        if ($editandoId) {
            $this->editandoId = $editandoId;
            $this->editandoCampo = $editandoCampo;
            $this->valorEditando = $valorEditando;
        }
    }

    // Métodos para lidar com atualizações de filtros individuais
    public function updatedFiltroData()
    {
        $this->atualizarFiltros();
    }

    public function updatedFiltroHistorico()
    {
        $this->atualizarFiltros();
    }

    public function updatedFiltroTerceiro()
    {
        $this->atualizarFiltros();
    }

    public function updatedFiltroImportacao()
    {
        $this->atualizarFiltros();
    }

    public function updatedFiltroCodigoFilial()
    {
        $this->atualizarFiltros();
    }

    public function updatedFiltroContaDebito()
    {
        $this->atualizarFiltros();
    }

    public function updatedFiltroContaCredito()
    {
        $this->atualizarFiltros();
    }

    public function updatedFiltroContaAmbas()
    {
        $this->atualizarFiltros();
    }

    public function updatedFiltroValor()
    {
        $this->atualizarFiltros();
    }

    public function updatedFiltroConferido()
    {
        $this->atualizarFiltros();
    }

    // Método para preservar estado de edição durante atualizações
    public function hydrate()
    {
        // Preservar estado de edição durante hidratação
        if ($this->editandoId) {
            // Garantir que o estado de edição seja mantido
            $this->dispatch('preservar-edicao', [
                'editandoId' => $this->editandoId,
                'editandoCampo' => $this->editandoCampo,
                'valorEditando' => $this->valorEditando
            ]);
        }
    }

    public function limparFiltros()
    {
        $this->filtroData = '';
        $this->filtroHistorico = '';
        $this->filtroTerceiro = '';
        $this->filtroImportacao = '';
        $this->filtroCodigoFilial = '';
        $this->filtroContaDebito = '';
        $this->filtroContaCredito = '';
        $this->filtroContaAmbas = '';
        $this->filtroValor = '';
        $this->filtroConferido = '';
        $this->resetPage();
    }

    /**
     * Reprocessa amarrações por descrição nos lançamentos da importação selecionada.
     * Aplica as regras de amarração (empresa + layout da importação) ao histórico de cada lançamento.
     */
    public function reprocessarAmarracoes()
    {
        if (empty($this->filtroImportacao)) {
            session()->flash('error', 'Selecione uma importação para reprocessar as amarrações.');
            return;
        }

        $importacao = Importacao::find($this->filtroImportacao);
        if (!$importacao) {
            session()->flash('error', 'Importação não encontrada.');
            return;
        }

        $empresaId = $importacao->empresa_id;
        if (!$empresaId) {
            session()->flash('error', 'Esta importação não possui empresa vinculada.');
            return;
        }

        $layoutAvancado = $importacao->layout_avancado ?? null;
        $contaBanco = $importacao->conta_banco ? ltrim((string) $importacao->conta_banco, '0') : null;

        if (!$contaBanco && in_array($layoutAvancado, ['grafeno', 'sicoob', 'caixa_federal', 'registros', 'sicredi'])) {
            session()->flash('error', 'Esta importação não possui conta banco registrada. Importe novamente informando a conta do banco.');
            return;
        }

        $lancamentos = Lancamento::where('importacao_id', $importacao->id)->get();
        $atualizados = 0;

        foreach ($lancamentos as $lancamento) {
            $valorFloat = (float) str_replace(',', '.', preg_replace('/[^\d,.-]/', '', (string) $lancamento->valor));
            if (str_contains((string) $lancamento->valor, '-')) {
                $valorFloat = -abs($valorFloat);
            }

            $contaDeb = self::normalizarConta((string) ($lancamento->conta_debito ?? ''));
            $contaCred = self::normalizarConta((string) ($lancamento->conta_credito ?? ''));
            $contaBancoNorm = $contaBanco ? self::normalizarConta((string) $contaBanco) : null;
            $debPreenchido = $contaDeb !== '';
            $credPreenchido = $contaCred !== '';

            // Inferir sinal do valor: valor no DB costuma vir sempre positivo
            // 1) Fallback: quando só um lado preenchido, esse lado é o banco
            if ($debPreenchido && !$credPreenchido) {
                $valorFloat = abs($valorFloat); // Banco no débito = entrada
            } elseif (!$debPreenchido && $credPreenchido) {
                $valorFloat = -abs($valorFloat); // Banco no crédito = saída
            } elseif ($valorFloat >= 0 && $contaBancoNorm !== null && $contaBancoNorm !== '') {
                // 2) Quando ambos preenchidos, usar conta_banco (com match flexível)
                if (self::contaCorresponde($contaCred, $contaBancoNorm) && !self::contaCorresponde($contaDeb, $contaBancoNorm)) {
                    $valorFloat = -abs($valorFloat); // Banco no crédito = saída
                }
            }

            // Usar historico_original (CSV antes de regra) ou nome_empresa/terceiro para matching; historico pode ter sido sobrescrito por regra anterior
            $descricaoParaMatch = $lancamento->historico_original
                ?? $lancamento->nome_empresa
                ?? ($lancamento->terceiro?->nome)
                ?? $lancamento->historico
                ?? '';

            $regraAplicada = RegraAmarracaoDescricao::aplicarRegrasParaEmpresaLayout(
                $empresaId,
                $layoutAvancado,
                $descricaoParaMatch,
                $valorFloat
            );

            if ($regraAplicada) {
                $upd = [];
                // Determinar onde está o banco: heurística quando só um lado preenchido, conta_banco quando ambos
                $bancoNoDebito = $debPreenchido && !$credPreenchido;
                $bancoNoCredito = !$debPreenchido && $credPreenchido;
                if ($contaBancoNorm && $debPreenchido && $credPreenchido) {
                    $bancoNoDebito = self::contaCorresponde($contaDeb, $contaBancoNorm);
                    $bancoNoCredito = self::contaCorresponde($contaCred, $contaBancoNorm);
                }

                // Contrapartida preenche o lado oposto ao banco
                if (!empty($regraAplicada['conta_debito']) && $bancoNoCredito) {
                    $v = ltrim($regraAplicada['conta_debito'], '0');
                    $upd['conta_debito'] = $v;
                    $upd['conta_debito_original'] = $v;
                }
                if (!empty($regraAplicada['conta_credito']) && $bancoNoDebito) {
                    $v = ltrim($regraAplicada['conta_credito'], '0');
                    $upd['conta_credito'] = $v;
                    $upd['conta_credito_original'] = $v;
                }
                if (!empty($regraAplicada['historico'])) {
                    $upd['historico'] = $regraAplicada['historico'];
                }
                if (!empty($upd)) {
                    $lancamento->update($upd);
                    $atualizados++;
                } elseif (config('app.debug') || env('REGRAS_AMARRACAO_DEBUG', false)) {
                    Log::channel('single')->warning('[Reprocessar] Regra bateu mas nenhuma conta atualizada', [
                        'lancamento_id' => $lancamento->id,
                        'historico' => mb_substr($lancamento->historico ?? '', 0, 60),
                        'banco_no_debito' => $bancoNoDebito,
                        'banco_no_credito' => $bancoNoCredito,
                        'conta_debito_regra' => $regraAplicada['conta_debito'] ?? null,
                        'conta_credito_regra' => $regraAplicada['conta_credito'] ?? null,
                        'conta_banco' => $contaBancoNorm,
                        'deb_preenchido' => $debPreenchido,
                        'cred_preenchido' => $credPreenchido,
                    ]);
                }
            }
        }

        $totalLancamentos = $lancamentos->count();
        $empresaSessao = session('empresa_selecionada_id');
        $ctx = "Importação empresa_id={$empresaId}, layout={$layoutAvancado}";
        $avisoEmpresa = ($empresaSessao && (int) $empresaSessao !== (int) $empresaId)
            ? " Atenção: as regras devem estar na mesma empresa da importação (empresa {$empresaId}). No seletor do cabeçalho você está com empresa {$empresaSessao}."
            : '';
        if ($atualizados > 0) {
            session()->flash('message', "Amarrações reprocessadas: {$atualizados} de {$totalLancamentos} lançamento(s) atualizado(s). ({$ctx})");
        } else {
            session()->flash('message', "Nenhum lançamento atualizado. {$totalLancamentos} lançamento(s) processado(s). ({$ctx}){$avisoEmpresa} Verifique em Regras de Amarração se as regras têm conta contra-partida e estão na mesma empresa.");
        }
    }

    /**
     * Normaliza conta para comparação: trim, remove zeros à esquerda.
     */
    private static function normalizarConta(string $conta): string
    {
        $c = trim($conta);
        if ($c === '') {
            return '';
        }
        return ltrim($c, '0') ?: '0';
    }

    /**
     * Verifica se a conta do lançamento corresponde à conta banco (exata ou como prefixo/subconta).
     */
    private static function contaCorresponde(string $contaLancamento, string $contaBanco): bool
    {
        if ($contaLancamento === '' || $contaBanco === '') {
            return false;
        }
        return $contaLancamento === $contaBanco
            || str_starts_with($contaLancamento . '.', $contaBanco . '.')
            || str_starts_with($contaBanco . '.', $contaLancamento . '.');
    }

    public function ordenar($campo)
    {
        // Validar se o campo de ordenação é válido
        $camposValidos = ['data', 'nome_empresa', 'conta_debito', 'conta_credito', 'valor', 'historico', 'codigo_filial_matriz'];
        
        if (!in_array($campo, $camposValidos)) {
            // Se o campo não for válido, usar ordenação padrão
            $this->ordenacao = 'data';
            $this->direcao = 'desc';
        } else {
            if ($this->ordenacao === $campo) {
                $this->direcao = $this->direcao === 'asc' ? 'desc' : 'asc';
            } else {
                $this->ordenacao = $campo;
                $this->direcao = 'asc';
            }
        }
        $this->resetPage();
        $this->dispatch('ordenacao-alterada');
    }

    public function iniciarEdicao($id, $campo, $valor)
    {
        $this->editandoId = $id;
        $this->editandoCampo = $campo;
        $this->valorEditando = $valor;
    }

    public function salvarEdicao()
    {
        if (!$this->editandoId || !$this->editandoCampo) {
            // Se for confirmação de amarração, usar os dados pendentes
            if ($this->edicaoLancamentoId && $this->edicaoCampo && $this->edicaoValor) {
                $lancamento = Lancamento::find($this->edicaoLancamentoId);
                $campo = $this->edicaoCampo;
                $valorNovo = $this->edicaoValor;
            } else {
                return;
            }
        } else {
            $lancamento = Lancamento::find($this->editandoId);
            $campo = $this->editandoCampo;
            $valorNovo = $this->valorEditando;
        }
        
        if (!$lancamento) {
            return;
        }
        
        $valorAnterior = $lancamento->{$campo};
        
        // Edição de conta débito/crédito ou outros campos
        if (in_array($campo, ['conta_debito', 'conta_credito'])) {
            $lancamento->{$campo} = $valorNovo;
            $lancamento->conferido = true;
            $lancamento->save();

            AlteracaoLog::create([
                'lancamento_id' => $lancamento->id,
                'campo_alterado' => $campo,
                'valor_anterior' => $valorAnterior,
                'valor_novo' => $valorNovo,
                'tipo_alteracao' => 'conta',
                'data_alteracao' => now()
            ]);
            $this->cancelarEdicao();
            return;
        } else {
            // Tratamento específico para data
            if ($this->editandoCampo === 'data') {
                try {
                    $dataFormatada = \Carbon\Carbon::parse($this->valorEditando)->format('Y-m-d');
                    $lancamento->data = $dataFormatada;
                } catch (\Exception $e) {
                    session()->flash('error', 'Data inválida. Use o formato DD/MM/AAAA.');
                    return;
                }
            } 
            // Tratamento específico para valor
            elseif ($this->editandoCampo === 'valor') {
                $valor = floatval($this->valorEditando);
                if ($valor < 0) {
                    session()->flash('error', 'O valor não pode ser negativo.');
                    return;
                }
                $lancamento->valor = $valor;
            } else {
                $lancamento->{$this->editandoCampo} = $this->valorEditando;
            }
        }
        
        $lancamento->conferido = true; // Marcar como conferido ao editar
        $lancamento->save();

        // Registrar alteração no log
        AlteracaoLog::create([
            'lancamento_id' => $lancamento->id,
            'campo_alterado' => $this->editandoCampo,
            'valor_anterior' => $valorAnterior,
            'valor_novo' => $this->valorEditando,
            'tipo_alteracao' => in_array($this->editandoCampo, ['conta_debito', 'conta_credito']) ? 'conta' : 'outro',
            'data_alteracao' => now()
        ]);

        $this->cancelarEdicao();
    }



    public function cancelarEdicao()
    {
        $this->editandoId = null;
        $this->editandoCampo = '';
        $this->valorEditando = '';
    }











    public function toggleConferido($id)
    {
        $lancamento = Lancamento::find($id);
        if (!$lancamento) {
            return;
        }

        $lancamento->conferido = !$lancamento->conferido;
        $lancamento->save();

        // Forçar atualização da view
        $this->dispatch('conferido-alterado', $id, $lancamento->conferido);
    }

    public function marcarComoConferido($id)
    {
        $lancamento = Lancamento::find($id);
        if (!$lancamento) {
            return;
        }

        $lancamento->conferido = true;
        $lancamento->save();

        // Forçar atualização da view
        $this->dispatch('conferido-alterado', $id, $lancamento->conferido);
    }

    public function updatingPerPage()
    {
        $this->resetPage();
    }

    private function getLancamentosQuery()
    {
        $query = Lancamento::with(['importacao', 'terceiro']);

        // Filtrar pela empresa selecionada no seletor global
        $empresaId = session('empresa_selecionada_id');
        if ($empresaId) {
            $query->where('empresa_id', $empresaId);
        }

        if (!empty($this->filtroData)) {
            $query->whereDate('data', $this->filtroData);
        }
        if (!empty($this->filtroHistorico)) {
            $query->where('historico', 'like', '%' . $this->filtroHistorico . '%');
        }
        if (!empty($this->filtroTerceiro)) {
            $query->where(function($q) {
                $q->where('nome_empresa', 'like', '%' . $this->filtroTerceiro . '%')
                  ->orWhereHas('terceiro', function($subQ) {
                      $subQ->where('nome', 'like', '%' . $this->filtroTerceiro . '%');
                  });
            });
        }
        if (!empty($this->filtroImportacao)) {
            $query->where('importacao_id', $this->filtroImportacao);
        }
        if (!empty($this->filtroCodigoFilial)) {
            $query->where('codigo_filial_matriz', 'like', '%' . $this->filtroCodigoFilial . '%');
        }
        if ($this->filtroContaDebito !== '') {
            $query->where('conta_debito_original', $this->filtroContaDebito);
        }
        if ($this->filtroContaCredito !== '') {
            $query->where('conta_credito_original', $this->filtroContaCredito);
        }
        if (!empty($this->filtroContaAmbas)) {
            $query->where(function($q) {
                $q->where('conta_debito_original', $this->filtroContaAmbas)
                  ->orWhere('conta_credito_original', $this->filtroContaAmbas);
            });
        }
        if (!empty($this->filtroValor)) {
            // Converter valor para formato numérico (remover vírgulas e pontos de milhares)
            $valor = str_replace(['.', ','], ['', '.'], $this->filtroValor);
            if (is_numeric($valor)) {
                $query->where('valor', $valor);
            }
        }
        if ($this->filtroConferido !== '') {
            if ($this->filtroConferido === 'conferidos') {
                $query->where('conferido', true);
            } elseif ($this->filtroConferido === 'nao_conferidos') {
                $query->where('conferido', false);
            }
            // Se for 'todos' ou vazio, não aplica filtro
        }
        // Ordenação
        if ($this->ordenacao === 'nome_empresa') {
            // Ordenação personalizada para terceiro: primeiro por nome do terceiro, depois por nome da empresa
            $query->orderByRaw("
                CASE 
                    WHEN terceiro_id IS NOT NULL THEN (
                        SELECT nome FROM terceiros WHERE terceiros.id = lancamentos.terceiro_id
                    )
                    ELSE nome_empresa 
                END {$this->direcao}
            ");
        } else {
            $query->orderBy($this->ordenacao, $this->direcao);
        }
        return $query;
    }

    // Métodos para novo lançamento
    public function abrirModalNovoLancamento()
    {
        $this->modalNovoLancamento = true;
        $this->resetNovoLancamento();
    }

    public function fecharModalNovoLancamento()
    {
        $this->modalNovoLancamento = false;
        $this->resetNovoLancamento();
    }

    public function resetNovoLancamento()
    {
        $this->novoLancamento = [
            'importacao_id' => '',
            'data' => now()->format('Y-m-d'),
            'conta_debito' => '',
            'conta_credito' => '',
            'valor' => '',
            'nome_empresa' => '',
            'historico' => '',
            'codigo_filial_matriz' => '',
            'arquivo_origem' => '',
        ];
    }

    public function carregarDadosImportacao()
    {
        if (!empty($this->novoLancamento['importacao_id'])) {
            $importacao = Importacao::with('empresa')->find($this->novoLancamento['importacao_id']);
            
            if ($importacao && $importacao->empresa) {
                $this->novoLancamento['codigo_filial_matriz'] = $importacao->empresa->codigo_filial ?? '';
            }
        }
    }

    public function salvarNovoLancamento()
    {
        $this->validate([
            'novoLancamento.importacao_id' => 'required|exists:importacoes,id',
            'novoLancamento.data' => 'required|date',
            'novoLancamento.conta_debito' => 'required|string|max:255',
            'novoLancamento.conta_credito' => 'required|string|max:255',
            'novoLancamento.valor' => 'required|numeric|min:0.01',
            'novoLancamento.historico' => 'required|string|max:1000',
            'novoLancamento.nome_empresa' => 'nullable|string|max:255',
            'novoLancamento.codigo_filial_matriz' => 'nullable|string|max:255',
            'novoLancamento.arquivo_origem' => 'nullable|string|max:255',
        ], [
            'novoLancamento.importacao_id.required' => 'A importação é obrigatória',
            'novoLancamento.importacao_id.exists' => 'Importação selecionada não existe',
            'novoLancamento.data.required' => 'A data é obrigatória',
            'novoLancamento.data.date' => 'A data deve ser uma data válida',
            'novoLancamento.conta_debito.required' => 'A conta débito é obrigatória',
            'novoLancamento.conta_credito.required' => 'A conta crédito é obrigatória',
            'novoLancamento.valor.required' => 'O valor é obrigatório',
            'novoLancamento.valor.numeric' => 'O valor deve ser um número',
            'novoLancamento.valor.min' => 'O valor deve ser maior que zero',
            'novoLancamento.historico.required' => 'O histórico é obrigatório',
        ]);

        try {
            // Buscar a importação para obter a empresa
            $importacao = Importacao::find($this->novoLancamento['importacao_id']);
            if (!$importacao) {
                throw new \Exception('Importação não encontrada');
            }

            // Criar o novo lançamento
            $lancamento = Lancamento::create([
                'importacao_id' => $this->novoLancamento['importacao_id'],
                'empresa_id' => $importacao->empresa_id,
                'data' => $this->novoLancamento['data'],
                'conta_debito' => $this->novoLancamento['conta_debito'],
                'conta_credito' => $this->novoLancamento['conta_credito'],
                'conta_debito_original' => $this->novoLancamento['conta_debito'],
                'conta_credito_original' => $this->novoLancamento['conta_credito'],
                'valor' => $this->novoLancamento['valor'],
                'nome_empresa' => $this->novoLancamento['nome_empresa'],
                'historico' => $this->novoLancamento['historico'],
                'codigo_filial_matriz' => $this->novoLancamento['codigo_filial_matriz'],
                'arquivo_origem' => $this->novoLancamento['arquivo_origem'],
                'conferido' => false,
            ]);

            // Log adicional para confirmar empresa
            Log::info("Novo lançamento criado com empresa", [
                'lancamento_id' => $lancamento->id,
                'empresa_id' => $lancamento->empresa_id,
                'empresa_nome' => $importacao->empresa ? $importacao->empresa->nome : 'N/A',
                'codigo_sistema_empresa' => $importacao->empresa ? $importacao->empresa->codigo_sistema : 'N/A',
            ]);

            // Registrar no log
            Log::info("Novo lançamento criado manualmente", [
                'lancamento_id' => $lancamento->id,
                'importacao_id' => $lancamento->importacao_id,
                'empresa_id' => $lancamento->empresa_id,
                'data' => $lancamento->data,
                'valor' => $lancamento->valor,
                'historico' => $lancamento->historico,
                'usuario' => 'Sistema'
            ]);

            session()->flash('message', 'Lançamento criado com sucesso!');
            $this->fecharModalNovoLancamento();

        } catch (\Exception $e) {
            Log::error("Erro ao criar novo lançamento", [
                'erro' => $e->getMessage(),
                'dados' => $this->novoLancamento
            ]);
            
            session()->flash('error', 'Erro ao criar lançamento: ' . $e->getMessage());
        }
    }

    // Métodos para menu de ações
    public function abrirMenuAcoes($lancamentoId)
    {
        $this->menuAcoesAberto = $lancamentoId;
    }

    public function fecharMenuAcoes()
    {
        $this->menuAcoesAberto = null;
    }

    public function editarLancamento($lancamentoId)
    {
        $lancamento = Lancamento::find($lancamentoId);
        if (!$lancamento) {
            session()->flash('error', 'Lançamento não encontrado.');
            return;
        }

        $this->lancamentoEditando = $lancamento;
        $this->dadosEdicao = [
            'data' => $lancamento->data->format('Y-m-d'),
            'conta_debito' => $lancamento->conta_debito,
            'conta_credito' => $lancamento->conta_credito,
            'valor' => $lancamento->valor,
            'nome_empresa' => $lancamento->nome_empresa,
            'historico' => $lancamento->historico,
            'codigo_filial_matriz' => $lancamento->codigo_filial_matriz,
            'arquivo_origem' => $lancamento->arquivo_origem,
        ];

        $this->modalEditarLancamento = true;
        $this->fecharMenuAcoes();
    }

    public function salvarEdicaoLancamento()
    {
        $this->validate([
            'dadosEdicao.data' => 'required|date',
            'dadosEdicao.conta_debito' => 'required|string|max:255',
            'dadosEdicao.conta_credito' => 'required|string|max:255',
            'dadosEdicao.valor' => 'required|numeric|min:0.01',
            'dadosEdicao.historico' => 'required|string|max:1000',
            'dadosEdicao.nome_empresa' => 'nullable|string|max:255',
            'dadosEdicao.codigo_filial_matriz' => 'nullable|string|max:255',
            'dadosEdicao.arquivo_origem' => 'nullable|string|max:255',
        ], [
            'dadosEdicao.data.required' => 'A data é obrigatória',
            'dadosEdicao.data.date' => 'A data deve ser uma data válida',
            'dadosEdicao.conta_debito.required' => 'A conta débito é obrigatória',
            'dadosEdicao.conta_credito.required' => 'A conta crédito é obrigatória',
            'dadosEdicao.valor.required' => 'O valor é obrigatório',
            'dadosEdicao.valor.numeric' => 'O valor deve ser um número',
            'dadosEdicao.valor.min' => 'O valor deve ser maior que zero',
            'dadosEdicao.historico.required' => 'O histórico é obrigatório',
        ]);

        try {
            $lancamento = $this->lancamentoEditando;
            if (!$lancamento) {
                throw new \Exception('Lançamento não encontrado');
            }

            // Registrar valores anteriores para o log
            $valoresAnteriores = [
                'data' => $lancamento->data->format('Y-m-d'),
                'conta_debito' => $lancamento->conta_debito,
                'conta_credito' => $lancamento->conta_credito,
                'valor' => $lancamento->valor,
                'nome_empresa' => $lancamento->nome_empresa,
                'historico' => $lancamento->historico,
                'codigo_filial_matriz' => $lancamento->codigo_filial_matriz,
                'arquivo_origem' => $lancamento->arquivo_origem,
            ];

            // Atualizar o lançamento
            $lancamento->update([
                'data' => $this->dadosEdicao['data'],
                'conta_debito' => $this->dadosEdicao['conta_debito'],
                'conta_credito' => $this->dadosEdicao['conta_credito'],
                'valor' => $this->dadosEdicao['valor'],
                'nome_empresa' => $this->dadosEdicao['nome_empresa'],
                'historico' => $this->dadosEdicao['historico'],
                'codigo_filial_matriz' => $this->dadosEdicao['codigo_filial_matriz'],
                'arquivo_origem' => $this->dadosEdicao['arquivo_origem'],
            ]);

            // Registrar alterações no log
            foreach ($this->dadosEdicao as $campo => $valorNovo) {
                if ($valoresAnteriores[$campo] != $valorNovo) {
                    AlteracaoLog::create([
                        'lancamento_id' => $lancamento->id,
                        'campo_alterado' => $campo,
                        'valor_anterior' => $valoresAnteriores[$campo],
                        'valor_novo' => $valorNovo,
                        'tipo_alteracao' => in_array($campo, ['conta_debito', 'conta_credito']) ? 'conta' : 'outro',
                        'data_alteracao' => now()
                    ]);
                }
            }

            session()->flash('message', 'Lançamento atualizado com sucesso!');
            $this->fecharModalEdicao();

        } catch (\Exception $e) {
            Log::error("Erro ao editar lançamento", [
                'lancamento_id' => $this->lancamentoEditando ? $this->lancamentoEditando->id : null,
                'erro' => $e->getMessage(),
                'dados' => $this->dadosEdicao
            ]);
            
            session()->flash('error', 'Erro ao editar lançamento: ' . $e->getMessage());
        }
    }

    public function fecharModalEdicao()
    {
        $this->modalEditarLancamento = false;
        $this->lancamentoEditando = null;
        $this->dadosEdicao = [
            'data' => '',
            'conta_debito' => '',
            'conta_credito' => '',
            'valor' => '',
            'nome_empresa' => '',
            'historico' => '',
            'codigo_filial_matriz' => '',
            'arquivo_origem' => '',
        ];
    }

    public function excluirLancamento($lancamentoId)
    {
        try {
            $lancamento = Lancamento::find($lancamentoId);
            if (!$lancamento) {
                session()->flash('error', 'Lançamento não encontrado.');
                return;
            }

            // Registrar no log antes da exclusão
            Log::info("Lançamento excluído via menu", [
                'lancamento_id' => $lancamento->id,
                'data' => $lancamento->data,
                'historico' => $lancamento->historico,
                'valor' => $lancamento->valor,
                'terceiro' => $lancamento->nome_empresa,
                'importacao_id' => $lancamento->importacao_id,
                'usuario' => 'Sistema'
            ]);

            $lancamento->delete();
            session()->flash('message', 'Lançamento excluído com sucesso!');
            $this->fecharMenuAcoes();

        } catch (\Exception $e) {
            Log::error("Erro ao excluir lançamento", [
                'lancamento_id' => $lancamentoId,
                'erro' => $e->getMessage()
            ]);
            
            session()->flash('error', 'Erro ao excluir lançamento: ' . $e->getMessage());
        }
    }

    public function duplicarLancamento($lancamentoId)
    {
        try {
            $lancamentoOriginal = Lancamento::find($lancamentoId);
            if (!$lancamentoOriginal) {
                session()->flash('error', 'Lançamento não encontrado.');
                return;
            }

            // Criar cópia do lançamento
            $lancamentoCopia = $lancamentoOriginal->replicate();
            $lancamentoCopia->historico = $lancamentoOriginal->historico . ' (CÓPIA)';
            $lancamentoCopia->conferido = false;
            $lancamentoCopia->save();

            // Registrar no log
            Log::info("Lançamento duplicado", [
                'lancamento_original_id' => $lancamentoOriginal->id,
                'lancamento_copia_id' => $lancamentoCopia->id,
                'data' => $lancamentoCopia->data,
                'historico' => $lancamentoCopia->historico,
                'valor' => $lancamentoCopia->valor,
                'usuario' => 'Sistema'
            ]);

            session()->flash('message', 'Lançamento duplicado com sucesso!');
            $this->fecharMenuAcoes();

        } catch (\Exception $e) {
            Log::error("Erro ao duplicar lançamento", [
                'lancamento_id' => $lancamentoId,
                'erro' => $e->getMessage()
            ]);
            
            session()->flash('error', 'Erro ao duplicar lançamento: ' . $e->getMessage());
        }
    }

    public function render()
    {
        $query = $this->getLancamentosQuery();
        $lancamentos = $query->paginate($this->perPage);
        
        // Carregar importações com nome da empresa (filtradas pela empresa do seletor global)
        $empresaId = session('empresa_selecionada_id');
        $importacoesQuery = Importacao::with('empresa')->orderBy('created_at', 'desc');
        if ($empresaId) {
            $importacoesQuery->where('empresa_id', $empresaId);
        }
        $importacoes = $importacoesQuery->get()
            ->map(function ($importacao) {
                $empresaNome = $importacao->empresa ? $importacao->empresa->nome : 'Sem empresa';
                return [
                    'id' => $importacao->id,
                    'nome_arquivo' => $importacao->nome_arquivo,
                    'empresa_nome' => $empresaNome,
                    'layout_avancado' => $importacao->layout_avancado,
                    'display_text' => "ID: {$importacao->id} - {$importacao->nome_arquivo} - {$empresaNome}"
                ];
            });

        $importacaoSelecionada = $importacoes->firstWhere('id', (int) $this->filtroImportacao);
        $mostrarReprocessar = $importacaoSelecionada && !empty($importacaoSelecionada['layout_avancado']);

        return view('livewire.tabela-lancamentos', [
            'lancamentos' => $lancamentos,
            'importacoes' => $importacoes,
            'mostrarReprocessar' => $mostrarReprocessar,
        ]);
    }

}
