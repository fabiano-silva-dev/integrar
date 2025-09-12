<?php

namespace App\Livewire;

use App\Models\Empresa;
use App\Models\Importacao;
use App\Models\Lancamento;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class ImportadorAvancado extends Component
{
    use WithFileUploads;

    public $arquivo;
    public $empresa_id;
    public $layout_selecionado = '';
    public $conta_banco = '';
    public $status_importacao = 'pendente';
    public $progresso = 0;
    public $mensagem_status = '';
    public $arquivo_processado = false;
    public $caminho_csv_final = '';
    public $totalLinhas = 0;
    public $linhaAtual = 0;
    public $importacao_id = null;
    public $total_registros_importados = 0;

    protected $rules = [
        'arquivo' => 'required|file|extensions:csv,txt,pdf,ofx|max:10240', // 10MB
        'empresa_id' => 'required|exists:empresas,id',
        'layout_selecionado' => 'required|in:dominio,grafeno,sicoob,caixa_federal,ofx,registros',
        'conta_banco' => 'required_if:layout_selecionado,grafeno,sicoob,caixa_federal,registros',
    ];

    protected $messages = [
        'arquivo.required' => 'O arquivo é obrigatório.',
        'arquivo.file' => 'O arquivo deve ser um arquivo válido.',
        'arquivo.extensions' => 'O arquivo deve ter uma das extensões: csv, txt, pdf, ofx.',
        'arquivo.max' => 'O arquivo não pode ser maior que 10MB.',
        'empresa_id.required' => 'A empresa é obrigatória.',
        'empresa_id.exists' => 'A empresa selecionada não existe.',
        'layout_selecionado.required' => 'O layout é obrigatório.',
        'layout_selecionado.in' => 'O layout selecionado não é válido.',
        'conta_banco.required_if' => 'O campo Conta do Banco é obrigatório para este layout.',
    ];

    public function mount()
    {
        $this->mensagem_status = 'Aguardando upload do arquivo...';
    }

    public function updatedArquivo()
    {
        $this->resetValidation();
        $this->validateOnly('arquivo');
        $this->mensagem_status = 'Arquivo selecionado. Escolha o layout e a empresa.';
    }

    public function processarArquivo()
    {
        // Aumentar limites de execução para arquivos grandes
        set_time_limit(300); // 5 minutos
        ini_set('memory_limit', '512M');
        
        $this->validate();

        try {
            $inicio_processamento = microtime(true);
            Log::info("=== INÍCIO DO PROCESSAMENTO ===", [
                'layout' => $this->layout_selecionado,
                'empresa_id' => $this->empresa_id,
                'arquivo' => $this->arquivo->getClientOriginalName()
            ]);

            $this->status_importacao = 'processando';
            $this->progresso = 10;
            $this->mensagem_status = 'Iniciando processamento...';

            // Salvar arquivo temporário
            $inicio_salvar = microtime(true);
            $caminho_original = $this->arquivo->store('temp');
            $caminho_completo = Storage::path($caminho_original);
            
            // Verificar se o arquivo foi salvo corretamente
            if (!file_exists($caminho_completo)) {
                throw new \Exception("Arquivo não foi salvo corretamente: {$caminho_completo}");
            }
            
            // Verificar tamanho do arquivo
            $tamanho_arquivo = filesize($caminho_completo);
            $tempo_salvar = microtime(true) - $inicio_salvar;
            
            Log::info("Arquivo salvo:", [
                'caminho' => $caminho_completo,
                'tamanho' => $tamanho_arquivo,
                'nome_original' => $this->arquivo->getClientOriginalName(),
                'tempo_salvar' => round($tempo_salvar, 2) . 's'
            ]);
            
            $this->progresso = 20;
            $this->mensagem_status = 'Arquivo salvo. Iniciando conversão...';

            // Determinar script Python baseado no layout
            $script_python = $this->determinarScriptPython();
            $caminho_script = "/var/www/html/scripts/{$script_python}";
            
            // Verificar se o script Python existe
            if (!file_exists($caminho_script)) {
                throw new \Exception("Script Python não encontrado: {$caminho_script}");
            }
            
            Log::info("Script Python encontrado:", [
                'script' => $script_python,
                'caminho' => $caminho_script,
                'existe' => file_exists($caminho_script)
            ]);
            
            // Gerar nome do arquivo de saída
            $nome_arquivo_saida = 'converted_' . time() . '.csv';
            $caminho_saida = Storage::path('temp/' . $nome_arquivo_saida);

            $this->progresso = 30;
            $this->mensagem_status = 'Executando conversão Python...';

            // Executar script Python
            $inicio_python = microtime(true);
            $resultado = $this->executarScriptPython($script_python, $caminho_completo, $caminho_saida);
            $tempo_python = microtime(true) - $inicio_python;

            if (!$resultado['sucesso']) {
                $erro_detalhado = "Erro na conversão:\n";
                $erro_detalhado .= "Script: {$script_python}\n";
                $erro_detalhado .= "Arquivo entrada: {$caminho_completo}\n";
                $erro_detalhado .= "Arquivo saída: {$caminho_saida}\n";
                $erro_detalhado .= "Erro: " . $resultado['erro'] . "\n";
                $erro_detalhado .= "Saída: " . $resultado['saida'];
                
                Log::error($erro_detalhado);
                throw new \Exception('Erro na conversão: ' . $resultado['erro']);
            }

            Log::info("Conversão Python concluída:", [
                'tempo_python' => round($tempo_python, 2) . 's',
                'arquivo_saida' => $caminho_saida
            ]);

            $this->progresso = 60;
            $this->mensagem_status = 'Conversão concluída. Importando dados...';

            // Importar dados do CSV gerado
            $inicio_importacao = microtime(true);
            $resultado_importacao = $this->importarDadosCSV($caminho_saida);
            $tempo_importacao = microtime(true) - $inicio_importacao;

            $tempo_total = microtime(true) - $inicio_processamento;
            
            Log::info("=== PROCESSAMENTO CONCLUÍDO ===", [
                'tempo_total' => round($tempo_total, 2) . 's',
                'tempo_salvar' => round($tempo_salvar, 2) . 's',
                'tempo_python' => round($tempo_python, 2) . 's',
                'tempo_importacao' => round($tempo_importacao, 2) . 's'
            ]);

            $this->progresso = 100;
            $this->status_importacao = 'concluida';
            $this->mensagem_status = 'Importação concluída com sucesso!';
            $this->arquivo_processado = true;
            $this->caminho_csv_final = $caminho_saida;
            $this->importacao_id = $resultado_importacao['importacao_id'];
            $this->total_registros_importados = $resultado_importacao['total_registros'];

            // Limpar arquivo temporário original
            Storage::delete($caminho_original);

        } catch (\Exception $e) {
            $this->status_importacao = 'erro';
            $this->mensagem_status = 'Erro: ' . $e->getMessage();
            
            // Log detalhado do erro
            Log::error('Erro na importação:', [
                'mensagem' => $e->getMessage(),
                'arquivo' => $e->getFile(),
                'linha' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'layout_selecionado' => $this->layout_selecionado,
                'empresa_id' => $this->empresa_id,
                'arquivo_original' => $this->arquivo ? $this->arquivo->getClientOriginalName() : 'N/A'
            ]);
        }
    }

    private function determinarScriptPython()
    {
        $scripts = [
            'dominio' => 'conversor_dominio_txt_csv.py',
            'grafeno' => 'conversor_extrato_grafeno_pdf_csv.py',
            'sicoob' => 'conversor_extrato_sicoob_pdf_csv.py',
            'caixa_federal' => 'conversor_extrato_caixa_federal_pdf_csv.py',
            'ofx' => 'conversor_ofx_csv.py',
            'registros' => 'conversor_registros_csv.py',
        ];

        return $scripts[$this->layout_selecionado] ?? 'conversor_registros_csv.py';
    }

    private function executarScriptPython($script, $entrada, $saida)
    {
        // Buscar código da empresa
        $empresa = Empresa::find($this->empresa_id);
        $codigoEmpresa = $empresa ? $empresa->codigo_sistema : null;
        
        Log::info("=== DEBUG SCRIPT PYTHON ===", [
            'layout_selecionado' => $this->layout_selecionado,
            'script' => $script,
            'empresa_id' => $this->empresa_id,
            'codigo_empresa' => $codigoEmpresa,
            'conta_banco' => $this->conta_banco ?? 'não informada'
        ]);
        
        // Se for o script do Grafeno, SICOOB, Caixa Federal ou Registros, passar a conta do banco como terceiro parâmetro
        if (($this->layout_selecionado === 'grafeno' || $this->layout_selecionado === 'sicoob' || $this->layout_selecionado === 'caixa_federal' || $this->layout_selecionado === 'registros') && !empty($this->conta_banco)) {
            $comando = "python3 /var/www/html/scripts/{$script} \"{$entrada}\" \"{$saida}\" \"{$this->conta_banco}\"";
        } else {
            $comando = "python3 /var/www/html/scripts/{$script} \"{$entrada}\" \"{$saida}\"";
        }
        
        // Log do comando que será executado
        Log::info("Executando comando Python: {$comando}");
        
        $resultado = Process::run($comando);
        
        // Log do resultado
        Log::info("Resultado do comando Python:", [
            'sucesso' => $resultado->successful(),
            'codigo_saida' => $resultado->exitCode(),
            'saida' => $resultado->output(),
            'erro' => $resultado->errorOutput()
        ]);

        return [
            'sucesso' => $resultado->successful(),
            'erro' => $resultado->errorOutput(),
            'saida' => $resultado->output(),
            'codigo_saida' => $resultado->exitCode()
        ];
    }

    private function importarDadosCSV($caminho_csv)
    {
        $inicio_importacao = microtime(true);
        Log::info("=== INÍCIO DA IMPORTAÇÃO CSV ===", ['arquivo' => $caminho_csv]);
        
        // Verificar se o arquivo CSV existe
        if (!file_exists($caminho_csv)) {
            throw new \Exception("Arquivo CSV gerado não encontrado: {$caminho_csv}");
        }
        
        $empresa = Empresa::find($this->empresa_id);
        $contaBancoEmpresa = $empresa ? ltrim($empresa->codigo_conta_banco, '0') : null;
        $codigoSistemaEmpresa = $empresa ? $empresa->codigo_sistema : null;
        
        // Criar registro de importação
        $importacao = Importacao::create([
            'nome_arquivo' => $this->arquivo->getClientOriginalName(),
            'total_registros' => 0,
            'registros_processados' => 0,
            'status' => 'processando',
            'usuario' => auth()->user() ? auth()->user()->name : 'Sistema',
            'user_id' => auth()->id(),
            'empresa_id' => $empresa->id,
        ]);

        Log::info("Importação criada:", ['importacao_id' => $importacao->id]);

        // Ler CSV e importar lançamentos
        $inicio_leitura = microtime(true);
        $linhas = file($caminho_csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $tempo_leitura = microtime(true) - $inicio_leitura;
        
        Log::info("Arquivo CSV lido:", [
            'total_linhas' => count($linhas),
            'tempo_leitura' => round($tempo_leitura, 2) . 's'
        ]);
        
        if (empty($linhas)) {
            throw new \Exception("Arquivo CSV está vazio ou não pôde ser lido: {$caminho_csv}");
        }
        
        $importados = 0;
        $linhaNumero = 0;
        $datasLancamentos = [];
        $this->totalLinhas = count($linhas);
        $this->linhaAtual = 0;

        $cabecalho = [];
        $lancamentos_batch = [];
        $batch_size = 100; // Processar em lotes de 100
        
        $inicio_processamento_linhas = microtime(true);
        
        foreach ($linhas as $linha) {
            $linhaNumero++;
            $this->linhaAtual = $linhaNumero;
            $this->progresso = 60 + round(($linhaNumero / $this->totalLinhas) * 35); // 60% a 95%
            $this->dispatch('progresso-atualizado', $this->progresso);
            
            // Log a cada 1000 linhas para monitorar progresso
            if ($linhaNumero % 1000 === 0) {
                $tempo_parcial = microtime(true) - $inicio_processamento_linhas;
                Log::info("Processando linha {$linhaNumero}/{$this->totalLinhas}", [
                    'progresso' => round(($linhaNumero / $this->totalLinhas) * 100, 1) . '%',
                    'tempo_parcial' => round($tempo_parcial, 2) . 's',
                    'importados' => $importados
                ]);
            }
            
            // Log detalhado para as primeiras linhas
            if ($linhaNumero <= 5) {
                Log::info("Processando linha {$linhaNumero}:", [
                    'linha_original' => $linha,
                    'tamanho_linha' => strlen($linha)
                ]);
            }
            
            $dados = str_getcsv($linha, ';');
            
            if ($linhaNumero === 1) {
                // Mapear cabeçalho para índice
                foreach ($dados as $i => $coluna) {
                    $cabecalho[trim($coluna)] = $i;
                }
                Log::info("Cabeçalho mapeado:", ['colunas' => array_keys($cabecalho)]);
                continue;
            }
            
            // Log para as primeiras linhas de dados
            if ($linhaNumero <= 5) {
                Log::info("Dados da linha {$linhaNumero}:", [
                    'total_colunas' => count($dados),
                    'dados' => $dados
                ]);
            }
            
            // Função auxiliar para pegar valor por nome de coluna
            $get = function($nome) use ($cabecalho, $dados) {
                return isset($cabecalho[$nome]) ? ($dados[$cabecalho[$nome]] ?? null) : null;
            };
            
            // Agora use $get('Nome da Coluna') para buscar os dados
            $dataLancamento = $get('Data do Lançamento');
            if ($dataLancamento) {
                $datasLancamentos[] = $this->formatarData($dataLancamento);
            }
            $usuario = $get('Usuário');
            $contaDebito = $get('Conta Débito');
            $contaCredito = $get('Conta Crédito');
            $valor = $get('Valor do Lançamento');
            $historico = $get('Histórico') ?? $get('Histórico (Complemento)');
            $codigoFilialRaw = $get('Código da Filial/Matriz');
            // Se o CSV já tem o código da filial preenchido, usar ele. Senão, usar o código da empresa
            if (!empty($codigoFilialRaw) && $codigoFilialRaw !== '0000001') {
                $codigoFilial = str_pad($codigoFilialRaw, 7, '0', STR_PAD_LEFT);
            } else {
                $codigoFilial = str_pad($codigoSistemaEmpresa, 7, '0', STR_PAD_LEFT);
            }
            
            // Log para debug do código da filial
            if ($linhaNumero <= 5) {
                Log::info("Debug código filial linha {$linhaNumero}:", [
                    'codigo_filial_raw' => $codigoFilialRaw,
                    'codigo_sistema_empresa' => $codigoSistemaEmpresa,
                    'codigo_filial_final' => $codigoFilial
                ]);
            }
            $nomeEmpresa = $get('Nome da Empresa');
            $numeroNota = $get('Número da Nota');
            
            // Log para as primeiras linhas de dados
            if ($linhaNumero <= 5) {
                Log::info("Dados extraídos da linha {$linhaNumero}:", [
                    'data' => $dataLancamento,
                    'usuario' => $usuario,
                    'conta_debito' => $contaDebito,
                    'conta_credito' => $contaCredito,
                    'valor' => $valor,
                    'historico' => $historico,
                    'nome_empresa' => $nomeEmpresa
                ]);
            }
            
            if (count($dados) >= 8) {
                Log::info("Iniciando processamento completo da linha {$linhaNumero}");
                
                // Processar terceiro se existir
                $terceiroId = null;
                if (!empty($nomeEmpresa)) { // Nome da Empresa
                    $terceiro = \App\Models\Terceiro::firstOrCreate(
                        ['nome' => trim($nomeEmpresa)],
                        [
                            'tipo' => 'empresa',
                            'ativo' => true
                        ]
                    );
                    $terceiroId = $terceiro->id;
                    
                    if ($linhaNumero <= 5) {
                        Log::info("Terceiro processado:", ['terceiro_id' => $terceiroId, 'nome' => $nomeEmpresa]);
                    }
                }

                // Preparar dados para amarração (sem criar automaticamente)
                $terceiroNome = trim($nomeEmpresa ?? '');
                $historico = $historico ?? '';
                $contaDebito = ltrim($contaDebito, '0');
                $contaCredito = ltrim($contaCredito, '0');

                // Filtrar detalhes para amarração
                $palavrasTags = array_filter(
                    array_map('trim', preg_split('/\s+/', preg_replace('/[^\w\s]/u', '', $historico)))
                );
                if ($terceiroNome) {
                    $palavrasTerceiro = array_filter(
                        array_map('trim', preg_split('/\s+/', preg_replace('/[^\w\s]/u', '', $terceiroNome)))
                    );
                    $palavrasTags = array_filter($palavrasTags, function($palavra) use ($palavrasTerceiro) {
                        return !in_array(strtolower($palavra), array_map('strtolower', $palavrasTerceiro));
                    });
                }
                $palavrasTags = $this->filtrarPadroesIndesejados($palavrasTags, $historico);
                
                // Gerar detalhes da operação para uso posterior
                $detalhesOperacao = '';
                if (count($palavrasTags) >= 2) {
                    $detalhesOperacao = trim(implode(',', $palavrasTags), '"');
                    $detalhesOperacao = str_replace('"', '', $detalhesOperacao);
                    $detalhesOperacao = trim($detalhesOperacao);
                }

                // Log detalhado para debug das palavras
                if ($linhaNumero <= 5) {
                    Log::info("Palavras processadas linha {$linhaNumero}:", [
                        'historico_original' => $historico,
                        'palavras_apos_filtro' => $palavrasTags,
                        'detalhes_operacao' => $detalhesOperacao
                    ]);
                }

                // Usar contas originais (sem amarração automática)
                $contaDebitoFinal = $contaDebito;
                $contaCreditoFinal = $contaCredito;

                // Adicionar ao batch em vez de criar imediatamente
                $lancamentos_batch[] = [
                    'data' => $this->formatarData($dataLancamento),
                    'usuario' => $usuario ?? '',
                    'conta_debito' => $contaDebitoFinal,
                    'conta_credito' => $contaCreditoFinal,
                    'conta_debito_original' => $contaDebito,
                    'conta_credito_original' => $contaCredito,
                    'valor' => $this->formatarValor($valor ?? 0),
                    'historico' => $historico,
                    'codigo_filial_matriz' => $codigoFilial ?? null,
                    'numero_nota' => $numeroNota ?? null,
                    'importacao_id' => $importacao->id,
                    'empresa_id' => $empresa->id,
                    'terceiro_id' => $terceiroId,
                    'amarracao_id' => null,
                    'linha_arquivo' => $linhaNumero,
                    'detalhes_operacao_para_amarracao' => $detalhesOperacao,
                    'processado' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                
                $importados++;
                
                if ($linhaNumero <= 5) {
                    Log::info("Lançamento preparado para batch:", [
                        'linha' => $linhaNumero,
                        'importados' => $importados,
                        'tamanho_batch' => count($lancamentos_batch)
                    ]);
                }
                
                // Inserir batch quando atingir o tamanho
                if (count($lancamentos_batch) >= $batch_size) {
                    Lancamento::insert($lancamentos_batch);
                    $lancamentos_batch = [];
                    
                    // Log do progresso do batch
                    Log::info("Batch inserido:", [
                        'linha_atual' => $linhaNumero,
                        'importados' => $importados,
                        'tempo_parcial' => round(microtime(true) - $inicio_processamento_linhas, 2) . 's'
                    ]);
                }
            } else {
                if ($linhaNumero <= 5) {
                    Log::warning("Linha {$linhaNumero} ignorada - colunas insuficientes:", [
                        'colunas_encontradas' => count($dados),
                        'colunas_necessarias' => 8
                    ]);
                }
            }
        }
        
        // Inserir lançamentos restantes
        if (!empty($lancamentos_batch)) {
            Lancamento::insert($lancamentos_batch);
            Log::info("Último batch inserido:", ['registros' => count($lancamentos_batch)]);
        }

        $tempo_processamento_linhas = microtime(true) - $inicio_processamento_linhas;
        Log::info("Processamento de linhas concluído:", [
            'total_linhas_processadas' => $linhaNumero,
            'total_importados' => $importados,
            'tempo_processamento' => round($tempo_processamento_linhas, 2) . 's'
        ]);

        // Calcular data inicial e final
        $dataInicial = !empty($datasLancamentos) ? min($datasLancamentos) : null;
        $dataFinal = !empty($datasLancamentos) ? max($datasLancamentos) : null;

        // Atualizar importação
        $importacao->update([
            'total_registros' => $importados,
            'registros_processados' => $importados,
            'status' => 'concluida',
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal
        ]);
        
        $tempo_total_importacao = microtime(true) - $inicio_importacao;
        
        Log::info("=== IMPORTAÇÃO CSV CONCLUÍDA ===", [
            'importacao_id' => $importacao->id,
            'total_registros' => $importados,
            'registros_processados' => $importados,
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'tempo_total' => round($tempo_total_importacao, 2) . 's',
            'tempo_leitura' => round($tempo_leitura, 2) . 's',
            'tempo_processamento' => round($tempo_processamento_linhas, 2) . 's'
        ]);
        
        return [
            'importacao_id' => $importacao->id,
            'total_registros' => $importados,
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal
        ];
    }

    private function formatarData($data)
    {
        // Verificar se a data não é nula ou vazia
        if (empty($data) || $data === null) {
            return date('Y-m-d'); // Data atual como fallback
        }
        
        // Limpar bytes nulos se existirem
        $data = str_replace("\0", '', $data);
        $data = trim($data);
        
        if (empty($data)) {
            return date('Y-m-d'); // Data atual como fallback
        }
        
        // Tentar diferentes formatos de data
        $formatos = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'];
        
        foreach ($formatos as $formato) {
            $data_obj = \DateTime::createFromFormat($formato, $data);
            if ($data_obj) {
                return $data_obj->format('Y-m-d');
            }
        }
        
        return date('Y-m-d'); // Data atual como fallback
    }

    private function formatarValor($valor)
    {
        // Remover R$ e espaços, converter vírgula para ponto
        $valor_limpo = str_replace(['R$', ' ', '.'], '', $valor);
        $valor_limpo = str_replace(',', '.', $valor_limpo);
        
        $valor_float = (float) $valor_limpo;
        
        // Converter valor negativo para positivo (valor absoluto)
        return abs($valor_float);
    }

    private function filtrarPadroesIndesejados($palavras, $historico)
    {
        // Dicionário de padrões que não devem ir para detalhes
        $padroesIndesejados = [
            'CFE', 'NF', 'Nº', 'N'
        ];
        
        // Preposições, artigos e crase que devem ser removidos
        $palavrasIndesejadas = [
            'DE', 'A', 'O', 'DO'
        ];
        
        // Filtrar palavras que estão no dicionário de padrões
        $palavras = array_filter($palavras, function($palavra) use ($padroesIndesejados) {
            return !in_array(strtoupper($palavra), $padroesIndesejados);
        });
        
        // Filtrar preposições, artigos e crase
        $palavras = array_filter($palavras, function($palavra) use ($palavrasIndesejadas) {
            return !in_array(strtoupper($palavra), $palavrasIndesejadas);
        });
        
        // Extrair números que vêm após "CFE NF Nº" ou "CFE NF N" para removê-los
        $numerosParaRemover = [];
        if (preg_match_all('/CFE\s+NF\s+N[^a-zA-Z]*\s+([0-9\/]+)/i', $historico, $matches)) {
            foreach ($matches[1] as $numero) {
                // Dividir o número se contiver "/" e adicionar cada parte
                $partes = explode('/', $numero);
                foreach ($partes as $parte) {
                    if (is_numeric($parte)) {
                        $numerosParaRemover[] = $parte;
                    }
                }
            }
        }
        
        // Extrair números que vêm após "SERVIÇO TOMADO NESTA DATA" para removê-los
        if (preg_match_all('/SERVIÇO\s+TOMADO\s+NESTA\s+DATA\s+([0-9\/]+)/i', $historico, $matches)) {
            foreach ($matches[1] as $numero) {
                // Dividir o número se contiver "/" e adicionar cada parte
                $partes = explode('/', $numero);
                foreach ($partes as $parte) {
                    if (is_numeric($parte)) {
                        $numerosParaRemover[] = $parte;
                    }
                }
            }
        }
        
        // Filtrar números que devem ser removidos
        $palavras = array_filter($palavras, function($palavra) use ($numerosParaRemover) {
            return !in_array($palavra, $numerosParaRemover);
        });
        
        return $palavras;
    }

    public function resetarImportacao()
    {
        $this->reset(['arquivo', 'empresa_id', 'layout_selecionado', 'conta_banco', 'status_importacao', 'progresso', 'mensagem_status', 'arquivo_processado', 'caminho_csv_final', 'importacao_id', 'total_registros_importados']);
        $this->mensagem_status = 'Aguardando upload do arquivo...';
    }

    public function abrirLancamentos()
    {
        if ($this->importacao_id) {
            return redirect()->route('lancamentos', ['importacao_id' => $this->importacao_id]);
        }
        return null;
    }

    public function render()
    {
        $empresas = Empresa::orderBy('nome')->get();
        
        $layouts = [
            'dominio' => 'Domínio (TXT)',
            'grafeno' => 'Grafeno (PDF)',
            'sicoob' => 'Sicoob (PDF)',
            'caixa_federal' => 'Caixa Econômica Federal (PDF)',
            'ofx' => 'Formato OFX',
            'registros' => 'Connectere > Contas Financeiras > Diário (CSV)',
        ];

        return view('livewire.importador-avancado', [
            'empresas' => $empresas,
            'layouts' => $layouts,
        ]);
    }
}
