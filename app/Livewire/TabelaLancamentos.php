<?php

namespace App\Livewire;

use App\Models\Lancamento;
use App\Models\AlteracaoLog;
use App\Models\Importacao;
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

    // Novas propriedades para confirmação de edição
    public $confirmarSalvarAmarracao = false;
    public $edicaoPendente = null;
    public $edicaoTipo = '';
    public $edicaoCampo = '';
    public $edicaoValor = '';
    public $edicaoLancamentoId = null;
    public $edicaoAmarracaoId = null;
    
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
        
        // Verificar se é edição de conta débito/crédito
        if (in_array($campo, ['conta_debito', 'conta_credito'])) {
            $amarracao = $lancamento->amarracao;
            
            // Se há amarração e ainda não foi confirmado, perguntar sobre salvar na amarração
            if ($amarracao && !$this->confirmarSalvarAmarracao && !$this->confirmarSalvarAmarracao === false) {
                $this->edicaoLancamentoId = $lancamento->id;
                $this->edicaoCampo = $campo;
                $this->edicaoValor = $valorNovo;
                $this->edicaoTipo = 'amarracao';
                return;
            }
            
            // Executar ações conforme confirmações
            if ($this->confirmarSalvarAmarracao && $amarracao) {
                $amarracao->{$campo} = $valorNovo;
                $amarracao->save();
            }
            
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
            
            // Resetar confirmações
            $this->confirmarSalvarAmarracao = false;
            $this->edicaoPendente = null;
            $this->edicaoTipo = '';
            $this->edicaoCampo = '';
            $this->edicaoValor = '';
            $this->edicaoLancamentoId = null;
            $this->edicaoAmarracaoId = null;
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
        
        // Criar amarração se foi marcado como conferido e não tem amarração
        if ($lancamento->conferido && !$lancamento->amarracao_id && !empty($lancamento->detalhes_operacao_para_amarracao)) {
            $this->criarAmarracaoParaLancamento($lancamento);
        }
        
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
        
        // Criar amarração se não existir e se há detalhes da operação
        if (!$lancamento->amarracao_id && !empty($lancamento->detalhes_operacao_para_amarracao)) {
            $this->criarAmarracaoParaLancamento($lancamento);
        }
        
        // Forçar atualização da view
        $this->dispatch('conferido-alterado', $id, $lancamento->conferido);
    }

    private function criarAmarracaoParaLancamento($lancamento)
    {
        try {
            // Buscar empresa para obter o código do sistema
            $empresa = \App\Models\Empresa::find($lancamento->empresa_id);
            $codigoSistemaEmpresa = $empresa ? $empresa->codigo_sistema : null;
            
            // Buscar terceiro
            $terceiroNome = '';
            if ($lancamento->terceiro_id) {
                $terceiro = \App\Models\Terceiro::find($lancamento->terceiro_id);
                $terceiroNome = $terceiro ? $terceiro->nome : '';
            } else {
                $terceiroNome = $lancamento->nome_empresa ?? '';
            }
            
            // Verificar se já existe uma amarração similar (sem detalhes da operação)
            $amarracaoExistente = \App\Models\Amarracao::where('terceiro', $terceiroNome)
                ->where('conta_debito', $lancamento->conta_debito)
                ->where('conta_credito', $lancamento->conta_credito)
                ->where('codigo_sistema_empresa', $codigoSistemaEmpresa)
                ->whereNull('detalhes_operacao') // Sem detalhes da operação
                ->first();
            
            if ($amarracaoExistente) {
                // Usar amarração existente
                $lancamento->amarracao_id = $amarracaoExistente->id;
                $lancamento->save();
                
                Log::info("Lancamento vinculado a amarração existente", [
                    'lancamento_id' => $lancamento->id,
                    'amarracao_id' => $amarracaoExistente->id,
                    'terceiro' => $terceiroNome,
                    'conta_debito' => $lancamento->conta_debito,
                    'conta_credito' => $lancamento->conta_credito
                ]);
            } else {
                // Criar nova amarração sem detalhes da operação
                $novaAmarracao = \App\Models\Amarracao::create([
                    'terceiro' => $terceiroNome,
                    'detalhes_operacao' => null, // Sem detalhes da operação
                    'conta_debito' => $lancamento->conta_debito,
                    'conta_credito' => $lancamento->conta_credito,
                    'codigo_sistema_empresa' => $codigoSistemaEmpresa,
                ]);
                
                // Vincular lançamento à nova amarração
                $lancamento->amarracao_id = $novaAmarracao->id;
                $lancamento->save();
                
                Log::info("Nova amarração criada e vinculada ao lançamento", [
                    'lancamento_id' => $lancamento->id,
                    'amarracao_id' => $novaAmarracao->id,
                    'terceiro' => $terceiroNome,
                    'conta_debito' => $lancamento->conta_debito,
                    'conta_credito' => $lancamento->conta_credito,
                    'codigo_sistema' => $codigoSistemaEmpresa
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error("Erro ao criar amarração para lançamento", [
                'lancamento_id' => $lancamento->id,
                'erro' => $e->getMessage()
            ]);
        }
    }

    public function atualizarLancamentosComAmarracoes()
    {
        try {
            if (empty($this->filtroImportacao)) {
                session()->flash('error', 'Nenhuma importação selecionada.');
                return;
            }

            Log::info("=== INICIANDO ATUALIZAÇÃO DE LANÇAMENTOS COM AMARRAÇÕES ===", [
                'importacao_id' => $this->filtroImportacao
            ]);

            // Buscar lançamentos da importação que têm contas vazias (débito ou crédito vazio)
            $lancamentos = Lancamento::where('importacao_id', $this->filtroImportacao)
                ->where(function($query) {
                    $query->where('conta_debito', '')
                          ->orWhere('conta_credito', '')
                          ->orWhereNull('conta_debito')
                          ->orWhereNull('conta_credito');
                })
                ->with(['terceiro', 'empresa'])
                ->get();

            Log::info("Lançamentos encontrados com contas vazias", [
                'total' => $lancamentos->count()
            ]);

            $atualizados = 0;
            $naoEncontrados = 0;

            foreach ($lancamentos as $lancamento) {
                // Buscar terceiro através do terceiro_id
                $terceiroNome = '';
                if ($lancamento->terceiro_id) {
                    $terceiro = \App\Models\Terceiro::find($lancamento->terceiro_id);
                    $terceiroNome = $terceiro ? $terceiro->nome : '';
                } else {
                    $terceiroNome = $lancamento->nome_empresa ?? '';
                }

                // Buscar código do sistema da empresa
                $codigoSistemaEmpresa = $lancamento->empresa ? $lancamento->empresa->codigo_sistema : null;

                // Verificar se tem código do sistema
                if (empty($codigoSistemaEmpresa)) {
                    $naoEncontrados++;
                    continue;
                }

                // Verificar se tem terceiro - obrigatório para fazer a correspondência
                if (empty($terceiroNome)) {
                    $naoEncontrados++;
                    continue;
                }

                // Buscar amarração correspondente
                // Deve ter correspondência exata: terceiro + código do sistema + contas
                $query = \App\Models\Amarracao::where('terceiro', $terceiroNome)
                    ->where('codigo_sistema_empresa', $codigoSistemaEmpresa);

                // Se débito está vazio, buscar amarração com débito preenchido
                if (empty($lancamento->conta_debito)) {
                    $query->whereNotNull('conta_debito')
                          ->where('conta_debito', '!=', '');
                } else {
                    $query->where('conta_debito', $lancamento->conta_debito);
                }

                // Se crédito está vazio, buscar amarração com crédito preenchido
                if (empty($lancamento->conta_credito)) {
                    $query->whereNotNull('conta_credito')
                          ->where('conta_credito', '!=', '');
                } else {
                    $query->where('conta_credito', $lancamento->conta_credito);
                }

                $amarracao = $query->first();

                if ($amarracao) {
                    // Atualizar lançamento com a amarração encontrada
                    $lancamento->amarracao_id = $amarracao->id;
                    
                    // Atualizar contas vazias com as contas da amarração
                    if (empty($lancamento->conta_debito) && !empty($amarracao->conta_debito)) {
                        $lancamento->conta_debito = $amarracao->conta_debito;
                    }
                    if (empty($lancamento->conta_credito) && !empty($amarracao->conta_credito)) {
                        $lancamento->conta_credito = $amarracao->conta_credito;
                    }
                    
                    $lancamento->save();

                    $atualizados++;

                    Log::info("Lançamento atualizado com amarração", [
                        'lancamento_id' => $lancamento->id,
                        'amarracao_id' => $amarracao->id,
                        'terceiro' => $terceiroNome,
                        'conta_debito_antes' => $lancamento->getOriginal('conta_debito'),
                        'conta_credito_antes' => $lancamento->getOriginal('conta_credito'),
                        'conta_debito_depois' => $lancamento->conta_debito,
                        'conta_credito_depois' => $lancamento->conta_credito
                    ]);
                } else {
                    $naoEncontrados++;

                    Log::info("Nenhuma amarração encontrada para lançamento", [
                        'lancamento_id' => $lancamento->id,
                        'terceiro' => $terceiroNome,
                        'codigo_sistema' => $codigoSistemaEmpresa,
                        'conta_debito' => $lancamento->conta_debito,
                        'conta_credito' => $lancamento->conta_credito
                    ]);
                }
            }

            Log::info("=== ATUALIZAÇÃO CONCLUÍDA ===", [
                'total_processados' => $lancamentos->count(),
                'atualizados' => $atualizados,
                'nao_encontrados' => $naoEncontrados
            ]);

            session()->flash('message', "Atualização concluída! {$atualizados} lançamentos com correspondência exata de terceiro e amarração foram atualizados, {$naoEncontrados} não encontraram amarração correspondente.");

        } catch (\Exception $e) {
            Log::error("Erro ao atualizar lançamentos com amarrações", [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            session()->flash('error', 'Erro ao atualizar lançamentos: ' . $e->getMessage());
        }
    }

    public function updatingPerPage()
    {
        $this->resetPage();
    }

    private function getLancamentosQuery()
    {
        $query = Lancamento::with(['importacao', 'terceiro']);

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
        
        // Carregar importações com nome da empresa
        $importacoes = Importacao::with('empresa')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($importacao) {
                $empresaNome = $importacao->empresa ? $importacao->empresa->nome : 'Sem empresa';
                return [
                    'id' => $importacao->id,
                    'nome_arquivo' => $importacao->nome_arquivo,
                    'empresa_nome' => $empresaNome,
                    'display_text' => "ID: {$importacao->id} - {$importacao->nome_arquivo} - {$empresaNome}"
                ];
            });

        return view('livewire.tabela-lancamentos', [
            'lancamentos' => $lancamentos,
            'importacoes' => $importacoes
        ]);
    }

    // Métodos para confirmar as ações do modal
    public function confirmarSalvarContaAmarracao()
    {
        $this->confirmarSalvarAmarracao = true;
        $this->salvarEdicao();
    }
    public function cancelarConfirmacaoEdicao()
    {
        // Se há dados pendentes de edição, salvar sem atualizar a amarração
        if ($this->edicaoLancamentoId && $this->edicaoCampo && $this->edicaoValor) {
            $lancamento = Lancamento::find($this->edicaoLancamentoId);
            if ($lancamento) {
                $campo = $this->edicaoCampo;
                $valorNovo = $this->edicaoValor;
                $valorAnterior = $lancamento->{$campo};
                
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
            }
        }
        
        // Limpar todas as propriedades
        $this->confirmarSalvarAmarracao = false;
        $this->edicaoPendente = null;
        $this->edicaoTipo = '';
        $this->edicaoCampo = '';
        $this->edicaoValor = '';
        $this->edicaoLancamentoId = null;
        $this->edicaoAmarracaoId = null;
        $this->cancelarEdicao();
    }
}
