<?php

namespace App\Services\Nfse;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use ZipArchive;

class LeitorPlanilha
{
    public const MAX_LINHAS = 200;
    public const MAX_COLUNAS = 40;
    public const MAX_BYTES = 2097152;

    public function ler(string $path, string $tipo, int $cabecalho, string $delimitador = ';', int $aba = 0): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_BYTES || $cabecalho < 1 || $cabecalho > 20 || $aba < 0 || $aba > 19 || ! in_array($delimitador, [';', ',', "\t"], true)) {
            $this->falhar('Arquivo ou configuração fora dos limites de importação.');
        }
        $linhas = match ($tipo) {
            'csv' => $this->csv($path, $delimitador),
            'xls', 'xlsx' => $this->excel($path, $tipo, $aba),
            default => $this->falhar('Use CSV, XLS ou XLSX.'),
        };
        $colunas = $linhas[$cabecalho] ?? [];
        while ($colunas !== [] && end($colunas) === '') {
            array_pop($colunas);
        }
        if ($colunas === [] || in_array('', $colunas, true) || count(array_unique($colunas)) !== count($colunas)) {
            $this->falhar('O cabeçalho precisa conter nomes preenchidos e distintos.');
        }
        $dados = [];
        foreach ($linhas as $numero => $linha) {
            if ($numero <= $cabecalho || count(array_filter($linha, fn ($v) => $v !== '')) === 0) {
                continue;
            }
            if (count($dados) >= self::MAX_LINHAS || count(array_filter(array_slice($linha, count($colunas)), fn ($v) => $v !== '')) > 0) {
                $this->falhar('Limite de 200 linhas de dados ou colunas sem cabeçalho excedido.');
            }
            $dados[$numero] = array_pad(array_slice($linha, 0, count($colunas)), count($colunas), '');
        }
        if ($dados === []) {
            $this->falhar('Não há linhas de dados para conferir.');
        }
        return ['colunas' => $colunas, 'linhas' => $dados, 'assinatura' => hash('sha256', json_encode($colunas, JSON_THROW_ON_ERROR))];
    }

    private function csv(string $path, string $delimitador): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            $this->falhar('Não foi possível ler o CSV.');
        }
        $linhas = [];
        try {
            while (($linha = fgetcsv($handle, 0, $delimitador, '"', '')) !== false) {
                if (count($linhas) >= self::MAX_LINHAS + 20 || count($linha) > self::MAX_COLUNAS) {
                    $this->falhar('Planilha excede os limites de linhas ou colunas.');
                }
                $numero = count($linhas) + 1;
                $linhas[$numero] = array_map(fn ($v) => $this->texto($v), $linha);
                if ($numero === 1) {
                    $linhas[1][0] = preg_replace('/^\xEF\xBB\xBF/', '', $linhas[1][0]);
                }
            }
        } finally {
            fclose($handle);
        }
        return $linhas;
    }

    private function excel(string $path, string $tipo, int $aba): array
    {
        if ($tipo === 'xlsx') {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                $this->falhar('Arquivo XLSX inválido.');
            }
            try {
                $total = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $total += $stat['size'] ?? 0;
                    if ($i >= 500 || $total > 16777216) {
                        $this->falhar('XLSX excede o limite de descompactação.');
                    }
                }
            } finally {
                $zip->close();
            }
        }
        $reader = $tipo === 'xlsx' ? new Xlsx : new Xls;
        try {
            $info = $reader->listWorksheetInfo($path);
            $sheet = $info[$aba] ?? null;
            if (! $sheet || $sheet['totalRows'] > self::MAX_LINHAS + 20 || $sheet['totalColumns'] > self::MAX_COLUNAS) {
                $this->falhar('Aba inexistente ou planilha excede os limites.');
            }
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly($sheet['worksheetName']);
            $book = $reader->load($path);
            try {
                $worksheet = $book->getSheet(0);
                $linhas = [];
                for ($r = 1; $r <= $sheet['totalRows']; $r++) {
                    for ($c = 1; $c <= $sheet['totalColumns']; $c++) {
                        $cell = $worksheet->getCell(Coordinate::stringFromColumnIndex($c).$r);
                        if ($cell->isFormula()) {
                            $this->falhar('A planilha contém fórmulas. Exporte apenas os valores antes de importar.');
                        }
                        $linhas[$r][] = $this->texto($cell->getValue());
                    }
                }
                return $linhas;
            } finally {
                $book->disconnectWorksheets();
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->falhar('Não foi possível ler a planilha Excel. Verifique se o arquivo está íntegro.');
        }
    }

    private function texto(mixed $valor): string
    {
        if ($valor !== null && ! is_scalar($valor)) {
            $this->falhar('Planilha contém uma célula não suportada.');
        }
        $texto = trim((string) $valor);
        if (strlen($texto) > 8000 || ! mb_check_encoding($texto, 'UTF-8')) {
            $this->falhar('Célula muito longa ou codificação inválida. Use UTF-8.');
        }
        return $texto;
    }

    private function falhar(string $mensagem): never
    {
        throw ValidationException::withMessages(['arquivo' => $mensagem]);
    }
}
