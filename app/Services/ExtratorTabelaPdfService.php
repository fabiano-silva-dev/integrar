<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class ExtratorTabelaPdfService
{
    public function extrair(
        string $arquivoPdf,
        int $indiceTabela = 0,
        bool $ignorarTotais = true,
        ?string $arquivoCsv = null
    ): array {
        try {
            if (!is_file($arquivoPdf)) {
                throw new Exception("Arquivo PDF não encontrado: {$arquivoPdf}");
            }

            $arquivoCsv = $arquivoCsv ?: ('/tmp/pdf_tabela_' . uniqid() . '.csv');
            $script = $this->caminhoScript();

            $comando = sprintf(
                'python3 %s %s %s --indice %d --ignorar-totais %d',
                escapeshellarg($script),
                escapeshellarg($arquivoPdf),
                escapeshellarg($arquivoCsv),
                $indiceTabela,
                $ignorarTotais ? 1 : 0
            );

            $resultado = shell_exec($comando . ' 2>/dev/null');
            $dados = json_decode((string) $resultado, true);

            if (!is_array($dados) || !isset($dados['sucesso'])) {
                Log::error('Extrator de tabela PDF retornou resposta inválida', [
                    'resultado_raw' => $resultado,
                    'json_error' => json_last_error_msg(),
                ]);
                throw new Exception('Não foi possível analisar o PDF.');
            }

            if (!$dados['sucesso']) {
                throw new Exception($dados['mensagem'] ?? 'Nenhuma tabela foi identificada neste PDF.');
            }

            return [
                'sucesso' => true,
                'arquivo_csv' => $dados['arquivo_saida'] ?? $arquivoCsv,
                'estrategia' => $dados['estrategia'] ?? null,
                'tabela_escolhida' => $dados['tabela_escolhida'] ?? 0,
                'cabecalho' => $dados['cabecalho'] ?? [],
                'linhas_dados' => $dados['linhas_dados'] ?? 0,
                'tabelas' => $dados['tabelas'] ?? [],
                'resumo' => $dados['resumo'] ?? [],
                'mensagem' => $dados['mensagem'] ?? '',
            ];
        } catch (Exception $e) {
            Log::error('Erro ao extrair tabela do PDF', [
                'arquivo' => $arquivoPdf,
                'erro' => $e->getMessage(),
            ]);

            return [
                'sucesso' => false,
                'erro' => $e->getMessage(),
            ];
        }
    }

    public function analisar(string $arquivoPdf, bool $ignorarTotais = true): array
    {
        return $this->extrair($arquivoPdf, 0, $ignorarTotais);
    }

    private function caminhoScript(): string
    {
        $candidatos = [
            '/var/www/html/scripts/extrator_tabela_pdf.py',
            base_path('scripts/extrator_tabela_pdf.py'),
        ];

        foreach ($candidatos as $caminho) {
            if (is_file($caminho)) {
                return $caminho;
            }
        }

        return $candidatos[0];
    }
}
