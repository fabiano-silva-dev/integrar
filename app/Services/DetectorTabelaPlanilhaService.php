<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class DetectorTabelaPlanilhaService
{
    public function analisar(string $arquivoPlanilha): array
    {
        return $this->executar($arquivoPlanilha);
    }

    public function extrair(
        string $arquivoPlanilha,
        ?string $aba = null,
        int $indiceTabela = 0,
        ?string $arquivoCsv = null
    ): array {
        $arquivoCsv = $arquivoCsv ?: ('/tmp/planilha_tabela_' . uniqid() . '.csv');

        return $this->executar($arquivoPlanilha, $arquivoCsv, $aba, $indiceTabela);
    }

    private function executar(
        string $arquivoPlanilha,
        ?string $arquivoCsv = null,
        ?string $aba = null,
        int $indiceTabela = 0
    ): array {
        try {
            if (!is_file($arquivoPlanilha)) {
                throw new Exception("Arquivo de planilha não encontrado: {$arquivoPlanilha}");
            }

            $script = $this->caminhoScript();
            $comando = sprintf(
                'python3 %s %s',
                escapeshellarg($script),
                escapeshellarg($arquivoPlanilha)
            );

            if ($arquivoCsv !== null) {
                $comando .= ' ' . escapeshellarg($arquivoCsv);
                $comando .= ' --indice ' . (int) $indiceTabela;
                if ($aba !== null && $aba !== '') {
                    $comando .= ' --aba ' . escapeshellarg($aba);
                }
            }

            $resultado = shell_exec($comando . ' 2>/dev/null');
            $dados = json_decode((string) $resultado, true);

            if (!is_array($dados) || !isset($dados['sucesso'])) {
                Log::error('Detector de tabela da planilha retornou resposta inválida', [
                    'resultado_raw' => $resultado,
                    'json_error' => json_last_error_msg(),
                ]);
                throw new Exception('Não foi possível analisar a planilha.');
            }

            if (!$dados['sucesso']) {
                throw new Exception($dados['mensagem'] ?? 'Nenhuma tabela foi identificada nesta planilha.');
            }

            return [
                'sucesso' => true,
                'arquivo_csv' => $dados['arquivo_saida'] ?? $arquivoCsv,
                'aba_escolhida' => $dados['aba_escolhida'] ?? null,
                'tabela_escolhida' => $dados['tabela_escolhida'] ?? 0,
                'escolha_automatica' => (bool) ($dados['escolha_automatica'] ?? false),
                'cabecalho' => $dados['cabecalho'] ?? [],
                'linhas_dados' => $dados['linhas_dados'] ?? 0,
                'abas' => $dados['abas'] ?? [],
                'abas_com_tabela' => $dados['abas_com_tabela'] ?? [],
                'tabelas' => $dados['tabelas'] ?? [],
                'resumo' => $dados['resumo'] ?? [],
                'mensagem' => $dados['mensagem'] ?? '',
            ];
        } catch (Exception $e) {
            Log::error('Erro ao detectar tabela da planilha', [
                'arquivo' => $arquivoPlanilha,
                'erro' => $e->getMessage(),
            ]);

            return [
                'sucesso' => false,
                'erro' => $e->getMessage(),
            ];
        }
    }

    private function caminhoScript(): string
    {
        $candidatos = [
            '/var/www/html/scripts/detector_tabela_planilha.py',
            base_path('scripts/detector_tabela_planilha.py'),
        ];

        foreach ($candidatos as $caminho) {
            if (is_file($caminho)) {
                return $caminho;
            }
        }

        return $candidatos[0];
    }
}
