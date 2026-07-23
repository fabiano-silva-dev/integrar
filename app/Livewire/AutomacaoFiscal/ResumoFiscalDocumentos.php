<?php

namespace App\Livewire\AutomacaoFiscal;

use App\Models\DocumentoFiscal;
use App\Models\DocumentoFiscalColeta;
use App\Models\Empresa;
use App\Models\PortalIntegracao;
use App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser;
use App\Services\AutomacaoFiscal\ExtratoNfseParser;
use App\Services\OperadoraContext;
use Livewire\Component;
use Livewire\WithPagination;

class ResumoFiscalDocumentos extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public $filtro_empresa_id = null;
    public $filtro_portal_id = null;
    public string $filtro_periodo_inicio = '';
    public string $filtro_periodo_fim = '';
    public string $filtro_tipo_operacao = '';
    public ?int $coletaId = null;
    public array $resumo = [];
    public array $avisos = [];
    public array $resumoPeriodo = [];
    public string $aba = 'resumo';
    public ?string $coletaEmpresaNome = null;
    public ?string $coletaPortalNome = null;
    public ?string $coletaPeriodoLabel = null;

    /** Coleta do Portal Nacional da NFS-e (sem ICMS/modelo NF-e). */
    public bool $coletaEhNfse = false;

    public function mount(?int $coleta = null): void
    {
        if ($coleta) {
            $this->carregarColeta($coleta);
        }
    }

    public function updatedFiltroEmpresaId(): void
    {
        $this->resetPage('listagemPage');
        $this->resetPage('periodoPage');
        $this->recalcularResumoPeriodo();
    }

    public function updatedFiltroPortalId(): void
    {
        $this->resetPage('listagemPage');
    }

    public function updatedFiltroPeriodoInicio(): void
    {
        $this->resetPage('periodoPage');
        $this->recalcularResumoPeriodo();
    }

    public function updatedFiltroPeriodoFim(): void
    {
        $this->resetPage('periodoPage');
        $this->recalcularResumoPeriodo();
    }

    public function updatedFiltroTipoOperacao(): void
    {
        $this->resetPage('periodoPage');
    }

    public function carregarColeta(int $id): void
    {
        $coleta = DocumentoFiscalColeta::query()
            ->with([
                'empresa',
                'execucao.portalRecurso.portal',
                'execucao.empresaIntegracao.portal',
            ])
            ->findOrFail($id);

        $this->coletaId = $coleta->id;
        $this->coletaEmpresaNome = $coleta->empresa?->nome;
        $this->coletaPortalNome = $coleta->nomePortal();
        $this->coletaPeriodoLabel = $coleta->periodoLabel();
        $this->resumo = $coleta->resumo ?? [];
        $this->avisos = [];
        $this->aba = 'resumo';
        $this->coletaEhNfse = $this->detectarColetaNfse($coleta);

        if ($this->coletaEhNfse && $coleta->empresa) {
            // Usa o resumo da coleta (extrato completo). Só remonta se estiver vazio.
            if (empty($this->resumo['quantidade'])) {
                $docs = DocumentoFiscal::query()
                    ->where('empresa_id', $coleta->empresa_id)
                    ->where('tipo_documento', 'nfse')
                    ->when(
                        $coleta->periodo_inicio && $coleta->periodo_fim,
                        fn ($q) => $q->whereBetween('data_emissao', [
                            $coleta->periodo_inicio->format('Y-m-d'),
                            $coleta->periodo_fim->format('Y-m-d'),
                        ])
                    )
                    ->get()
                    ->map(fn (DocumentoFiscal $d) => $d->toArray())
                    ->all();

                if ($docs !== []) {
                    $this->resumo = app(ExtratoNfseParser::class)->montarResumo($docs);
                }
            }
        } elseif (empty($this->resumo['por_tipo_operacao']) && $coleta->empresa) {
            // Garante bloco de operações mesmo em coletas antigas de NF-e.
            $docs = DocumentoFiscal::query()
                ->where('empresa_id', $coleta->empresa_id)
                ->when(
                    $coleta->periodo_inicio && $coleta->periodo_fim,
                    fn ($q) => $q->whereBetween('data_emissao', [
                        $coleta->periodo_inicio->format('Y-m-d'),
                        $coleta->periodo_fim->format('Y-m-d'),
                    ])
                )
                ->get()
                ->map(fn (DocumentoFiscal $d) => $d->toArray())
                ->all();
            $extra = app(ExtratoNfeEcacRsParser::class)->montarResumo($docs, $coleta->empresa->cnpj);
            $this->resumo['por_tipo_operacao'] = $extra['por_tipo_operacao'] ?? [];
        }

        $this->resetPage();
    }

    private function detectarColetaNfse(DocumentoFiscalColeta $coleta): bool
    {
        if ($coleta->origem === 'nfse_nacional_extrato_txt') {
            return true;
        }

        $portalCodigo = $coleta->portalIntegracao()?->codigo;

        return $portalCodigo === 'nfse_nacional';
    }

    public function setAba(string $aba): void
    {
        $this->aba = $aba;
        $this->resetPage();
    }

    public function limparPeriodo(): void
    {
        $this->filtro_periodo_inicio = '';
        $this->filtro_periodo_fim = '';
        $this->filtro_tipo_operacao = '';
        $this->resumoPeriodo = [];
        $this->resetPage('periodoPage');
    }

    private function recalcularResumoPeriodo(): void
    {
        $this->resumoPeriodo = [];

        if (!$this->filtro_empresa_id || $this->filtro_periodo_inicio === '' || $this->filtro_periodo_fim === '') {
            return;
        }

        $empresa = Empresa::query()->find((int) $this->filtro_empresa_id);
        if (!$empresa) {
            return;
        }

        $docs = DocumentoFiscal::query()
            ->where('empresa_id', $empresa->id)
            ->whereBetween('data_emissao', [$this->filtro_periodo_inicio, $this->filtro_periodo_fim])
            ->orderBy('data_emissao')
            ->get()
            ->map(function (DocumentoFiscal $d) use ($empresa) {
                $arr = $d->toArray();
                $arr['tipo_operacao'] = data_get($d->dados_complementares, 'tipo_operacao')
                    ?: ExtratoNfeEcacRsParser::classificarTipoOperacao($empresa->cnpj, $arr);

                return $arr;
            })
            ->all();

        $this->resumoPeriodo = app(ExtratoNfeEcacRsParser::class)->montarResumo($docs, $empresa->cnpj);
        $this->resumoPeriodo['empresa_nome'] = $empresa->nome;
    }

    public function render()
    {
        $empresas = Empresa::query()->where('ativo', true)->orderBy('nome')->get();
        $portais = PortalIntegracao::query()->where('ativo', true)->orderBy('nome')->get();
        $coletas = null;
        $documentos = null;
        $documentosPeriodo = null;
        $precisaSelecionarEscritorio = OperadoraContext::superAdminPrecisaSelecionarEscritorio();
        $modoPeriodo = !$this->coletaId
            && $this->filtro_empresa_id
            && $this->filtro_periodo_inicio !== ''
            && $this->filtro_periodo_fim !== '';

        if (!$precisaSelecionarEscritorio && !$this->coletaId && !$modoPeriodo) {
            $coletas = DocumentoFiscalColeta::query()
                ->with([
                    'empresa',
                    'execucao.portalRecurso.portal',
                    'execucao.empresaIntegracao.portal',
                ])
                ->when(
                    $this->filtro_empresa_id,
                    fn ($q) => $q->where('empresa_id', (int) $this->filtro_empresa_id)
                )
                ->when(
                    $this->filtro_portal_id,
                    function ($q) {
                        $portalId = (int) $this->filtro_portal_id;
                        $portal = PortalIntegracao::query()->find($portalId);
                        $q->where(function ($inner) use ($portalId, $portal) {
                            $inner->whereHas(
                                'execucao.portalRecurso',
                                fn ($qr) => $qr->where('portal_integracao_id', $portalId)
                            )->orWhereHas(
                                'execucao.empresaIntegracao',
                                fn ($qi) => $qi->where('portal_integracao_id', $portalId)
                            );

                            if ($portal?->codigo === 'ecac_rs') {
                                $inner->orWhere(function ($origem) {
                                    $origem->whereNull('automacao_execucao_id')
                                        ->where('origem', 'ecac_rs_extrato_txt');
                                });
                            }

                            if ($portal?->codigo === 'nfse_nacional') {
                                $inner->orWhere(function ($origem) {
                                    $origem->whereNull('automacao_execucao_id')
                                        ->where('origem', 'nfse_nacional_extrato_txt');
                                });
                            }
                        });
                    }
                )
                ->orderByDesc('id')
                ->paginate(20, pageName: 'listagemPage');
        }

        if (!$precisaSelecionarEscritorio && $modoPeriodo) {
            if ($this->resumoPeriodo === []) {
                $this->recalcularResumoPeriodo();
            }

            $empresa = Empresa::query()->find((int) $this->filtro_empresa_id);
            $idsFiltrados = null;
            if ($this->filtro_tipo_operacao !== '' && $empresa) {
                $tipo = $this->filtro_tipo_operacao;
                $idsFiltrados = DocumentoFiscal::query()
                    ->where('empresa_id', $empresa->id)
                    ->whereBetween('data_emissao', [$this->filtro_periodo_inicio, $this->filtro_periodo_fim])
                    ->get()
                    ->filter(function (DocumentoFiscal $d) use ($empresa, $tipo) {
                        $arr = $d->toArray();
                        $classificado = data_get($d->dados_complementares, 'tipo_operacao')
                            ?: ExtratoNfeEcacRsParser::classificarTipoOperacao($empresa->cnpj, $arr);

                        return $classificado === $tipo;
                    })
                    ->pluck('id')
                    ->all();
            }

            $documentosPeriodo = DocumentoFiscal::query()
                ->where('empresa_id', (int) $this->filtro_empresa_id)
                ->whereBetween('data_emissao', [$this->filtro_periodo_inicio, $this->filtro_periodo_fim])
                ->when(is_array($idsFiltrados), fn ($q) => $q->whereIn('id', $idsFiltrados ?: [0]))
                ->orderByDesc('data_emissao')
                ->orderByDesc('numero')
                ->paginate(25, pageName: 'periodoPage');
        }

        if (!$precisaSelecionarEscritorio && $this->coletaId && $this->aba === 'documentos') {
            $coleta = DocumentoFiscalColeta::find($this->coletaId);
            if ($coleta) {
                $documentos = DocumentoFiscal::query()
                    ->where('empresa_id', $coleta->empresa_id)
                    ->when(
                        $this->coletaEhNfse,
                        fn ($q) => $q->where('tipo_documento', 'nfse')
                    )
                    ->when(
                        ! $this->coletaEhNfse && $coleta->automacao_execucao_id,
                        fn ($q) => $q->where('automacao_execucao_id', $coleta->automacao_execucao_id)
                    )
                    ->when(
                        !empty($this->resumo['periodo_inicio']) && !empty($this->resumo['periodo_fim']),
                        fn ($q) => $q->whereBetween('data_emissao', [
                            $this->resumo['periodo_inicio'],
                            $this->resumo['periodo_fim'],
                        ])
                    )
                    ->when(
                        $this->coletaEhNfse && empty($this->resumo['periodo_inicio'])
                            && $coleta->periodo_inicio && $coleta->periodo_fim,
                        fn ($q) => $q->whereBetween('data_emissao', [
                            $coleta->periodo_inicio->format('Y-m-d'),
                            $coleta->periodo_fim->format('Y-m-d'),
                        ])
                    )
                    ->orderByDesc('data_emissao')
                    ->orderByDesc('numero')
                    ->paginate(25);
            }
        }

        return view('livewire.automacao-fiscal.resumo-fiscal-documentos', [
            'empresas' => $empresas,
            'portais' => $portais,
            'coletas' => $coletas,
            'documentos' => $documentos,
            'documentosPeriodo' => $documentosPeriodo,
            'modoPeriodo' => $modoPeriodo,
            'tiposOperacao' => ExtratoNfeEcacRsParser::TIPOS_OPERACAO,
            'colunasValor' => $this->coletaEhNfse
                ? ['valor_total' => 'Total dos serviços']
                : [
                    'valor_total' => 'Total NF-e',
                    'valor_bc_icms' => 'Base ICMS',
                    'valor_icms' => 'ICMS',
                    'valor_bc_icms_st' => 'Base ICMS ST',
                    'valor_icms_st' => 'ICMS ST',
                ],
            'precisaSelecionarEscritorio' => $precisaSelecionarEscritorio,
            'labelsColunasArquivo' => $this->coletaEhNfse
                ? ExtratoNfseParser::COLUNAS
                : ExtratoNfeEcacRsParser::COLUNAS,
        ]);
    }
}
