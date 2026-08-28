<?php

namespace App\Livewire\AutomacaoFiscal;

use App\Jobs\AutomacaoFiscal\BaixarDocumentoFiscalXmlJob;
use App\Jobs\AutomacaoFiscal\BaixarNfseXmlJob;
use App\Models\DocumentoFiscal;
use App\Models\Empresa;
use App\Models\PortalIntegracao;
use App\Services\AutomacaoFiscal\AnaliseFiscalService;
use App\Services\AutomacaoFiscal\ExecucaoProgressoPresenter;
use App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser;
use App\Services\AutomacaoFiscal\ExtratoNfseParser;
use App\Services\AutomacaoFiscal\FilaAutomacoesStatus;
use App\Services\AutomacaoFiscal\NfeXmlDownloadProgresso;
use App\Services\AutomacaoFiscal\NfeXmlDownloadService;
use App\Services\AutomacaoFiscal\NfseXmlDownloadService;
use App\Services\OperadoraContext;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class ResumoFiscalDocumentos extends Component
{
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public $filtro_empresa_id = null;
    public $filtro_portal_id = null;
    public string $filtro_competencia = '';

    public ?int $analiseEmpresaId = null;
    public ?int $analisePortalId = null;
    public ?string $analiseCompetencia = null;
    public ?string $analiseTipoListagem = null;

    public array $resumo = [];
    public string $aba = 'documentos';
    public ?string $analiseEmpresaNome = null;
    public ?string $analisePortalNome = null;
    public ?string $analiseCompetenciaLabel = null;
    public ?string $analiseTipoListagemLabel = null;
    public bool $analiseEhNfse = false;

    public bool $xmlModalAberto = false;
    public ?string $xmlToken = null;
    public string $xmlStatus = 'idle';
    /** @var list<array{at: string, level: string, eventType: string, message: string}> */
    public array $xmlLogs = [];
    public ?string $xmlErro = null;
    public ?string $xmlNomeArquivo = null;
    public ?string $xmlFonte = null;
    public ?string $xmlChave = null;
    public ?int $xmlDocumentoId = null;
    public ?int $xmlDuracaoMs = null;
    public ?string $xmlFinishedAt = null;
    public string $xmlContextoLabel = 'NF-e · DistDFe / WS Contabilista';
    /** @var array<string, mixed> */
    public array $xmlParametros = [];

    public function mount(?int $empresa = null, ?int $portal = null, ?string $competencia = null): void
    {
        if ($empresa && $portal && $competencia) {
            $listagem = AnaliseFiscalService::normalizarTipoListagem(
                request()->query('listagem')
            );
            $this->carregarAnalise($empresa, $portal, $competencia, $listagem);
        }
    }

    public function updatedFiltroEmpresaId(): void
    {
        $this->resetPage('listagemPage');
    }

    public function updatedFiltroPortalId(): void
    {
        $this->resetPage('listagemPage');
    }

    public function updatedFiltroCompetencia(): void
    {
        $this->resetPage('listagemPage');
    }

    public function carregarAnalise(
        int $empresaId,
        int $portalId,
        string $competencia,
        ?string $tipoListagem = null
    ): void {
        $dados = app(AnaliseFiscalService::class)->carregar(
            $empresaId,
            $portalId,
            $competencia,
            $tipoListagem
        );

        $this->analiseEmpresaId = $empresaId;
        $this->analisePortalId = $portalId;
        $this->analiseCompetencia = $competencia;
        $this->analiseTipoListagem = $dados['tipo_listagem'];
        $this->analiseEmpresaNome = $dados['empresa']->nome;
        $this->analisePortalNome = $dados['portal']?->nome ?? '—';
        $this->analiseCompetenciaLabel = $dados['competencia_label'];
        $this->analiseTipoListagemLabel = $dados['tipo_listagem_label'];
        $this->analiseEhNfse = $dados['eh_nfse'];
        $this->resumo = $dados['resumo'];
        $this->aba = 'documentos';
        $this->resetPage();
    }

    public function setAba(string $aba): void
    {
        $permitidas = ['documentos', 'grupos', 'resumo'];
        if ($this->podeVerAbaColunas()) {
            $permitidas[] = 'colunas';
        }

        if (! in_array($aba, $permitidas, true)) {
            return;
        }

        $this->aba = $aba;
        $this->resetPage();
    }

    public function podeVerAbaColunas(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isSuperAdmin();
    }

    public function limparFiltros(): void
    {
        $this->filtro_empresa_id = null;
        $this->filtro_portal_id = null;
        $this->filtro_competencia = '';
        $this->resetPage('listagemPage');
    }

    public function baixarXml(int $documentoId): void
    {
        $mensagemFila = app(FilaAutomacoesStatus::class)->mensagemBloqueioDesenvolvimento();
        if ($mensagemFila !== null) {
            session()->flash('error', $mensagemFila);

            return;
        }

        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior.');

            return;
        }

        $documento = DocumentoFiscal::query()->whereKey($documentoId)->firstOrFail();

        if ((string) $documento->tipo_documento === 'nfse') {
            $this->iniciarDownloadNfse($documento);

            return;
        }

        $service = app(NfeXmlDownloadService::class);

        if (! $service->ehModelo55($documento) || ! $service->chaveNfeValida($documento)) {
            session()->flash('error', 'Download de XML disponível apenas para NF-e modelo 55 com chave válida.');

            return;
        }

        $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso);
        $token = (string) Str::uuid();
        $operadoraId = OperadoraContext::id() ?? (int) $documento->empresa_operadora_id;

        NfeXmlDownloadProgresso::iniciar(
            $token,
            $documento->id,
            $operadoraId,
            $chave,
            'xml_nfe_documento',
            [
                'documento_id' => $documento->id,
                'chave_acesso' => $chave,
            ]
        );
        NfeXmlDownloadProgresso::adicionarLog(
            $token,
            'info',
            'JOB_QUEUED',
            'Na fila — aguardando worker de automações…'
        );

        $this->xmlModalAberto = true;
        $this->xmlToken = $token;
        $this->xmlChave = $chave;
        $this->xmlDocumentoId = $documento->id;
        $this->xmlContextoLabel = 'NF-e · DistDFe / WS Contabilista';
        $this->aplicarProgressoXml(NfeXmlDownloadProgresso::obter($token) ?? []);
        $this->xmlStatus = 'running';

        BaixarDocumentoFiscalXmlJob::dispatch($token, $documento->id, $operadoraId);
    }

    public function baixarXmlsDoPeriodo(): mixed
    {
        $mensagemFila = app(FilaAutomacoesStatus::class)->mensagemBloqueioDesenvolvimento();
        if ($mensagemFila !== null) {
            session()->flash('error', $mensagemFila);

            return null;
        }

        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior.');

            return null;
        }

        if (! $this->analiseEhNfse || ! $this->analiseEmpresaId || ! $this->analisePortalId || ! $this->analiseCompetencia) {
            session()->flash('error', 'Baixar XMLs do período está disponível na análise de NFS-e.');

            return null;
        }

        $docs = app(AnaliseFiscalService::class)
            ->queryDocumentos(
                (int) $this->analiseEmpresaId,
                (int) $this->analisePortalId,
                (string) $this->analiseCompetencia,
                $this->analiseTipoListagem
            )
            ->where('tipo_documento', 'nfse')
            ->get();

        $pendentes = $docs->filter(fn (DocumentoFiscal $doc) => ! $doc->temXmlPersistido());
        $enfileirados = app(NfseXmlDownloadService::class)->enfileirarPendentes(
            $pendentes->pluck('id')->all(),
            OperadoraContext::id()
        );

        $jaBaixados = $docs->filter(fn (DocumentoFiscal $doc) => $doc->temXmlPersistido())->count();
        if ($jaBaixados > 0) {
            if ($enfileirados > 0) {
                session()->flash(
                    'message',
                    "{$enfileirados} XML(s) enfileirado(s). O ZIP inclui os {$jaBaixados} já baixado(s)."
                );
            }

            return redirect()->route('automacao-fiscal.nfse.periodo.zip', array_filter([
                'empresa' => $this->analiseEmpresaId,
                'portal' => $this->analisePortalId,
                'competencia' => $this->analiseCompetencia,
                'listagem' => $this->analiseTipoListagem,
            ]));
        }

        session()->flash(
            'message',
            $enfileirados > 0
                ? "{$enfileirados} download(s) enfileirado(s). Atualize a lista em instantes."
                : 'Nenhuma NFS-e elegível para download (verifique o certificado A1 da empresa).'
        );

        return null;
    }

    private function iniciarDownloadNfse(DocumentoFiscal $documento): void
    {
        $service = app(NfseXmlDownloadService::class);
        if (! $service->chaveNfseValida($documento)) {
            session()->flash('error', 'Download de XML disponível apenas para NFS-e com chave de 50 dígitos.');

            return;
        }

        $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso);
        $token = (string) Str::uuid();
        $operadoraId = OperadoraContext::id() ?? (int) $documento->empresa_operadora_id;

        NfeXmlDownloadProgresso::iniciar(
            $token,
            $documento->id,
            $operadoraId,
            $chave,
            'xml_nfse_documento',
            [
                'documento_id' => $documento->id,
                'chave_acesso' => $chave,
            ]
        );
        NfeXmlDownloadProgresso::adicionarLog(
            $token,
            'info',
            'JOB_QUEUED',
            'Na fila — aguardando worker de automações…'
        );

        $this->xmlModalAberto = true;
        $this->xmlToken = $token;
        $this->xmlChave = $chave;
        $this->xmlDocumentoId = $documento->id;
        $this->xmlContextoLabel = 'NFS-e · Sefin Nacional';
        $this->aplicarProgressoXml(NfeXmlDownloadProgresso::obter($token) ?? []);
        $this->xmlStatus = 'running';

        BaixarNfseXmlJob::dispatch($documento->id, $operadoraId, $token, false);
    }

    public function atualizarProgressoXml(): void
    {
        if (! $this->xmlToken || ! $this->xmlModalAberto) {
            return;
        }

        $data = NfeXmlDownloadProgresso::obter($this->xmlToken);
        if ($data === null) {
            return;
        }

        $this->aplicarProgressoXml($data);
    }

    public function fecharModalXml(): void
    {
        $this->xmlModalAberto = false;
        if ($this->xmlStatus !== 'running') {
            $this->xmlToken = null;
            $this->xmlStatus = 'idle';
            $this->xmlLogs = [];
            $this->xmlErro = null;
            $this->xmlNomeArquivo = null;
            $this->xmlFonte = null;
            $this->xmlChave = null;
            $this->xmlDocumentoId = null;
            $this->xmlDuracaoMs = null;
            $this->xmlFinishedAt = null;
            $this->xmlParametros = [];
            $this->xmlContextoLabel = 'NF-e · DistDFe / WS Contabilista';
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function aplicarProgressoXml(array $data): void
    {
        $this->xmlStatus = (string) ($data['status'] ?? 'running');
        $this->xmlLogs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
        $this->xmlErro = isset($data['error']) ? (string) $data['error'] : null;
        $this->xmlNomeArquivo = isset($data['nome_arquivo']) ? (string) $data['nome_arquivo'] : null;
        $this->xmlFonte = isset($data['fonte']) ? (string) $data['fonte'] : null;
        $this->xmlChave = isset($data['chave']) ? (string) $data['chave'] : $this->xmlChave;
        $this->xmlDuracaoMs = isset($data['duracao_ms']) ? (int) $data['duracao_ms'] : null;
        $this->xmlParametros = is_array($data['parametros'] ?? null) ? $data['parametros'] : [];
        $this->xmlFinishedAt = null;
        if (! empty($data['finished_at'])) {
            try {
                $this->xmlFinishedAt = Carbon::parse((string) $data['finished_at'])->format('d/m/Y H:i:s');
            } catch (\Throwable) {
                $this->xmlFinishedAt = (string) $data['finished_at'];
            }
        }
    }

    public function render()
    {
        if ($this->aba === 'colunas' && ! $this->podeVerAbaColunas()) {
            $this->aba = 'documentos';
        }

        $empresas = Empresa::query()->where('ativo', true)->orderBy('nome')->get();
        $portais = PortalIntegracao::query()->where('ativo', true)->orderBy('nome')->get();
        $analises = null;
        $documentos = null;
        $precisaSelecionarEscritorio = OperadoraContext::superAdminPrecisaSelecionarEscritorio();
        $emDetalhe = $this->analiseEmpresaId
            && $this->analisePortalId
            && $this->analiseCompetencia;

        if (! $precisaSelecionarEscritorio && ! $emDetalhe) {
            $analises = app(AnaliseFiscalService::class)->listar(
                $this->filtro_empresa_id ? (int) $this->filtro_empresa_id : null,
                $this->filtro_portal_id ? (int) $this->filtro_portal_id : null,
                $this->filtro_competencia !== '' ? $this->filtro_competencia : null,
            );
        }

        if (! $precisaSelecionarEscritorio && $emDetalhe && $this->aba === 'documentos') {
            $documentos = app(AnaliseFiscalService::class)
                ->queryDocumentos(
                    (int) $this->analiseEmpresaId,
                    (int) $this->analisePortalId,
                    (string) $this->analiseCompetencia,
                    $this->analiseTipoListagem
                )
                ->orderByDesc('data_emissao')
                ->orderByDesc('numero')
                ->paginate(25);
        }

        $progresso = app(ExecucaoProgressoPresenter::class);
        $xmlPipeline = $this->xmlToken
            ? $progresso->montarPipelineDeEventos($this->xmlStatus, $this->xmlLogs)
            : [];
        $xmlEtapaAtual = null;
        if ($this->xmlLogs !== []) {
            $xmlEtapaAtual = (string) ($this->xmlLogs[array_key_last($this->xmlLogs)]['eventType'] ?? '');
        }

        return view('livewire.automacao-fiscal.resumo-fiscal-documentos', [
            'avisoFila' => app(FilaAutomacoesStatus::class)->avisoDesenvolvimento(),
            'empresas' => $empresas,
            'portais' => $portais,
            'analises' => $analises,
            'documentos' => $documentos,
            'emDetalhe' => $emDetalhe,
            'precisaSelecionarEscritorio' => $precisaSelecionarEscritorio,
            'labelsColunasArquivo' => $this->analiseEhNfse
                ? ExtratoNfseParser::COLUNAS
                : ExtratoNfeEcacRsParser::COLUNAS,
            'xmlProgresso' => $progresso,
            'xmlPipeline' => $xmlPipeline,
            'xmlEtapaAtual' => $xmlEtapaAtual ?: ($this->xmlStatus === 'running' ? 'executando' : null),
        ]);
    }
}
