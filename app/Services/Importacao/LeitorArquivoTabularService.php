<?php

namespace App\Services\Importacao;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class LeitorArquivoTabularService
{
    /**
     * @return array{colunas: list<string>, linhas: list<array<string, string>>}
     */
    public function ler(
        string $caminho,
        string $extensao,
        int $linhaCabecalho = 1,
        string $delimitador = ';',
        bool $temCabecalho = true
    ): array {
        $extensao = strtolower(ltrim($extensao, '.'));

        if ($extensao === 'csv') {
            return $this->lerCsv($caminho, $linhaCabecalho, $delimitador, $temCabecalho);
        }

        if (in_array($extensao, ['xls', 'xlsx'], true)) {
            return $this->lerExcel($caminho, $linhaCabecalho, $temCabecalho);
        }

        throw new RuntimeException("Formato não suportado: {$extensao}");
    }

    /**
     * @return array{colunas: list<string>, linhas: list<array<string, string>>}
     */
    private function lerCsv(string $caminho, int $linhaCabecalho, string $delimitador, bool $temCabecalho): array
    {
        $handle = fopen($caminho, 'r');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo CSV.');
        }

        $linhaAtual = 0;
        $cabecalho = [];

        while (($row = fgetcsv($handle, 0, $delimitador)) !== false) {
            $linhaAtual++;

            if ($linhaAtual < $linhaCabecalho) {
                continue;
            }

            if ($linhaAtual === $linhaCabecalho) {
                $cabecalho = $this->normalizarCabecalho($row, $temCabecalho);
                if (!$temCabecalho) {
                    $linhas = [$this->mapearLinha($cabecalho, $row)];
                    fclose($handle);

                    return ['colunas' => $cabecalho, 'linhas' => $linhas];
                }
                continue;
            }

            if ($this->linhaVazia($row)) {
                continue;
            }

            $linhas[] = $this->mapearLinha($cabecalho, $row);
        }

        fclose($handle);

        return [
            'colunas' => $cabecalho,
            'linhas' => $linhas ?? [],
        ];
    }

    /**
     * @return array{colunas: list<string>, linhas: list<array<string, string>>}
     */
    private function lerExcel(string $caminho, int $linhaCabecalho, bool $temCabecalho): array
    {
        $spreadsheet = IOFactory::load($caminho);
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        $cabecalhoRaw = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cell = $worksheet->getCell(Coordinate::stringFromColumnIndex($col) . $linhaCabecalho);
            $cabecalhoRaw[] = $this->valorCelulaComoString($cell);
        }

        $cabecalho = $this->normalizarCabecalho($cabecalhoRaw, $temCabecalho);
        $linhas = [];

        for ($row = $linhaCabecalho + ($temCabecalho ? 1 : 0); $row <= $highestRow; $row++) {
            $valores = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $worksheet->getCell(Coordinate::stringFromColumnIndex($col) . $row);
                $valores[] = $this->valorCelulaComoString($cell);
            }

            if ($this->linhaVazia($valores)) {
                continue;
            }

            $linhas[] = $this->mapearLinha($cabecalho, $valores);
        }

        return [
            'colunas' => $cabecalho,
            'linhas' => $linhas,
        ];
    }

    private function valorCelulaComoString($cell): string
    {
        $formatado = $cell->getFormattedValue();

        if ($formatado !== null && $formatado !== '') {
            return trim((string) $formatado);
        }

        $valor = $cell->getValue();

        if ($valor === null) {
            return '';
        }

        return trim((string) $valor);
    }

    /**
     * @param  list<string>  $row
     * @return list<string>
     */
    private function normalizarCabecalho(array $row, bool $temCabecalho): array
    {
        if (!$temCabecalho) {
            return array_map(fn (int $i) => 'Coluna ' . ($i + 1), array_keys($row));
        }

        $colunas = [];
        foreach ($row as $indice => $nome) {
            $nome = trim((string) $nome);
            $colunas[] = $nome !== '' ? $nome : 'Coluna ' . ($indice + 1);
        }

        return $colunas;
    }

    /**
     * @param  list<string>  $cabecalho
     * @param  list<string>  $row
     * @return array<string, string>
     */
    private function mapearLinha(array $cabecalho, array $row): array
    {
        $linha = [];
        foreach ($cabecalho as $indice => $coluna) {
            $linha[$coluna] = trim((string) ($row[$indice] ?? ''));
        }

        return $linha;
    }

    /**
     * @param  list<string>  $row
     */
    private function linhaVazia(array $row): bool
    {
        foreach ($row as $valor) {
            if (trim((string) $valor) !== '') {
                return false;
            }
        }

        return true;
    }
}
