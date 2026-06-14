<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class ConversorExcelService
{
    /**
     * Converte arquivo Excel para CSV usando Python
     */
    public function converterExcelParaCsv($arquivoExcel, $delimitador = ',')
    {
        try {
            // Gerar nome único para arquivo de saída
            $nomeArquivo = pathinfo($arquivoExcel, PATHINFO_FILENAME);
            $arquivoCsv = "/tmp/{$nomeArquivo}_" . time() . ".csv";
            
            // Comando Python
            $comando = sprintf(
                'python3 %s %s %s %s',
                '/var/www/html/scripts/conversor_laravel.py',
                escapeshellarg($arquivoExcel),
                escapeshellarg($arquivoCsv),
                escapeshellarg($delimitador)
            );
            
            // Log::info("Executando conversor Python", ['comando' => $comando]);
            
            // Executar conversão
            $resultado = shell_exec($comando);
            
            // Log para debug
            Log::debug('Resultado do conversor Python:', ['resultado' => $resultado]);
            
            $dados = json_decode($resultado, true);
            
            if (!$dados || !isset($dados['sucesso'])) {
                Log::error('Erro ao decodificar JSON do conversor Python:', [
                    'resultado_raw' => $resultado,
                    'json_error' => json_last_error_msg()
                ]);
                throw new Exception("Erro ao executar conversor Python: resposta inválida - " . json_last_error_msg());
            }
            
            if (!$dados['sucesso']) {
                throw new Exception("Erro na conversão: " . ($dados['mensagem'] ?? 'Erro desconhecido'));
            }
            
            // Log::info("Conversão realizada com sucesso", [
            //     'arquivo_entrada' => $arquivoExcel,
            //     'arquivo_saida' => $arquivoCsv,
            //     'resumo' => $dados['resumo']
            // ]);
            
            return [
                'sucesso' => true,
                'arquivo_csv' => $arquivoCsv,
                'tipos_detectados' => $dados['tipos_detectados'],
                'resumo' => $dados['resumo']
            ];
            
        } catch (Exception $e) {
            // Log::error("Erro na conversão Excel para CSV", [
            //     'arquivo' => $arquivoExcel,
            //     'erro' => $e->getMessage()
            // ]);
            
            return [
                'sucesso' => false,
                'erro' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Detecta tipos de colunas sem converter o arquivo
     */
    public function detectarTiposColunas($arquivoExcel)
    {
        try {
            // Arquivo temporário para detecção
            $arquivoTemp = "/tmp/detectar_tipos_" . time() . ".csv";
            
            $comando = sprintf(
                'python3 %s %s %s',
                '/var/www/html/scripts/conversor_laravel.py',
                escapeshellarg($arquivoExcel),
                escapeshellarg($arquivoTemp)
            );
            
            $resultado = shell_exec($comando);
            $dados = json_decode($resultado, true);
            
            // Limpar arquivo temporário
            if (file_exists($arquivoTemp)) {
                unlink($arquivoTemp);
            }
            
            if (!$dados || !$dados['sucesso']) {
                throw new Exception("Erro na detecção de tipos: " . ($dados['mensagem'] ?? 'Erro desconhecido'));
            }
            
            return $dados['tipos_detectados'];
            
        } catch (Exception $e) {
            Log::error("Erro na detecção de tipos", [
                'arquivo' => $arquivoExcel,
                'erro' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    /**
     * Limpa arquivos temporários antigos
     */
    public function limparArquivosTemporarios($maxIdade = 3600) // 1 hora
    {
        try {
            $diretorio = OperadoraStorage::tempDirectory();
            if (!is_dir($diretorio)) {
                return;
            }
            
            $arquivos = glob($diretorio . '/*');
            $agora = time();
            
            foreach ($arquivos as $arquivo) {
                if (is_file($arquivo)) {
                    $idade = $agora - filemtime($arquivo);
                    if ($idade > $maxIdade) {
                        unlink($arquivo);
                        Log::info("Arquivo temporário removido", ['arquivo' => $arquivo]);
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning("Erro ao limpar arquivos temporários", ['erro' => $e->getMessage()]);
        }
    }
    
    /**
     * Verifica se o conversor Python está disponível
     */
    public function verificarConversor()
    {
                    try {
                $comando = sprintf(
                    'python3 %s --help 2>&1',
                    '/var/www/html/scripts/conversor_laravel.py'
                );
            
            $resultado = shell_exec($comando);
            
                    // Verificar se Python está disponível
        $pythonComando = 'python3 --version 2>&1';
            $versaoPython = shell_exec($pythonComando);
            
            return [
                'python_disponivel' => !empty($versaoPython),
                'versao_python' => trim($versaoPython),
                'conversor_disponivel' => !empty($resultado),
                'conversor_ajuda' => $resultado
            ];
            
        } catch (Exception $e) {
            return [
                'python_disponivel' => false,
                'versao_python' => null,
                'conversor_disponivel' => false,
                'erro' => $e->getMessage()
            ];
        }
    }
}
