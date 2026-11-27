<?php

namespace App\Services\Nfse;

use Illuminate\Support\Str;
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
        $bruto = $this->lerBruto($path, $tipo, $delimitador, $aba);
        return $this->tabela($bruto, $cabecalho);
    }

    public function detectar(string $path, string $tipo, ?int $abaSelecionada = null): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            $this->falhar('Arquivo fora dos limites de importação.');
        }
        $melhor = null;
        $alternativa = null;
        $abas = $this->abas($path, $tipo);
        if ($tipo !== 'csv' && $abaSelecionada !== null && ! array_key_exists($abaSelecionada, $abas)) {
            $this->falhar('Aba não encontrada.');
        }
        $opcoes = $tipo === 'csv' ? array_map(fn ($d) => [0, $d], [';', ',', "\t"]) : array_map(fn ($a) => [$a, ';'], $abaSelecionada === null ? array_keys($abas) : [$abaSelecionada]);
        foreach ($opcoes as [$aba, $delimitador]) {
            try {
                $bruto = $this->lerBruto($path, $tipo, $delimitador, $aba);
            } catch (ValidationException $e) {
                continue;
            }
            for ($r = 1; $r <= 20; $r++) {
                try {
                    $tabela = $this->tabela($bruto, $r);
                } catch (ValidationException $e) {
                    continue;
                }
                if (count($tabela['colunas']) < 2 || isset($bruto['formulas'][$r])) {
                    continue;
                }
                $sugestoes = $this->sugerir($tabela['colunas']);
                $pontos = count($sugestoes) * 100 + count($tabela['colunas']);
                if (! isset($sugestoes['coluna_tomador'], $sugestoes['coluna_valor'])) {
                    // Cabeçalhos desconhecidos: somente sugerir linhas de títulos textuais,
                    // sem interpretar a primeira linha de dados como um cabeçalho numérico.
                    $rotulos = array_filter($tabela['colunas'], fn ($v) => preg_match('/\pL/u', $v) && mb_strlen($v) <= 100);
                    if (count($rotulos) === count($tabela['colunas']) && ! isset($bruto['numericas'][$r])
                        && ($alternativa === null || $pontos > $alternativa['pontos'])) {
                        $alternativa = $tabela + ['cabecalho' => $r, 'aba' => $aba, 'delimitador' => $delimitador, 'sugestoes' => $sugestoes, 'pontos' => $pontos];
                    }
                    continue;
                }
                if ($melhor === null || $pontos > $melhor['pontos']) {
                    $melhor = $tabela + ['cabecalho' => $r, 'aba' => $aba, 'delimitador' => $delimitador, 'sugestoes' => $sugestoes, 'pontos' => $pontos];
                }
            }
            if ($melhor !== null && $tipo !== 'csv') {
                break;
            }
        }
        $melhor ??= $alternativa;
        if ($melhor === null) {
            // Não impedir o mapeamento manual de uma planilha com títulos desconhecidos.
            $this->falhar('Não foi possível sugerir o cabeçalho automaticamente. Selecione a aba, linha do cabeçalho e separador e clique em Ler colunas.');
        }
        return $melhor + ['abas' => $abas];
    }

    public function sugerir(array $colunas): array
    {
        $aliases = [
            'coluna_tomador' => ['cpf', 'cnpj', 'cpf cnpj', 'cnpj cpf', 'documento', 'documento tomador', 'cpf do cliente', 'cpf do tomador', 'cnpj do cliente', 'cnpj do tomador', 'cpf cliente', 'cnpj cliente'],
            'coluna_nome' => ['nome completo', 'nome', 'cliente', 'tomador', 'nome cliente', 'nome do cliente', 'nome tomador', 'nome do tomador', 'razao social', 'nome razao social'],
            'coluna_valor' => ['valor pago', 'valor', 'valor do servico', 'valor servico', 'valor total', 'valor recebido', 'valor da nota', 'mensalidade', 'total pago'],
            'coluna_endereco' => ['endereco completo', 'endereco', 'endereco do cliente', 'endereco tomador'],
            'coluna_servico' => ['codigo servico', 'codigo do servico', 'codigo interno do servico'],
            'coluna_descricao' => ['descricao', 'descricao do servico', 'discriminacao', 'discriminacao dos servicos'],
            'coluna_cep' => ['cep'], 'coluna_logradouro' => ['logradouro', 'rua'], 'coluna_numero' => ['numero', 'numero endereco'],
            'coluna_bairro' => ['bairro'], 'coluna_complemento' => ['complemento'], 'coluna_municipio' => ['codigo municipio ibge', 'codigo ibge', 'ibge'],
            'coluna_uf' => ['uf', 'estado'], 'coluna_email' => ['email', 'e mail'], 'coluna_telefone' => ['telefone', 'celular'],
        ];
        $normalizadas = array_map(fn ($v) => trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($v)))), $colunas);
        $sugestoes = [];
        foreach ($aliases as $campo => $nomes) {
            $indices = array_keys(array_filter($normalizadas, fn ($v) => in_array($v, $nomes, true)));
            if (count($indices) === 1) {
                $sugestoes[$campo] = $indices[0];
            }
        }
        return $sugestoes;
    }

    private function lerBruto(string $path, string $tipo, string $delimitador, int $aba): array
    {
        return match ($tipo) {
            'csv' => ['linhas' => $this->csv($path, $delimitador), 'numericas' => [], 'formulas' => [], 'invalidas' => [], 'nome_aba' => 'CSV'],
            'xls', 'xlsx' => $this->excel($path, $tipo, $aba),
            default => $this->falhar('Use CSV, XLS ou XLSX.'),
        };
    }

    private function tabela(array $bruto, int $cabecalho): array
    {
        $linhas = $bruto['linhas'];
        $colunas = $linhas[$cabecalho] ?? [];
        while ($colunas !== [] && end($colunas) === '') {
            array_pop($colunas);
        }
        if ($colunas === [] || isset($bruto['formulas'][$cabecalho]) || isset($bruto['invalidas'][$cabecalho]) || in_array('', $colunas, true) || count(array_unique($colunas)) !== count($colunas)) {
            $this->falhar('O cabeçalho precisa conter nomes preenchidos e distintos.');
        }
        $dados = [];
        $ignoradas = [];
        foreach ($linhas as $numero => $linha) {
            if ($numero <= $cabecalho || (count(array_filter($linha, fn ($v) => $v !== '')) === 0 && empty($bruto['formulas'][$numero]) && empty($bruto['invalidas'][$numero]))) {
                continue;
            }
            $preenchidas = array_values(array_filter($linha, fn ($v) => $v !== ''));
            $rotulo = strtolower(Str::ascii($preenchidas[0] ?? ''));
            if (in_array($rotulo, ['total', 'total geral', 'subtotal', 'totais', 'soma'], true)
                && count($preenchidas) <= 2
                && (count($preenchidas) === 1 || preg_match('/^(?:R\$\s*)?[\d.,]+$/D', $preenchidas[1]))) {
                $ignoradas[] = $numero;
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
        return ['colunas' => $colunas, 'linhas' => $dados, 'assinatura' => hash('sha256', json_encode($colunas, JSON_THROW_ON_ERROR)), 'ignoradas' => $ignoradas, 'numericas' => $bruto['numericas'], 'formulas' => $bruto['formulas'], 'invalidas' => $bruto['invalidas'], 'nome_aba' => $bruto['nome_aba']];
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

    public function abas(string $path, string $tipo): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            $this->falhar('Arquivo fora dos limites de importação.');
        }
        if ($tipo === 'csv') {
            return [];
        }
        if (! in_array($tipo, ['xls', 'xlsx'], true)) {
            $this->falhar('Use CSV, XLS ou XLSX.');
        }
        $this->verificarZip($path, $tipo);
        try {
            $reader = $tipo === 'xlsx' ? new Xlsx : new Xls;
            $abas = $reader->listWorksheetNames($path);
        } catch (\Throwable) {
            $this->falhar('Não foi possível reconhecer as abas do Excel.');
        }
        if (count($abas) > 20 || $abas === []) {
            $this->falhar('Use uma planilha com 1 a 20 abas.');
        }
        return array_values($abas);
    }

    private function verificarZip(string $path, string $tipo): void
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
    }

    private function excel(string $path, string $tipo, int $aba): array
    {
        $this->verificarZip($path, $tipo);
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
                $linhas = $numericas = $formulas = $invalidas = [];
                for ($r = 1; $r <= $sheet['totalRows']; $r++) {
                    for ($c = 1; $c <= $sheet['totalColumns']; $c++) {
                        $cell = $worksheet->getCell(Coordinate::stringFromColumnIndex($c).$r);
                        // Nunca executar fórmulas, inclusive links externos. Utilizar somente valor salvo pelo Excel.
                        $valor = $cell->getValue();
                        if ($cell->isFormula()) {
                            $valor = $cell->getOldCalculatedValue();
                            $formulas[$r][$c - 1] = true;
                        }
                        if ($cell->getDataType() === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_ERROR
                            || ($cell->isFormula() && ($valor === null || (is_string($valor) && str_starts_with($valor, '#'))))) {
                            $invalidas[$r][$c - 1] = true;
                            $valor = null;
                        }
                        if (is_int($valor) || is_float($valor)) {
                            $numericas[$r][$c - 1] = true;
                        }
                        $linhas[$r][] = $this->texto($valor);
                    }
                }
                return ['linhas' => $linhas, 'numericas' => $numericas, 'formulas' => $formulas, 'invalidas' => $invalidas, 'nome_aba' => $sheet['worksheetName']];
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
