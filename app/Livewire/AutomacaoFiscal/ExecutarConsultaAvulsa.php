<?php

namespace App\Livewire\AutomacaoFiscal;

use App\Jobs\AutomacaoFiscal\ExecutarConsultaAvulsaJob;
use App\Models\CertificadoDigital;
use App\Services\AutomacaoFiscal\AnaliseFiscalService;
use App\Services\AutomacaoFiscal\ConsultaAvulsa\ConsultaAvulsaCatalogo;
use App\Services\AutomacaoFiscal\ExecucaoProgressoPresenter;
use App\Services\AutomacaoFiscal\FilaAutomacoesStatus;
use App\Services\AutomacaoFiscal\NfeXmlDownloadProgresso;
use App\Services\OperadoraContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class ExecutarConsultaAvulsa extends Component
{
    protected $layout = 'components.layouts.app';

    public string $tipo = '';

    /** @var array<string, mixed> */
    public array $entrada = [];

    public bool $emAndamento = false;
    public ?string $token = null;
    public string $status = 'idle';
    /** @var list<array{at: string, level: string, eventType: string, message: string}> */
    public array $logs = [];
    public ?string $erro = null;
    public ?string $nomeArquivo = null;
    public ?string $fonte = null;
    public ?int $duracaoMs = null;
    public ?string $finishedAt = null;
    /** @var array<string, mixed> */
    public array $parametros = [];

    public function mount(?string $tipo = null): void
    {
        $user = Auth::user();
        abort_unless($user && $user->isSuperAdmin(), 403);

        $tipos = ConsultaAvulsaCatalogo::disponiveisParaRole($user->role);
        if ($tipo && ConsultaAvulsaCatalogo::rolePodeAcessar($tipo, $user->role)) {
            $this->tipo = $tipo;
        } elseif ($tipos !== []) {
            $this->tipo = (string) $tipos[0]['codigo'];
        }

        $this->prepararEntradaDoTipo();
    }

    public function updatedTipo(): void
    {
        $this->prepararEntradaDoTipo();
        $this->resetProgresso();
    }

    public function executar(): void
    {
        $user = Auth::user();
        abort_unless($user && $user->isSuperAdmin(), 403);

        $mensagemFila = app(FilaAutomacoesStatus::class)->mensagemBloqueioDesenvolvimento();
        if ($mensagemFila !== null) {
            session()->flash('error', $mensagemFila);

            return;
        }

        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior.');

            return;
        }

        if (! ConsultaAvulsaCatalogo::rolePodeAcessar($this->tipo, $user->role)) {
            session()->flash('error', 'Tipo de consulta avulsa não permitido.');

            return;
        }

        $meta = ConsultaAvulsaCatalogo::porCodigo($this->tipo);
        if ($meta === null) {
            session()->flash('error', 'Tipo de consulta inválido.');

            return;
        }

        $this->validate($this->regrasValidacao($meta));

        $token = (string) Str::uuid();
        $entrada = $this->entradaSanitizada();
        $chave = AnaliseFiscalService::normalizarChaveAcesso((string) ($entrada['chave_acesso'] ?? ''));
        $operadoraId = OperadoraContext::id();

        NfeXmlDownloadProgresso::iniciar(
            $token,
            0,
            $operadoraId,
            $chave,
            $this->tipo,
            $entrada
        );
        NfeXmlDownloadProgresso::adicionarLog(
            $token,
            'info',
            'JOB_QUEUED',
            'Na fila — aguardando worker de automações…'
        );

        $this->aplicarProgresso(NfeXmlDownloadProgresso::obter($token) ?? []);
        $this->token = $token;
        $this->emAndamento = true;
        $this->status = 'running';

        ExecutarConsultaAvulsaJob::dispatch(
            $token,
            $this->tipo,
            $entrada,
            $operadoraId
        );
    }

    public function atualizarProgresso(): void
    {
        if (! $this->token || ! $this->emAndamento) {
            return;
        }

        $data = NfeXmlDownloadProgresso::obter($this->token);
        if ($data === null) {
            return;
        }

        $this->aplicarProgresso($data);
        $this->emAndamento = $this->status === 'running';
    }

    public function limpar(): void
    {
        $this->resetProgresso();
        $this->prepararEntradaDoTipo();
    }

    public function render()
    {
        $user = Auth::user();
        $tipos = ConsultaAvulsaCatalogo::disponiveisParaRole($user?->role);
        $meta = ConsultaAvulsaCatalogo::porCodigo($this->tipo);
        $precisaSelecionarEscritorio = OperadoraContext::superAdminPrecisaSelecionarEscritorio();
        $progresso = app(ExecucaoProgressoPresenter::class);

        $certificados = collect();
        if (! $precisaSelecionarEscritorio) {
            $certificados = CertificadoDigital::query()
                ->where('ativo', true)
                ->where('tipo', 'A1')
                ->orderBy('nome')
                ->get(['id', 'nome', 'titular', 'documento_titular', 'empresa_id']);
        }

        $pipeline = $this->token
            ? $progresso->montarPipelineDeEventos($this->status, $this->logs)
            : [];

        $etapaAtual = null;
        if ($this->logs !== []) {
            $etapaAtual = (string) ($this->logs[array_key_last($this->logs)]['eventType'] ?? '');
        }

        return view('livewire.automacao-fiscal.executar-consulta-avulsa', [
            'avisoFila' => app(FilaAutomacoesStatus::class)->avisoDesenvolvimento(),
            'tipos' => $tipos,
            'meta' => $meta,
            'certificados' => $certificados,
            'precisaSelecionarEscritorio' => $precisaSelecionarEscritorio,
            'fakeMode' => (bool) config('automacao_fiscal.fake_mode'),
            'progresso' => $progresso,
            'pipeline' => $pipeline,
            'etapaAtual' => $etapaAtual ?: ($this->emAndamento ? 'executando' : null),
            'contextoLabel' => $meta
                ? (($meta['nome'] ?? $this->tipo).($this->entrada['chave_acesso'] ?? null
                    ? ' · '.mb_substr((string) $this->entrada['chave_acesso'], 0, 20).'…'
                    : ''))
                : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function aplicarProgresso(array $data): void
    {
        $this->status = (string) ($data['status'] ?? 'running');
        $this->logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
        $this->erro = isset($data['error']) ? (string) $data['error'] : null;
        $this->nomeArquivo = isset($data['nome_arquivo']) ? (string) $data['nome_arquivo'] : null;
        $this->fonte = isset($data['fonte']) ? (string) $data['fonte'] : null;
        $this->duracaoMs = isset($data['duracao_ms']) ? (int) $data['duracao_ms'] : null;
        $this->parametros = is_array($data['parametros'] ?? null) ? $data['parametros'] : [];
        $this->finishedAt = null;
        if (! empty($data['finished_at'])) {
            try {
                $this->finishedAt = Carbon::parse((string) $data['finished_at'])->format('d/m/Y H:i:s');
            } catch (\Throwable) {
                $this->finishedAt = (string) $data['finished_at'];
            }
        }
    }

    private function prepararEntradaDoTipo(): void
    {
        $meta = ConsultaAvulsaCatalogo::porCodigo($this->tipo);
        $entrada = [];
        foreach (($meta['campos'] ?? []) as $campo) {
            $chave = (string) $campo['chave'];
            $entrada[$chave] = $this->entrada[$chave] ?? '';
        }
        $this->entrada = $entrada;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function regrasValidacao(array $meta): array
    {
        $regras = [
            'tipo' => ['required', 'string'],
        ];

        foreach ($meta['campos'] ?? [] as $campo) {
            $chave = (string) $campo['chave'];
            if ($chave === 'chave_acesso') {
                $regras['entrada.chave_acesso'] = ['required', 'string', function ($attr, $value, $fail) {
                    $digits = AnaliseFiscalService::normalizarChaveAcesso((string) $value);
                    $esperado = $this->tipo === 'xml_nfse_por_chave' ? 50 : 44;
                    if ($digits === null || strlen($digits) !== $esperado) {
                        $fail("Informe a chave de acesso com {$esperado} dígitos.");
                    }
                }];
            } elseif ($chave === 'certificado_digital_id') {
                $regras['entrada.certificado_digital_id'] = $this->tipo === 'xml_nfse_por_chave'
                    ? ['required', 'integer']
                    : ['nullable', 'integer'];
            } else {
                $regras['entrada.'.$chave] = ['nullable', 'string'];
            }
        }

        return $regras;
    }

    /**
     * @return array<string, mixed>
     */
    private function entradaSanitizada(): array
    {
        $out = $this->entrada;
        if (isset($out['chave_acesso'])) {
            $out['chave_acesso'] = AnaliseFiscalService::normalizarChaveAcesso((string) $out['chave_acesso']) ?? '';
        }
        if (isset($out['certificado_digital_id']) && $out['certificado_digital_id'] === '') {
            $out['certificado_digital_id'] = null;
        }

        return $out;
    }

    private function resetProgresso(): void
    {
        $this->emAndamento = false;
        $this->token = null;
        $this->status = 'idle';
        $this->logs = [];
        $this->erro = null;
        $this->nomeArquivo = null;
        $this->fonte = null;
        $this->duracaoMs = null;
        $this->finishedAt = null;
        $this->parametros = [];
    }
}
