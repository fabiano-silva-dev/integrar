<?php

namespace App\Livewire;

use App\Models\Lancamento;
use Livewire\Component;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ExportadorContabil extends Component
{
    protected $layout = 'components.layouts.app';
    public $dataInicio = '';
    public $dataFim = '';
    public $formato = 'txt'; // Fixar como txt
    public $layoutExport = 'dominio'; // Fixar como dominio
    public $codigoEmpresa = '';
    public $cnpjEmpresa = '';
    public $tipoNota = '05'; // 05 - Contabilidade-Lançamentos em lote (padrão)
    public $sistema = '1'; // 1 - Contabilidade (padrão)
    public $processando = false;
    public $mensagem = '';
    public $arquivoGerado = '';
    public $quantidadeRegistros = 0;
    public $importacaoId = null;
    public $importacoes = [];
    public $usuario = 'INTEGRAR02';
    public $empresas = [];
    public $empresaSelecionada = null;
    public $lancamentosComContasVazias = [];
    public $mostrarAvisoContasVazias = false;

    public function mount()
    {
        $this->dataInicio = now()->startOfMonth()->format('Y-m-d');
        $this->dataFim = now()->endOfMonth()->format('Y-m-d');
        $this->importacoes = \App\Models\Importacao::with('empresa')->orderByDesc('created_at')->get();
        $this->empresas = \App\Models\Empresa::orderBy('nome')->get();
        
        // Definir usuário padrão como Fabiano
        $this->usuario = 'INTEGRAR02';
        
        // Valores padrões para layout Domínio
        $this->tipoNota = '05'; // 05 - Contabilidade-Lançamentos em lote
        $this->sistema = '1';   // 1 - Contabilidade
    }

    public function updatedImportacaoId($value)
    {
        Log::info("=== INÍCIO updatedImportacaoId ===");
        Log::info("Importação selecionada", ['importacao_id' => $value, 'tipo' => gettype($value)]);
        
        if (empty($value)) {
            Log::info("Nenhuma importação selecionada");
            return;
        }
        
        $importacao = \App\Models\Importacao::with('empresa')->find($value);
        Log::info("Importação encontrada", [
            'importacao_id' => $importacao ? $importacao->id : null,
            'tem_empresa' => $importacao ? (bool)$importacao->empresa : false,
            'empresa_id' => $importacao ? $importacao->empresa_id : null
        ]);
        
        if ($importacao) {
            // Buscar informações da empresa através da relação
            $empresa = $importacao->empresa;
            Log::info("Dados da empresa", [
                'empresa_id' => $empresa ? $empresa->id : null,
                'codigo_sistema' => $empresa ? $empresa->codigo_sistema : null,
                'cnpj' => $empresa ? $empresa->cnpj : null
            ]);
            
            if ($empresa) {
                $this->codigoEmpresa = $empresa->codigo_sistema ?? '';
                // Limpar máscara do CNPJ
                $cnpjLimpo = preg_replace('/[^0-9]/', '', $empresa->cnpj ?? '');
                $this->cnpjEmpresa = $cnpjLimpo;
                Log::info("Campos preenchidos", [
                    'codigoEmpresa' => $this->codigoEmpresa,
                    'cnpjEmpresa' => $this->cnpjEmpresa,
                    'cnpj_original' => $empresa->cnpj
                ]);
            } else {
                Log::warning("Importação sem empresa associada");
                // Tentar buscar empresa pelo código da importação
                $empresa = \App\Models\Empresa::where('codigo_sistema', $importacao->codigo_empresa)->first();
                if ($empresa) {
                    $this->codigoEmpresa = $empresa->codigo_sistema ?? '';
                    // Limpar máscara do CNPJ
                    $cnpjLimpo = preg_replace('/[^0-9]/', '', $empresa->cnpj ?? '');
                    $this->cnpjEmpresa = $cnpjLimpo;
                    Log::info("Empresa encontrada pelo código", [
                        'codigoEmpresa' => $this->codigoEmpresa,
                        'cnpjEmpresa' => $this->cnpjEmpresa,
                        'cnpj_original' => $empresa->cnpj
                    ]);
                } else {
                    // Se não encontrar empresa, usar valores padrão ou deixar vazio
                    Log::warning("Nenhuma empresa encontrada para a importação", [
                        'importacao_id' => $importacao->id,
                        'codigo_empresa' => $importacao->codigo_empresa
                    ]);
                    // Manter os campos vazios para que o usuário preencha manualmente
                    $this->codigoEmpresa = '';
                    $this->cnpjEmpresa = '';
                }
            }
            
            // Buscar datas mínima e máxima dos lançamentos vinculados à importação
            $datas = \App\Models\Lancamento::where('importacao_id', $importacao->id)
                ->selectRaw('MIN(data) as data_min, MAX(data) as data_max')
                ->first();

            if ($datas && $datas->data_min && $datas->data_max) {
                $this->dataInicio = $datas->data_min;
                $this->dataFim = $datas->data_max;
                Log::info("Datas sugeridas pelos lançamentos", [
                    'dataInicio' => $this->dataInicio,
                    'dataFim' => $this->dataFim
                ]);
            } else {
                // fallback para datas da importação ou padrão
                $this->dataInicio = $importacao->data_inicial ?? $this->dataInicio;
                $this->dataFim = $importacao->data_final ?? $this->dataFim;
            }
            
            // Verificar lançamentos com contas vazias
            $this->verificarLancamentosComContasVazias();
            
            // Forçar atualização da interface
            $this->dispatch('$refresh');
            

            
            // Manter valores padrões do layout Domínio
            $this->tipoNota = '05'; // 05 - Contabilidade-Lançamentos em lote
            $this->sistema = '1';   // 1 - Contabilidade
            
            Log::info("Datas definidas", [
                'dataInicio' => $this->dataInicio,
                'dataFim' => $this->dataFim
            ]);
        }
        
        Log::info("=== FIM updatedImportacaoId ===");
        
        // Forçar atualização da interface
        $this->dispatch('$refresh');
    }

    public function selecionarImportacao($importacaoId)
    {
        Log::info("=== INÍCIO selecionarImportacao ===");
        Log::info("Importação selecionada via método público", ['importacao_id' => $importacaoId]);
        
        $this->importacaoId = $importacaoId;
        $this->updatedImportacaoId($importacaoId);
        
        Log::info("=== FIM selecionarImportacao ===");
    }

    public function getQuantidadeRegistros()
    {
        $query = \App\Models\Lancamento::query();
        
        if ($this->importacaoId) {
            $query->where('importacao_id', $this->importacaoId);
        } else {
            if ($this->dataInicio && $this->dataFim) {
                $query->whereBetween('data', [$this->dataInicio, $this->dataFim]);
            }
        }
        
        return $query->count();
    }

    public function verificarLancamentosComContasVazias()
    {
        $query = \App\Models\Lancamento::query();
        
        if ($this->importacaoId) {
            $query->where('importacao_id', $this->importacaoId);
        } else {
            if ($this->dataInicio && $this->dataFim) {
                $query->whereBetween('data', [$this->dataInicio, $this->dataFim]);
            }
        }
        
        // Buscar lançamentos com conta débito ou crédito vazias
        $this->lancamentosComContasVazias = $query->where(function($q) {
            $q->whereNull('conta_debito')
              ->orWhere('conta_debito', '')
              ->orWhereNull('conta_credito')
              ->orWhere('conta_credito', '');
        })->get();
        
        $this->mostrarAvisoContasVazias = count($this->lancamentosComContasVazias) > 0;
        
        return $this->lancamentosComContasVazias;
    }

    public function updatedEmpresaSelecionada($value)
    {
        Log::info("updatedEmpresaSelecionada chamado", ['value' => $value]);
        
        if (!empty($value)) {
            $empresa = \App\Models\Empresa::find($value);
            if ($empresa) {
                $this->codigoEmpresa = $empresa->codigo_sistema ?? '';
                // Limpar máscara do CNPJ ao selecionar empresa
                $cnpjLimpo = preg_replace('/[^0-9]/', '', $empresa->cnpj ?? '');
                $this->cnpjEmpresa = $cnpjLimpo;
                Log::info("Empresa selecionada manualmente", [
                    'empresa_id' => $empresa->id,
                    'empresa_nome' => $empresa->nome,
                    'codigoEmpresa' => $this->codigoEmpresa,
                    'cnpjEmpresa' => $this->cnpjEmpresa,
                    'cnpj_original' => $empresa->cnpj
                ]);
            } else {
                Log::warning("Empresa não encontrada", ['empresa_id' => $value]);
            }
        } else {
            Log::info("Valor vazio recebido em updatedEmpresaSelecionada");
        }
    }

    public function updatedDataInicio($value)
    {
        if ($this->dataInicio && $this->dataFim) {
            $this->verificarLancamentosComContasVazias();
        }
    }

    public function updatedDataFim($value)
    {
        if ($this->dataInicio && $this->dataFim) {
            $this->verificarLancamentosComContasVazias();
        }
    }

    public function exportar()
    {
        // Forçar sempre txt e dominio
        $this->formato = 'txt';
        $this->layoutExport = 'dominio';

        Log::info('Iniciando exportação', [
            'dataInicio' => $this->dataInicio,
            'dataFim' => $this->dataFim,
            'formato' => $this->formato,
            'layoutExport' => $this->layoutExport,
            'importacaoId' => $this->importacaoId
        ]);

        // Limpar máscara do CNPJ antes da validação
        $cnpjLimpo = preg_replace('/[^0-9]/', '', $this->cnpjEmpresa);
        
        Log::info('CNPJ processado', [
            'cnpj_original' => $this->cnpjEmpresa,
            'cnpj_limpo' => $cnpjLimpo
        ]);

        $regras = [
            'dataInicio' => 'required|date',
            'dataFim' => 'required|date|after_or_equal:dataInicio',
            'formato' => 'required|in:csv,txt',
            'layoutExport' => 'required|in:padrao,contabil,simples,dominio'
        ];

        // Validações específicas para layout Domínio
        if ($this->layoutExport === 'dominio') {
            $regras['codigoEmpresa'] = 'required|string|max:7';
            $regras['cnpjEmpresa'] = 'required|string|max:14';
            $regras['tipoNota'] = 'required|in:01,02,03,04,05';
            $regras['sistema'] = 'required|in:0,1,2';
            
            // Verificar se os campos estão vazios e dar dica ao usuário
            if (empty($this->codigoEmpresa) || empty($cnpjLimpo)) {
                Log::warning('Campos obrigatórios do layout Domínio não preenchidos', [
                    'codigoEmpresa' => $this->codigoEmpresa,
                    'cnpjEmpresa' => $this->cnpjEmpresa,
                    'cnpjLimpo' => $cnpjLimpo,
                    'importacaoId' => $this->importacaoId
                ]);
            }
        }

        try {
            // Criar dados para validação com CNPJ limpo
            $dadosValidacao = [
                'dataInicio' => $this->dataInicio,
                'dataFim' => $this->dataFim,
                'formato' => $this->formato,
                'layoutExport' => $this->layoutExport,
                'codigoEmpresa' => $this->codigoEmpresa,
                'cnpjEmpresa' => $cnpjLimpo, // Usar CNPJ limpo
                'tipoNota' => $this->tipoNota,
                'sistema' => $this->sistema
            ];
            
            $this->validate($regras, [], $dadosValidacao);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro de validação na exportação', [
                'erros' => $e->errors(),
                'cnpj_original' => $this->cnpjEmpresa,
                'cnpj_limpo' => $cnpjLimpo
            ]);
            
            // Mensagem mais amigável para campos obrigatórios do layout Domínio
            $mensagens = [];
            foreach ($e->errors() as $campo => $erros) {
                if ($campo === 'codigoEmpresa') {
                    $mensagens[] = 'Código da empresa é obrigatório para o layout Domínio';
                } elseif ($campo === 'cnpjEmpresa') {
                    $mensagens[] = 'CNPJ da empresa é obrigatório para o layout Domínio';
                } else {
                    $mensagens[] = implode(', ', $erros);
                }
            }
            
            $this->mensagem = 'Erro de validação: ' . implode(', ', $mensagens);
            return;
        }

        $this->processando = true;
        $this->mensagem = 'Gerando arquivo...';

        try {
            Log::info('Buscando lançamentos para exportação');
            
            // Verificar se há lançamentos com contas vazias
            $this->verificarLancamentosComContasVazias();
            
            $query = \App\Models\Lancamento::query();
            if ($this->importacaoId) {
                $query->where('importacao_id', $this->importacaoId);
                Log::info('Filtrando por importação ID: ' . $this->importacaoId);
            } else {
                $query->whereBetween('data', [$this->dataInicio, $this->dataFim]);
                Log::info('Filtrando por período: ' . $this->dataInicio . ' a ' . $this->dataFim);
            }
            $lancamentos = $query->orderBy('data')->orderBy('id')->get();

            Log::info('Lançamentos encontrados: ' . $lancamentos->count());

            if ($lancamentos->isEmpty()) {
                $this->mensagem = 'Nenhum lançamento encontrado para o período selecionado.';
                $this->processando = false;
                Log::warning('Nenhum lançamento encontrado para exportação');
                return;
            }

            Log::info('Gerando conteúdo do arquivo');
            $conteudo = $this->gerarConteudo($lancamentos);
            
            // Converter para ISO-8859-1 se for layout Domínio
            if ($this->layoutExport === 'dominio') {
                Log::info('Convertendo para ISO-8859-1 (layout Domínio)');
                if (function_exists('mb_convert_encoding')) {
                    $conteudo = mb_convert_encoding($conteudo, 'ISO-8859-1', 'UTF-8');
                } else {
                    $conteudo = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $conteudo);
                }
            }
            
            $nomeArquivo = $this->gerarNomeArquivo();
            Log::info('Nome do arquivo gerado: ' . $nomeArquivo);
            
            Log::info('Salvando arquivo no storage');
            Storage::put("exports/{$nomeArquivo}", $conteudo);
            
            $this->arquivoGerado = $nomeArquivo;
            $this->quantidadeRegistros = $lancamentos->count();
            
            // Mensagem de sucesso com aviso sobre contas vazias
            if ($this->mostrarAvisoContasVazias) {
                $this->mensagem = "Arquivo gerado com sucesso! {$this->quantidadeRegistros} lançamento(s) exportado(s). ATENÇÃO: " . count($this->lancamentosComContasVazias) . " lançamento(s) possuem conta débito ou crédito vazias.";
            } else {
                $this->mensagem = "Arquivo gerado com sucesso! {$this->quantidadeRegistros} lançamento(s) exportado(s).";
            }
            
            Log::info('Exportação concluída com sucesso', [
                'arquivo' => $nomeArquivo,
                'lançamentos' => $lancamentos->count(),
                'contas_vazias' => count($this->lancamentosComContasVazias)
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro na exportação', [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->mensagem = 'Erro na exportação: ' . $e->getMessage();
        }

        $this->processando = false;
        Log::info('Processamento finalizado');
    }

    private function gerarConteudo($lancamentos)
    {
        switch ($this->layoutExport) {
            case 'contabil':
                return $this->gerarLayoutContabil($lancamentos);
            case 'simples':
                return $this->gerarLayoutSimples($lancamentos);
            case 'dominio':
                return $this->gerarLayoutDominio($lancamentos);
            default:
                return $this->gerarLayoutPadrao($lancamentos);
        }
    }

    private function gerarLayoutPadrao($lancamentos)
    {
        $linhas = [];
        
        // Cabeçalho
        if ($this->formato === 'csv') {
            $linhas[] = 'Data;Histórico;Conta Débito;Conta Crédito;Valor;Terceiro';
        }

        foreach ($lancamentos as $lancamento) {
            if ($this->formato === 'csv') {
                $linhas[] = implode(';', [
                    $lancamento->data->format('d/m/Y'),
                    $lancamento->historico,
                    $lancamento->conta_debito ?? '',
                    $lancamento->conta_credito ?? '',
                    number_format($lancamento->valor, 2, ',', '.'),
                    $lancamento->nome_empresa ?? ''
                ]);
            } else {
                // Formato TXT com campos posicionais
                $linha = str_pad($lancamento->data->format('dmY'), 8);
                $linha .= str_pad(substr($lancamento->historico, 0, 50), 50);
                $linha .= str_pad($lancamento->conta_debito ?? '', 10);
                $linha .= str_pad($lancamento->conta_credito ?? '', 10);
                $linha .= str_pad(number_format($lancamento->valor, 2, '', ''), 15);
                $linha .= str_pad($lancamento->nome_empresa ?? '', 30);
                $linhas[] = $linha;
            }
        }

        return implode("\n", $linhas);
    }

    private function gerarLayoutContabil($lancamentos)
    {
        $linhas = [];
        
        // Cabeçalho específico para contabilidade
        if ($this->formato === 'csv') {
            $linhas[] = 'Data;Tipo;Histórico;Conta;Débito;Crédito;Terceiro';
        }

        foreach ($lancamentos as $lancamento) {
            if ($this->formato === 'csv') {
                // Linha de débito
                $linhas[] = implode(';', [
                    $lancamento->data->format('d/m/Y'),
                    'D',
                    $lancamento->historico,
                    $lancamento->conta_debito ?? '',
                    number_format($lancamento->valor, 2, ',', '.'),
                    '',
                    $lancamento->nome_empresa ?? ''
                ]);
                
                // Linha de crédito
                $linhas[] = implode(';', [
                    $lancamento->data->format('d/m/Y'),
                    'C',
                    $lancamento->historico,
                    $lancamento->conta_credito ?? '',
                    '',
                    number_format($lancamento->valor, 2, ',', '.'),
                    $lancamento->nome_empresa ?? ''
                ]);
            } else {
                // Formato TXT contábil
                $linha = str_pad($lancamento->data->format('dmY'), 8);
                $linha .= 'D';
                $linha .= str_pad(substr($lancamento->historico, 0, 40), 40);
                $linha .= str_pad($lancamento->conta_debito ?? '', 10);
                $linha .= str_pad(number_format($lancamento->valor, 2, '', ''), 15);
                $linha .= str_pad('', 15); // Crédito vazio
                $linha .= str_pad($lancamento->nome_empresa ?? '', 30);
                $linhas[] = $linha;
                
                $linha = str_pad($lancamento->data->format('dmY'), 8);
                $linha .= 'C';
                $linha .= str_pad(substr($lancamento->historico, 0, 40), 40);
                $linha .= str_pad($lancamento->conta_credito ?? '', 10);
                $linha .= str_pad('', 15); // Débito vazio
                $linha .= str_pad(number_format($lancamento->valor, 2, '', ''), 15);
                $linha .= str_pad($lancamento->nome_empresa ?? '', 30);
                $linhas[] = $linha;
            }
        }

        return implode("\n", $linhas);
    }

    private function gerarLayoutSimples($lancamentos)
    {
        $linhas = [];
        
        foreach ($lancamentos as $lancamento) {
            if ($this->formato === 'csv') {
                $linhas[] = implode(';', [
                    $lancamento->data->format('Y-m-d'),
                    $lancamento->historico,
                    $lancamento->valor
                ]);
            } else {
                $linha = $lancamento->data->format('Y-m-d') . ' | ';
                $linha .= $lancamento->historico . ' | ';
                $linha .= number_format($lancamento->valor, 2, ',', '.');
                $linhas[] = $linha;
            }
        }

        return implode("\n", $linhas);
    }

    private function gerarLayoutDominio($lancamentos)
    {
        $linhas = [];
        $sequencial = 1;
        
        // Registro 01 - Cabeçalho do Arquivo (100 caracteres)
        $registro01 = '01'; // Identificador
        $registro01 .= str_pad($this->codigoEmpresa, 7, '0', STR_PAD_LEFT); // Código da Empresa
        // Garantir que o CNPJ esteja sem máscara
        $cnpjLimpo = preg_replace('/[^0-9]/', '', $this->cnpjEmpresa);
        $registro01 .= str_pad($cnpjLimpo, 14, ' ', STR_PAD_RIGHT); // CNPJ da empresa
        $registro01 .= $this->dataInicio ? date('d/m/Y', strtotime($this->dataInicio)) : str_pad('', 10); // Data Inicial
        $registro01 .= $this->dataFim ? date('d/m/Y', strtotime($this->dataFim)) : str_pad('', 10); // Data Final
        $registro01 .= 'N'; // Valor fixo "N"
        $registro01 .= str_pad($this->tipoNota, 2, '0', STR_PAD_LEFT); // Tipo de Nota
        $registro01 .= '00000'; // Constante "00000"
        $registro01 .= $this->sistema; // Sistema
        $registro01 .= '17'; // Valor fixo "17"
        $registro01 = str_pad($registro01, 100, ' ', STR_PAD_RIGHT); // Completar até 100 caracteres
        
        $linhas[] = $registro01;
        
        // Um registro 02 e 03 para cada lançamento
        foreach ($lancamentos as $lancamento) {
            // Registro 02 - Dados do Lote (100 caracteres)
            $registro02 = '02'; // Identificador
            $registro02 .= str_pad($sequencial, 7, '0', STR_PAD_LEFT); // Código sequencial
            $registro02 .= 'X'; // Tipo (X=Um débito para um crédito)
            $registro02 .= $lancamento->data ? $lancamento->data->format('d/m/Y') : str_pad('', 10); // Data do lançamento
            $registro02 .= str_pad('INTEGRAR02', 30, ' ', STR_PAD_RIGHT); // Usuário
            $registro02 = str_pad($registro02, 100, ' ', STR_PAD_RIGHT); // Completar até 100 caracteres
            $linhas[] = $registro02;

            // Registro 03 - Partidas dos Lançamentos Contábeis (664 caracteres cada)
            $registro03 = '03'; // Identificador
            $registro03 .= str_pad($sequencial, 7, '0', STR_PAD_LEFT); // Código Sequencial
            $registro03 .= str_pad($lancamento->conta_debito ?? '', 7, '0', STR_PAD_LEFT); // Conta Débito
            $registro03 .= str_pad($lancamento->conta_credito ?? '', 7, '0', STR_PAD_LEFT); // Conta Crédito
            $registro03 .= str_pad(number_format($lancamento->valor, 2, '', ''), 15, '0', STR_PAD_LEFT); // Valor do lançamento
            $registro03 .= str_pad('0000000', 7, '0', STR_PAD_LEFT); // Código do Histórico (sete zeros)
            
            // Histórico com tamanho fixo de 512 caracteres conforme layout Domínio
            // Limpar quebras de linha e caracteres especiais
            $historicoLimpo = str_replace(["\r", "\n", "\t"], ' ', $lancamento->historico ?? '');
            $historicoLimpo = preg_replace('/\s+/', ' ', $historicoLimpo); // Remove múltiplos espaços
            $historicoLimpo = trim($historicoLimpo); // Remove espaços no início e fim
            
            $historico = mb_substr($historicoLimpo, 0, 512);
            $historicoFormatado = $this->mb_str_pad($historico, 512, ' ', STR_PAD_RIGHT);
            
            Log::info('Formatando histórico para layout Domínio', [
                'historico_original' => $lancamento->historico,
                'historico_limpo' => $historicoLimpo,
                'historico_truncado' => $historico,
                'tamanho_historico' => mb_strlen($historico),
                'tamanho_formatado' => mb_strlen($historicoFormatado)
            ]);
            
            $registro03 .= $historicoFormatado;
            $registro03 .= str_pad($lancamento->codigo_filial_matriz ?? '', 7, '0', STR_PAD_LEFT); // Código da Filial/Matriz
            $registro03 = str_pad($registro03, 664, ' ', STR_PAD_RIGHT); // Completar até 664 caracteres
            $linhas[] = $registro03;

            $sequencial++;
        }
        
        // Registro 99 - Finalizador do Arquivo (100 caracteres)
        $registro99 = '99'; // Identificador
        $registro99 = str_pad($registro99, 100, '0', STR_PAD_RIGHT); // Finalizador
        
        $linhas[] = $registro99;
        
        return implode("\n", $linhas);
    }

    private function gerarNomeArquivo()
    {
        // Buscar código do sistema da empresa
        $codigoSistema = '';
        if ($this->importacaoId) {
            $importacao = \App\Models\Importacao::find($this->importacaoId);
            if ($importacao && $importacao->empresa) {
                $codigoSistema = $importacao->empresa->codigo_sistema ?? '';
            }
        }
        
        // Se não tem código do sistema, usar o código da empresa do formulário
        if (empty($codigoSistema)) {
            $codigoSistema = $this->codigoEmpresa;
        }
        
        // Formatar mês-ano baseado nas datas selecionadas
        $mesAno = '';
        if ($this->dataInicio) {
            $data = \Carbon\Carbon::parse($this->dataInicio);
            $mesAno = $data->format('m-Y');
        } else {
            $mesAno = now()->format('m-Y');
        }
        
        $extensao = $this->formato;
        return "exportacao_dominio_{$codigoSistema}_{$mesAno}.{$extensao}";
    }

    public function downloadArquivo()
    {
        Log::info('Tentativa de download do arquivo', [
            'arquivo' => $this->arquivoGerado
        ]);
        
        if (!empty($this->arquivoGerado)) {
            if (Storage::exists("exports/{$this->arquivoGerado}")) {
                Log::info('Arquivo encontrado, iniciando download');
                return Storage::download("exports/{$this->arquivoGerado}");
            } else {
                Log::error('Arquivo não encontrado no storage', [
                    'arquivo' => $this->arquivoGerado,
                    'path' => "exports/{$this->arquivoGerado}"
                ]);
                $this->mensagem = 'Arquivo não encontrado no servidor.';
            }
        } else {
            Log::warning('Tentativa de download sem arquivo gerado');
            $this->mensagem = 'Nenhum arquivo foi gerado ainda.';
        }
    }

    public function render()
    {
        return view('livewire.exportador-contabil', [
            'empresas' => $this->empresas
        ]);
    }

    private function mb_str_pad(string $input, int $pad_length, string $pad_string = ' ', int $pad_type = STR_PAD_RIGHT, string $encoding = 'UTF-8')
    {
        $length = mb_strlen($input, $encoding);
        $pad_needed = $pad_length - $length;
        if ($pad_needed <= 0) {
            return mb_substr($input, 0, $pad_length, $encoding);
        }
        switch ($pad_type) {
            case STR_PAD_LEFT:
                return str_repeat($pad_string, $pad_needed) . $input;
            case STR_PAD_BOTH:
                $left = floor($pad_needed / 2);
                $right = $pad_needed - $left;
                return str_repeat($pad_string, $left) . $input . str_repeat($pad_string, $right);
            case STR_PAD_RIGHT:
            default:
                return $input . str_repeat($pad_string, $pad_needed);
        }
    }
}
