<?php

namespace App\Services\Nfse;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

class CatalogoIndicadoresNfse
{
    public const FONTE = 'https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc';
    public const CABECALHO = ['cIndOp', 'descricao', 'fundamento', 'dIniVig', 'dFimVig'];

    /**
     * Carga administrativa de uma edição integral do Anexo VII normalizada.
     * A data de implantação deve ser confirmada separadamente; null = somente acervo.
     */
    public function importar(string $csv, string $versao, string $publicadaEm, ?string $restritaDesde, string $sha256): stdClass
    {
        if (! preg_match('/^[\pL\pN ._-]{1,80}$/uD', $versao) || ! self::dataValida($publicadaEm)
            || $publicadaEm > now()->toDateString()
            || ($restritaDesde !== null && (! self::dataValida($restritaDesde) || $restritaDesde < $publicadaEm || $restritaDesde > now()->toDateString()))
            || ! preg_match('/^[a-f0-9]{64}$/D', $sha256) || ! hash_equals($sha256, hash('sha256', $csv))) {
            throw new InvalidArgumentException('Metadados, datas ou hash do arquivo inválidos.');
        }
        $linhas = $this->ler($csv);

        return DB::transaction(function () use ($versao, $publicadaEm, $restritaDesde, $sha256, $linhas) {
            $existente = DB::table('nfse_indop_versoes')->where('sha256', $sha256)->first();
            if ($existente) {
                if ($existente->versao !== $versao || $existente->publicada_em !== $publicadaEm || $existente->restrita_desde !== $restritaDesde) {
                    throw new InvalidArgumentException('Arquivo já registrado com outros metadados.');
                }
                return $existente;
            }
            if (DB::table('nfse_indop_versoes')->where('versao', $versao)->exists()) {
                throw new InvalidArgumentException('Versão já registrada. Não sobrescreva o histórico.');
            }
            $id = DB::table('nfse_indop_versoes')->insertGetId([
                'versao' => $versao, 'publicada_em' => $publicadaEm, 'restrita_desde' => $restritaDesde,
                'sha256' => $sha256, 'quantidade' => count($linhas), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($linhas, 200) as $lote) {
                DB::table('nfse_indop_codigos')->insert(array_map(fn ($l) => ['versao_id' => $id] + $l, $lote));
            }
            return DB::table('nfse_indop_versoes')->find($id);
        });
    }

    /** Seleção pela data de emissão/consulta, não pela competência do serviço. */
    public function atual(): ?stdClass
    {
        return DB::table('nfse_indop_versoes')->whereNotNull('restrita_desde')
            ->where('restrita_desde', '<=', now()->toDateString())
            ->orderByDesc('restrita_desde')->orderByDesc('publicada_em')->orderByDesc('id')->first();
    }

    public function pendente(): ?stdClass
    {
        return DB::table('nfse_indop_versoes')->whereNull('restrita_desde')
            ->orderByDesc('publicada_em')->orderByDesc('id')->first();
    }

    /** Liberação única após confirmar a implantação efetiva, nunca por previsão de calendário. */
    public function liberar(string $versao, string $data, string $sha256): stdClass
    {
        if (! self::dataValida($data) || $data > now()->toDateString() || ! preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new InvalidArgumentException('Informe data de implantação efetiva não futura e hash válido.');
        }
        return DB::transaction(function () use ($versao, $data, $sha256) {
            $v = DB::table('nfse_indop_versoes')->where('versao', $versao)->lockForUpdate()->first();
            if (! $v || ! hash_equals($v->sha256, $sha256) || $data < $v->publicada_em
                || ($v->restrita_desde !== null && $v->restrita_desde !== $data)) {
                throw new InvalidArgumentException('Versão, hash ou data incompatível com o histórico.');
            }
            if ($v->restrita_desde === null) {
                DB::table('nfse_indop_versoes')->where('id', $v->id)->update(['restrita_desde' => $data, 'updated_at' => now()]);
            }
            return DB::table('nfse_indop_versoes')->find($v->id);
        });
    }

    /** Datas por código são opcionais: só informar quando existirem na fonte. */
    public function consulta(int $versaoId, string $mes): Builder
    {
        if (! preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $mes)) {
            throw new InvalidArgumentException('Competência inválida.');
        }
        $inicio = CarbonImmutable::createFromFormat('!Y-m', $mes);
        return DB::table('nfse_indop_codigos')->where('versao_id', $versaoId)
            ->where(fn ($q) => $q->whereNull('inicio')->orWhere('inicio', '<=', $inicio->toDateString()))
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>=', $inicio->endOfMonth()->toDateString()));
    }

    public function buscar(int $versaoId, string $mes, string $busca): Builder
    {
        $q = $this->consulta($versaoId, $mes);
        $busca = trim(mb_substr($busca, 0, 100));
        if ($busca !== '') {
            $termo = '%'.$busca.'%';
            $q->where(fn ($q) => $q->where('codigo', 'like', $termo)->orWhere('descricao', 'like', $termo)->orWhere('fundamento', 'like', $termo));
        }
        return $q->orderBy('codigo');
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
                throw new InvalidArgumentException('Cabeçalho do CSV normalizado inválido.');
            }
            $linhas = [];
            $codigos = [];
            $n = 1;
            while (($l = fgetcsv($fp, 0, ';', '"', '')) !== false) {
                $n++;
                if ($l === [null]) {
                    continue;
                }
                if (count($l) !== count(self::CABECALHO) || count($linhas) >= 10000) {
                    throw new InvalidArgumentException('Quantidade de colunas ou linhas inválida na linha '.$n.'.');
                }
                [$codigo, $descricao, $fundamento, $ini, $fim] = array_map('trim', $l);
                if (! preg_match('/^\d{6}$/D', $codigo) || $descricao === '' || mb_strlen($descricao) > 5000
                    || mb_strlen($fundamento) > 5000 || ($ini !== '' && ! self::dataValida($ini))
                    || ($fim !== '' && (! self::dataValida($fim) || ($ini !== '' && $fim < $ini))) || isset($codigos[$codigo])) {
                    throw new InvalidArgumentException('Código duplicado ou dados inválidos na linha '.$n.'.');
                }
                $codigos[$codigo] = true;
                $linhas[] = ['codigo' => $codigo, 'descricao' => $descricao, 'fundamento' => $fundamento === '' ? null : $fundamento,
                    'inicio' => $ini === '' ? null : $ini, 'fim' => $fim === '' ? null : $fim];
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
}
