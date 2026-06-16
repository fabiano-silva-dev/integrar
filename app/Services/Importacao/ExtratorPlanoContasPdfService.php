<?php

namespace App\Services\Importacao;

use RuntimeException;

class ExtratorPlanoContasPdfService
{
    /**
     * @return array{colunas: list<string>, linhas: list<array<string, string>>, metadados: array<string, mixed>}
     */
    public function extrairDominio(string $caminhoPdf): array
    {
        $texto = $this->extrairTexto($caminhoPdf);
        if (trim($texto) === '') {
            throw new RuntimeException(
                'Não foi possível extrair texto do PDF. Este arquivo pode ser escaneado (imagem) e exigir OCR.'
            );
        }

        $linhas = preg_split('/\R/u', $texto) ?: [];
        $dados = [];
        $totalLidas = 0;

        foreach ($linhas as $linha) {
            $linha = trim((string) $linha);
            if ($linha === '' || $this->ignorarLinha($linha)) {
                continue;
            }

            $totalLidas++;
            $conta = $this->parseLinhaDominio($linha);
            if ($conta === null) {
                continue;
            }

            $dados[] = $conta;
        }

        if ($dados === []) {
            throw new RuntimeException(
                'Nenhuma linha de plano de contas reconhecida no PDF. Verifique se o layout é Domínio nativo.'
            );
        }

        return [
            'colunas' => [
                'codigo',
                'classificacao',
                'codigo_reduzido',
                'descricao',
                'tipo',
                'natureza',
                'nivel',
                'codigo_pai',
                'aceita_lancamento',
            ],
            'linhas' => $dados,
            'metadados' => [
                'layout_pdf' => 'dominio_nativo',
                'linhas_lidas' => $totalLidas,
                'linhas_parseadas' => count($dados),
            ],
        ];
    }

    private function extrairTexto(string $caminhoPdf): string
    {
        $comando = sprintf(
            "pdftotext -layout %s - 2>/dev/null",
            escapeshellarg($caminhoPdf)
        );

        $saida = shell_exec($comando);

        if (!is_string($saida)) {
            return '';
        }

        return str_replace("\f", "\n", $saida);
    }

    private function ignorarLinha(string $linha): bool
    {
        return str_starts_with($linha, 'Empresa:')
            || str_starts_with($linha, 'C.N.P.J')
            || str_starts_with($linha, 'PLANO DE CONTAS')
            || str_starts_with($linha, 'Folha:')
            || str_starts_with($linha, 'Código')
            || str_contains($linha, 'Sistema licenciado');
    }

    /**
     * Layout Domínio: Código, T, Classificação, Nome, Grau.
     * Ex.: "742 1.1.2.01.001 ARBAZA ALIMENTOS LTDA 5" ou "7 S 1.1.1.02 BANCOS CONTA MOVIMENTO 4"
     *
     * @return array<string, string>|null
     */
    private function parseLinhaDominio(string $linha): ?array
    {
        $regex = '/^\s*(\d+)\s+(S\s+)?([\d.]+)\s+(.+?)\s+(\d+)\s*$/u';
        if (!preg_match($regex, $linha, $match)) {
            return null;
        }

        $codigo = trim($match[1] ?? '');
        $isSintetica = trim($match[2] ?? '') !== '';
        $classificacao = trim($match[3] ?? '');
        $descricao = trim($match[4] ?? '');
        $nivel = (int) trim($match[5] ?? '1');

        return [
            'codigo' => $codigo,
            'classificacao' => $classificacao,
            'codigo_reduzido' => '',
            'descricao' => $descricao,
            'tipo' => $isSintetica ? 'sintetica' : 'analitica',
            'natureza' => '',
            'nivel' => (string) $nivel,
            'codigo_pai' => '',
            'aceita_lancamento' => $isSintetica ? 'nao' : 'sim',
        ];
    }
}
