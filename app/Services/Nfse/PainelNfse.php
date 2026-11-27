<?php

namespace App\Services\Nfse;

use App\Models\EmpresasOperadora;
use App\Services\OperadoraContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PainelNfse
{
    public function consulta(string $mes): Builder
    {
        abort_unless(auth()->user()?->podeAcessarSistema(), 403);
        $tenant = OperadoraContext::requireId();
        abort_unless(EmpresasOperadora::query()->whereKey($tenant)->where('ativo', true)->exists(), 403);
        Validator::make(['mes' => $mes], ['mes' => ['required', 'date_format:Y-m', 'regex:/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D']])->validate();
        $inicio = CarbonImmutable::createFromFormat('!Y-m', $mes);
        $primeiro = $inicio->toDateString();
        $ultimo = $inicio->endOfMonth()->toDateString();

        $notas = DB::table('nfse_notas')->where('empresa_operadora_id', $tenant)
            ->whereBetween('competencia', [$primeiro, $ultimo])->groupBy('empresa_id')
            ->select('empresa_id')
            ->selectRaw("SUM(CASE WHEN status = 'rascunho' THEN 1 ELSE 0 END) AS rascunhos")
            ->selectRaw("SUM(CASE WHEN status = 'em_lote' THEN 1 ELSE 0 END) AS em_lote")
            ->selectRaw("SUM(CASE WHEN status = 'descartada' THEN 1 ELSE 0 END) AS descartadas")
            ->selectRaw("SUM(CASE WHEN status IN ('rascunho', 'em_lote') THEN valor ELSE 0 END) AS valor_preparado");

        $lotes = DB::table('nfse_lotes')->where('empresa_operadora_id', $tenant)
            ->where('competencia', $primeiro)->where('status', 'preparado')
            ->groupBy('empresa_id')->select('empresa_id')->selectRaw('COUNT(*) AS lotes');

        $importacoes = DB::table('nfse_importacoes')->where('empresa_operadora_id', $tenant)
            ->where('competencia', $primeiro)->groupBy('empresa_id')->select('empresa_id')
            ->selectRaw('COUNT(*) AS importacoes, SUM(quantidade_pendente) AS linhas_pendentes');

        // A validade cadastral por datas não prova elegibilidade, senha ou autorização de emissão.
        $certificados = DB::table('certificados_digitais')->where('empresa_operadora_id', $tenant)
            ->whereNotNull('empresa_id')->where('ativo', true)->whereRaw('LOWER(tipo) = ?', ['a1'])
            ->where('valido_de', '<=', now())->where('valido_ate', '>', now())
            ->groupBy('empresa_id')->select('empresa_id')->selectRaw('MAX(valido_ate) AS certificado_ate');

        // Escolhe a última condição contratual vigente sem multiplicar valores por reajustes.
        $precos = DB::table('nfse_recorrencia_reajustes as p')->where('p.empresa_operadora_id', $tenant)
            ->where('p.vigencia', '<=', $primeiro)
            ->whereNotExists(function (Builder $q) use ($primeiro, $tenant) {
                $q->selectRaw('1')->from('nfse_recorrencia_reajustes as posterior')
                    ->where('posterior.empresa_operadora_id', $tenant)
                    ->whereColumn('posterior.empresa_id', 'p.empresa_id')
                    ->whereColumn('posterior.recorrencia_id', 'p.recorrencia_id')
                    ->whereColumn('posterior.vigencia', '>', 'p.vigencia')
                    ->where('posterior.vigencia', '<=', $primeiro);
            })->select('p.empresa_id', 'p.recorrencia_id', 'p.valor');

        $recorrencias = DB::table('nfse_recorrencias as r')->where('r.empresa_operadora_id', $tenant)
            ->where('r.inicio', '<=', $primeiro)
            ->where(fn ($q) => $q->whereNull('r.fim')->orWhere('r.fim', '>=', $primeiro))
            ->leftJoinSub($precos, 'preco', fn (JoinClause $j) => $j->on('preco.recorrencia_id', '=', 'r.id')->on('preco.empresa_id', '=', 'r.empresa_id'))
            ->leftJoin('nfse_recorrencia_pausas as pausa', function (JoinClause $j) use ($primeiro, $tenant) {
                $j->on('pausa.recorrencia_id', '=', 'r.id')->on('pausa.empresa_id', '=', 'r.empresa_id')
                    ->where('pausa.empresa_operadora_id', $tenant)->where('pausa.competencia', $primeiro);
            })
            ->leftJoin('nfse_notas as gerada', function (JoinClause $j) use ($primeiro, $tenant) {
                $j->on('gerada.recorrencia_id', '=', 'r.id')->on('gerada.empresa_id', '=', 'r.empresa_id')
                    ->where('gerada.empresa_operadora_id', $tenant)->where('gerada.recorrencia_competencia', $primeiro);
            })
            ->groupBy('r.empresa_id')->select('r.empresa_id')
            ->selectRaw('SUM(CASE WHEN COALESCE(pausa.pausada, 0) = 0 THEN 1 ELSE 0 END) AS previstas')
            ->selectRaw('SUM(CASE WHEN COALESCE(pausa.pausada, 0) = 1 THEN 1 ELSE 0 END) AS pausadas')
            ->selectRaw('SUM(CASE WHEN COALESCE(pausa.pausada, 0) = 0 AND gerada.id IS NULL THEN 1 ELSE 0 END) AS recorrencias_pendentes')
            ->selectRaw("SUM(CASE WHEN gerada.status = 'descartada' THEN 1 ELSE 0 END) AS recorrencias_descartadas")
            ->selectRaw('SUM(CASE WHEN COALESCE(pausa.pausada, 0) = 0 THEN COALESCE(preco.valor, 0) ELSE 0 END) AS valor_previsto');

        $empresas = DB::table('empresas as e')->where('e.empresa_operadora_id', $tenant)->where('e.ativo', true)
            ->leftJoin('nfse_configuracoes as cfg', fn (JoinClause $j) => $j->on('cfg.empresa_id', '=', 'e.id')->where('cfg.empresa_operadora_id', $tenant))
            ->leftJoinSub($notas, 'n', 'n.empresa_id', '=', 'e.id')
            ->leftJoinSub($lotes, 'l', 'l.empresa_id', '=', 'e.id')
            ->leftJoinSub($importacoes, 'i', 'i.empresa_id', '=', 'e.id')
            ->leftJoinSub($certificados, 'c', 'c.empresa_id', '=', 'e.id')
            ->leftJoinSub($recorrencias, 'r', 'r.empresa_id', '=', 'e.id')
            ->select('e.id', 'e.nome', 'e.razao_social', 'e.nome_fantasia', 'e.cnpj', 'c.certificado_ate')
            ->selectRaw("COALESCE(NULLIF(e.razao_social, ''), e.nome) AS empresa")
            ->selectRaw('COALESCE(cfg.habilitado_preparo, 0) AS habilitada');

        foreach ([
            'n.rascunhos', 'n.em_lote', 'n.descartadas', 'n.valor_preparado',
            'l.lotes', 'i.importacoes', 'i.linhas_pendentes',
            'r.previstas', 'r.pausadas', 'r.recorrencias_pendentes', 'r.recorrencias_descartadas', 'r.valor_previsto',
        ] as $campo) {
            $alias = explode('.', $campo)[1];
            $empresas->selectRaw("COALESCE({$campo}, 0) AS {$alias}");
        }

        return DB::query()->fromSub($empresas, 'painel');
    }

    public function resumo(Builder $consulta): object
    {
        $q = DB::query()->fromSub(clone $consulta, 'consolidado')
            ->selectRaw('COUNT(*) AS empresas, COALESCE(SUM(habilitada), 0) AS habilitadas');
        foreach (['rascunhos', 'em_lote', 'descartadas', 'lotes', 'importacoes', 'linhas_pendentes', 'previstas', 'pausadas',
            'recorrencias_pendentes', 'recorrencias_descartadas', 'valor_previsto', 'valor_preparado'] as $campo) {
            $q->selectRaw("COALESCE(SUM({$campo}), 0) AS {$campo}");
        }
        return $q->first();
    }

    public function filtrar(Builder $consulta, string $busca, string $filtro): Builder
    {
        $busca = trim(mb_substr($busca, 0, 100));
        if ($busca !== '') {
            $termo = '%'.$busca.'%';
            $consulta->where(fn ($q) => $q->where('empresa', 'like', $termo)
                ->orWhere('nome', 'like', $termo)->orWhere('nome_fantasia', 'like', $termo)->orWhere('cnpj', 'like', $termo));
        }

        return match ($filtro) {
            'todas' => $consulta,
            'habilitadas' => $consulta->where('habilitada', 1),
            'rascunhos' => $consulta->where('rascunhos', '>', 0),
            'lotes' => $consulta->where('lotes', '>', 0),
            'previstas' => $consulta->where('previstas', '>', 0),
            'recorrencias' => $consulta->where(fn ($q) => $q->where('recorrencias_pendentes', '>', 0)->orWhere('recorrencias_descartadas', '>', 0)),
            'importacoes' => $consulta->where('linhas_pendentes', '>', 0),
            'certificado' => $consulta->where('habilitada', 1)->whereNull('certificado_ate'),
            default => $consulta->whereRaw('1 = 0'),
        };
    }
}
