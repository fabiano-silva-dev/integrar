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
     * Lista análises agrupadas por Empresa + Portal + Competência (+ tipo NFS-e).
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function listar(
        ?int $empresaId = null,
        ?int $portalId = null,
        ?string $competencia = null,
        ?string $tipoListagem = null,
        int $perPage = 20,
        string $pageName = 'listagemPage'
    ): LengthAwarePaginator {
        $competenciaExpr = $this->expressaoCompetencia();
        $tipoListagemExpr = $this->expressaoTipoListagem();
        $tipoListagem = self::normalizarTipoListagem($tipoListagem);

        $query = DocumentoFiscal::query()
            ->select([
                'documentos_fiscais.empresa_id',
                'documentos_fiscais.portal_integracao_id',
                DB::raw("{$competenciaExpr} as competencia"),
                DB::raw("{$tipoListagemExpr} as tipo_listagem"),
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
            ->when(
                $tipoListagem !== null,
                fn (Builder $q) => $q->whereRaw("{$tipoListagemExpr} = ?", [$tipoListagem])
            )
            ->groupBy([
                'documentos_fiscais.empresa_id',
                'documentos_fiscais.portal_integracao_id',
                DB::raw($competenciaExpr),
                DB::raw($tipoListagemExpr),
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
            $row->tipo_listagem = self::normalizarTipoListagem(
                $row->eh_nfse ? (string) ($row->tipo_listagem ?? '') : null
            );
            $row->tipo_listagem_label = self::formatarTipoListagem($row->tipo_listagem);

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
     *     tipo_listagem: string|null,
     *     tipo_listagem_label: string,
     *     resumo: array<string, mixed>,
     *     documentos_query: Builder
     * }
     */
    public function carregar(int $empresaId, int $portalId, string $competencia, ?string $tipoListagem = null): array
    {
        $empresa = Empresa::query()->findOrFail($empresaId);
        $portal = PortalIntegracao::query()->find($portalId);
        $competencia = trim($competencia);
        $tipoListagem = self::normalizarTipoListagem($tipoListagem);
        $ehNfse = $portal?->codigo === 'nfse_nacional';

        if ($ehNfse && $tipoListagem === null) {
            $tipoListagem = 'emitidas';
        }

        $docs = $this->queryDocumentos($empresaId, $portalId, $competencia, $tipoListagem)
            ->orderBy('data_emissao')
            ->orderBy('numero')
            ->get();

        if (! $ehNfse) {
            $ehNfse = $docs->contains(fn (DocumentoFiscal $d) => $d->tipo_documento === 'nfse');
            if ($ehNfse && $tipoListagem === null) {
                $tipoListagem = 'emitidas';
                $docs = $this->queryDocumentos($empresaId, $portalId, $competencia, $tipoListagem)
                    ->orderBy('data_emissao')
                    ->orderBy('numero')
                    ->get();
            }
        }

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
            'tipo_listagem' => $ehNfse ? $tipoListagem : null,
            'tipo_listagem_label' => self::formatarTipoListagem($ehNfse ? $tipoListagem : null),
            'resumo' => $resumo,
            'documentos_query' => $this->queryDocumentos($empresaId, $portalId, $competencia, $tipoListagem),
        ];
    }

    /**
     * Resolve a análise (empresa/portal/competência/tipo) a partir de uma coleta legada.
     *
     * @return array{empresa_id: int, portal_id: int, competencia: string, tipo_listagem: string|null}|null
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

        $tipoListagem = null;
        $recursoCodigo = $coleta->execucao?->portalRecurso?->codigo;
        if (in_array($recursoCodigo, ['nfse_emitidas', 'nfse_recebidas'], true)) {
            $tipoListagem = $recursoCodigo === 'nfse_recebidas' ? 'recebidas' : 'emitidas';
        } elseif (($coleta->origem ?? '') === 'nfse_nacional_extrato_txt') {
            $amostra = DocumentoFiscal::query()
                ->where('empresa_id', $coleta->empresa_id)
                ->where('portal_integracao_id', $portalId)
                ->where('automacao_execucao_id', $coleta->automacao_execucao_id)
                ->value('dados_complementares');
            if (is_string($amostra) && $amostra !== '') {
                $amostra = json_decode($amostra, true);
            }
            $tipoListagem = self::normalizarTipoListagem(
                is_array($amostra) ? (string) ($amostra['tipo_listagem'] ?? '') : null
            ) ?? 'emitidas';
        }

        return [
            'empresa_id' => (int) $coleta->empresa_id,
            'portal_id' => (int) $portalId,
            'competencia' => (string) $competencia,
            'tipo_listagem' => $tipoListagem,
        ];
    }

    public function queryDocumentos(
        int $empresaId,
        int $portalId,
        string $competencia,
        ?string $tipoListagem = null
    ): Builder {
        $competenciaExpr = $this->expressaoCompetencia();
        $tipoListagem = self::normalizarTipoListagem($tipoListagem);

        return DocumentoFiscal::query()
            ->where('empresa_id', $empresaId)
            ->where('portal_integracao_id', $portalId)
            ->whereRaw("{$competenciaExpr} = ?", [$competencia])
            ->when(
                $tipoListagem !== null,
                fn (Builder $q) => $q->whereRaw("{$this->expressaoTipoListagem()} = ?", [$tipoListagem])
            );
    }

    public static function formatarCompetencia(?string $competencia): string
    {
        if (! $competencia || ! preg_match('/^(\d{4})-(\d{2})$/', $competencia, $m)) {
            return $competencia ?: '—';
        }

        return $m[2].'/'.$m[1];
    }

    public static function normalizarTipoListagem(?string $tipo): ?string
    {
        $tipo = strtolower(trim((string) $tipo));

        return match ($tipo) {
            'emitidas', 'recebidas' => $tipo,
            default => null,
        };
    }

    public static function formatarTipoListagem(?string $tipo): string
    {
        return match (self::normalizarTipoListagem($tipo)) {
            'emitidas' => 'Emitidas',
            'recebidas' => 'Recebidas',
            default => '—',
        };
    }

    private function expressaoCompetencia(): string
    {
        return "COALESCE(NULLIF(documentos_fiscais.competencia, ''), DATE_FORMAT(documentos_fiscais.data_emissao, '%Y-%m'))";
    }

    /**
     * Tipo da listagem NFS-e (emitidas/recebidas); vazio para demais documentos.
     */
    private function expressaoTipoListagem(): string
    {
        return "CASE
            WHEN documentos_fiscais.tipo_documento = 'nfse' THEN COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(documentos_fiscais.dados_complementares, '$.tipo_listagem')), ''),
                'emitidas'
            )
            ELSE ''
        END";
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
