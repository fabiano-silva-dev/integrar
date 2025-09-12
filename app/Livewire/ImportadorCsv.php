<?php

namespace App\Livewire;

use App\Models\Lancamento;
use App\Models\Importacao;
use App\Models\Terceiro;
use App\Models\Amarracao;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ImportadorCsv extends Component
{
    use WithFileUploads;

    public $arquivo;
    public $processando = false;
    public $mensagem = '';
    public $totalImportado = 0;
    public $importacaoId = null;
    public $progresso = 0;
    public $totalLinhas = 0;
    public $linhaAtual = 0;

    public function importar()
    {
        $this->validate([
            'arquivo' => 'required|file|mimes:csv,txt|max:10240', // 10MB max
        ]);

        $this->processando = true;
        $this->mensagem = 'Processando arquivo...';

        // Variáveis para os novos campos
        $codigoEmpresa = '';
        $cnpjEmpresa = '';
        $datasLancamentos = [];

        try {
            // Criar registro de importação
            $importacao = Importacao::create([
                'nome_arquivo' => $this->arquivo->getClientOriginalName(),
                'status' => 'processando',
                'usuario' => auth()->user() ? auth()->user()->name : 'Sistema',
                'user_id' => auth()->id(),
                'empresa_id' => auth()->user() ? auth()->user()->empresa_id : null,
            ]);

            $this->importacaoId = $importacao->id;

            $caminho = $this->arquivo->store('csv_imports');
            $linhas = file(Storage::path($caminho), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            $importados = 0;
            $linhaNumero = 0;
            $this->totalLinhas = count($linhas);
            $this->linhaAtual = 0;
            $this->progresso = 0;

            $cabecalho = [];
            foreach ($linhas as $linha) {
                $linhaNumero++;
                $this->linhaAtual = $linhaNumero;
                $this->progresso = round(($linhaNumero / $this->totalLinhas) * 100);
                $this->dispatch('progresso-atualizado', $this->progresso);
                
                $dados = str_getcsv($linha, ';');
                if ($linhaNumero === 1) {
                    // Mapear cabeçalho para índice
                    foreach ($dados as $i => $coluna) {
                        $cabecalho[trim($coluna)] = $i;
                    }
                    // Buscar código da empresa e CNPJ se existirem no cabeçalho
                    if (isset($cabecalho['Código da Empresa'])) {
                        $codigoEmpresa = $dados[$cabecalho['Código da Empresa']] ?? '';
                    }
                    if (isset($cabecalho['CNPJ da Empresa'])) {
                        $cnpjEmpresa = $dados[$cabecalho['CNPJ da Empresa']] ?? '';
                    }
                    continue;
                }
                // Função auxiliar para pegar valor por nome de coluna
                $get = function($nome) use ($cabecalho, $dados) {
                    return isset($cabecalho[$nome]) ? ($dados[$cabecalho[$nome]] ?? null) : null;
                };
                // Agora use $get('Nome da Coluna') para buscar os dados
                $dataLancamento = $get('Data do Lançamento');
                if ($dataLancamento) {
                    $datasLancamentos[] = $this->parseData($dataLancamento);
                }
                $usuario = $get('Usuário');
                $contaDebito = $get('Conta Débito');
                $contaCredito = $get('Conta Crédito');
                $valor = $get('Valor do Lançamento');
                $historico = $get('Histórico') ?? $get('Histórico (Complemento)');
                $codigoFilial = $get('Código da Filial/Matriz');
                $nomeEmpresa = $get('Nome da Empresa');
                $numeroNota = $get('Número da Nota');
                
                // Se não há código da filial, usar o código da empresa
                if (empty($codigoFilial)) {
                    $empresa = \App\Models\Empresa::find($importacao->empresa_id);
                    if ($empresa && $empresa->codigo_sistema) {
                        $codigoFilial = str_pad($empresa->codigo_sistema, 7, '0', STR_PAD_LEFT);
                    }
                }
                
                if (count($dados) >= 9) {
                    // Processar terceiro se existir
                    $terceiroId = null;
                    if (!empty($nomeEmpresa)) { // Nome da Empresa
                        $terceiro = Terceiro::firstOrCreate(
                            ['nome' => trim($nomeEmpresa)],
                            [
                                'tipo' => 'empresa',
                                'ativo' => true
                            ]
                        );
                        $terceiroId = $terceiro->id;
                    }

                    // NOVA LÓGICA DE AMARRAÇÃO
                    $terceiroNome = trim($nomeEmpresa ?? '');
                    $historico = $historico ?? '';
                    $palavras = array_filter(array_map('trim', preg_split('/\s+/', preg_replace('/[^\w\s]/u', '', $historico))));
                    $detalhesOperacao = $historico;
                    $contaDebito = ltrim($contaDebito, '0');
                    $contaCredito = ltrim($contaCredito, '0');

                    // Filtrar detalhes para amarração
                    $palavrasTags = array_filter(array_map('trim', preg_split('/\s+/', preg_replace('/[^\w\s]/u', '', $historico))));
                    if ($terceiroNome) {
                        $palavrasTerceiro = array_filter(array_map('trim', preg_split('/\s+/', preg_replace('/[^\w\s]/u', '', $terceiroNome))));
                        $palavrasTags = array_filter($palavrasTags, function($palavra) use ($palavrasTerceiro) {
                            return !in_array(strtolower($palavra), array_map('strtolower', $palavrasTerceiro));
                        });
                    }
                    $palavrasTags = $this->filtrarPadroesIndesejados($palavrasTags, $historico);

                    // Buscar empresa pelo código ou CNPJ se possível
                    $empresa = null;
                    if (!empty($codigoEmpresa)) {
                        $empresa = \App\Models\Empresa::where('codigo_sistema', $codigoEmpresa)->first();
                    } elseif (!empty($cnpjEmpresa)) {
                        $empresa = \App\Models\Empresa::where('cnpj', $cnpjEmpresa)->first();
                    }
                    $contaBancoEmpresa = $empresa ? ltrim($empresa->codigo_conta_banco, '0') : null;
                    
                    // Salvar a empresa encontrada para associar à importação
                    if ($empresa && !isset($empresaImportacao)) {
                        $empresaImportacao = $empresa;
                    }

                    // LÓGICA DE BUSCA PROGRESSIVA DE AMARRAÇÕES
                    $amarracao = null;
                    if (count($palavrasTags) >= 2) {
                        $tags_completas = trim(implode(',', $palavrasTags), '"');
                        $tags_completas = str_replace('"', '', $tags_completas);
                        $tags_completas = trim($tags_completas);
                        if ($contaBancoEmpresa && $contaDebito === $contaBancoEmpresa) {
                            $amarracao = Amarracao::where('terceiro', $terceiroNome)
                                ->where('detalhes_operacao', $tags_completas)
                                ->where('conta_debito', $contaBancoEmpresa)
                                ->first();
                        } else {
                            $amarracao = Amarracao::where('terceiro', $terceiroNome)
                                ->where('detalhes_operacao', $tags_completas)
                                ->where(function($q) use ($contaBancoEmpresa) {
                                    if ($contaBancoEmpresa) {
                                        $q->where('conta_debito', '!=', $contaBancoEmpresa);
                                    }
                                })
                                ->first();
                        }
                        // Busca progressiva reduzindo tags
                        if (!$amarracao && count($palavrasTags) > 2) {
                            $tags_para_testar = $palavrasTags;
                            while (count($tags_para_testar) > 2 && !$amarracao) {
                                array_pop($tags_para_testar);
                                $tags_reduzidas = trim(implode(',', $tags_para_testar), '"');
                                $tags_reduzidas = str_replace('"', '', $tags_reduzidas);
                                $tags_reduzidas = trim($tags_reduzidas);
                                if ($contaBancoEmpresa && $contaDebito === $contaBancoEmpresa) {
                                    $amarracao = Amarracao::where('terceiro', $terceiroNome)
                                        ->where('detalhes_operacao', $tags_reduzidas)
                                        ->where('conta_debito', $contaBancoEmpresa)
                                        ->first();
                                } else {
                                    $amarracao = Amarracao::where('terceiro', $terceiroNome)
                                        ->where('detalhes_operacao', $tags_reduzidas)
                                        ->where(function($q) use ($contaBancoEmpresa) {
                                            if ($contaBancoEmpresa) {
                                                $q->where('conta_debito', '!=', $contaBancoEmpresa);
                                            }
                                        })
                                        ->first();
                                }
                            }
                        }
                        if (!$amarracao) {
                            $amarracao_id = Amarracao::insertGetId([
                                'terceiro' => $terceiroNome,
                                'detalhes_operacao' => $tags_completas,
                                'conta_debito' => $contaDebito,
                                'conta_credito' => $contaCredito,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $amarracao = Amarracao::find($amarracao_id);
                        }
                    }
                    $contaDebitoFinal = $amarracao ? $amarracao->conta_debito : $contaDebito;
                    $contaCreditoFinal = $amarracao ? $amarracao->conta_credito : $contaCredito;

                    Lancamento::create([
                        'data' => $this->parseData($dataLancamento),
                        'usuario' => $usuario ?? '',
                        'conta_debito' => $contaDebitoFinal,
                        'conta_credito' => $contaCreditoFinal,
                        'conta_debito_original' => $contaDebito,
                        'conta_credito_original' => $contaCredito,
                        'valor' => $this->parseValor($valor ?? 0),
                        'historico' => $historico,
                        'codigo_filial_matriz' => $codigoFilial ?? null,
                        'nome_empresa' => $nomeEmpresa ?? null,
                        'numero_nota' => $numeroNota ?? null,
                        'importacao_id' => $importacao->id,
                        'terceiro_id' => $terceiroId,
                        'amarracao_id' => $amarracao->id
                    ]);
                    $importados++;
                }
            }

            // Calcular data inicial e final
            $dataInicial = !empty($datasLancamentos) ? min($datasLancamentos) : null;
            $dataFinal = !empty($datasLancamentos) ? max($datasLancamentos) : null;

            // Atualizar importação
            $importacao->update([
                'total_registros' => $importados,
                'registros_processados' => $importados,
                'status' => 'concluida',
                'codigo_empresa' => $codigoEmpresa,
                'cnpj_empresa' => $cnpjEmpresa,
                'empresa_id' => $empresaImportacao->id ?? null,
                'data_inicial' => $dataInicial,
                'data_final' => $dataFinal
            ]);

            $this->totalImportado = $importados;
            $this->progresso = 100;
            $this->mensagem = "Importação concluída! {$importados} lançamentos importados.";
            
            // Limpar arquivo temporário
            Storage::delete($caminho);
            
        } catch (\Exception $e) {
            $this->progresso = 0;
            if ($this->importacaoId) {
                Importacao::where('id', $this->importacaoId)->update([
                    'status' => 'erro',
                    'erro_mensagem' => $e->getMessage()
                ]);
            }
            $this->mensagem = 'Erro na importação: ' . $e->getMessage();
        }

        $this->processando = false;
        $this->progresso = 0;
        $this->linhaAtual = 0;
        $this->totalLinhas = 0;
        $this->arquivo = null;
    }

    private function parseData($data)
    {
        // Tentar diferentes formatos de data
        $formatos = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'];
        
        foreach ($formatos as $formato) {
            $dataObj = \DateTime::createFromFormat($formato, trim($data));
            if ($dataObj) {
                return $dataObj->format('Y-m-d');
            }
        }
        
        return now()->format('Y-m-d');
    }

    private function parseValor($valor)
    {
        // Remover caracteres não numéricos exceto vírgula e ponto
        $valor = preg_replace('/[^0-9,.-]/', '', $valor);
        
        // Converter vírgula para ponto
        $valor = str_replace(',', '.', $valor);
        
        return (float) $valor;
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



    public function render()
    {
        return view('livewire.importador-csv');
    }
}
