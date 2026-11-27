<?php

namespace App\Services\Nfse;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

class CatalogoTributarioNfse
{
    public const FONTES = [
        'portal_nfe' => 'https://www.nfe.fazenda.gov.br/PORTAL/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D',
        'svrs' => 'https://dfe-portal.svrs.rs.gov.br/DFE/ClassificacaoTributaria',
    ];

    public const CABECALHO = ['CST', 'descricaoCST', 'cClassTrib', 'descricao', 'dIniVig', 'dFimVig', 'indNFSe', 'ind_gIBSCBS', 'pRedIBS', 'pRedCBS'];

    /**
     * Formato intermediário normalizado, não pressupõe o formato do XLSX/JSON do portal.
     * Importação administrativa da tabela completa conferida, sem rede ou dados de clientes.
     */
    public function importar(string $csv, string $fonte, string $versao, string $publicadaEm, string $sha256): stdClass
    {
        if (! isset(self::FONTES[$fonte]) || ! preg_match('/^[\pL\pN ._-]{1,80}$/uD', $versao)
            || ! self::dataValida($publicadaEm) || $publicadaEm > now()->toDateString()
            || ! preg_match('/^[a-f0-9]{64}$/D', $sha256) || ! hash_equals($sha256, hash('sha256', $csv))) {
            throw new InvalidArgumentException('Metadados ou hash do arquivo inválidos.');
        }
        $linhas = $this->ler($csv);

        return DB::transaction(function () use ($linhas, $fonte, $versao, $publicadaEm, $sha256) {
            $existente = DB::table('nfse_catalogo_versoes')->where('sha256', $sha256)->first();
            if ($existente) {
                if ($existente->fonte !== $fonte || $existente->versao !== $versao || $existente->publicada_em !== $publicadaEm) {
                    throw new InvalidArgumentException('Arquivo já registrado com outros metadados.');
                }
                return $existente;
            }
            if (DB::table('nfse_catalogo_versoes')->where('fonte', $fonte)->where('versao', $versao)->exists()) {
                throw new InvalidArgumentException('Versão já registrada com outro conteúdo. Não sobrescreva o histórico.');
            }
            $id = DB::table('nfse_catalogo_versoes')->insertGetId([
                'fonte' => $fonte, 'versao' => $versao, 'publicada_em' => $publicadaEm, 'sha256' => $sha256,
                'quantidade' => count($linhas), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($linhas, 200) as $lote) {
                DB::table('nfse_catalogo_classificacoes')->insert(array_map(fn ($l) => ['versao_id' => $id] + $l, $lote));
            }

            return DB::table('nfse_catalogo_versoes')->find($id);
        });
    }

    public function atual(): ?stdClass
    {
        return DB::table('nfse_catalogo_versoes')->orderByDesc('publicada_em')->orderByDesc('id')->first();
    }

    /** Perfil mensal: deve estar permitido e vigente durante todo o mês. */
    public function consulta(int $versaoId, string $mes): Builder
    {
        if (! preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $mes)) {
            throw new InvalidArgumentException('Competência inválida.');
        }
        $inicio = CarbonImmutable::createFromFormat('!Y-m', $mes);
        return DB::table('nfse_catalogo_classificacoes')->where('versao_id', $versaoId)->where('ind_nfse', true)
            ->where('inicio', '<=', $inicio->toDateString())
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>=', $inicio->endOfMonth()->toDateString()));
    }

    public function buscar(int $versaoId, string $mes, string $busca): Builder
    {
        $q = $this->consulta($versaoId, $mes);
        $busca = trim(mb_substr($busca, 0, 100));
        if ($busca !== '') {
            $termo = '%'.$busca.'%';
            $q->where(fn ($q) => $q->where('cst', 'like', $termo)->orWhere('cclass_trib', 'like', $termo)
                ->orWhere('descricao_cst', 'like', $termo)->orWhere('descricao', 'like', $termo));
        }

        return $q->orderBy('cclass_trib');
    }

    public function ler(string $csv): array
    {
        if ($csv === '' || strlen($csv) > 5242880 || ! mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
            throw new InvalidArgumentException('CSV inválido, não UTF-8 ou maior que 5 MB.');
        }
        $fp = fopen('php://temp', 'w+');
        try {
            fwrite($fp, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
            rewind($fp);
            if (fgetcsv($fp, 0, ';', '"', '') !== self::CABECALHO) {
                throw new InvalidArgumentException('Cabeçalho do CSV normalizado não corresponde ao formato esperado.');
            }
            $linhas = [];
            $periodos = [];
            $n = 1;
            while (($l = fgetcsv($fp, 0, ';', '"', '')) !== false) {
                $n++;
                if ($l === [null]) {
                    continue;
                }
                if (count($l) !== count(self::CABECALHO) || count($linhas) >= 10000) {
                    throw new InvalidArgumentException('Quantidade de colunas ou linhas inválida na linha '.$n.'.');
                }
                [$cst, $dcst, $codigo, $desc, $ini, $fim, $nfse, $grupo, $ibs, $cbs] = array_map('trim', $l);
                if (! preg_match('/^\d{3}$/D', $cst) || ! preg_match('/^\d{6}$/D', $codigo) || substr($codigo, 0, 3) !== $cst
                    || $dcst === '' || mb_strlen($dcst) > 255 || $desc === '' || mb_strlen($desc) > 5000
                    || ! self::dataValida($ini) || ($fim !== '' && (! self::dataValida($fim) || $fim < $ini))
                    || ! in_array($nfse, ['0', '1'], true) || ! in_array($grupo, ['', '0', '1'], true)
                    || ! self::percentualValido($ibs) || ! self::percentualValido($cbs)) {
                    throw new InvalidArgumentException('Dados inválidos na linha '.$n.'.');
                }
                // Intervalos inclusivos, sem sobreposição para o mesmo código.
                $ate = $fim === '' ? '9999-12-31' : $fim;
                foreach ($periodos[$codigo] ?? [] as [$a, $b]) {
                    if ($ini <= $b && $ate >= $a) {
                        throw new InvalidArgumentException('Vigências sobrepostas para o código '.$codigo.'.');
                    }
                }
                $periodos[$codigo][] = [$ini, $ate];
                $linhas[] = [
                    'cst' => $cst, 'cclass_trib' => $codigo, 'descricao_cst' => $dcst, 'descricao' => $desc,
                    'inicio' => $ini, 'fim' => $fim === '' ? null : $fim, 'ind_nfse' => (int) $nfse,
                    'ind_gibscbs' => $grupo === '' ? null : (int) $grupo,
                    'reducao_ibs' => $ibs === '' ? null : $ibs, 'reducao_cbs' => $cbs === '' ? null : $cbs,
                ];
            }
            if ($linhas === []) {
                throw new InvalidArgumentException('Catálogo vazio.');
            }
            return $linhas;
        } finally {
            fclose($fp);
        }
    }

    private static function dataValida(string $data): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        return $d !== false && preg_match('/^[1-9]\d{3}-\d{2}-\d{2}$/D', $data) && $d->format('Y-m-d') === $data;
    }

    private static function percentualValido(string $v): bool
    {
        return $v === '' || (preg_match('/^(?:0|[1-9]\d{0,2})\.\d{2}$/D', $v) && (float) $v <= 100);
    }
}
