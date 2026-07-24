<?php

namespace App\Livewire\AutomacaoFiscal;

use App\Jobs\AutomacaoFiscal\BaixarDocumentoFiscalXmlJob;
use App\Models\DocumentoFiscal;
use App\Models\Empresa;
use App\Models\PortalIntegracao;
use App\Services\AutomacaoFiscal\AnaliseFiscalService;
use App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser;
use App\Services\AutomacaoFiscal\ExtratoNfseParser;
use App\Services\AutomacaoFiscal\NfeXmlDownloadProgresso;
use App\Services\AutomacaoFiscal\NfeXmlDownloadService;
use App\Services\OperadoraContext;
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

    public array $resumo = [];
    public string $aba = 'resumo';
    public ?string $analiseEmpresaNome = null;
    public ?string $analisePortalNome = null;
    public ?string $analiseCompetenciaLabel = null;
    public bool $analiseEhNfse = false;

    public bool $xmlModalAberto = false;
    public ?string $xmlToken = null;
    public string $xmlStatus = 'idle';
    /** @var list<array{at: string, level: string, eventType: string, message: string}> */
    public array $xmlLogs = [];
    public ?string $xmlErro = null;
    public ?string $xmlNomeArquivo = null;
    public ?string $xmlChave = null;
    public ?int $xmlDocumentoId = null;

    public function mount(?int $empresa = null, ?int $portal = null, ?string $competencia = null): void
    {
        if ($empresa && $portal && $competencia) {
            $this->carregarAnalise($empresa, $portal, $competencia);
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

    public function carregarAnalise(int $empresaId, int $portalId, string $competencia): void
    {
        $dados = app(AnaliseFiscalService::class)->carregar($empresaId, $portalId, $competencia);

        $this->analiseEmpresaId = $empresaId;
        $this->analisePortalId = $portalId;
        $this->analiseCompetencia = $competencia;
        $this->analiseEmpresaNome = $dados['empresa']->nome;
        $this->analisePortalNome = $dados['portal']?->nome ?? '—';
        $this->analiseCompetenciaLabel = $dados['competencia_label'];
        $this->analiseEhNfse = $dados['eh_nfse'];
        $this->resumo = $dados['resumo'];
        $this->aba = 'resumo';
        $this->resetPage();
    }

    public function setAba(string $aba): void
    {
        $this->aba = $aba;
        $this->resetPage();
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
        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior.');

            return;
        }

        $documento = DocumentoFiscal::query()->whereKey($documentoId)->firstOrFail();
        $service = app(NfeXmlDownloadService::class);

        if (! $service->ehModelo55($documento) || ! $service->chaveNfeValida($documento)) {
            session()->flash('error', 'Download de XML disponível apenas para NF-e modelo 55 com chave válida.');

            return;
        }

        $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso);
        $token = (string) Str::uuid();
        $operadoraId = OperadoraContext::id() ?? (int) $documento->empresa_operadora_id;

        NfeXmlDownloadProgresso::iniciar($token, $documento->id, $operadoraId, $chave);
        NfeXmlDownloadProgresso::adicionarLog(
            $token,
            'info',
            'JOB_QUEUED',
            'Na fila — aguardando worker de automações…'
        );

        $this->xmlModalAberto = true;
        $this->xmlToken = $token;
        $this->xmlStatus = 'running';
        $this->xmlLogs = NfeXmlDownloadProgresso::obter($token)['logs'] ?? [];
        $this->xmlErro = null;
        $this->xmlNomeArquivo = null;
        $this->xmlChave = $chave;
        $this->xmlDocumentoId = $documento->id;

        BaixarDocumentoFiscalXmlJob::dispatch($token, $documento->id, $operadoraId);
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

        $this->xmlStatus = (string) ($data['status'] ?? 'running');
        $this->xmlLogs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
        $this->xmlErro = isset($data['error']) ? (string) $data['error'] : null;
        $this->xmlNomeArquivo = isset($data['nome_arquivo']) ? (string) $data['nome_arquivo'] : null;
        $this->xmlChave = isset($data['chave']) ? (string) $data['chave'] : $this->xmlChave;
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
            $this->xmlChave = null;
            $this->xmlDocumentoId = null;
        }
    }

    public function render()
    {
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
                    (string) $this->analiseCompetencia
                )
                ->orderByDesc('data_emissao')
                ->orderByDesc('numero')
                ->paginate(25);
        }

        return view('livewire.automacao-fiscal.resumo-fiscal-documentos', [
            'empresas' => $empresas,
            'portais' => $portais,
            'analises' => $analises,
            'documentos' => $documentos,
            'emDetalhe' => $emDetalhe,
            'precisaSelecionarEscritorio' => $precisaSelecionarEscritorio,
            'labelsColunasArquivo' => $this->analiseEhNfse
                ? ExtratoNfseParser::COLUNAS
                : ExtratoNfeEcacRsParser::COLUNAS,
        ]);
    }
}
