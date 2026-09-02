<?php

namespace App\Livewire\AutomacaoFiscal;

use App\Models\AgendaAutomacao;
use App\Models\AutomacaoConfiguracao;
use App\Models\AutomacaoExecucao;
use App\Models\AutomacaoExecucaoLog;
use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\EmpresaIntegracaoRecurso;
use App\Models\PortalIntegracao;
use App\Services\AutomacaoFiscal\AgendaAutomacaoProximaExecucaoService;
use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use App\Services\OperadoraContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class ConfiguracoesAutomacaoFiscal extends Component
{
    use WithFileUploads;
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public string $aba = 'geral';

    public string $timezone = 'America/Sao_Paulo';

    /** @var int|string */
    public $periodo_padrao_dias = 30;

    /** @var int|string */
    public $max_execucoes_simultaneas = 1;

    /** @var int|string */
    public $politica_tentativas = 3;

    /** @var int|string */
    public $retencao_logs_dias = 90;

    /** @var int|string */
    public $retencao_artefatos_dias = 30;

    /** @var int|string */
    public $aviso_certificado_dias = 30;

    public $certificadoArquivo = null;

    public string $certificadoNome = '';

    public string $certificadoSenha = '';

    public $certificadoEmpresaId = null;

    public string $agendaNome = '';

    public string $agendaFrequencia = 'diaria';

    public string $agendaHorario = '06:00';

    /** @var list<int|string> */
    public array $agendaDiasSemana = [];

    public string $agendaDiasMes = '';

    /** @var int|string */
    public $agendaIntervalo = 60;

    /** @var bool|string|int */
    public $agendaAtiva = true;

    public $agendaId = null;

    public $confirmandoExclusaoCertificado = null;

    public $confirmandoExclusaoAgenda = null;

    protected $queryString = [
        'aba' => ['except' => 'geral'],
    ];

    public function mount(?string $aba = null): void
    {
        $user = Auth::user();
        if (! $user || (! $user->isSuperAdmin() && ! in_array($user->role, ['admin', 'gerente'], true))) {
            abort(403, 'Sem permissão para configurações da automação fiscal.');
        }

        if ($aba) {
            $this->aba = $aba;
        }

        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            return;
        }

        if (OperadoraContext::id()) {
            $this->carregarConfiguracao();
        }
    }

    public function setAba(string $aba): void
    {
        if ($aba === 'logs' && ! $this->podeVerLogsTecnicos()) {
            return;
        }

        $this->aba = $aba;
        $this->resetPage();
    }

    public function salvarGeral(): void
    {
        if (! $this->garantirContexto()) {
            return;
        }

        $this->validate([
            'timezone' => 'required|string|max:64',
            'periodo_padrao_dias' => 'required|integer|min:1|max:30',
            'max_execucoes_simultaneas' => 'required|integer|min:1|max:20',
            'politica_tentativas' => 'required|integer|min:1|max:10',
            'retencao_logs_dias' => 'required|integer|min:1|max:3650',
            'retencao_artefatos_dias' => 'required|integer|min:1|max:3650',
            'aviso_certificado_dias' => 'required|integer|min:1|max:365',
        ]);

        $config = AutomacaoConfiguracao::forOperadora(OperadoraContext::requireId());
        $config->update([
            'timezone' => $this->timezone,
            'periodo_padrao_dias' => (int) $this->periodo_padrao_dias,
            'max_execucoes_simultaneas' => (int) $this->max_execucoes_simultaneas,
            'politica_tentativas' => (int) $this->politica_tentativas,
            'retencao_logs_dias' => (int) $this->retencao_logs_dias,
            'retencao_artefatos_dias' => (int) $this->retencao_artefatos_dias,
            'aviso_certificado_dias' => (int) $this->aviso_certificado_dias,
        ]);

        session()->flash('message', 'Configurações gerais salvas.');
    }

    public function uploadCertificado(CertificadoDigitalService $service): void
    {
        if (! $this->garantirContexto()) {
            return;
        }

        $this->validate([
            'certificadoArquivo' => 'required|file|extensions:pfx,p12|max:5120',
            'certificadoNome' => 'required|string|min:3|max:255',
            'certificadoSenha' => 'required|string|max:255',
            'certificadoEmpresaId' => 'nullable',
        ]);

        $empresaId = $this->certificadoEmpresaId ?: null;
        if ($empresaId) {
            OperadoraContext::resolveEmpresa((int) $empresaId);
        }

        try {
            $service->armazenar(
                $this->certificadoArquivo,
                $this->certificadoSenha,
                $this->certificadoNome,
                OperadoraContext::requireId(),
                $empresaId ? (int) $empresaId : null
            );
        } catch (\Throwable $e) {
            $this->addError('certificadoArquivo', $e->getMessage());

            return;
        }

        $this->reset(['certificadoArquivo', 'certificadoNome', 'certificadoSenha', 'certificadoEmpresaId']);
        session()->flash('message', 'Certificado armazenado com sucesso.');
        $this->aba = 'certificados';
    }

    public function confirmarDesativarCertificado(int $id): void
    {
        $this->confirmandoExclusaoCertificado = $id;
    }

    public function desativarCertificado(CertificadoDigitalService $service): void
    {
        if (! $this->confirmandoExclusaoCertificado) {
            return;
        }

        $cert = CertificadoDigital::findOrFail($this->confirmandoExclusaoCertificado);
        $service->desativar($cert);
        $this->confirmandoExclusaoCertificado = null;
        session()->flash('message', 'Certificado desativado.');
    }

    public function salvarAgenda(): void
    {
        if (! $this->garantirContexto()) {
            return;
        }

        $this->validate([
            'agendaNome' => 'required|string|min:3|max:255',
            'agendaFrequencia' => 'required|in:diaria,semanal,mensal,intervalo,manual',
            'agendaHorario' => 'required|date_format:H:i',
            'agendaAtiva' => 'boolean',
            'agendaDiasSemana' => $this->agendaFrequencia === 'semanal' ? 'required|array|min:1' : 'nullable|array',
            'agendaDiasMes' => $this->agendaFrequencia === 'mensal' ? 'required|string' : 'nullable|string',
            'agendaIntervalo' => $this->agendaFrequencia === 'intervalo' ? 'required|integer|min:5|max:10080' : 'nullable',
        ]);

        if ($this->agendaFrequencia === 'semanal' && $this->normalizarDiasSemana($this->agendaDiasSemana) === []) {
            $this->addError('agendaDiasSemana', 'Selecione ao menos um dia da semana.');

            return;
        }

        if ($this->agendaFrequencia === 'mensal' && $this->normalizarDiasMes($this->agendaDiasMes) === []) {
            $this->addError('agendaDiasMes', 'Informe ao menos um dia do mês.');

            return;
        }

        $payload = [
            'empresa_operadora_id' => OperadoraContext::requireId(),
            'nome' => $this->agendaNome,
            'frequencia' => $this->agendaFrequencia,
            'horarios' => [$this->agendaHorario],
            'ativo' => filter_var($this->agendaAtiva, FILTER_VALIDATE_BOOLEAN),
            'timezone' => $this->timezone ?: 'America/Sao_Paulo',
            'dias_semana' => $this->agendaFrequencia === 'semanal' ? $this->normalizarDiasSemana($this->agendaDiasSemana) : null,
            'dias_mes' => $this->agendaFrequencia === 'mensal' ? $this->normalizarDiasMes($this->agendaDiasMes) : null,
            'intervalo' => $this->agendaFrequencia === 'intervalo' ? (int) $this->agendaIntervalo : null,
        ];

        if ($this->agendaId) {
            $agenda = AgendaAutomacao::findOrFail($this->agendaId);
            $agenda->update($payload);
            $this->recalcularProximasDaAgenda($agenda->fresh());
            session()->flash('message', 'Agenda atualizada.');
        } else {
            AgendaAutomacao::create($payload);
            session()->flash('message', 'Agenda criada.');
        }

        $this->reset(['agendaNome', 'agendaId']);
        $this->agendaFrequencia = 'diaria';
        $this->agendaHorario = '06:00';
        $this->agendaDiasSemana = [];
        $this->agendaDiasMes = '';
        $this->agendaIntervalo = 60;
        $this->agendaAtiva = true;
        $this->aba = 'agendas';
    }

    public function editarAgenda(int $id): void
    {
        $agenda = AgendaAutomacao::findOrFail($id);
        $this->agendaId = $agenda->id;
        $this->agendaNome = $agenda->nome;
        $this->agendaFrequencia = $agenda->frequencia;
        $this->agendaHorario = ($agenda->horarios[0] ?? '06:00');
        $this->agendaDiasSemana = array_map('intval', $agenda->dias_semana ?? []);
        $this->agendaDiasMes = implode(', ', $agenda->dias_mes ?? []);
        $this->agendaIntervalo = $agenda->intervalo ?: 60;
        $this->agendaAtiva = (bool) $agenda->ativo;
        $this->aba = 'agendas';
    }

    public function confirmarExcluirAgenda(int $id): void
    {
        $this->confirmandoExclusaoAgenda = $id;
    }

    public function excluirAgenda(): void
    {
        if (! $this->confirmandoExclusaoAgenda) {
            return;
        }

        AgendaAutomacao::findOrFail($this->confirmandoExclusaoAgenda)->delete();
        $this->confirmandoExclusaoAgenda = null;
        session()->flash('message', 'Agenda removida.');
    }

    public function duplicarAgenda(int $id): void
    {
        $agenda = AgendaAutomacao::findOrFail($id);
        $copia = $agenda->replicate(['nome']);
        $copia->nome = $agenda->nome.' (cópia)';
        $copia->empresa_operadora_id = $agenda->empresa_operadora_id;
        $copia->save();
        session()->flash('message', 'Agenda duplicada.');
    }

    /**
     * @param  list<int|string>  $dias
     * @return list<int>
     */
    private function normalizarDiasSemana(array $dias): array
    {
        $validos = [];
        foreach ($dias as $dia) {
            $n = (int) $dia;
            if ($n >= 1 && $n <= 7) {
                $validos[] = $n;
            }
        }

        return array_values(array_unique($validos));
    }

    /**
     * @return list<int>
     */
    private function normalizarDiasMes(string $texto): array
    {
        $dias = [];
        foreach (preg_split('/[,\s]+/', $texto) ?: [] as $parte) {
            if ($parte === '') {
                continue;
            }
            $n = (int) $parte;
            if ($n >= 1 && $n <= 31) {
                $dias[] = $n;
            }
        }

        return array_values(array_unique($dias));
    }

    private function recalcularProximasDaAgenda(AgendaAutomacao $agenda): void
    {
        $proximas = app(AgendaAutomacaoProximaExecucaoService::class);
        $agora = now();

        EmpresaIntegracaoRecurso::query()
            ->where('agenda_automacao_id', $agenda->id)
            ->where('ativo', true)
            ->get()
            ->each(function (EmpresaIntegracaoRecurso $vinculo) use ($agenda, $proximas, $agora) {
                $vinculo->update([
                    'next_run_at' => $proximas->calcular($agenda, $agora),
                ]);
            });
    }

    private function carregarConfiguracao(): void
    {
        $config = AutomacaoConfiguracao::forOperadora(OperadoraContext::requireId());
        $this->timezone = $config->timezone;
        $this->periodo_padrao_dias = $config->periodo_padrao_dias;
        $this->max_execucoes_simultaneas = $config->max_execucoes_simultaneas;
        $this->politica_tentativas = $config->politica_tentativas;
        $this->retencao_logs_dias = $config->retencao_logs_dias;
        $this->retencao_artefatos_dias = $config->retencao_artefatos_dias;
        $this->aviso_certificado_dias = $config->aviso_certificado_dias;
    }

    private function garantirContexto(): bool
    {
        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior.');

            return false;
        }

        return true;
    }

    public function podeVerLogsTecnicos(): bool
    {
        $user = Auth::user();

        return $user && ($user->isSuperAdmin() || in_array($user->role, ['admin', 'gerente'], true));
    }

    public function render()
    {
        $precisaSelecionar = OperadoraContext::superAdminPrecisaSelecionarEscritorio();

        $portais = PortalIntegracao::query()->with('recursos')->where('ativo', true)->orderBy('nome')->get();
        $certificados = collect();
        $agendas = collect();
        $execucoes = collect();
        $logs = collect();
        $empresas = collect();

        if (! $precisaSelecionar && OperadoraContext::id()) {
            $certificados = CertificadoDigital::query()->with('empresa')->orderByDesc('id')->paginate(10, pageName: 'certsPage');
            $agendas = AgendaAutomacao::query()->orderBy('nome')->get();
            $execucoes = AutomacaoExecucao::query()
                ->with(['empresa', 'portalRecurso.portal'])
                ->orderByDesc('id')
                ->paginate(15, pageName: 'execPage');
            $empresas = Empresa::query()->orderBy('nome')->get(['id', 'nome']);

            if ($this->podeVerLogsTecnicos() && $this->aba === 'logs') {
                $logs = AutomacaoExecucaoLog::query()
                    ->with('execucao')
                    ->orderByDesc('ocorrido_em')
                    ->paginate(20, pageName: 'logsPage');
            }
        }

        return view('livewire.automacao-fiscal.configuracoes-automacao-fiscal', [
            'precisaSelecionarEscritorio' => $precisaSelecionar,
            'portais' => $portais,
            'certificados' => $certificados,
            'agendas' => $agendas,
            'execucoes' => $execucoes,
            'logs' => $logs,
            'empresas' => $empresas,
            'podeVerLogs' => $this->podeVerLogsTecnicos(),
        ]);
    }
}
