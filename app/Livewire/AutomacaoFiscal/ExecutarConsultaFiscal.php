<?php

namespace App\Livewire\AutomacaoFiscal;

use App\Models\AutomacaoConsultaSalva;
use App\Models\AutomacaoExecucao;
use App\Models\Empresa;
use App\Models\EmpresaIntegracao;
use App\Models\PortalRecurso;
use App\Rules\EmpresaDoEscritorio;
use App\Services\AutomacaoFiscal\AutomacaoArtefatoService;
use App\Services\AutomacaoFiscal\AutomacaoExecucaoService;
use App\Services\AutomacaoFiscal\ExecucaoProgressoPresenter;
use App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser;
use App\Services\AutomacaoFiscal\FilaAutomacoesStatus;
use App\Services\OperadoraContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ExecutarConsultaFiscal extends Component
{
    protected $layout = 'components.layouts.app';

    public $empresa_id = '';

    public $empresa_integracao_id = '';

    /** extrato_nfe_nfce | extrato_nfse_emitidas | extrato_nfse_recebidas | validar_acesso */
    public string $tipo_consulta = '';

    public $portal_recurso_id = '';

    public $consulta_salva_id = '';

    public string $nome_consulta_salva = '';

    /** @var array<string, mixed> */
    public array $parametros = [];

    public bool $salvarAoExecutar = true;

    /** mes_atual | mes_anterior | personalizado */
    public string $periodo_modo = 'mes_atual';

    public bool $modelo_nfe = true;

    public bool $modelo_nfce = false;

    public ?int $ultima_execucao_id = null;

    public function mount(?int $execucao = null): void
    {
        abort_unless($this->podeVerProcessamento(), 403);

        if ($execucao) {
            $model = AutomacaoExecucao::query()
                ->with(['empresa', 'empresaIntegracao', 'portalRecurso'])
                ->findOrFail($execucao);

            $this->ultima_execucao_id = $model->id;
            $this->empresa_id = (string) $model->empresa_id;
            $this->empresa_integracao_id = (string) $model->empresa_integracao_id;
            $this->parametros = (array) ($model->parametros ?? []);
            $this->sincronizarTipoPeloRecurso($model->portalRecurso);
            $this->sincronizarPeriodoModoDosParametros();
            $this->sincronizarChecksDoModelo();

            if ($this->parametros === [] && $this->portal_recurso_id) {
                $this->carregarParametrosDoRecurso();
            } else {
                $this->aplicarDatasDoPeriodoModo();
            }

            $this->dispatch('scroll-progresso-execucao');

            return;
        }

        $empresaSessao = session('empresa_selecionada_id');
        if ($empresaSessao && Empresa::query()->whereKey($empresaSessao)->exists()) {
            $this->empresa_id = (string) $empresaSessao;
            $this->carregarIntegracoesDaEmpresa();
        }
    }

    public function podeVerProcessamento(): bool
    {
        $user = Auth::user();

        return $user && ($user->isSuperAdmin() || $user->isEscritorioAdmin());
    }

    public function updatedEmpresaId(): void
    {
        $this->empresa_integracao_id = '';
        $this->resetConsultaEstado();
        $this->ultima_execucao_id = null;
        $this->carregarIntegracoesDaEmpresa();
    }

    public function updatedEmpresaIntegracaoId(): void
    {
        $this->resetConsultaEstado();
        $this->ultima_execucao_id = null;

        if ($this->empresa_integracao_id) {
            $this->tipo_consulta = $this->primeiroTipoDisponivel() ?: '';
            if ($this->tipo_consulta !== '') {
                $this->aplicarTipoConsulta();
            }
        }
    }

    public function updatedTipoConsulta(): void
    {
        $this->consulta_salva_id = '';
        $this->nome_consulta_salva = '';
        $this->parametros = [];
        $this->aplicarTipoConsulta();
    }

    public function updatedConsultaSalvaId(): void
    {
        if (!$this->consulta_salva_id) {
            $this->nome_consulta_salva = '';
            $this->carregarParametrosDoRecurso();

            return;
        }

        $preset = AutomacaoConsultaSalva::query()
            ->whereKey($this->consulta_salva_id)
            ->where('empresa_integracao_id', $this->empresa_integracao_id)
            ->first();

        if (!$preset) {
            $this->consulta_salva_id = '';
            session()->flash('error', 'Modelo de consulta não encontrado.');

            return;
        }

        $this->aplicarParametrosSalvos((array) ($preset->parametros ?? []));
        $this->nome_consulta_salva = $preset->nome;
    }

    public function updatedPeriodoModo(): void
    {
        if (!in_array($this->periodo_modo, ['mes_atual', 'mes_anterior', 'personalizado'], true)) {
            $this->periodo_modo = 'mes_atual';
        }

        $this->aplicarDatasDoPeriodoModo();
    }

    public function updatedModeloNfe(): void
    {
        $this->sincronizarModeloNosParametros();
    }

    public function updatedModeloNfce(): void
    {
        $this->sincronizarModeloNosParametros();
    }

    public function carregarIntegracoesDaEmpresa(): void
    {
        // noop — render monta a lista; mantém método para updated hooks
    }

    public function carregarParametrosDoRecurso(): void
    {
        $this->parametros = [];

        if (!$this->empresa_id || !$this->empresa_integracao_id || !$this->portal_recurso_id) {
            return;
        }

        $integracao = EmpresaIntegracao::query()
            ->where('empresa_id', $this->empresa_id)
            ->whereKey($this->empresa_integracao_id)
            ->first();
        $recurso = PortalRecurso::find($this->portal_recurso_id);

        if (!$integracao || !$recurso || empty($recurso->parametros_schema)) {
            return;
        }

        $vinculo = $integracao->recursos()
            ->where('portal_recurso_id', $recurso->id)
            ->first();

        $this->aplicarParametrosSalvos((array) ($vinculo?->parametros ?? []));
    }

    /**
     * Aplica um blob salvo (modelo nomeado ou padrão do vínculo), preenchendo faltantes.
     *
     * @param  array<string, mixed>  $salvos
     */
    private function aplicarParametrosSalvos(array $salvos): void
    {
        $empresa = Empresa::find($this->empresa_id);
        $recurso = PortalRecurso::find($this->portal_recurso_id);

        if (!$empresa || !$recurso || empty($recurso->parametros_schema)) {
            return;
        }

        $this->parametros = [];
        $schema = (array) $recurso->parametros_schema;

        foreach ($schema as $chave => $def) {
            $def = (array) $def;
            $type = $def['type'] ?? 'string';

            if (array_key_exists($chave, $salvos)) {
                if ($type === 'boolean') {
                    $this->parametros[$chave] = filter_var($salvos[$chave], FILTER_VALIDATE_BOOLEAN);
                    continue;
                }

                if ($salvos[$chave] !== null && $salvos[$chave] !== '') {
                    $this->parametros[$chave] = $salvos[$chave];
                    continue;
                }
            }

            if (array_key_exists('default', $def)) {
                $this->parametros[$chave] = $def['default'];
                continue;
            }

            $this->parametros[$chave] = match ($chave) {
                'ie' => (string) ($empresa->inscricao_estadual ?? ''),
                'cnpj' => preg_replace('/\D+/', '', (string) ($empresa->cnpj ?? '')) ?: '',
                'periodo_inicial', 'periodo_inicio' => now()->startOfMonth()->toDateString(),
                'periodo_final', 'periodo_fim' => now()->toDateString(),
                'operacao' => 'saida-consulente',
                'situacao_normal' => true,
                'situacao_cancelada', 'totalizado_por_mes', 'excluir_venda_fora_estabelecimento' => false,
                default => $type === 'boolean' ? false : '',
            };
        }

        if (!empty($salvos['periodo_modo'])) {
            $this->parametros['periodo_modo'] = $salvos['periodo_modo'];
        }

        $this->sincronizarChecksDoModelo();
        $this->sincronizarPeriodoModoDosParametros();
        $this->aplicarDatasDoPeriodoModo();
    }

    private function sincronizarChecksDoModelo(): void
    {
        $modelo = (string) ($this->parametros['modelo'] ?? 'nfe');

        $this->modelo_nfe = in_array($modelo, ['nfe', 'ambos'], true);
        $this->modelo_nfce = in_array($modelo, ['nfce', 'ambos'], true);

        if (!$this->modelo_nfe && !$this->modelo_nfce) {
            $this->modelo_nfe = true;
        }

        $this->sincronizarModeloNosParametros();
    }

    private function sincronizarModeloNosParametros(): void
    {
        if ($this->modelo_nfe && $this->modelo_nfce) {
            $this->parametros['modelo'] = 'ambos';
        } elseif ($this->modelo_nfce) {
            $this->parametros['modelo'] = 'nfce';
        } else {
            $this->modelo_nfe = true;
            $this->parametros['modelo'] = 'nfe';
        }
    }

    private function garantirModeloSelecionado(): void
    {
        if (!$this->modelo_nfe && !$this->modelo_nfce) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'modelo_nfe' => 'Marque NF-e, NFC-e ou ambos.',
            ]);
        }

        $this->sincronizarModeloNosParametros();
    }

    public function salvarConsultaNomeada(AutomacaoExecucaoService $service): void
    {
        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior.');

            return;
        }

        if (! in_array($this->tipo_consulta, ['extrato_nfe_nfce', 'extrato_nfse_emitidas', 'extrato_nfse_recebidas'], true)) {
            session()->flash('error', 'Modelos salvos só se aplicam a extratos de consulta.');

            return;
        }

        $this->aplicarDatasDoPeriodoModo();
        if ($this->tipo_consulta === 'extrato_nfe_nfce') {
            $this->garantirModeloSelecionado();
        }
        $this->validate($this->regrasBasicas() + $this->regrasParametros() + [
            'nome_consulta_salva' => ['required', 'string', 'min:2', 'max:120'],
            'periodo_modo' => ['required', 'in:mes_atual,mes_anterior,personalizado'],
        ], [], [
            'nome_consulta_salva' => 'nome do modelo',
            'periodo_modo' => 'período',
        ]);

        [$integracao, $recurso] = $this->resolverIntegracaoRecurso();
        $paramsExecucao = $this->parametrosParaExecucao($recurso);
        if ($this->tipo_consulta === 'extrato_nfe_nfce') {
            $this->garantirIdentificacaoContribuinte($paramsExecucao);
        }
        $paramsPersistidos = $this->parametrosParaPersistencia($recurso);

        $vinculo = $service->garantirVinculo($integracao, $recurso);
        $service->salvarParametrosRecurso($vinculo, $paramsPersistidos);

        $preset = AutomacaoConsultaSalva::query()->updateOrCreate(
            [
                'empresa_integracao_id' => $integracao->id,
                'portal_recurso_id' => $recurso->id,
                'nome' => trim($this->nome_consulta_salva),
            ],
            [
                'empresa_id' => (int) $this->empresa_id,
                'parametros' => $paramsPersistidos,
            ]
        );

        $this->consulta_salva_id = (string) $preset->id;
        $this->aplicarParametrosSalvos((array) ($preset->fresh()->parametros ?? $paramsPersistidos));
        $this->nome_consulta_salva = $preset->nome;

        session()->flash('message', 'Modelo “'.$preset->nome.'” salvo.');
    }

    public function excluirConsultaSalva(): void
    {
        if (!$this->consulta_salva_id) {
            return;
        }

        AutomacaoConsultaSalva::query()
            ->whereKey($this->consulta_salva_id)
            ->where('empresa_integracao_id', $this->empresa_integracao_id)
            ->delete();

        $this->consulta_salva_id = '';
        $this->nome_consulta_salva = '';
        $this->carregarParametrosDoRecurso();
        session()->flash('message', 'Modelo excluído.');
    }

    public function testarAcesso(AutomacaoExecucaoService $service): void
    {
        if ($this->filaAutomacoesParada()) {
            return;
        }

        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior.');

            return;
        }

        $this->validate([
            'empresa_id' => ['required', new EmpresaDoEscritorio],
            'empresa_integracao_id' => ['required', 'integer'],
        ]);

        $integracao = EmpresaIntegracao::query()
            ->where('empresa_id', $this->empresa_id)
            ->whereKey($this->empresa_integracao_id)
            ->firstOrFail();

        try {
            $execucao = $service->enfileirarValidacaoAcesso($integracao, Auth::id());
            session()->flash('message', 'Validação de acesso enfileirada.');
            $this->redirect(route('automacao-fiscal.execucao', $execucao), navigate: true);
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function executar(AutomacaoExecucaoService $service): void
    {
        if ($this->filaAutomacoesParada()) {
            return;
        }

        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior.');

            return;
        }

        if ($this->tipo_consulta === 'validar_acesso') {
            $this->testarAcesso($service);

            return;
        }

        $this->aplicarDatasDoPeriodoModo();
        if ($this->tipo_consulta === 'extrato_nfe_nfce') {
            $this->garantirModeloSelecionado();
        }
        $this->validate($this->regrasBasicas() + $this->regrasParametros());

        [$integracao, $recurso] = $this->resolverIntegracaoRecurso();

        $params = $this->parametrosParaExecucao($recurso);
        if ($this->tipo_consulta === 'extrato_nfe_nfce') {
            $this->garantirIdentificacaoContribuinte($params);
        }
        $vinculo = $service->garantirVinculo($integracao, $recurso);

        if ($this->salvarAoExecutar) {
            $service->salvarParametrosRecurso($vinculo, $this->parametrosParaPersistencia($recurso));
        }

        try {
            $execucao = $service->enfileirarManual(
                $vinculo,
                userId: Auth::id(),
                parametros: $params
            );
            session()->flash('message', 'Consulta enfileirada.');
            $this->redirect(route('automacao-fiscal.execucao', $execucao), navigate: true);
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    private function filaAutomacoesParada(): bool
    {
        $mensagem = app(FilaAutomacoesStatus::class)->mensagemBloqueioDesenvolvimento();
        if ($mensagem === null) {
            return false;
        }

        session()->flash('error', $mensagem);

        return true;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function garantirIdentificacaoContribuinte(array $params): void
    {
        $ie = preg_replace('/\D+/', '', (string) ($params['ie'] ?? '')) ?? '';
        $cnpj = preg_replace('/\D+/', '', (string) ($params['cnpj'] ?? '')) ?? '';

        if ($ie === '' && $cnpj === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'parametros.ie' => 'Informe a inscrição estadual ou o CNPJ (só números).',
            ]);
        }

        if ($cnpj !== '' && strlen($cnpj) !== 14) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'parametros.cnpj' => 'CNPJ deve ter 14 dígitos.',
            ]);
        }
    }

    /**
     * @return array{0: EmpresaIntegracao, 1: PortalRecurso}
     */
    private function resolverIntegracaoRecurso(): array
    {
        $integracao = EmpresaIntegracao::query()
            ->where('empresa_id', $this->empresa_id)
            ->whereKey($this->empresa_integracao_id)
            ->firstOrFail();

        $recurso = PortalRecurso::query()
            ->whereKey($this->portal_recurso_id)
            ->where('portal_integracao_id', $integracao->portal_integracao_id)
            ->firstOrFail();

        return [$integracao, $recurso];
    }

    private function resetConsultaEstado(): void
    {
        $this->tipo_consulta = '';
        $this->portal_recurso_id = '';
        $this->consulta_salva_id = '';
        $this->nome_consulta_salva = '';
        $this->periodo_modo = 'mes_atual';
        $this->modelo_nfe = true;
        $this->modelo_nfce = false;
        $this->parametros = [];
    }

    private function aplicarTipoConsulta(): void
    {
        $this->portal_recurso_id = '';

        if (!$this->empresa_integracao_id || $this->tipo_consulta === '') {
            return;
        }

        $integracao = EmpresaIntegracao::query()
            ->where('empresa_id', $this->empresa_id)
            ->whereKey($this->empresa_integracao_id)
            ->first();

        if (!$integracao) {
            return;
        }

        $codigo = match ($this->tipo_consulta) {
            'extrato_nfe_nfce' => 'nfe_emitidas',
            'extrato_nfse_emitidas' => 'nfse_emitidas',
            'extrato_nfse_recebidas' => 'nfse_recebidas',
            'validar_acesso' => 'validar_acesso',
            default => null,
        };

        if (!$codigo) {
            return;
        }

        $recurso = PortalRecurso::query()
            ->where('portal_integracao_id', $integracao->portal_integracao_id)
            ->where('codigo', $codigo)
            ->where('ativo', true)
            ->first();

        if (!$recurso && $codigo === 'nfe_emitidas') {
            $recurso = PortalRecurso::query()
                ->where('portal_integracao_id', $integracao->portal_integracao_id)
                ->whereIn('codigo', ['nfe_emitidas', 'nfce_emitidas'])
                ->orderBy('codigo')
                ->first();
        }

        if (!$recurso) {
            session()->flash('error', 'Este portal não possui a consulta selecionada.');

            return;
        }

        $this->portal_recurso_id = (string) $recurso->id;

        if (in_array($this->tipo_consulta, ['extrato_nfe_nfce', 'extrato_nfse_emitidas', 'extrato_nfse_recebidas'], true)) {
            $this->carregarParametrosDoRecurso();
        }
    }

    private function primeiroTipoDisponivel(): string
    {
        if (!$this->empresa_integracao_id) {
            return '';
        }

        $integracao = EmpresaIntegracao::query()->find($this->empresa_integracao_id);
        if (!$integracao) {
            return '';
        }

        $codigos = PortalRecurso::query()
            ->where('portal_integracao_id', $integracao->portal_integracao_id)
            ->where('ativo', true)
            ->pluck('codigo');

        if ($codigos->contains('nfe_emitidas') || $codigos->contains('nfce_emitidas')) {
            return 'extrato_nfe_nfce';
        }
        if ($codigos->contains('nfse_emitidas')) {
            return 'extrato_nfse_emitidas';
        }
        if ($codigos->contains('nfse_recebidas')) {
            return 'extrato_nfse_recebidas';
        }
        if ($codigos->contains('validar_acesso')) {
            return 'validar_acesso';
        }

        return '';
    }

    private function sincronizarTipoPeloRecurso(?PortalRecurso $recurso): void
    {
        if (!$recurso) {
            return;
        }

        if ($recurso->codigo === 'validar_acesso') {
            $this->tipo_consulta = 'validar_acesso';
            $this->portal_recurso_id = (string) $recurso->id;

            return;
        }

        if (in_array($recurso->codigo, ['nfe_emitidas', 'nfce_emitidas'], true)) {
            $this->tipo_consulta = 'extrato_nfe_nfce';

            $unificado = PortalRecurso::query()
                ->where('portal_integracao_id', $recurso->portal_integracao_id)
                ->where('codigo', 'nfe_emitidas')
                ->first();

            $this->portal_recurso_id = (string) ($unificado?->id ?? $recurso->id);

            return;
        }

        if ($recurso->codigo === 'nfse_emitidas') {
            $this->tipo_consulta = 'extrato_nfse_emitidas';
            $this->portal_recurso_id = (string) $recurso->id;

            return;
        }

        if ($recurso->codigo === 'nfse_recebidas') {
            $this->tipo_consulta = 'extrato_nfse_recebidas';
            $this->portal_recurso_id = (string) $recurso->id;

            return;
        }

        $this->tipo_consulta = '';
        $this->portal_recurso_id = (string) $recurso->id;
    }

    public function labelPeriodoModo(string $modo): string
    {
        return match ($modo) {
            'mes_atual' => 'Mês atual',
            'mes_anterior' => 'Mês anterior',
            'personalizado' => 'Personalizado',
            default => $modo,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function opcoesPeriodoModo(): array
    {
        return [
            ['value' => 'mes_atual', 'label' => 'Mês atual'],
            ['value' => 'mes_anterior', 'label' => 'Mês anterior'],
            ['value' => 'personalizado', 'label' => 'Personalizado'],
        ];
    }

    public function aplicarDatasDoPeriodoModo(): void
    {
        if ($this->periodo_modo === 'personalizado') {
            return;
        }

        [$inicio, $fim] = $this->resolverIntervaloPeriodo($this->periodo_modo);
        $this->parametros['periodo_inicial'] = $inicio;
        $this->parametros['periodo_final'] = $fim;

        if (array_key_exists('periodo_inicio', $this->parametros)) {
            $this->parametros['periodo_inicio'] = $inicio;
        }
        if (array_key_exists('periodo_fim', $this->parametros)) {
            $this->parametros['periodo_fim'] = $fim;
        }
    }

    private function sincronizarPeriodoModoDosParametros(): void
    {
        $modo = (string) ($this->parametros['periodo_modo'] ?? '');

        if (in_array($modo, ['mes_atual', 'mes_anterior', 'personalizado'], true)) {
            $this->periodo_modo = $modo;

            return;
        }

        $inicio = (string) ($this->parametros['periodo_inicial'] ?? $this->parametros['periodo_inicio'] ?? '');
        $fim = (string) ($this->parametros['periodo_final'] ?? $this->parametros['periodo_fim'] ?? '');

        foreach (['mes_atual', 'mes_anterior'] as $candidato) {
            [$i, $f] = $this->resolverIntervaloPeriodo($candidato);
            if ($inicio === $i && $fim === $f) {
                $this->periodo_modo = $candidato;

                return;
            }
        }

        $this->periodo_modo = ($inicio !== '' || $fim !== '') ? 'personalizado' : 'mes_atual';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolverIntervaloPeriodo(string $modo): array
    {
        if ($modo === 'mes_anterior') {
            $ref = now()->subMonthNoOverflow();

            return [
                $ref->copy()->startOfMonth()->toDateString(),
                $ref->copy()->endOfMonth()->toDateString(),
            ];
        }

        return [
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
        ];
    }

    /**
     * Grava opções do modelo; datas só quando o período é personalizado.
     *
     * @return array<string, mixed>
     */
    private function parametrosParaPersistencia(PortalRecurso $recurso): array
    {
        $out = $this->normalizarParametros($recurso);
        $out['periodo_modo'] = $this->periodo_modo;

        if ($this->periodo_modo !== 'personalizado') {
            unset($out['periodo_inicial'], $out['periodo_final'], $out['periodo_inicio'], $out['periodo_fim']);
        }

        return $out;
    }

    /**
     * Resolve datas concretas para enfileirar a execução.
     *
     * @return array<string, mixed>
     */
    private function parametrosParaExecucao(PortalRecurso $recurso): array
    {
        $this->aplicarDatasDoPeriodoModo();
        $out = $this->normalizarParametros($recurso);
        $out['periodo_modo'] = $this->periodo_modo;

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function regrasBasicas(): array
    {
        return [
            'empresa_id' => ['required', new EmpresaDoEscritorio],
            'empresa_integracao_id' => ['required', 'integer'],
            'tipo_consulta' => ['required', 'in:extrato_nfe_nfce,extrato_nfse_emitidas,extrato_nfse_recebidas,validar_acesso'],
            'portal_recurso_id' => ['required', 'integer'],
            'periodo_modo' => ['nullable', 'in:mes_atual,mes_anterior,personalizado'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function regrasParametros(): array
    {
        $recurso = PortalRecurso::find($this->portal_recurso_id);
        if (!$recurso || empty($recurso->parametros_schema)) {
            return [];
        }

        $rules = [];
        foreach ($recurso->parametros_schema as $chave => $def) {
            $def = (array) $def;
            $required = !empty($def['required']);
            $type = $def['type'] ?? 'string';

            if (in_array($chave, ['periodo_inicial', 'periodo_final', 'periodo_inicio', 'periodo_fim'], true)
                && $this->periodo_modo !== 'personalizado') {
                $required = true;
            }

            $campo = "parametros.{$chave}";
            $lista = [$required ? 'required' : 'nullable'];

            if ($type === 'date') {
                $lista[] = 'date';
            } elseif ($type === 'boolean') {
                $lista[] = 'boolean';
            } elseif ($type === 'enum' && !empty($def['values'])) {
                $lista[] = 'in:' . implode(',', $def['values']);
            } else {
                $lista[] = 'string';
                $lista[] = 'max:120';
            }

            $rules[$campo] = $lista;
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizarParametros(PortalRecurso $recurso): array
    {
        $schema = (array) ($recurso->parametros_schema ?? []);
        $out = [];

        foreach ($schema as $chave => $def) {
            $def = (array) $def;
            $valor = $this->parametros[$chave] ?? null;

            if (($def['type'] ?? '') === 'boolean') {
                $out[$chave] = filter_var($valor, FILTER_VALIDATE_BOOLEAN);
                continue;
            }

            if ($valor === null || $valor === '') {
                continue;
            }

            if (in_array($chave, ['ie', 'cnpj'], true)) {
                $digitos = preg_replace('/\D+/', '', (string) $valor) ?? '';
                if ($digitos !== '') {
                    $out[$chave] = $digitos;
                }
                continue;
            }

            $out[$chave] = $valor;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function ordenarSchema(array $schema): array
    {
        $ordem = [
            'ie',
            'cnpj',
            'modelo',
            'totalizado_por_mes',
            'periodo_inicial',
            'periodo_inicio',
            'periodo_final',
            'periodo_fim',
            'busca',
            'operacao',
            'excluir_venda_fora_estabelecimento',
            'situacao_normal',
            'situacao_cancelada',
        ];

        $ordenado = [];
        foreach ($ordem as $chave) {
            if (array_key_exists($chave, $schema)) {
                $ordenado[$chave] = $schema[$chave];
            }
        }

        foreach ($schema as $chave => $def) {
            if (!array_key_exists($chave, $ordenado)) {
                $ordenado[$chave] = $def;
            }
        }

        return $ordenado;
    }

    public function labelCampo(string $chave): string
    {
        return match ($chave) {
            'ie' => 'Inscrição Estadual',
            'cnpj' => 'CNPJ',
            'modelo' => 'Modelo',
            'periodo_inicial', 'periodo_inicio' => 'Período Inicial',
            'periodo_final', 'periodo_fim' => 'Período Final',
            'busca' => 'Pesquisar pessoa física ou jurídica',
            'operacao' => 'Operação',
            'situacao_normal' => 'Normal',
            'situacao_cancelada' => 'Cancelada',
            'totalizado_por_mes' => 'Totalizado por mês',
            'periodo_modo' => 'Período',
            'excluir_venda_fora_estabelecimento' => 'Sem as NF-e\'s/NFC-e\'s exclusivamente de venda fora do estabelecimento (CFOP: 5103, 5104, 6103 e 6104)',
            default => str_replace('_', ' ', ucfirst($chave)),
        };
    }

    public function labelEnum(string $chave, string $valor): string
    {
        if ($chave === 'operacao') {
            return match ($valor) {
                'saida-consulente' => 'Exibir as NF-e\'s/NFC-e\'s de Saída emitidas pelo consulente (ou seja, o emitente da NF-e/NFC-e é o consulente)',
                'saida-terceiros' => 'Exibir as NF-e\'s/NFC-e\'s de Saída emitidas por terceiros (ou seja, o destinatário da NF-e/NFC-e é o consulente)',
                'entrada-consulente' => 'Exibir as NF-e\'s de Entrada emitidas pelo consulente (ou seja, o emitente da NF-e é o consulente)',
                'entrada-terceiros' => 'Exibir as NF-e\'s de Entrada emitidas por terceiros (ou seja, o remetente da NF-e é o consulente)',
                default => $valor,
            };
        }

        if ($chave === 'modelo') {
            return match ($valor) {
                'nfe' => 'NF-e',
                'nfce' => 'NFC-e',
                'ambos' => 'NF-e e NFC-e',
                default => strtoupper($valor),
            };
        }

        return $valor;
    }

    public function labelEnumCurto(string $chave, string $valor): string
    {
        if ($chave === 'operacao') {
            return ExtratoNfeEcacRsParser::labelTipoOperacao($valor);
        }

        return $this->labelEnum($chave, $valor);
    }

    /**
     * Formulário no estilo do portal e-CAC (checkboxes / rádios).
     *
     * @param  array<string, mixed>  $schema
     */
    public function schemaEstiloPortal(array $schema): bool
    {
        return isset($schema['modelo'], $schema['operacao'], $schema['situacao_normal']);
    }

    /**
     * @param  array<string, mixed>  $def
     * @return list<array{value: string, label: string}>
     */
    public function opcoesCampo(string $chave, array $def): array
    {
        if (!empty($def['options']) && is_array($def['options'])) {
            $out = [];
            foreach ($def['options'] as $opt) {
                if (is_array($opt) && isset($opt['value'])) {
                    $out[] = [
                        'value' => (string) $opt['value'],
                        'label' => (string) ($opt['label'] ?? $this->labelEnum($chave, (string) $opt['value'])),
                    ];
                }
            }

            if ($out !== []) {
                return $out;
            }
        }

        $out = [];
        foreach (($def['values'] ?? []) as $valor) {
            $out[] = [
                'value' => (string) $valor,
                'label' => $this->labelEnum($chave, (string) $valor),
            ];
        }

        return $out;
    }

    public function render(ExecucaoProgressoPresenter $progresso)
    {
        $precisaSelecionar = OperadoraContext::superAdminPrecisaSelecionarEscritorio();

        $empresas = collect();
        $integracoes = collect();
        $consultasSalvas = collect();
        $schema = [];
        $ultima = null;
        $logs = collect();
        $artefatos = collect();
        $pipeline = [];
        $erros = collect();
        $tiposDisponiveis = [];

        if (!$precisaSelecionar && OperadoraContext::id()) {
            $empresas = Empresa::query()
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(['id', 'nome', 'cnpj']);

            if ($this->empresa_id) {
                $integracoes = EmpresaIntegracao::query()
                    ->with('portal')
                    ->where('empresa_id', $this->empresa_id)
                    ->where('ativo', true)
                    ->get();
            }

            if ($this->empresa_integracao_id) {
                $integracao = $integracoes->firstWhere('id', (int) $this->empresa_integracao_id)
                    ?: EmpresaIntegracao::with('portal')->find($this->empresa_integracao_id);

                if ($integracao) {
                    $codigos = PortalRecurso::query()
                        ->where('portal_integracao_id', $integracao->portal_integracao_id)
                        ->where('ativo', true)
                        ->pluck('codigo');

                    if ($codigos->contains('nfe_emitidas') || $codigos->contains('nfce_emitidas')) {
                        $tiposDisponiveis[] = [
                            'value' => 'extrato_nfe_nfce',
                            'label' => 'Extrato NF-e/NFC-e',
                            'hint' => 'Consulta com filtros do portal (modelo, período, operação e situação).',
                        ];
                    }

                    if ($codigos->contains('nfse_emitidas')) {
                        $tiposDisponiveis[] = [
                            'value' => 'extrato_nfse_emitidas',
                            'label' => 'NFS-e emitidas',
                            'hint' => 'Listagem do Emissor Nacional (período até 30 dias; chaves no HTML).',
                        ];
                    }

                    if ($codigos->contains('nfse_recebidas')) {
                        $tiposDisponiveis[] = [
                            'value' => 'extrato_nfse_recebidas',
                            'label' => 'NFS-e recebidas',
                            'hint' => 'Listagem do Emissor Nacional (período até 30 dias; chaves no HTML).',
                        ];
                    }

                    if ($codigos->contains('validar_acesso')) {
                        $tiposDisponiveis[] = [
                            'value' => 'validar_acesso',
                            'label' => 'Validar acesso',
                            'hint' => 'Testa o certificado digital no portal, sem extrair documentos.',
                        ];
                    }
                }
            }

            if ($this->portal_recurso_id) {
                $recurso = PortalRecurso::find($this->portal_recurso_id);
                $schema = $this->ordenarSchema((array) ($recurso?->parametros_schema ?? []));
            }

            if (in_array($this->tipo_consulta, ['extrato_nfe_nfce', 'extrato_nfse_emitidas', 'extrato_nfse_recebidas'], true)
                && $this->empresa_integracao_id
                && $this->portal_recurso_id) {
                $consultasSalvas = AutomacaoConsultaSalva::query()
                    ->where('empresa_integracao_id', $this->empresa_integracao_id)
                    ->where('portal_recurso_id', $this->portal_recurso_id)
                    ->orderBy('nome')
                    ->get(['id', 'nome']);
            }

            if ($this->ultima_execucao_id) {
                $ultima = AutomacaoExecucao::query()
                    ->with(['portalRecurso.portal', 'empresa', 'artefatos'])
                    ->find($this->ultima_execucao_id);

                if ($ultima) {
                    $logs = $ultima->logs()->orderBy('id')->limit(200)->get();
                    $artefatos = $ultima->artefatos;
                    $pipeline = $progresso->montarPipeline($ultima, $logs);
                    $erros = $logs->filter(fn ($log) => $log->nivel === 'error'
                        || in_array((string) $log->etapa, ['RUN_FAILED', 'JOB_FAILED', 'erro'], true)
                        || str_contains(mb_strtolower((string) $log->mensagem), 'falha')
                        || str_contains(mb_strtolower((string) $log->mensagem), 'erro'));

                    // Sucesso com consulta vazia: não exibir falso positivo de importação.
                    if (in_array($ultima->status, ['sucesso', 'sucesso_parcial'], true)) {
                        $erros = $erros->reject(function ($log) {
                            $msg = mb_strtolower((string) $log->mensagem);
                            $etapa = (string) ($log->etapa ?? '');

                            return $etapa === 'IMPORT_FAILED'
                                || str_contains($msg, 'cabeçalho incompatível')
                                || str_contains($msg, 'extratonfe-vazio')
                                || str_contains($msg, 'nenhuma nf-e')
                                || str_contains($msg, 'não foram localizadas nfes');
                        })->values();
                    }
                }
            }
        }

        $artefatoService = app(AutomacaoArtefatoService::class);
        $screenshots = $artefatos
            ->filter(fn ($a) => $artefatoService->ehImagem($a))
            ->values();
        $ultimoScreenshot = $screenshots->last();

        return view('livewire.automacao-fiscal.executar-consulta-fiscal', [
            'avisoFila' => app(FilaAutomacoesStatus::class)->avisoDesenvolvimento(),
            'precisaSelecionarEscritorio' => $precisaSelecionar,
            'empresas' => $empresas,
            'integracoes' => $integracoes,
            'tiposDisponiveis' => $tiposDisponiveis,
            'consultasSalvas' => $consultasSalvas,
            'schema' => $schema,
            'ultima' => $ultima,
            'logs' => $logs,
            'artefatos' => $artefatos,
            'screenshots' => $screenshots,
            'ultimoScreenshot' => $ultimoScreenshot,
            'pipeline' => $pipeline,
            'erros' => $erros,
            'progresso' => $progresso,
            'emAndamento' => $ultima ? $progresso->emAndamento($ultima->status) : false,
            'fakeMode' => (bool) config('automacao_fiscal.fake_mode', true),
        ]);
    }
}
