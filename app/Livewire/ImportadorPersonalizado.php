<?php

namespace App\Livewire;

use App\Models\LayoutImportacao;
use App\Models\LayoutColuna;
use App\Models\RegraAmarracaoDescricao;
use App\Models\RegraAmarracaoImportacao;
use App\Models\Lancamento;
use App\Models\Importacao;
use App\Models\Empresa;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\ConversorExcelService;

class ImportadorPersonalizado extends Component
{
    use WithFileUploads;

    #[Rule('required|file|extensions:csv,xls,xlsx|max:10240')]
    public $arquivo;

    public $colunasArquivo = [];
    public $mapeamentoColunas = [];
    public $dadosPrevia = [];
    public $nomeLayout = '';
    public $tipoArquivo = '';
    public $delimitador = ',';
    public $temCabecalho = true;
    public $linhaCabecalho = 1; // Número da linha do cabeçalho (1-indexed)
    public $colunaDescricao = '';
    public $aplicarRegrasAutomaticas = false;
    public $layoutSelecionado = null;
    public $layoutsDisponiveis = [];
    public $empresa_id = null;
    public $empresas = [];
    public $layoutAtualizado = false;
    public $converterNegativosParaPositivos = true;

    // Novas propriedades para regras de amarração
    public $regrasAmarracao = [];
    public $regrasDisponiveis = [];
    public $regraSelecionada = null;
    public $colunasIncompatíveis = null;
    public $regraAtual = [
        'nome_regra' => '',
        'tipo' => 'automatica',
        'coluna_data' => '',
        'coluna_descricao' => '',
        'coluna_documento' => '',
        'conta_debito_fixa' => '',
        'conta_credito_fixa' => '',
        'historico_fixo' => '',
        'centro_custo_fixo' => '',
        'colunas_valores' => [''],
        'contas_debito' => [''],
        'contas_credito' => [''],
        'historicos' => [''],
    ];

    public $step = 1; // 1: upload, 2: mapeamento, 3: previa, 4: confirmacao
    public $totalLinhas = 0;
    public $linhasProcessadas = 0;
    public $processando = false; // Controla o estado de processamento

    protected $listeners = ['atualizarPrevia'];

    public function mount()
    {
        $this->carregarEmpresas();
        $this->carregarLayoutsDisponiveis();
    }

    public function carregarEmpresas()
    {
        $this->empresas = Empresa::orderBy('nome')->get();
        // Não definir empresa padrão - usuário deve escolher
        $this->empresa_id = null;
    }

    public function carregarLayoutsDisponiveis()
    {
        if ($this->empresa_id) {
            $this->layoutsDisponiveis = LayoutImportacao::where('empresa_id', $this->empresa_id)
                ->orderBy('nome')
                ->get();
        } else {
            $this->layoutsDisponiveis = collect();
        }
    }

    public function carregarRegrasDisponiveis()
    {
        if ($this->empresa_id) {
            $this->regrasDisponiveis = RegraAmarracaoImportacao::whereHas('layoutImportacao', function($query) {
                $query->where('empresa_id', $this->empresa_id);
            })->orderBy('nome_regra')->get();
        } else {
            $this->regrasDisponiveis = collect();
        }
    }

    public function updatedEmpresaId()
    {
        $this->carregarLayoutsDisponiveis();
        $this->carregarRegrasDisponiveis();
        $this->layoutSelecionado = null; // Resetar layout selecionado
        $this->regraSelecionada = null; // Resetar regra selecionada
    }

    // Métodos para gerenciar regras de amarração
    public function adicionarRegra()
    {
        $this->regrasAmarracao[] = $this->regraAtual;
        $this->resetarRegraAtual();
    }

    public function removerRegra($indice)
    {
        unset($this->regrasAmarracao[$indice]);
        $this->regrasAmarracao = array_values($this->regrasAmarracao);
    }

    public function resetarRegraAtual()
    {
        $this->regraAtual = [
            'nome_regra' => '',
            'tipo' => 'automatica',
            'coluna_data' => '',
            'coluna_descricao' => '',
            'coluna_documento' => '',
            'conta_debito_fixa' => '',
            'conta_credito_fixa' => '',
            'historico_fixo' => '',
            'centro_custo_fixo' => '',
            'colunas_valores' => [''],
            'contas_debito' => [''],
            'contas_credito' => [''],
            'historicos' => [''],
        ];
    }

    public function adicionarValorMultiplo($indiceRegra = null)
    {
        if ($indiceRegra !== null) {
            // Adicionar valor a uma regra específica
            $this->regrasAmarracao[$indiceRegra]['colunas_valores'][] = '';
            $this->regrasAmarracao[$indiceRegra]['contas_debito'][] = '';
            $this->regrasAmarracao[$indiceRegra]['contas_credito'][] = '';
            $this->regrasAmarracao[$indiceRegra]['historicos'][] = '';
        } else {
            // Adicionar valor à regra atual (para quando ainda não foi salva)
            $this->regraAtual['colunas_valores'][] = '';
            $this->regraAtual['contas_debito'][] = '';
            $this->regraAtual['contas_credito'][] = '';
            $this->regraAtual['historicos'][] = '';
        }
    }

    public function removerValorMultiplo($indice, $indiceRegra = null)
    {
        if ($indiceRegra !== null) {
            // Remover valor de uma regra específica
            unset($this->regrasAmarracao[$indiceRegra]['colunas_valores'][$indice]);
            unset($this->regrasAmarracao[$indiceRegra]['contas_debito'][$indice]);
            unset($this->regrasAmarracao[$indiceRegra]['contas_credito'][$indice]);
            unset($this->regrasAmarracao[$indiceRegra]['historicos'][$indice]);
            
            $this->regrasAmarracao[$indiceRegra]['colunas_valores'] = array_values($this->regrasAmarracao[$indiceRegra]['colunas_valores']);
            $this->regrasAmarracao[$indiceRegra]['contas_debito'] = array_values($this->regrasAmarracao[$indiceRegra]['contas_debito']);
            $this->regrasAmarracao[$indiceRegra]['contas_credito'] = array_values($this->regrasAmarracao[$indiceRegra]['contas_credito']);
            $this->regrasAmarracao[$indiceRegra]['historicos'] = array_values($this->regrasAmarracao[$indiceRegra]['historicos']);
        } else {
            // Remover valor da regra atual
            unset($this->regraAtual['colunas_valores'][$indice]);
            unset($this->regraAtual['contas_debito'][$indice]);
            unset($this->regraAtual['contas_credito'][$indice]);
            unset($this->regraAtual['historicos'][$indice]);
            
            $this->regraAtual['colunas_valores'] = array_values($this->regraAtual['colunas_valores']);
            $this->regraAtual['contas_debito'] = array_values($this->regraAtual['contas_debito']);
            $this->regraAtual['contas_credito'] = array_values($this->regraAtual['contas_credito']);
            $this->regraAtual['historicos'] = array_values($this->regraAtual['historicos']);
        }
    }

    // Métodos para salvar e selecionar regras
    public function salvarRegra()
    {
        $this->validate([
            'regraAtual.nome_regra' => 'required|string|max:255',
            'regraAtual.tipo' => 'required|in:automatica,manual',
        ]);

        // Criar nova regra
        $regra = new RegraAmarracaoImportacao();
        $regra->nome_regra = $this->regraAtual['nome_regra'];
        $regra->tipo = $this->regraAtual['tipo'];
        $regra->coluna_data = $this->regraAtual['coluna_data'];
        $regra->coluna_descricao = $this->regraAtual['coluna_descricao'];
        $regra->coluna_documento = $this->regraAtual['coluna_documento'];
        $regra->conta_debito_fixa = $this->regraAtual['conta_debito_fixa'];
        $regra->conta_credito_fixa = $this->regraAtual['conta_credito_fixa'];
        $regra->historico_fixo = $this->regraAtual['historico_fixo'];
        $regra->centro_custo_fixo = $this->regraAtual['centro_custo_fixo'];
        $regra->colunas_valores = $this->regraAtual['colunas_valores'];
        $regra->contas_debito = $this->regraAtual['contas_debito'];
        $regra->contas_credito = $this->regraAtual['contas_credito'];
        $regra->historicos = $this->regraAtual['historicos'];
        $regra->ativo = true;
        $regra->ordem = 1;

        // Associar ao layout se houver um selecionado
        if ($this->layoutSelecionado) {
            $regra->layout_importacao_id = $this->layoutSelecionado;
        }

        $regra->save();

        // Recarregar regras disponíveis
        $this->carregarRegrasDisponiveis();

        // Resetar regra atual
        $this->resetarRegraAtual();

        session()->flash('message', 'Regra salva com sucesso!');
    }

    public function selecionarRegra($regraId)
    {
        $regra = RegraAmarracaoImportacao::find($regraId);
        if ($regra) {
            $this->regraSelecionada = $regraId;
            $this->regraAtual = [
                'nome_regra' => $regra->nome_regra,
                'tipo' => $regra->tipo,
                'coluna_data' => $regra->coluna_data,
                'coluna_descricao' => $regra->coluna_descricao,
                'coluna_documento' => $regra->coluna_documento,
                'conta_debito_fixa' => $regra->conta_debito_fixa,
                'conta_credito_fixa' => $regra->conta_credito_fixa,
                'historico_fixo' => $regra->historico_fixo,
                'centro_custo_fixo' => $regra->centro_custo_fixo,
                'colunas_valores' => $regra->colunas_valores ?? [''],
                'contas_debito' => $regra->contas_debito ?? [''],
                'contas_credito' => $regra->contas_credito ?? [''],
                'historicos' => $regra->historicos ?? [''],
            ];
        }
    }

    public function aplicarRegraSelecionada()
    {
        if ($this->regraSelecionada) {
            $regra = RegraAmarracaoImportacao::find($this->regraSelecionada);
            if ($regra) {
                // Verificar compatibilidade das colunas
                $colunasRegra = $this->extrairColunasDaRegra($regra);
                $colunasArquivo = $this->colunasArquivo;
                
                $colunasCompatíveis = $this->verificarCompatibilidadeColunas($colunasRegra, $colunasArquivo);
                
                if ($colunasCompatíveis['total_compativel']) {
                    // Todas as colunas são compatíveis, aplicar regra normalmente
                    $this->aplicarRegraCompleta($regra);
                    session()->flash('message', 'Regra aplicada com sucesso! Todas as colunas são compatíveis.');
                } else {
                    // Algumas colunas não são compatíveis, aplicar parcialmente
                    $this->aplicarRegraParcial($regra, $colunasCompatíveis);
                    session()->flash('message', 'Regra aplicada parcialmente. Algumas colunas não foram encontradas no arquivo atual. Verifique e ajuste o mapeamento.');
                }
            }
        }
    }

    private function extrairColunasDaRegra($regra)
    {
        $colunas = [];
        
        // Colunas básicas
        if ($regra->coluna_data) $colunas[] = $regra->coluna_data;
        if ($regra->coluna_descricao) $colunas[] = $regra->coluna_descricao;
        if ($regra->coluna_documento) $colunas[] = $regra->coluna_documento;
        
        // Colunas de valores múltiplos
        if ($regra->colunas_valores) {
            foreach ($regra->colunas_valores as $coluna) {
                if ($coluna) $colunas[] = $coluna;
            }
        }
        
        return array_unique($colunas);
    }

    private function verificarCompatibilidadeColunas($colunasRegra, $colunasArquivo)
    {
        $resultado = [
            'total_compativel' => true,
            'colunas_encontradas' => [],
            'colunas_nao_encontradas' => [],
            'sugestoes' => []
        ];
        
        foreach ($colunasRegra as $colunaRegra) {
            if (in_array($colunaRegra, $colunasArquivo)) {
                $resultado['colunas_encontradas'][] = $colunaRegra;
            } else {
                $resultado['colunas_nao_encontradas'][] = $colunaRegra;
                $resultado['total_compativel'] = false;
                
                // Buscar sugestões similares
                $sugestao = $this->encontrarColunaSimilar($colunaRegra, $colunasArquivo);
                if ($sugestao) {
                    $resultado['sugestoes'][$colunaRegra] = $sugestao;
                }
            }
        }
        
        return $resultado;
    }

    private function encontrarColunaSimilar($colunaRegra, $colunasArquivo)
    {
        $colunaRegraLower = strtolower($colunaRegra);
        
        // Buscar por correspondência exata (case insensitive)
        foreach ($colunasArquivo as $colunaArquivo) {
            if (strtolower($colunaArquivo) === $colunaRegraLower) {
                return $colunaArquivo;
            }
        }
        
        // Buscar por correspondência parcial
        foreach ($colunasArquivo as $colunaArquivo) {
            $colunaArquivoLower = strtolower($colunaArquivo);
            
            // Verificar se uma contém a outra
            if (strpos($colunaRegraLower, $colunaArquivoLower) !== false || 
                strpos($colunaArquivoLower, $colunaRegraLower) !== false) {
                return $colunaArquivo;
            }
        }
        
        // Buscar por palavras-chave comuns
        $palavrasChave = ['data', 'valor', 'descricao', 'historico', 'documento', 'conta'];
        foreach ($palavrasChave as $palavra) {
            if (strpos($colunaRegraLower, $palavra) !== false) {
                foreach ($colunasArquivo as $colunaArquivo) {
                    $colunaArquivoLower = strtolower($colunaArquivo);
                    if (strpos($colunaArquivoLower, $palavra) !== false) {
                        return $colunaArquivo;
                    }
                }
            }
        }
        
        return null;
    }

    private function aplicarRegraCompleta($regra)
    {
        // Aplicar mapeamento da regra
        $this->mapeamentoColunas = $regra->mapeamento_colunas;
        
        // Aplicar valores fixos se for regra manual
        if ($regra->tipo === 'manual') {
            $this->regraAtual = [
                'nome_regra' => $regra->nome_regra,
                'tipo' => $regra->tipo,
                'coluna_data' => $regra->coluna_data,
                'coluna_descricao' => $regra->coluna_descricao,
                'coluna_documento' => $regra->coluna_documento,
                'conta_debito_fixa' => $regra->conta_debito_fixa,
                'conta_credito_fixa' => $regra->conta_credito_fixa,
                'historico_fixo' => $regra->historico_fixo,
                'centro_custo_fixo' => $regra->centro_custo_fixo,
                'colunas_valores' => $regra->colunas_valores ?? [''],
                'contas_debito' => $regra->contas_debito ?? [''],
                'contas_credito' => $regra->contas_credito ?? [''],
                'historicos' => $regra->historicos ?? [''],
            ];
        }
    }

    private function aplicarRegraParcial($regra, $colunasCompatíveis)
    {
        // Aplicar apenas as colunas que são compatíveis
        $mapeamentoParcial = [];
        
        // Mapear colunas encontradas
        foreach ($colunasCompatíveis['colunas_encontradas'] as $coluna) {
            if ($regra->coluna_data === $coluna) {
                $mapeamentoParcial[$coluna] = 'data';
            } elseif ($regra->coluna_descricao === $coluna) {
                $mapeamentoParcial[$coluna] = 'descricao';
            } elseif ($regra->coluna_documento === $coluna) {
                $mapeamentoParcial[$coluna] = 'documento';
            }
        }
        
        $this->mapeamentoColunas = $mapeamentoParcial;
        
        // Aplicar valores fixos se for regra manual
        if ($regra->tipo === 'manual') {
            $this->regraAtual = [
                'nome_regra' => $regra->nome_regra,
                'tipo' => $regra->tipo,
                'coluna_data' => in_array($regra->coluna_data, $colunasCompatíveis['colunas_encontradas']) ? $regra->coluna_data : '',
                'coluna_descricao' => in_array($regra->coluna_descricao, $colunasCompatíveis['colunas_encontradas']) ? $regra->coluna_descricao : '',
                'coluna_documento' => in_array($regra->coluna_documento, $colunasCompatíveis['colunas_encontradas']) ? $regra->coluna_documento : '',
                'conta_debito_fixa' => $regra->conta_debito_fixa,
                'conta_credito_fixa' => $regra->conta_credito_fixa,
                'historico_fixo' => $regra->historico_fixo,
                'centro_custo_fixo' => $regra->centro_custo_fixo,
                'colunas_valores' => $this->filtrarColunasValores($regra->colunas_valores ?? [], $colunasCompatíveis),
                'contas_debito' => $regra->contas_debito ?? [''],
                'contas_credito' => $regra->contas_credito ?? [''],
                'historicos' => $regra->historicos ?? [''],
            ];
        }
        
        // Armazenar informações sobre incompatibilidades para exibição
        $this->colunasIncompatíveis = $colunasCompatíveis;
    }

    private function filtrarColunasValores($colunasValores, $colunasCompatíveis)
    {
        $colunasFiltradas = [];
        foreach ($colunasValores as $coluna) {
            if (in_array($coluna, $colunasCompatíveis['colunas_encontradas'])) {
                $colunasFiltradas[] = $coluna;
            } else {
                $colunasFiltradas[] = ''; // Manter estrutura, mas sem valor
            }
        }
        return $colunasFiltradas;
    }

    public function updatedArquivo()
    {
        try {
            Log::info('Arquivo atualizado:', ['nome' => $this->arquivo ? $this->arquivo->getClientOriginalName() : 'null']);
            
            if ($this->arquivo) {
                $this->processarArquivo();
            }
        } catch (\Exception $e) {
            Log::error('Erro ao processar arquivo atualizado:', [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Resetar estado em caso de erro
            $this->step = 1;
            $this->colunasArquivo = [];
            $this->mapeamentoColunas = [];
            $this->dadosPrevia = [];
            $this->processando = false;
            
            session()->flash('error', 'Erro ao processar arquivo: ' . $e->getMessage());
        }
    }

    public function processarArquivo()
    {
        try {
            Log::info('Iniciando processamento de arquivo:', [
                'nome' => $this->arquivo->getClientOriginalName(),
                'tamanho' => $this->arquivo->getSize(),
                'tipo' => $this->arquivo->getMimeType()
            ]);
            
            $this->processando = true; // Ativar indicador de processamento
            
            $this->step = 1;
            $this->colunasArquivo = [];
            $this->mapeamentoColunas = [];
            $this->dadosPrevia = [];

            $extensao = strtolower($this->arquivo->getClientOriginalExtension());
            $this->tipoArquivo = $extensao;

            // Sugerir nome do layout baseado no nome do arquivo
            $nomeArquivo = pathinfo($this->arquivo->getClientOriginalName(), PATHINFO_FILENAME);
            $this->nomeLayout = $nomeArquivo;

            // Detectar delimitador para CSV
            if ($extensao === 'csv') {
                $this->detectarDelimitador();
            }

            // Ler cabeçalho do arquivo
            $this->lerCabecalho();
            
            Log::info('Processamento de arquivo concluído com sucesso');
            
        } catch (\Exception $e) {
            Log::error('Erro ao processar arquivo:', [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            session()->flash('error', 'Erro ao processar arquivo: ' . $e->getMessage());
        } finally {
            $this->processando = false; // Desativar indicador de processamento
        }
    }

    public function detectarDelimitador()
    {
        $conteudo = file_get_contents($this->arquivo->getRealPath());
        $primeirasLinhas = substr($conteudo, 0, 1000);
        
        $delimitadores = [',', ';', "\t", '|'];
        $maxCampos = 0;
        $melhorDelimitador = ',';

        foreach ($delimitadores as $delim) {
            $linhas = explode("\n", $primeirasLinhas);
            $primeiraLinha = $linhas[0] ?? '';
            $campos = explode($delim, $primeiraLinha);
            
            if (count($campos) > $maxCampos) {
                $maxCampos = count($campos);
                $melhorDelimitador = $delim;
            }
        }

        $this->delimitador = $melhorDelimitador;
    }

    /**
     * Lê a linha do cabeçalho de um arquivo CSV, pulando as primeiras N linhas.
     * @param resource $handle Handle do arquivo aberto
     * @return array|false Linha do cabeçalho ou false
     */
    private function lerLinhaCabecalhoCsv($handle)
    {
        $linhaNumero = 0;
        while (($linha = fgetcsv($handle, 0, $this->delimitador)) !== false) {
            $linhaNumero++;
            if ($linhaNumero < $this->linhaCabecalho) {
                continue;
            }
            return $linha;
        }
        return false;
    }

    public function lerCabecalho()
    {
        $extensao = $this->tipoArquivo;
        
        // Usar o conversor Python para ambos CSV e Excel para garantir consistência
        $this->lerCabecalhoComConversor();
    }
    
    private function lerCabecalhoComConversor()
    {
        try {
            $this->processando = true; // Ativar indicador de processamento
            
            // Usar o conversor Python para detectar tipos e converter para CSV
            $conversor = new ConversorExcelService();
            
            // Criar diretório temp se não existir
            $diretorioTemp = storage_path("app/temp");
            if (!is_dir($diretorioTemp)) {
                mkdir($diretorioTemp, 0755, true);
            }
            
            $resultado = $conversor->converterExcelParaCsv(
                $this->arquivo->getRealPath(),
                $this->delimitador
            );
            
            if ($resultado['sucesso']) {
                // Ler o cabeçalho do CSV convertido (pulando linhas iniciais se necessário)
                $handle = fopen($resultado['arquivo_csv'], 'r');
                $cabecalho = $this->lerLinhaCabecalhoCsv($handle);
                fclose($handle);
                
                if ($this->temCabecalho && $cabecalho) {
                    $this->colunasArquivo = array_map('trim', $cabecalho);
                } else {
                    // Se não tem cabeçalho, criar nomes automáticos
                    $this->colunasArquivo = array_map(function($i) {
                        return "Coluna " . ($i + 1);
                    }, range(0, count($cabecalho) - 1));
                }
                
                // Carregar prévia automática do CSV convertido
                $this->carregarPreviaAutomaticaCsvConvertido($resultado['arquivo_csv']);
                
                // Limpar arquivo temporário
                if (file_exists($resultado['arquivo_csv'])) {
                    unlink($resultado['arquivo_csv']);
                }
                
                Log::info('Cabeçalho lido via conversor Python:', [
                    'cabecalho' => $this->colunasArquivo,
                    'tipos_detectados' => $resultado['tipos_detectados']
                ]);
            } else {
                // Fallback para o método antigo se Python falhar
                Log::warning('Conversor Python falhou, usando fallback');
                if ($this->tipoArquivo === 'csv') {
                    $this->lerCabecalhoCsv();
                } else {
                    $this->lerCabecalhoExcelFallback();
                }
                return;
            }
            
        } catch (\Exception $e) {
            Log::error('Erro ao usar conversor Python:', ['erro' => $e->getMessage()]);
            // Fallback para o método antigo
            if ($this->tipoArquivo === 'csv') {
                $this->lerCabecalhoCsv();
            } else {
                $this->lerCabecalhoExcelFallback();
            }
            return;
        } finally {
            $this->processando = false; // Desativar indicador de processamento
        }
        
        $this->step = 2;
    }

    public function lerCabecalhoCsv()
    {
        $this->processando = true; // Ativar indicador de processamento
        
        try {
            Log::info('Lendo cabeçalho CSV:', [
                'arquivo' => $this->arquivo->getRealPath(),
                'delimitador' => $this->delimitador,
                'tem_cabecalho' => $this->temCabecalho
            ]);
            
            $handle = fopen($this->arquivo->getRealPath(), 'r');
            if (!$handle) {
                throw new \Exception('Não foi possível abrir o arquivo CSV');
            }
            
            $cabecalho = $this->lerLinhaCabecalhoCsv($handle);
            
            Log::info('Cabeçalho lido do CSV:', ['cabecalho' => $cabecalho, 'tem_cabecalho' => $this->temCabecalho]);

            if ($this->temCabecalho && $cabecalho) {
                $this->colunasArquivo = array_map('trim', $cabecalho);
            } else {
                // Se não tem cabeçalho, criar nomes automáticos
                $this->colunasArquivo = array_map(function($i) {
                    return "Coluna " . ($i + 1);
                }, range(0, count($cabecalho) - 1));
            }

            // Carregar prévia automática de 10 linhas
            $this->carregarPreviaAutomaticaCsv($handle);

            fclose($handle);
            Log::info('Colunas do arquivo definidas:', ['colunas' => $this->colunasArquivo]);
            $this->step = 2;
        } catch (\Exception $e) {
            Log::error('Erro ao ler cabeçalho CSV:', [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        } finally {
            $this->processando = false; // Desativar indicador de processamento
        }
    }

    public function lerCabecalhoExcel()
    {
        try {
            $this->processando = true; // Ativar indicador de processamento
            
            // Usar o conversor Python para detectar tipos e converter para CSV
            $conversor = new ConversorExcelService();
            
            // Criar diretório temp se não existir
            $diretorioTemp = storage_path("app/temp");
            if (!is_dir($diretorioTemp)) {
                mkdir($diretorioTemp, 0755, true);
            }
            
            // Criar arquivo temporário para detecção
            $arquivoTemp = $diretorioTemp . "/detectar_cabecalho_" . time() . ".csv";
            
            $resultado = $conversor->converterExcelParaCsv(
                $this->arquivo->getRealPath(),
                $this->delimitador
            );
            
            if ($resultado['sucesso']) {
                // Ler o cabeçalho do CSV convertido (pulando linhas iniciais se necessário)
                $handle = fopen($resultado['arquivo_csv'], 'r');
                $cabecalho = $this->lerLinhaCabecalhoCsv($handle);
                fclose($handle);
                
                if ($this->temCabecalho && $cabecalho) {
                    $this->colunasArquivo = array_map('trim', $cabecalho);
                } else {
                    // Se não tem cabeçalho, criar nomes automáticos
                    $this->colunasArquivo = array_map(function($i) {
                        return "Coluna " . ($i + 1);
                    }, range(0, count($cabecalho) - 1));
                }
                
                // Carregar prévia automática do CSV convertido
                $this->carregarPreviaAutomaticaCsvConvertido($resultado['arquivo_csv']);
                
                // Limpar arquivo temporário
                if (file_exists($resultado['arquivo_csv'])) {
                    unlink($resultado['arquivo_csv']);
                }
                
                Log::info('Cabeçalho lido do Excel via Python:', [
                    'cabecalho' => $this->colunasArquivo,
                    'tipos_detectados' => $resultado['tipos_detectados']
                ]);
            } else {
                // Fallback para o método antigo se Python falhar
                Log::warning('Conversor Python falhou, usando fallback PhpSpreadsheet');
                $this->lerCabecalhoExcelFallback();
                return;
            }
            
        } catch (\Exception $e) {
            Log::error('Erro ao usar conversor Python:', ['erro' => $e->getMessage()]);
            // Fallback para o método antigo
            $this->lerCabecalhoExcelFallback();
            return;
        } finally {
            $this->processando = false; // Desativar indicador de processamento
        }
        
        $this->step = 2;
    }
    
    private function lerCabecalhoExcelFallback()
    {
        // Método antigo usando PhpSpreadsheet como fallback
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->arquivo->getRealPath());
        $worksheet = $spreadsheet->getActiveSheet();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        $linhaCabecalho = $this->linhaCabecalho;
        $colunas = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellValue = $worksheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $linhaCabecalho)->getValue();
            
            if ($this->temCabecalho && $cellValue) {
                $colunas[] = trim($cellValue);
            } else {
                $colunas[] = "Coluna " . $col;
            }
        }

        $this->colunasArquivo = $colunas;
        
        // Carregar prévia automática de 10 linhas
        $this->carregarPreviaAutomaticaExcel($worksheet);
        
        $this->step = 2;
    }
    
    private function carregarPreviaAutomaticaCsvConvertido($arquivoCsv)
    {
        $this->dadosPrevia = [];
        $this->totalLinhas = 0;
        
        $handle = fopen($arquivoCsv, 'r');
        $linhaNumero = 0;
        $maxLinhas = 10;
        $linhaInicialDados = $this->linhaCabecalho + ($this->temCabecalho ? 1 : 0); // Primeira linha de dados
        
        while (($linha = fgetcsv($handle, 0, $this->delimitador)) !== false) {
            $linhaNumero++;
            if ($linhaNumero < $linhaInicialDados) {
                continue; // Pular linhas antes dos dados
            }
            if ($linhaNumero > $linhaInicialDados + $maxLinhas - 1) {
                break;
            }
            
            $this->totalLinhas++;
            $this->dadosPrevia[] = $linha;
        }
        
        fclose($handle);
        
        // Atualizar o indicador de processamento para mostrar que está carregando a prévia
        $this->dispatch('previa-carregada');
    }

    public function updatedLinhaCabecalho()
    {
        $this->recarregarCabecalhoEPrevia();
    }

    public function updatedTemCabecalho()
    {
        $this->recarregarCabecalhoEPrevia();
    }

    private function recarregarCabecalhoEPrevia()
    {
        if (!$this->arquivo || $this->step !== 2) {
            return;
        }
        try {
            $this->lerCabecalho();
        } catch (\Exception $e) {
            Log::error('Erro ao recarregar cabeçalho:', ['erro' => $e->getMessage()]);
            session()->flash('error', 'Erro ao recarregar: ' . $e->getMessage());
        }
    }

    public function carregarLayout($layoutId)
    {
        $layout = LayoutImportacao::find($layoutId);
        if (!$layout) return;

        $this->layoutSelecionado = $layout;
        $this->nomeLayout = $layout->nome;
        $this->tipoArquivo = $layout->tipo_arquivo;
        $this->delimitador = $layout->delimitador ?? ',';
        $this->temCabecalho = $layout->tem_cabecalho;
        $this->linhaCabecalho = $layout->configuracoes['linha_cabecalho'] ?? ($layout->configuracoes['linhas_pular_cabecalho'] ?? 0) + 1;

        // Carregar mapeamento de colunas
        $this->mapeamentoColunas = $layout->getMapeamentoColunas();

        $this->step = 2;
    }

    private function carregarPreviaAutomaticaCsv($handle)
    {
        $this->dadosPrevia = [];
        $this->totalLinhas = 0;
        
        rewind($handle);
        $linhaNumero = 0;
        $maxLinhas = 10;
        $linhaInicialDados = $this->linhaCabecalho + ($this->temCabecalho ? 1 : 0);
        
        while (($linha = fgetcsv($handle, 0, $this->delimitador)) !== false) {
            $linhaNumero++;
            if ($linhaNumero < $linhaInicialDados) {
                continue;
            }
            if ($linhaNumero > $linhaInicialDados + $maxLinhas - 1) {
                break;
            }
            
            $this->totalLinhas++;
            $this->dadosPrevia[] = $linha;
        }
        
        // Atualizar o indicador de processamento para mostrar que está carregando a prévia
        $this->dispatch('previa-carregada');
    }

    private function carregarPreviaAutomaticaExcel($worksheet)
    {
        $this->dadosPrevia = [];
        $this->totalLinhas = 0;
        
        $highestRow = $worksheet->getHighestRow();
        $maxLinhas = 10;
        $linhaInicial = $this->linhaCabecalho + ($this->temCabecalho ? 1 : 0);
        
        for ($row = $linhaInicial; $row <= min($highestRow, $linhaInicial + $maxLinhas - 1); $row++) {
            $linha = [];
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $worksheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row);
                $valor = $cell->getValue();
                
                // Converter datas do Excel se necessário
                if ($this->ehDataExcel($valor)) {
                    $valor = $this->converterDataExcel($valor);
                }
                
                $linha[] = $valor;
            }

            $this->totalLinhas++;
            $this->dadosPrevia[] = $linha;
        }
        
        // Atualizar o indicador de processamento para mostrar que está carregando a prévia
        $this->dispatch('previa-carregada');
    }

    private function ehDataExcel($valor, $colunaIndex = null)
    {
        // Verificar se é um número que pode ser uma data do Excel
        if (is_numeric($valor) && $valor > 1 && $valor < 100000) {
            try {
                // Verificar se está no intervalo típico de datas do Excel (1900-2100)
                $dataTeste = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($valor);
                $ano = (int)$dataTeste->format('Y');
                
                // Verificar se o ano está em um intervalo razoável para datas
                if ($ano >= 1900 && $ano <= 2100) {
                    // Verificar se o valor não é um código ou número de documento
                    // Datas típicas do Excel são entre 1 e 100000
                    // Mas vamos ser mais restritivos para evitar falsos positivos
                    
                    // Se o valor for muito alto, provavelmente não é uma data
                    if ($valor > 50000) {
                        return false;
                    }
                    
                    // Verificar se o mês e dia são válidos
                    $mes = (int)$dataTeste->format('m');
                    $dia = (int)$dataTeste->format('d');
                    
                    if ($mes >= 1 && $mes <= 12 && $dia >= 1 && $dia <= 31) {
                        // Verificação adicional: se temos o nome da coluna, verificar se parece ser uma data
                        if ($colunaIndex !== null && isset($this->colunasArquivo[$colunaIndex - 1])) {
                            $nomeColuna = strtolower($this->colunasArquivo[$colunaIndex - 1]);
                            
                            // Palavras-chave que indicam que é uma data
                            $palavrasData = ['data', 'date', 'dt_', 'emissao', 'recebimento', 'validade', 'quitacao', 'liberacao', 'vencimento', 'compensacao', 'aprovacao'];
                            
                            $ehColunaData = false;
                            foreach ($palavrasData as $palavra) {
                                if (strpos($nomeColuna, $palavra) !== false) {
                                    $ehColunaData = true;
                                    break;
                                }
                            }
                            
                            // Só converter se for uma coluna que parece ser de data
                            return $ehColunaData;
                        }
                        
                        // Se não temos o nome da coluna, ser mais conservador
                        return false;
                    }
                }
            } catch (\Exception $e) {
                return false;
            }
        }
        return false;
    }

    private function converterDataExcel($valor)
    {
        try {
            $data = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($valor);
            return $data->format('d/m/Y');
        } catch (\Exception $e) {
            return $valor; // Retorna o valor original se não conseguir converter
        }
    }

    public function avancarParaPrevia()
    {
        // Validar se empresa foi selecionada
        if (!$this->empresa_id) {
            session()->flash('error', 'Por favor, selecione uma empresa.');
            return;
        }

        $this->step = 3;
        $this->gerarPrevia();
    }

    public function gerarPrevia()
    {
        $this->dadosPrevia = [];
        $this->totalLinhas = 0;

        $extensao = $this->tipoArquivo;
        
        if ($extensao === 'csv') {
            $this->gerarPreviaCsv();
        } else {
            $this->gerarPreviaExcel();
        }
    }

    public function gerarPreviaCsv()
    {
        $handle = fopen($this->arquivo->getRealPath(), 'r');
        $linhaNumero = 0;
        $maxLinhas = 10;
        $linhaInicialDados = $this->linhaCabecalho + ($this->temCabecalho ? 1 : 0);

        while (($linha = fgetcsv($handle, 0, $this->delimitador)) !== false) {
            $linhaNumero++;
            if ($linhaNumero < $linhaInicialDados) {
                continue;
            }

            $this->totalLinhas++;
            
            if ($this->totalLinhas <= $maxLinhas) {
                $dadosProcessados = $this->processarLinha($linha);
                $this->dadosPrevia[] = $dadosProcessados;
            }
        }

        fclose($handle);
    }

    public function gerarPreviaExcel()
    {
        try {
            // Usar o conversor Python para gerar prévia
            $conversor = new ConversorExcelService();
            
            $resultado = $conversor->converterExcelParaCsv(
                $this->arquivo->getRealPath(),
                $this->delimitador
            );
            
            if ($resultado['sucesso']) {
                // Ler prévia do CSV convertido
                $this->gerarPreviaCsvConvertido($resultado['arquivo_csv']);
                
                // Limpar arquivo temporário
                if (file_exists($resultado['arquivo_csv'])) {
                    unlink($resultado['arquivo_csv']);
                }
                
                Log::info('Prévia gerada via Python:', [
                    'total_linhas' => $this->totalLinhas,
                    'tipos_detectados' => $resultado['tipos_detectados']
                ]);
            } else {
                // Fallback para o método antigo se Python falhar
                Log::warning('Conversor Python falhou na prévia, usando fallback PhpSpreadsheet');
                $this->gerarPreviaExcelFallback();
            }
            
        } catch (\Exception $e) {
            Log::error('Erro ao usar conversor Python para prévia:', ['erro' => $e->getMessage()]);
            // Fallback para o método antigo
            $this->gerarPreviaExcelFallback();
        }
    }
    
    private function gerarPreviaExcelFallback()
    {
        // Método antigo usando PhpSpreadsheet como fallback
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->arquivo->getRealPath());
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        $maxLinhas = 10;

        $linhaInicial = $this->linhaCabecalho + ($this->temCabecalho ? 1 : 0);
        
        for ($row = $linhaInicial; $row <= min($highestRow, $linhaInicial + $maxLinhas - 1); $row++) {
            $linha = [];
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $worksheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row);
                $valor = $cell->getValue();
                
                // Converter datas do Excel se necessário
                if ($this->ehDataExcel($valor)) {
                    $valor = $this->converterDataExcel($valor);
                }
                
                $linha[] = $valor;
            }

            $this->totalLinhas++;
            $dadosProcessados = $this->processarLinha($linha);
            $this->dadosPrevia[] = $dadosProcessados;
        }
    }
    
    private function gerarPreviaCsvConvertido($arquivoCsv)
    {
        $handle = fopen($arquivoCsv, 'r');
        $linhaNumero = 0;
        $maxLinhas = 10;
        $linhaInicialDados = $this->linhaCabecalho + ($this->temCabecalho ? 1 : 0);

        while (($linha = fgetcsv($handle, 0, $this->delimitador)) !== false) {
            $linhaNumero++;
            if ($linhaNumero < $linhaInicialDados) {
                continue;
            }

            $this->totalLinhas++;
            
            if ($this->totalLinhas <= $maxLinhas) {
                $dadosProcessados = $this->processarLinha($linha);
                $this->dadosPrevia[] = $dadosProcessados;
            }
        }

        fclose($handle);
    }

    public function processarLinha($linha)
    {
        $resultados = [];
        
        // Se não há regras de amarração, usar o método antigo
        if (empty($this->regrasAmarracao)) {
            $dados = $this->processarLinhaAntigo($linha);
            return [$dados];
        }
        
        // Processar cada regra de amarração
        foreach ($this->regrasAmarracao as $regra) {
            $dados = $this->processarLinhaComRegra($linha, $regra);
            if ($dados) {
                $resultados[] = $dados;
            }
        }
        
        return $resultados;
    }

    private function processarLinhaAntigo($linha)
    {
        $dados = [];
        
        // Normalizar o mapeamento de colunas para garantir que seja um array simples
        $mapeamentoNormalizado = $this->normalizarMapeamentoColunas();
        
        Log::info('Colunas do arquivo:', ['colunas' => $this->colunasArquivo]);
        Log::info('Processando linha:', ['linha' => $linha, 'mapeamento' => $mapeamentoNormalizado]);
        
        foreach ($mapeamentoNormalizado as $colunaArquivo => $campoLancamento) {
            // Busca exata primeiro
            $indice = array_search($colunaArquivo, $this->colunasArquivo);
            
            // Se não encontrar, fazer busca parcial
            if ($indice === false) {
                foreach ($this->colunasArquivo as $i => $coluna) {
                    if (stripos($coluna, $colunaArquivo) !== false || stripos($colunaArquivo, $coluna) !== false) {
                        $indice = $i;
                        Log::info('Coluna encontrada por busca parcial:', [
                            'procurada' => $colunaArquivo,
                            'encontrada' => $coluna,
                            'indice' => $indice
                        ]);
                        break;
                    }
                }
            }
            
            $valor = $indice !== false ? ($linha[$indice] ?? '') : '';
            
            Log::info('Mapeando coluna:', [
                'coluna_arquivo' => $colunaArquivo,
                'campo_lancamento' => $campoLancamento,
                'indice' => $indice,
                'valor_original' => $valor
            ]);
            
            $dados[$campoLancamento] = $valor;
        }

        // Aplicar regras automáticas se habilitado
        if ($this->aplicarRegrasAutomaticas && $this->colunaDescricao) {
            $descricao = $dados[$this->colunaDescricao] ?? '';
            if ($descricao) {
                $regras = $this->aplicarRegrasDescricao($descricao);
                $dados = array_merge($dados, $regras);
            }
        }

        Log::info('Dados processados:', $dados);
        return $dados;
    }

    private function processarLinhaComRegra($linha, $regra)
    {
        $dados = [];
        
        if ($regra['tipo'] === 'automatica') {
            // Mapeamento automático
            $mapeamentos = [
                'data' => $regra['coluna_data'],
                'descricao' => $regra['coluna_descricao'],
                'documento' => $regra['coluna_documento'],
            ];
            
            foreach ($mapeamentos as $campo => $coluna) {
                if ($coluna) {
                    $indice = $this->encontrarIndiceColuna($coluna);
                    $valor = $indice !== false ? ($linha[$indice] ?? '') : '';
                    $dados[$campo] = $valor;
                }
            }
            
            // Processar múltiplos valores se configurado
            if (!empty($regra['colunas_valores'][0])) {
                $dados['valores_multiplos'] = [];
                foreach ($regra['colunas_valores'] as $i => $coluna) {
                    if ($coluna) {
                        $indice = $this->encontrarIndiceColuna($coluna);
                        $valor = $indice !== false ? ($linha[$indice] ?? '') : '';
                        $dados['valores_multiplos'][] = [
                            'valor' => $valor,
                            'conta_debito' => $regra['contas_debito'][$i] ?? '',
                            'conta_credito' => $regra['contas_credito'][$i] ?? '',
                            'historico' => $regra['historicos'][$i] ?? '',
                        ];
                    }
                }
            }
        } else {
            // Mapeamento manual - usar valores fixos
            $dados = [
                'conta_debito' => $regra['conta_debito_fixa'],
                'conta_credito' => $regra['conta_credito_fixa'],
                'historico' => $regra['historico_fixo'],
                'centro_custo' => $regra['centro_custo_fixo'],
            ];
            
            // Buscar data das colunas mapeadas
            if ($regra['coluna_data']) {
                $indice = $this->encontrarIndiceColuna($regra['coluna_data']);
                $dados['data'] = $indice !== false ? ($linha[$indice] ?? '') : '';
            }
        }
        
        return $dados;
    }

    private function encontrarIndiceColuna($coluna)
    {
        // Busca exata primeiro
        $indice = array_search($coluna, $this->colunasArquivo);
        
        // Se não encontrar, fazer busca parcial
        if ($indice === false) {
            foreach ($this->colunasArquivo as $i => $col) {
                if (stripos($col, $coluna) !== false || stripos($coluna, $col) !== false) {
                    $indice = $i;
                    break;
                }
            }
        }
        
        return $indice;
    }

    private function normalizarMapeamentoColunas()
    {
        $mapeamentoNormalizado = [];
        
        if (is_array($this->mapeamentoColunas)) {
            foreach ($this->mapeamentoColunas as $chave => $valor) {
                // Debug para ver a estrutura
                Log::info('Processando mapeamento:', ['chave' => $chave, 'valor' => $valor]);
                
                // Se a chave é um array (estrutura aninhada do Livewire), extrair o valor real
                if (is_array($chave)) {
                    // Para estruturas aninhadas como ["Dt. Prev. Liquid" => "data"]
                    $chaveReal = $this->extrairChaveReal($chave);
                    $valorReal = is_array($valor) ? $this->extrairValorReal($valor) : $valor;
                    if ($chaveReal && $valorReal) {
                        $mapeamentoNormalizado[$chaveReal] = $valorReal;
                        Log::info('Mapeamento extraído:', ['chave' => $chaveReal, 'valor' => $valorReal]);
                    }
                } else {
                    // Chave simples - mas pode ter valor aninhado
                    $valorReal = is_array($valor) ? $this->extrairValorReal($valor) : $valor;
                    if ($valorReal && $valorReal !== 'Não mapear') {
                        $mapeamentoNormalizado[$chave] = $valorReal;
                        Log::info('Mapeamento simples:', ['chave' => $chave, 'valor' => $valorReal]);
                    }
                }
            }
        }
        
        Log::info('Mapeamento final:', $mapeamentoNormalizado);
        return $mapeamentoNormalizado;
    }

    private function extrairChaveReal($array)
    {
        if (is_array($array)) {
            // Para estruturas como ["Dt. Prev. Liquid" => "data"]
            foreach ($array as $chave => $valor) {
                if (is_string($chave)) {
                    return $chave;
                }
                // Se a chave não é string, pode ser um índice numérico
                if (is_numeric($chave) && is_array($valor)) {
                    $resultado = $this->extrairChaveReal($valor);
                    if ($resultado) return $resultado;
                }
            }
        }
        return null;
    }

    private function extrairValorReal($array)
    {
        if (is_array($array)) {
            // Para estruturas como ["data" => "data"] ou {" Prev":{" Liquid":"data"}}
            foreach ($array as $chave => $valor) {
                if (is_string($valor)) {
                    return $valor;
                }
                // Se o valor não é string, pode ser um array aninhado
                if (is_array($valor)) {
                    $resultado = $this->extrairValorReal($valor);
                    if ($resultado) return $resultado;
                }
            }
        }
        return null;
    }

    public function aplicarRegrasDescricao($descricao)
    {
        $empresaId = auth()->user() ? auth()->user()->empresa_id : 1;
        $regras = RegraAmarracaoDescricao::where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->orderBy('prioridade', 'desc')
            ->get();

        foreach ($regras as $regra) {
            $resultado = $regra->aplicarRegra($descricao);
            if ($resultado) {
                return $resultado;
            }
        }

        return [];
    }

    public function confirmarImportacao()
    {
        $this->step = 4;
        
        // Criar importação
        $importacao = Importacao::create([
            'nome_arquivo' => $this->arquivo->getClientOriginalName(),
            'nome' => $this->nomeLayout,
            'tipo' => 'personalizado',
            'empresa_id' => $this->empresa_id ?? (auth()->user() ? auth()->user()->empresa_id : 1),
            'user_id' => auth()->id(),
            'usuario' => auth()->user() ? auth()->user()->name : 'Sistema',
            'status' => 'processando',
        ]);

        // Salvar layout se não existir
        if (!$this->layoutSelecionado) {
            $this->salvarLayout($importacao);
        }

        // Processar arquivo completo
        $this->processarArquivoCompleto($importacao);

        $mensagem = $this->layoutAtualizado 
            ? 'Importação concluída com sucesso! Layout "' . $this->nomeLayout . '" foi atualizado.'
            : 'Importação concluída com sucesso! Novo layout "' . $this->nomeLayout . '" foi criado.';
        
        session()->flash('message', $mensagem);
        return redirect()->route('importacoes');
    }

    public function salvarLayout($importacao)
    {
        // Verificar se já existe um layout com esse nome para esta empresa
        $empresaId = $this->empresa_id ?? auth()->user()->empresa_id ?? 1;
        $layoutExistente = LayoutImportacao::where('nome', $this->nomeLayout)
            ->where('empresa_id', $empresaId)
            ->first();

        $configuracoes = array_merge(($layoutExistente && $layoutExistente->configuracoes) ? $layoutExistente->configuracoes : [], [
            'linha_cabecalho' => (int) $this->linhaCabecalho,
        ]);

        if ($layoutExistente) {
            // Se existe, usar o layout existente e atualizar as colunas
            $layout = $layoutExistente;
            $this->layoutAtualizado = true;
            $layout->update([
                'delimitador' => $this->delimitador,
                'tem_cabecalho' => $this->temCabecalho,
                'configuracoes' => $configuracoes,
            ]);
            
            // Remover colunas existentes
            LayoutColuna::where('layout_importacao_id', $layout->id)->delete();
        } else {
            $this->layoutAtualizado = false;
            // Se não existe, criar novo layout
            $layout = LayoutImportacao::create([
                'nome' => $this->nomeLayout,
                'tipo_arquivo' => $this->tipoArquivo,
                'delimitador' => $this->delimitador,
                'tem_cabecalho' => $this->temCabecalho,
                'configuracoes' => $configuracoes,
                'empresa_id' => $empresaId,
                'user_id' => auth()->id(),
            ]);
        }

        // Salvar mapeamento de colunas
        $mapeamentoNormalizado = $this->normalizarMapeamentoColunas();
        foreach ($mapeamentoNormalizado as $colunaArquivo => $campoLancamento) {
            LayoutColuna::create([
                'layout_importacao_id' => $layout->id,
                'coluna_arquivo' => $colunaArquivo,
                'campo_lancamento' => $campoLancamento,
                'ordem' => array_search($colunaArquivo, $this->colunasArquivo),
            ]);
        }
    }

    public function processarArquivoCompleto($importacao)
    {
        $extensao = $this->tipoArquivo;
        $linhasProcessadas = 0;
        
        if ($extensao === 'csv') {
            $linhasProcessadas = $this->processarCsvCompleto($importacao);
        } else {
            $linhasProcessadas = $this->processarExcelCompleto($importacao);
        }

        // Atualizar importação
        $importacao->update([
            'status' => 'concluida',
            'total_registros' => $linhasProcessadas,
        ]);
    }

    public function processarCsvCompleto($importacao)
    {
        $handle = fopen($this->arquivo->getRealPath(), 'r');
        $linhaNumero = 0;
        $linhasProcessadas = 0;

        $linhaInicialDados = $this->linhaCabecalho + ($this->temCabecalho ? 1 : 0);

        while (($linha = fgetcsv($handle, 0, $this->delimitador)) !== false) {
            $linhaNumero++;
            if ($linhaNumero < $linhaInicialDados) {
                continue;
            }

            $resultados = $this->processarLinha($linha);
            foreach ($resultados as $dados) {
                $this->criarLancamento($dados, $importacao);
            }
            $linhasProcessadas++;
        }

        fclose($handle);
        return $linhasProcessadas;
    }

    public function processarExcelCompleto($importacao)
    {
        try {
            // Usar o conversor Python para processar arquivo completo
            $conversor = new ConversorExcelService();
            
            $resultado = $conversor->converterExcelParaCsv(
                $this->arquivo->getRealPath(),
                $this->delimitador
            );
            
            if ($resultado['sucesso']) {
                // Processar CSV convertido
                $linhasProcessadas = $this->processarCsvConvertido($resultado['arquivo_csv'], $importacao);
                
                // Limpar arquivo temporário
                if (file_exists($resultado['arquivo_csv'])) {
                    unlink($resultado['arquivo_csv']);
                }
                
                Log::info('Arquivo Excel processado via Python:', [
                    'total_linhas' => $linhasProcessadas,
                    'tipos_detectados' => $resultado['tipos_detectados']
                ]);
                
                return $linhasProcessadas;
            } else {
                // Fallback para o método antigo se Python falhar
                Log::warning('Conversor Python falhou no processamento completo, usando fallback PhpSpreadsheet');
                return $this->processarExcelCompletoFallback($importacao);
            }
            
        } catch (\Exception $e) {
            Log::error('Erro ao usar conversor Python para processamento completo:', ['erro' => $e->getMessage()]);
            // Fallback para o método antigo
            return $this->processarExcelCompletoFallback($importacao);
        }
    }
    
    private function processarExcelCompletoFallback($importacao)
    {
        // Método antigo usando PhpSpreadsheet como fallback
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->arquivo->getRealPath());
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        $linhasProcessadas = 0;

        $linhaInicial = $this->linhaCabecalho + ($this->temCabecalho ? 1 : 0);
        
        for ($row = $linhaInicial; $row <= $highestRow; $row++) {
            $linha = [];
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $linha[] = $worksheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row)->getValue();
            }

            $resultados = $this->processarLinha($linha);
            foreach ($resultados as $dados) {
                $this->criarLancamento($dados, $importacao);
            }
            $linhasProcessadas++;
        }

        return $linhasProcessadas;
    }
    
    private function processarCsvConvertido($arquivoCsv, $importacao)
    {
        $handle = fopen($arquivoCsv, 'r');
        $linhaNumero = 0;
        $linhasProcessadas = 0;
        $linhaInicialDados = $this->linhaCabecalho + ($this->temCabecalho ? 1 : 0);

        while (($linha = fgetcsv($handle, 0, $this->delimitador)) !== false) {
            $linhaNumero++;
            if ($linhaNumero < $linhaInicialDados) {
                continue;
            }

            $resultados = $this->processarLinha($linha);
            foreach ($resultados as $dados) {
                $this->criarLancamento($dados, $importacao);
            }
            $linhasProcessadas++;
        }

        fclose($handle);
        return $linhasProcessadas;
    }

    public function criarLancamento($dados, $importacao)
    {
        // Se há valores múltiplos, criar um lançamento para cada
        if (isset($dados['valores_multiplos']) && !empty($dados['valores_multiplos'])) {
            foreach ($dados['valores_multiplos'] as $valorMultiplo) {
                $dadosLancamento = $this->prepararDadosLancamento($dados, $importacao);
                $dadosLancamento['valor'] = $this->formatarValor($valorMultiplo['valor']);
                $dadosLancamento['conta_debito'] = $valorMultiplo['conta_debito'];
                $dadosLancamento['conta_credito'] = $valorMultiplo['conta_credito'];
                $dadosLancamento['historico'] = $valorMultiplo['historico'];
                
                Lancamento::create($dadosLancamento);
            }
        } else {
            // Lançamento único
            $dadosLancamento = $this->prepararDadosLancamento($dados, $importacao);
            Lancamento::create($dadosLancamento);
        }
    }

    private function prepararDadosLancamento($dados, $importacao)
    {
        // Formatar valor se existir
        if (isset($dados['valor'])) {
            $dados['valor'] = $this->formatarValor($dados['valor']);
        }
        
        // Garantir que campos obrigatórios existam
        $dados['importacao_id'] = $importacao->id;
        $dados['empresa_id'] = $this->empresa_id ?? auth()->user()->empresa_id ?? 1;
        $dados['historico'] = $dados['historico'] ?? '';
        $dados['valor'] = $dados['valor'] ?? 0.00;
        $dados['conta_debito'] = $dados['conta_debito'] ?? '';
        $dados['conta_credito'] = $dados['conta_credito'] ?? '';
        $dados['centro_custo'] = $dados['centro_custo'] ?? '';
        
        // Garantir que codigo_filial_matriz seja salvo
        if (!isset($dados['codigo_filial_matriz']) || empty($dados['codigo_filial_matriz'])) {
            // Buscar código da empresa para usar como fallback
            $empresa = Empresa::find($dados['empresa_id']);
            $codigoSistemaEmpresa = $empresa ? $empresa->codigo_sistema : null;
            $dados['codigo_filial_matriz'] = $codigoSistemaEmpresa ? str_pad($codigoSistemaEmpresa, 7, '0', STR_PAD_LEFT) : null;
        }
        
        // Garantir que data sempre exista
        if (!isset($dados['data']) || empty($dados['data'])) {
            $dados['data'] = date('Y-m-d');
        } else {
            $dados['data'] = $this->formatarData($dados['data']);
        }
        
        return $dados;
    }

    private function formatarValor($valor)
    {
        if (empty($valor)) {
            return 0.00;
        }
        
        $valorOriginal = $valor;
        
        // Remover espaços e caracteres não numéricos exceto vírgula, ponto e hífen
        $valor = trim($valor);
        
        // Remover símbolo R$ e outros símbolos de moeda
        $valor = preg_replace('/R\$\s*/i', '', $valor);
        $valor = preg_replace('/\$\s*/', '', $valor);
        $valor = preg_replace('/€\s*/', '', $valor);
        $valor = preg_replace('/£\s*/', '', $valor);
        
        // Remover caracteres não numéricos exceto vírgula, ponto e hífen
        $valor = preg_replace('/[^\d,.-]/', '', $valor);
        
        // Log para debug
        Log::debug('Formatando valor', [
            'original' => $valorOriginal,
            'limpo' => $valor
        ]);
        
        // Verificar se é negativo (tem hífen)
        $ehNegativo = false;
        if (strpos($valor, '-') !== false) {
            $ehNegativo = true;
            if ($this->converterNegativosParaPositivos) {
                $valor = str_replace('-', '', $valor);
                Log::debug('Valor negativo detectado, convertendo para positivo', ['valor' => $valor]);
            }
        }
        
        // Se tem vírgula e ponto, analisar a posição para determinar qual é separador decimal
        if (strpos($valor, ',') !== false && strpos($valor, '.') !== false) {
            $posVirgula = strrpos($valor, ',');
            $posPonto = strrpos($valor, '.');
            
            // Se a vírgula está depois do ponto, ela é o separador decimal
            if ($posVirgula > $posPonto) {
                // Formato: 5.961,29 (ponto como milhar, vírgula como decimal)
                $valor = str_replace('.', '', $valor);
                $valor = str_replace(',', '.', $valor);
                Log::debug('Formato detectado: brasileiro com ponto milhar', ['valor' => $valor]);
            } else {
                // Formato: 5,961.29 (vírgula como milhar, ponto como decimal)
                $valor = str_replace(',', '', $valor);
                Log::debug('Formato detectado: americano com vírgula milhar', ['valor' => $valor]);
            }
        }
        // Se só tem vírgula, verificar se é separador decimal ou milhar
        elseif (strpos($valor, ',') !== false) {
            $partes = explode(',', $valor);
            // Se tem apenas uma vírgula e a parte após tem 1 ou 2 dígitos, é separador decimal
            if (count($partes) == 2 && (strlen($partes[1]) == 1 || strlen($partes[1]) == 2)) {
                // Formato: 5961,29 ou 3875,2 (vírgula como decimal)
                $valor = str_replace(',', '.', $valor);
                Log::debug('Formato detectado: brasileiro só com vírgula decimal', ['valor' => $valor]);
            } else {
                // Formato: 5,961 (vírgula como milhar)
                $valor = str_replace(',', '', $valor);
                Log::debug('Formato detectado: americano só com vírgula milhar', ['valor' => $valor]);
            }
        }
        
        // Converter para float e formatar com 2 casas decimais
        $valor = (float) $valor;
        $resultado = number_format($valor, 2, '.', '');
        
        Log::debug('Valor formatado', [
            'original' => $valorOriginal,
            'final' => $resultado,
            'era_negativo' => $ehNegativo
        ]);
        
        return $resultado;
    }

    private function formatarData($data)
    {
        if (empty($data)) {
            return date('Y-m-d');
        }
        
        // Remover espaços
        $data = trim($data);
        
        // Se já está no formato Y-m-d, retornar como está
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            return $data;
        }
        
        // Tentar formatos brasileiros comuns
        $formatos = [
            'd/m/Y',    // 23/07/2025
            'd-m-Y',    // 23-07-2025
            'd.m.Y',    // 23.07.2025
            'Y/m/d',    // 2025/07/23
            'Y-m-d',    // 2025-07-23
        ];
        
        foreach ($formatos as $formato) {
            $dataObj = \DateTime::createFromFormat($formato, $data);
            if ($dataObj !== false) {
                return $dataObj->format('Y-m-d');
            }
        }
        
        // Se não conseguir formatar, usar data atual
        return date('Y-m-d');
    }

    public function render()
    {
        // Garantir que os dados sejam serializáveis
        $this->limparDadosParaSerializacao();
        
        return view('livewire.importador-personalizado');
    }
    
    private function limparDadosParaSerializacao()
    {
        // Garantir que colunasArquivo seja um array simples
        if (is_array($this->colunasArquivo)) {
            $this->colunasArquivo = array_map('strval', $this->colunasArquivo);
        }
        
        // Garantir que mapeamentoColunas seja serializável
        if (is_array($this->mapeamentoColunas)) {
            $this->mapeamentoColunas = array_filter($this->mapeamentoColunas, function($valor) {
                return is_string($valor) || is_numeric($valor);
            });
        }
    }
} 