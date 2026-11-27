<?php

namespace App\Services\Nfse;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

class CatalogoServicosNfse
{
    public const ARQUIVO = 'nfse/oficiais/anexo-b-nbs2-lista-servico-nacional-snnfse-v1.01-20260122.xlsx';
    public const GIT_BLOB = 'a2eab7f0e2fa54a7f22bce0ad29b1b7b751f64d1';
    public const FONTE = 'https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/anexo-b-nbs2-lista-servico-nacional-snnfse-v1.01-20260122.xlsx';

    public function catalogos(): array
    {
        return Cache::remember('nfse:servicos:'.self::GIT_BLOB, now()->addDays(30), fn () => $this->ler());
    }

    public function ler(): array
    {
        $path = resource_path(self::ARQUIVO);
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($bytes) || ! hash_equals(self::GIT_BLOB, sha1('blob '.strlen($bytes)."\0".$bytes))) {
            throw new RuntimeException('Anexo B ausente ou alterado. Atualize os arquivos do IntegraExpert.');
        }
        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        try {
            $servicos = $book->getSheetByName('LISTA.SERV.NAC.');
            $nbs = $book->getSheetByName('LISTA.NBS_v2.0');
            if (! $servicos || ! $nbs || $servicos->getCell('A1')->getValue() !== 'CÓDIGO DE TRIBUTAÇÃO NACIONAL'
                || $nbs->getCell('A1')->getValue() !== 'CÓDIGO NBS') {
                throw new RuntimeException('Estrutura do Anexo B divergente.');
            }
            $resultado = ['nacional' => [], 'nbs' => []];
            foreach (array_slice($servicos->toArray(null, false, false, false), 1) as $row) {
                if (! filled($row[0] ?? null)) {
                    continue;
                }
                $codigo = str_pad((string) $row[0], 6, '0', STR_PAD_LEFT);
                if (! preg_match('/^\d{6}$/D', $codigo) || $codigo !== sprintf('%02d%02d%02d', $row[1], $row[2], $row[3])
                    || (int) $row[3] === 0 || ! filled($row[4] ?? null) || isset($resultado['nacional'][$codigo])) {
                    throw new RuntimeException('Código nacional inválido ou duplicado no Anexo B.');
                }
                $resultado['nacional'][$codigo] = trim((string) $row[4]);
            }
            foreach (array_slice($nbs->toArray(null, false, false, false), 1) as $row) {
                $codigo = str_replace('.', '', trim((string) ($row[0] ?? '')));
                // Não disponibilizar títulos de posição/subposição nem entradas sem descrição.
                if (! preg_match('/^\d{9}$/D', $codigo) || ! filled($row[1] ?? null)) {
                    continue;
                }
                if (isset($resultado['nbs'][$codigo])) {
                    throw new RuntimeException('NBS duplicada no Anexo B.');
                }
                $resultado['nbs'][$codigo] = trim((string) $row[1]);
            }
            if (count($resultado['nacional']) < 300 || count($resultado['nbs']) < 500) {
                throw new RuntimeException('Anexo B incompleto.');
            }
            return $resultado;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function descricao(string $tipo, string $codigo): ?string
    {
        return $this->catalogos()[$tipo][$codigo] ?? null;
    }

    public function buscar(string $tipo, string $busca): array
    {
        $lista = $this->catalogos()[$tipo] ?? [];
        $busca = trim(mb_substr($busca, 0, 100));
        $numero = preg_match('/^[\d.\s]+$/D', $busca) ? preg_replace('/\D/', '', $busca) : null;
        $termos = preg_split('/\s+/', Str::lower(Str::ascii($busca)), -1, PREG_SPLIT_NO_EMPTY);
        $resultado = [];
        foreach ($lista as $codigo => $descricao) {
            $codigo = (string) $codigo;
            $texto = Str::lower(Str::ascii($descricao));
            if ($numero !== null ? ! str_starts_with($codigo, $numero) : array_filter($termos, fn ($t) => ! str_contains($texto, $t))) {
                continue;
            }
            $resultado[] = ['codigo' => $codigo, 'descricao' => $descricao];
            if (count($resultado) >= 30) {
                break;
            }
        }
        return $resultado;
    }
}
