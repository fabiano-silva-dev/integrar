<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\DocumentoFiscal;
use App\Models\DocumentoFiscalColeta;
use App\Models\Empresa;
use App\Models\PortalIntegracao;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AnaliseFiscalService
{
    /**
     * Normaliza a chave de acesso do documento (só dígitos).
     * Comprimento varia conforme o tipo (NF-e/NFC-e ~44, NFS-e pode ser maior).
     */
    public static function normalizarChaveAcesso(?string $chave): ?string
    {
        if ($chave === null || $chave === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $chave) ?? '';

        return $digits !== '' ? $digits : null;
    }

    /**
     * Lista análises agrupadas por Empresa + Portal + Competência.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function listar(
        ?int $empresaId = null,
        ?int $portalId = null,
        ?string $competencia = null,
        int $perPage = 20,
        string $pageName = 'listagemPage'
    ): LengthAwarePaginator {
        $competenciaExpr = $this->expressaoCompetencia();

        $query = DocumentoFiscal::query()
            ->select([
                'documentos_fiscais.empresa_id',
                'documentos_fiscais.portal_integracao_id',
                DB::raw("{$competenciaExpr} as competencia"),
                DB::raw('COUNT(*) as quantidade_documentos'),
                DB::raw('COALESCE(SUM(documentos_fiscais.valor_total), 0) as valor_total'),
                DB::raw('MAX(documentos_fiscais.updated_at) as atualizado_em'),
                DB::raw('MAX(documentos_fiscais.tipo_documento) as tipo_documento_amostra'),
            ])
            ->whereNotNull('documentos_fiscais.portal_integracao_id')
            ->whereRaw("{$competenciaExpr} IS NOT NULL")
            ->when($empresaId, fn (Builder $q) => $q->where('documentos_fiscais.empresa_id', $empresaId))
            ->when($portalId, fn (Builder $q) => $q->where('documentos_fiscais.portal_integracao_id', $portalId))
            ->when(
                $competencia !== null && $competencia !== '',
                fn (Builder $q) => $q->whereRaw("{$competenciaExpr} = ?", [$competencia])
            )
            ->groupBy([
                'documentos_fiscais.empresa_id',
                'documentos_fiscais.portal_integracao_id',
                DB::raw($competenciaExpr),
            ])
            ->orderByDesc(DB::raw('MAX(documentos_fiscais.updated_at)'));

        /** @var LengthAwarePaginator<int, object> $paginator */
        $paginator = $query->paginate($perPage, ['*'], $pageName);

        $empresaIds = collect($paginator->items())->pluck('empresa_id')->unique()->filter()->all();
        $portalIds = collect($paginator->items())->pluck('portal_integracao_id')->unique()->filter()->all();

        $empresas = Empresa::query()->whereIn('id', $empresaIds)->get()->keyBy('id');
        $portais = PortalIntegracao::query()->whereIn('id', $portalIds)->get()->keyBy('id');

        $paginator->getCollection()->transform(function ($row) use ($empresas, $portais) {
            $empresa = $empresas->get((int) $row->empresa_id);
            $portal = $row->portal_integracao_id
                ? $portais->get((int) $row->portal_integracao_id)
                : null;

            $row->empresa_nome = $empresa?->nome ?? '—';
            $row->portal_nome = $portal?->nome ?? '—';
            $row->competencia_label = self::formatarCompetencia((string) $row->competencia);
            $row->eh_nfse = str_starts_with((string) ($row->tipo_documento_amostra ?? ''), 'nfse');

            return $row;
        });

        return $paginator;
    }

    /**
     * @return array{
     *     empresa: Empresa,
     *     portal: PortalIntegracao|null,
     *     competencia: string,
     *     competencia_label: string,
     *     eh_nfse: bool,
     *     resumo: array<string, mixed>,
     *     documentos_query: Builder
     * }
     */
    public function carregar(int $empresaId, int $portalId, string $competencia): array
    {
        $empresa = Empresa::query()->findOrFail($empresaId);
        $portal = PortalIntegracao::query()->find($portalId);
        $competencia = trim($competencia);

        $docs = $this->queryDocumentos($empresaId, $portalId, $competencia)
            ->orderBy('data_emissao')
            ->orderBy('numero')
            ->get();

        $ehNfse = $docs->contains(fn (DocumentoFiscal $d) => $d->tipo_documento === 'nfse')
            || ($portal?->codigo === 'nfse_nacional');

        $arrays = $docs->map(function (DocumentoFiscal $d) use ($empresa, $ehNfse) {
            $arr = $d->toArray();
            if (! $ehNfse) {
                $arr['tipo_operacao'] = data_get($d->dados_complementares, 'tipo_operacao')
                    ?: ExtratoNfeEcacRsParser::classificarTipoOperacao($empresa->cnpj, $arr);
            }

            return $arr;
        })->all();

        $resumo = $ehNfse
            ? app(ExtratoNfseParser::class)->montarResumo($arrays)
            : app(ExtratoNfeEcacRsParser::class)->montarResumo($arrays, $empresa->cnpj);

        return [
            'empresa' => $empresa,
            'portal' => $portal,
            'competencia' => $competencia,
            'competencia_label' => self::formatarCompetencia($competencia),
            'eh_nfse' => $ehNfse,
            'resumo' => $resumo,
            'documentos_query' => $this->queryDocumentos($empresaId, $portalId, $competencia),
        ];
    }

    /**
     * Resolve a análise (empresa/portal/competência) a partir de uma coleta legada.
     *
     * @return array{empresa_id: int, portal_id: int, competencia: string}|null
     */
    public function resolverDeColeta(int $coletaId): ?array
    {
        $coleta = DocumentoFiscalColeta::query()
            ->with(['execucao.portalRecurso.portal', 'execucao.empresaIntegracao.portal'])
            ->find($coletaId);

        if (! $coleta) {
            return null;
        }

        $portalId = $coleta->portalIntegracao()?->id
            ?? $this->portalIdPorOrigem($coleta->origem);

        if (! $portalId) {
            return null;
        }

        $competenciaExpr = $this->expressaoCompetencia();

        $competencia = DocumentoFiscal::query()
            ->where('empresa_id', $coleta->empresa_id)
            ->where('portal_integracao_id', $portalId)
            ->when(
                $coleta->periodo_inicio && $coleta->periodo_fim,
                fn (Builder $q) => $q->whereBetween('data_emissao', [
                    $coleta->periodo_inicio->format('Y-m-d'),
                    $coleta->periodo_fim->format('Y-m-d'),
                ])
            )
            ->selectRaw("{$competenciaExpr} as competencia")
            ->selectRaw('COUNT(*) as qtd')
            ->groupBy(DB::raw($competenciaExpr))
            ->orderByDesc('qtd')
            ->value('competencia');

        if (! $competencia && $coleta->periodo_inicio) {
            $competencia = $coleta->periodo_inicio->format('Y-m');
        }

        if (! $competencia) {
            return null;
        }

        return [
            'empresa_id' => (int) $coleta->empresa_id,
            'portal_id' => (int) $portalId,
            'competencia' => (string) $competencia,
        ];
    }

    public function queryDocumentos(int $empresaId, int $portalId, string $competencia): Builder
    {
        $competenciaExpr = $this->expressaoCompetencia();

        return DocumentoFiscal::query()
            ->where('empresa_id', $empresaId)
            ->where('portal_integracao_id', $portalId)
            ->whereRaw("{$competenciaExpr} = ?", [$competencia]);
    }

    public static function formatarCompetencia(?string $competencia): string
    {
        if (! $competencia || ! preg_match('/^(\d{4})-(\d{2})$/', $competencia, $m)) {
            return $competencia ?: '—';
        }

        return $m[2].'/'.$m[1];
    }

    private function expressaoCompetencia(): string
    {
        return "COALESCE(NULLIF(documentos_fiscais.competencia, ''), DATE_FORMAT(documentos_fiscais.data_emissao, '%Y-%m'))";
    }

    private function portalIdPorOrigem(?string $origem): ?int
    {
        $codigo = match ($origem) {
            'ecac_rs_extrato_txt' => 'ecac_rs',
            'nfse_nacional_extrato_txt' => 'nfse_nacional',
            default => null,
        };

        if (! $codigo) {
            return null;
        }

        return PortalIntegracao::query()->where('codigo', $codigo)->value('id');
    }
}
