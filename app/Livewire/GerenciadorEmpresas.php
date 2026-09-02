<?php

namespace App\Livewire;

use App\Models\AgendaAutomacao;
use App\Models\AutomacaoExecucao;
use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\EmpresaIntegracao;
use App\Models\PortalIntegracao;
use App\Rules\CnpjValido;
use App\Models\EmpresaIntegracaoRecurso;
use App\Models\Documentos\EmpresaPastaDrive;
use App\Models\Documentos\GrupoWhatsapp;
use App\Services\AutomacaoFiscal\AutomacaoExecucaoService;
use App\Services\AutomacaoFiscal\CadastroEmpresaPorCertificadoService;
use App\Services\AutomacaoFiscal\EmpresaIntegracaoService;
use App\Services\AutomacaoFiscal\FilaAutomacoesStatus;
use App\Services\OperadoraContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class GerenciadorEmpresas extends Component
{
    use WithFileUploads;
    use WithPagination;

    protected $layout = 'components.layouts.app';

    public $nome = '';
    public $razao_social = '';
    public $nome_fantasia = '';
    public $cnpj = '';
    public $inscricao_estadual = '';
    public $inscricao_municipal = '';
    public $uf = '';
    public $codigo_municipio_ibge = '';
    public $municipio = '';
    public $codigo_sistema = '';
    public $codigo_conta_banco = '';
    public $ativo = true;
    public $empresa_id = null;
    public $modo_edicao = false;
    public $busca = '';
    public string $filtroAtivo = '';
    public string $ordenar = 'nome';
    public string $direcao = 'asc';
    public $confirmando_exclusao = false;
    public $empresa_para_excluir = null;
    public string $aba = 'cadastro';

    public bool $modalCadastroAberto = false;
    public string $modalAba = 'certificado';

    public $certificadoArquivo = null;
    public string $certificadoSenha = '';
    public bool $enviandoCertificado = false;
    public string $certificadoMensagem = '';
    public string $certificadoMensagemTipo = '';

    /** @var array<string, mixed> */
    public array $integracoesForm = [];

    protected function rules()
    {
        $operadoraId = OperadoraContext::id() ?? 0;

        return [
            'razao_social' => 'nullable|min:3|max:255',
            'nome_fantasia' => 'nullable|min:3|max:255',
            'nome' => 'nullable|min:3|max:255',
            'cnpj' => [
                'required',
                'string',
                'max:18',
                new CnpjValido(),
                Rule::unique('empresas', 'cnpj')
                    ->where('empresa_operadora_id', $operadoraId)
                    ->ignore($this->empresa_id),
            ],
            'inscricao_estadual' => 'nullable|max:32',
            'inscricao_municipal' => 'nullable|max:32',
            'uf' => 'nullable|size:2',
            'codigo_municipio_ibge' => 'nullable|max:10',
            'municipio' => 'nullable|max:255',
            'codigo_sistema' => 'nullable|max:50',
            'codigo_conta_banco' => 'nullable|max:50',
            'ativo' => 'boolean',
        ];
    }

    public function mount(): void
    {
        $this->limparFormulario();
        $this->modalCadastroAberto = false;
        $this->limparCertificado();
    }

    public function updatedBusca()
    {
        $this->resetPage();
    }

    public function updatedFiltroAtivo(): void
    {
        $this->resetPage();
    }

    public function ordenarPor(string $coluna): void
    {
        $permitidas = ['nome', 'cnpj', 'uf', 'codigo_sistema', 'ativo'];
        if (! in_array($coluna, $permitidas, true)) {
            return;
        }

        if ($this->ordenar === $coluna) {
            $this->direcao = $this->direcao === 'asc' ? 'desc' : 'asc';
        } else {
            $this->ordenar = $coluna;
            $this->direcao = 'asc';
        }

        $this->resetPage();
    }

    public function novaEmpresa(): void
    {
        $this->limparFormulario();
        $this->abrirModalCadastro('dados');
    }

    public function setAba(string $aba): void
    {
        if (!$this->modo_edicao && $aba !== 'cadastro') {
            return;
        }

        $this->aba = $aba;
    }

    public function abrirModalCadastro(string $aba = 'certificado'): void
    {
        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior para gerenciar empresas.');

            return;
        }

        if (! in_array($aba, ['dados', 'certificado', 'excel'], true)) {
            $aba = 'certificado';
        }

        $this->limparCertificado();
        $this->modalAba = $aba;
        $this->modalCadastroAberto = true;
        $this->resetValidation();
    }

    public function fecharModalCadastro(): void
    {
        $this->modalCadastroAberto = false;
        $this->limparCertificado();
    }

    public function setModalAba(string $aba): void
    {
        if (! in_array($aba, ['dados', 'certificado', 'excel'], true)) {
            return;
        }

        $this->modalAba = $aba;
    }

    public function updatedCertificadoArquivo(): void
    {
        $this->certificadoMensagem = '';
        $this->certificadoMensagemTipo = '';
        $this->certificadoSenha = '';

        $this->validate([
            'certificadoArquivo' => 'required|file|extensions:pfx,p12|max:5120',
        ], [], [
            'certificadoArquivo' => 'certificado',
        ]);
    }

    public function limparCertificado(): void
    {
        $this->certificadoArquivo = null;
        $this->certificadoSenha = '';
        $this->enviandoCertificado = false;
        $this->certificadoMensagem = '';
        $this->certificadoMensagemTipo = '';
        $this->resetValidation(['certificadoArquivo', 'certificadoSenha']);
    }

    public function cadastrarPorCertificado(CadastroEmpresaPorCertificadoService $service): void
    {
        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            $this->addError('certificadoArquivo', 'Selecione um escritório no menu superior.');

            return;
        }

        $this->validate([
            'certificadoArquivo' => 'required|file|extensions:pfx,p12|max:5120',
            'certificadoSenha' => 'required|string|max:255',
        ], [
            'certificadoSenha.required' => 'Informe a senha do certificado.',
        ], [
            'certificadoArquivo' => 'certificado',
            'certificadoSenha' => 'senha',
        ]);

        $this->enviandoCertificado = true;
        $this->certificadoMensagem = '';
        $this->certificadoMensagemTipo = '';

        try {
            $resultado = $service->cadastrar(
                $this->certificadoArquivo,
                OperadoraContext::requireId(),
                $this->certificadoSenha,
                pathinfo($this->certificadoArquivo->getClientOriginalName(), PATHINFO_FILENAME) ?: null
            );

            session()->flash(
                'message',
                $resultado['mensagem'] . ' CNPJ ' . $resultado['empresa']->cnpj . '.'
            );
            $this->fecharModalCadastro();
        } catch (\Throwable $e) {
            $this->certificadoSenha = '';
            $this->certificadoMensagem = $e->getMessage();
            $this->certificadoMensagemTipo = 'erro';
            $this->addError('certificadoSenha', $e->getMessage());
        } finally {
            $this->enviandoCertificado = false;
        }
    }

    public function salvar()
    {
        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            session()->flash('error', 'Selecione um escritório no menu superior para gerenciar empresas.');

            return;
        }

        $this->validate();

        $nome = trim((string) ($this->nome_fantasia ?: $this->razao_social ?: $this->nome));
        if ($nome === '') {
            $this->addError('nome_fantasia', 'Informe a razão social ou o nome fantasia.');

            return;
        }

        $cnpj = CnpjValido::format($this->cnpj);
        $payload = [
            'nome' => $nome,
            'razao_social' => $this->razao_social ?: $nome,
            'nome_fantasia' => $this->nome_fantasia ?: $nome,
            'cnpj' => $cnpj,
            'inscricao_estadual' => $this->inscricao_estadual ?: null,
            'inscricao_municipal' => $this->inscricao_municipal ?: null,
            'uf' => $this->uf ? strtoupper($this->uf) : null,
            'codigo_municipio_ibge' => $this->codigo_municipio_ibge ?: null,
            'municipio' => $this->municipio ?: null,
            'codigo_sistema' => $this->codigo_sistema,
            'codigo_conta_banco' => $this->codigo_conta_banco,
            'ativo' => (bool) $this->ativo,
        ];

        if ($this->modo_edicao) {
            $empresa = Empresa::findOrFail($this->empresa_id);
            $empresa->update($payload);
            session()->flash('message', 'Empresa atualizada com sucesso!');
        } else {
            Empresa::create($payload);
            session()->flash('message', 'Empresa criada com sucesso!');
            $this->limparFormulario();
        }

        if ($this->modalCadastroAberto) {
            $this->fecharModalCadastro();
        }
    }

    public function executarAgora(int $portalRecursoId, AutomacaoExecucaoService $service): void
    {
        if (!$this->modo_edicao || !$this->empresa_id) {
            return;
        }

        $mensagemFila = app(FilaAutomacoesStatus::class)->mensagemBloqueioDesenvolvimento();
        if ($mensagemFila !== null) {
            session()->flash('error', $mensagemFila);

            return;
        }

        $vinculo = EmpresaIntegracaoRecurso::query()
            ->whereHas('empresaIntegracao', fn ($q) => $q->where('empresa_id', $this->empresa_id))
            ->where('portal_recurso_id', $portalRecursoId)
            ->where('ativo', true)
            ->first();

        if (!$vinculo) {
            session()->flash('error', 'Ative o recurso e salve as integrações antes de executar.');

            return;
        }

        try {
            $execucao = $service->enfileirarManual($vinculo, userId: Auth::id());
            session()->flash('message', 'Execução enfileirada: ' . $execucao->uuid);
            $this->aba = 'historico';
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function salvarIntegracoes(EmpresaIntegracaoService $service): void
    {
        if (!$this->modo_edicao || !$this->empresa_id) {
            return;
        }

        $empresa = Empresa::findOrFail($this->empresa_id);
        $payload = [];

        foreach ($this->integracoesForm as $portalCodigo => $cfg) {
            $recursos = [];
            foreach (($cfg['recursos'] ?? []) as $recursoCodigo => $recursoCfg) {
                $recursoPayload = [
                    'ativo' => (bool) ($recursoCfg['ativo'] ?? false),
                    'agenda_automacao_id' => $recursoCfg['agenda_automacao_id'] ?: null,
                ];

                $parametros = $this->parametrosRecursoParaSalvar($recursoCodigo, $recursoCfg);
                if ($parametros !== null) {
                    $recursoPayload['parametros'] = $parametros;
                }

                $recursos[$recursoCodigo] = $recursoPayload;
            }

            $payload[] = [
                'portal_codigo' => $portalCodigo,
                'ativo' => (bool) ($cfg['ativo'] ?? false),
                'certificado_digital_id' => $cfg['certificado_digital_id'] ?: null,
                'recursos' => $recursos,
            ];
        }

        $service->sincronizar($empresa, $payload);
        $this->carregarIntegracoesForm($empresa);
        session()->flash('message', 'Integrações atualizadas.');
        $this->aba = 'integracoes';
    }

    public function editar($id)
    {
        $empresa = Empresa::findOrFail($id);
        $this->empresa_id = $empresa->id;
        $this->nome = $empresa->nome;
        $this->razao_social = $empresa->razao_social ?? '';
        $this->nome_fantasia = $empresa->nome_fantasia ?? $empresa->nome;
        $this->cnpj = $empresa->cnpj;
        $this->inscricao_estadual = $empresa->inscricao_estadual ?? '';
        $this->inscricao_municipal = $empresa->inscricao_municipal ?? '';
        $this->uf = $empresa->uf ?? '';
        $this->codigo_municipio_ibge = $empresa->codigo_municipio_ibge ?? '';
        $this->municipio = $empresa->municipio ?? '';
        $this->codigo_sistema = $empresa->codigo_sistema;
        $this->codigo_conta_banco = $empresa->codigo_conta_banco;
        $this->ativo = (bool) $empresa->ativo;
        $this->modo_edicao = true;
        $this->aba = 'cadastro';
        $this->carregarIntegracoesForm($empresa);
    }

    public function cancelarEdicao()
    {
        $this->limparFormulario();
    }

    public function confirmarExclusao($id)
    {
        $this->empresa_para_excluir = $id;
        $this->confirmando_exclusao = true;
    }

    public function excluir()
    {
        $empresa = Empresa::findOrFail($this->empresa_para_excluir);

        if ($empresa->importacoes()->count() > 0 || $empresa->lancamentos()->count() > 0) {
            session()->flash('error', 'Não é possível excluir uma empresa que possui importações ou lançamentos associados.');
            $this->confirmando_exclusao = false;
            $this->empresa_para_excluir = null;

            return;
        }

        $empresa->delete();
        session()->flash('message', 'Empresa excluída com sucesso!');
        $this->confirmando_exclusao = false;
        $this->empresa_para_excluir = null;
        $this->limparFormulario();
    }

    public function cancelarExclusao()
    {
        $this->confirmando_exclusao = false;
        $this->empresa_para_excluir = null;
    }

    private function carregarIntegracoesForm(Empresa $empresa): void
    {
        $portais = PortalIntegracao::query()
            ->with(['recursos' => fn ($q) => $q->where('ativo', true)->orderBy('nome')])
            ->where('ativo', true)
            ->orderBy('nome')
            ->get();

        $integracoes = EmpresaIntegracao::query()
            ->with(['recursos.portalRecurso'])
            ->where('empresa_id', $empresa->id)
            ->get()
            ->keyBy('portal_integracao_id');

        $form = [];
        foreach ($portais as $portal) {
            $integracao = $integracoes->get($portal->id);
            $recursosForm = [];
            foreach ($portal->recursos as $recurso) {
                if ($recurso->codigo === 'validar_acesso') {
                    continue;
                }
                $vinculo = $integracao
                    ? $integracao->recursos->firstWhere('portal_recurso_id', $recurso->id)
                    : null;
                $recursosForm[$recurso->codigo] = [
                    'ativo' => (bool) ($vinculo?->ativo ?? false),
                    'agenda_automacao_id' => $vinculo?->agenda_automacao_id,
                    'parametros' => $this->parametrosRecursoParaForm($recurso->codigo, $vinculo?->parametros),
                ];
            }

            $form[$portal->codigo] = [
                'ativo' => (bool) ($integracao?->ativo ?? false),
                'certificado_digital_id' => $integracao?->certificado_digital_id,
                'recursos' => $recursosForm,
            ];
        }

        $this->integracoesForm = $form;
    }

    /**
     * @param  array<string, mixed>  $recursoCfg
     * @return array<string, mixed>|null
     */
    private function parametrosRecursoParaSalvar(string $recursoCodigo, array $recursoCfg): ?array
    {
        if ($recursoCodigo !== 'nfe_emitidas' && $recursoCodigo !== 'nfce_emitidas') {
            return null;
        }

        $p = (array) ($recursoCfg['parametros'] ?? []);
        $modelo = (string) ($p['modelo'] ?? 'nfe');
        if (! in_array($modelo, ['nfe', 'nfce', 'ambos'], true)) {
            $modelo = 'nfe';
        }

        $operacao = (string) ($p['operacao'] ?? 'saida-consulente');
        $operacoesValidas = [
            'saida-consulente',
            'saida-terceiros',
            'entrada-consulente',
            'entrada-terceiros',
        ];
        if (! in_array($operacao, $operacoesValidas, true)) {
            $operacao = 'saida-consulente';
        }

        return [
            'modelo' => $modelo,
            'operacao' => $operacao,
            'situacao_normal' => filter_var($p['situacao_normal'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'situacao_cancelada' => filter_var($p['situacao_cancelada'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'totalizado_por_mes' => filter_var($p['totalizado_por_mes'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'excluir_venda_fora_estabelecimento' => filter_var(
                $p['excluir_venda_fora_estabelecimento'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $parametros
     * @return array<string, mixed>
     */
    private function parametrosRecursoParaForm(string $recursoCodigo, ?array $parametros): array
    {
        if ($recursoCodigo !== 'nfe_emitidas' && $recursoCodigo !== 'nfce_emitidas') {
            return is_array($parametros) ? $parametros : [];
        }

        $p = is_array($parametros) ? $parametros : [];

        return [
            'modelo' => $p['modelo'] ?? 'nfe',
            'operacao' => $p['operacao'] ?? 'saida-consulente',
            'situacao_normal' => array_key_exists('situacao_normal', $p)
                ? (bool) $p['situacao_normal']
                : true,
            'situacao_cancelada' => (bool) ($p['situacao_cancelada'] ?? false),
            'totalizado_por_mes' => (bool) ($p['totalizado_por_mes'] ?? false),
            'excluir_venda_fora_estabelecimento' => (bool) ($p['excluir_venda_fora_estabelecimento'] ?? false),
        ];
    }

    private function limparFormulario()
    {
        $this->nome = '';
        $this->razao_social = '';
        $this->nome_fantasia = '';
        $this->cnpj = '';
        $this->inscricao_estadual = '';
        $this->inscricao_municipal = '';
        $this->uf = '';
        $this->codigo_municipio_ibge = '';
        $this->municipio = '';
        $this->codigo_sistema = '';
        $this->codigo_conta_banco = '';
        $this->ativo = true;
        $this->empresa_id = null;
        $this->modo_edicao = false;
        $this->aba = 'cadastro';
        $this->integracoesForm = [];
        $this->resetValidation();
    }

    public function render()
    {
        $ordenacao = in_array($this->ordenar, ['nome', 'cnpj', 'uf', 'codigo_sistema', 'ativo'], true)
            ? $this->ordenar
            : 'nome';
        $direcao = $this->direcao === 'desc' ? 'desc' : 'asc';

        $empresas = Empresa::query()
            ->when(trim($this->busca) !== '', function ($query) {
                $termo = '%' . trim($this->busca) . '%';
                $query->where(function ($q) use ($termo) {
                    $q->where('nome', 'like', $termo)
                        ->orWhere('razao_social', 'like', $termo)
                        ->orWhere('nome_fantasia', 'like', $termo)
                        ->orWhere('cnpj', 'like', $termo)
                        ->orWhere('codigo_sistema', 'like', $termo);
                });
            })
            ->when($this->filtroAtivo !== '', function ($query) {
                $query->where('ativo', $this->filtroAtivo === '1');
            })
            ->orderBy($ordenacao, $direcao)
            ->paginate(15);

        $portais = PortalIntegracao::query()
            ->with(['recursos' => fn ($q) => $q->where('ativo', true)->orderBy('nome')])
            ->where('ativo', true)
            ->orderBy('nome')
            ->get();

        $agendas = AgendaAutomacao::query()->where('ativo', true)->orderBy('nome')->get();
        $certificados = CertificadoDigital::query()
            ->with('empresa')
            ->where('ativo', true)
            ->orderBy('nome')
            ->get();

        $execucoes = collect();
        $gruposWhatsapp = collect();
        $pastaDriveRaiz = null;
        if ($this->modo_edicao && $this->empresa_id) {
            $execucoes = AutomacaoExecucao::query()
                ->with(['portalRecurso.portal'])
                ->where('empresa_id', $this->empresa_id)
                ->orderByDesc('id')
                ->limit(20)
                ->get();
            $gruposWhatsapp = GrupoWhatsapp::query()
                ->where(function ($query) {
                    $query->where('empresa_id', $this->empresa_id)
                        ->orWhereHas('empresas', fn ($inner) => $inner->where('empresas.id', $this->empresa_id));
                })
                ->orderBy('nome')
                ->get();
            $pastaDriveRaiz = EmpresaPastaDrive::raizDaEmpresa((int) $this->empresa_id);
        }

        return view('livewire.gerenciador-empresas', [
            'empresas' => $empresas,
            'portais' => $portais,
            'agendas' => $agendas,
            'certificados' => $certificados,
            'execucoes' => $execucoes,
            'gruposWhatsapp' => $gruposWhatsapp,
            'pastaDriveRaiz' => $pastaDriveRaiz,
            'precisaSelecionarEscritorio' => OperadoraContext::superAdminPrecisaSelecionarEscritorio(),
        ]);
    }
}
