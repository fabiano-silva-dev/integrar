<?php

namespace App\Livewire\Nfse;

use App\Models\Nfse\Importacao;
use App\Models\Nfse\LayoutImportacao;
use App\Models\Nfse\Servico;
use App\Services\Nfse\ImportacaoPlanilha;
use App\Services\Nfse\LeitorPlanilha;
use App\Services\Nfse\PreparacaoNfse;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class ImportarPlanilha extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $empresaId;

    public $arquivo;
    public string $competencia = '';
    public ?int $layoutId = null;
    public string $nomeLayout = '';
    public array $config = [
        'cabecalho' => 1, 'aba' => 0, 'delimitador' => 'ponto_virgula', 'identificacao' => 'documento',
        'formato_valor' => 'br', 'coluna_tomador' => null, 'coluna_valor' => null,
        'coluna_servico' => null, 'coluna_descricao' => null, 'servico_fixo' => null,
        'cadastrar_tomadores' => false, 'coluna_nome' => null, 'coluna_endereco' => null,
        'coluna_cep' => null, 'coluna_logradouro' => null, 'coluna_numero' => null, 'coluna_bairro' => null,
        'coluna_complemento' => null, 'coluna_municipio' => null, 'coluna_uf' => null, 'coluna_email' => null, 'coluna_telefone' => null,
    ];

    #[Locked]
    public array $colunas = [];

    #[Locked]
    public array $amostra = [];

    #[Locked]
    public string $leitura = '';

    #[Locked]
    public array $ignoradas = [];

    #[Locked]
    public array $abas = [];

    #[Locked]
    public string $tipoArquivo = '';

    #[Locked]
    public array $ajustes = [];

    #[Locked]
    public ?int $linhaEdicao = null;

    public array $edicao = [];

    #[Locked]
    public int $uploadVersao = 0;

    #[Locked]
    public array $previa = [];

    #[Locked]
    public ?int $historicoId = null;

    public function mount(int $empresaId, ?string $mesInicial = null): void
    {
        $this->empresaId = app(PreparacaoNfse::class)->empresa($empresaId)->id;
        $this->competencia = $mesInicial && preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $mesInicial) && $mesInicial !== now()->format('Y-m')
            ? $mesInicial.'-01' : now()->toDateString();
    }

    public function updated(string $nome): void
    {
        if (in_array($nome, ['nomeLayout', 'edicao'], true) || str_starts_with($nome, 'edicao.')) {
            return;
        }
        $this->previa = [];
        if (in_array($nome, ['arquivo', 'layoutId'], true)) {
            $this->limparLeitura();
            if ($this->arquivo) {
                $this->carregarArquivo();
            }
            return;
        }
        if (in_array($nome, ['config.cabecalho', 'config.aba', 'config.delimitador'], true)) {
            $this->limparLeitura(false);
            if ($nome === 'config.aba' && $this->arquivo && ! $this->layoutId) {
                $this->detectarColunas((int) $this->config['aba']);
            }
        }
        if ($nome === 'config.coluna_servico' && filled($this->config['coluna_servico'])) {
            $this->config['servico_fixo'] = null;
        }
        if ($nome === 'config.servico_fixo' && filled($this->config['servico_fixo'])) {
            $this->config['coluna_servico'] = null;
        }
    }

    private function limparLeitura(bool $metadados = true): void
    {
        $this->colunas = $this->amostra = $this->ignoradas = $this->ajustes = $this->previa = [];
        $this->leitura = '';
        $this->fecharEdicao();
        if ($metadados) {
            $this->abas = [];
            $this->tipoArquivo = '';
        }
    }

    private function carregarArquivo(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        $this->resetValidation();
        $this->tipoArquivo = strtolower($this->arquivo->getClientOriginalExtension());
        $svc = app(ImportacaoPlanilha::class);
        $this->abas = $svc->abas($this->empresaId, $this->arquivo);
        if (! $this->layoutId) {
            $this->detectarColunas();
            return;
        }
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $layout = LayoutImportacao::daEmpresa($empresa)->findOrFail($this->layoutId);
        $this->reset('config');
        $this->config = array_replace($this->config, $layout->configuracao);
        $r = $svc->ler($this->empresaId, $this->arquivo, $this->configNormalizada());
        if ($layout->assinatura !== $r['assinatura'] || ($this->config['tipo'] ?? '') !== $this->tipoArquivo) {
            $this->addError('arquivo', 'O arquivo não corresponde ao layout salvo. Escolha Novo layout para configurar.');
            return;
        }
        $this->exibirLeitura($r, false);
    }

    public function cancelar(): void
    {
        $this->reset('arquivo', 'layoutId', 'nomeLayout', 'config');
        $this->limparLeitura();
        $this->uploadVersao++;
        $this->resetValidation();
    }

    public function ajustarLayout(): void
    {
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        abort_unless($this->layoutId && $this->colunas, 422);
        $layout = LayoutImportacao::daEmpresa($empresa)->findOrFail($this->layoutId);
        $this->layoutId = null;
        $this->nomeLayout = $layout->nome;
        $this->previa = [];
        $this->resetValidation();
    }

    private function configNormalizada(): array
    {
        return array_map(fn ($v) => $v === '' ? null : $v, $this->config);
    }

    public function detectarColunas(?int $aba = null): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        $this->resetValidation();
        abort_if($this->layoutId, 422);
        $this->limparLeitura(false);
        $this->tipoArquivo = strtolower($this->arquivo->getClientOriginalExtension());
        try {
            $svc = app(ImportacaoPlanilha::class);
            $this->abas = $svc->abas($this->empresaId, $this->arquivo);
            $r = $svc->detectar($this->empresaId, $this->arquivo, $aba);
            $this->config['cabecalho'] = $r['cabecalho'];
            $this->config['aba'] = $r['aba'];
            $this->config['delimitador'] = match ($r['delimitador']) { ',' => 'virgula', "\t" => 'tab', default => 'ponto_virgula' };
            $this->exibirLeitura($r);
        } catch (ValidationException $e) {
            // A detecção automática é uma sugestão; a leitura manual continua disponível.
            $this->addError('arquivo', collect($e->errors())->flatten()->first() ?? 'Não foi possível detectar as colunas.');
        }
    }

    private function exibirLeitura(array $r, bool $sugerir = true): void
    {
        $this->colunas = $r['colunas'];
        $this->atualizarGrade($r);
        $this->ignoradas = $r['ignoradas'];
        $this->leitura = $r['nome_aba'].' — '.count($r['linhas']).' linhas de dados';
        if (! $sugerir) {
            return;
        }
        foreach (['coluna_tomador', 'coluna_valor', 'coluna_servico', 'coluna_descricao', ...array_keys(ImportacaoPlanilha::CAMPOS_OPCIONAIS)] as $campo) {
            $this->config[$campo] = null;
        }
        foreach (app(LeitorPlanilha::class)->sugerir($this->colunas) as $campo => $indice) {
            $this->config[$campo] = $indice;
        }
        $this->config['servico_fixo'] = null;
        if (isset($this->config['coluna_tomador'])) {
            $this->config['identificacao'] = 'documento';
        }
        $this->config['cadastrar_tomadores'] = false;
        if (! isset($this->config['coluna_servico'])) {
            $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
            $servicos = Servico::daEmpresa($empresa)->where('ativo', true)->limit(2)->pluck('codigo');
            if ($servicos->count() === 1) {
                $this->config['servico_fixo'] = $servicos->first();
            }
        }
        if ($this->nomeLayout === '') {
            $this->nomeLayout = 'Planilha de serviços';
        }
    }

    private function atualizarGrade(array $planilha): void
    {
        // Todas as linhas ficam acessíveis. Textos longos têm versão integral no editor.
        $this->amostra = array_map(fn ($linha) => array_map(fn ($v) => \Illuminate\Support\Str::limit($v, 250), $linha), $planilha['linhas']);
    }

    private function planilhaOriginal(): array
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        abort_unless($this->colunas, 422);
        $svc = app(ImportacaoPlanilha::class);
        $config = $this->layoutId ? $svc->configuracao($this->empresaId, $this->layoutId, []) : $this->configNormalizada();
        $r = $svc->ler($this->empresaId, $this->arquivo, $config);
        abort_unless($r['colunas'] === $this->colunas, 422, 'A estrutura da planilha mudou. Leia novamente.');
        return $r;
    }

    public function editarLinha(int $linha): void
    {
        $r = $this->planilhaOriginal();
        app(ImportacaoPlanilha::class)->aplicarAjustes($r, $this->ajustes);
        abort_unless(isset($r['linhas'][$linha]), 404);
        $this->linhaEdicao = $linha;
        $this->edicao = $r['linhas'][$linha];
    }

    public function salvarEdicao(): void
    {
        $r = $this->planilhaOriginal();
        $linha = $this->linhaEdicao;
        abort_unless($linha !== null && isset($r['linhas'][$linha]), 404);
        if (array_keys($this->edicao) !== array_keys($r['linhas'][$linha])) {
            $this->addError('edicao', 'As colunas da edição não correspondem à planilha.');
            return;
        }
        $ajustes = $this->ajustes;
        $ajustes[$linha] = array_map(fn ($v) => $v === null ? '' : $v, $this->edicao);
        $historico = app(ImportacaoPlanilha::class)->aplicarAjustes($r, $ajustes);
        $this->ajustes = array_map(fn ($celulas) => array_map(fn ($c) => $c['ajustado'], $celulas), $historico);
        $this->atualizarGrade($r);
        $this->previa = [];
        $this->fecharEdicao();
        $this->resetValidation();
    }

    public function desfazerLinha(int $linha): void
    {
        $r = $this->planilhaOriginal();
        abort_unless(isset($r['linhas'][$linha]), 404);
        unset($this->ajustes[$linha]);
        app(ImportacaoPlanilha::class)->aplicarAjustes($r, $this->ajustes);
        $this->atualizarGrade($r);
        $this->previa = [];
        $this->fecharEdicao();
        $this->resetValidation();
    }

    public function fecharEdicao(): void
    {
        $this->linhaEdicao = null;
        $this->edicao = [];
    }

    public function lerCabecalho(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        abort_if($this->layoutId, 422);
        $this->limparLeitura(false);
        $this->resetValidation();
        $this->exibirLeitura(app(ImportacaoPlanilha::class)->ler($this->empresaId, $this->arquivo, $this->configNormalizada()));
    }

    public function conferir(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        abort_unless($this->colunas && $this->linhaEdicao === null, 422, 'Conclua a edição e leia a planilha.');
        $this->previa = [];
        $resultado = app(ImportacaoPlanilha::class)->previa($this->empresaId, $this->arquivo, $this->competencia, $this->layoutId, $this->configNormalizada(), $this->ajustes);
        $this->previa = array_intersect_key($resultado, array_flip(['conferencia', 'aptas', 'pendentes', 'total', 'ignoradas', 'novos_tomadores']));
        $this->previa['linhas'] = array_map(function ($linha) {
            $visivel = array_intersect_key($linha, array_flip(['linha', 'tomador', 'servico', 'valor', 'descricao', 'erro', 'formulas']));
            foreach (['tomador', 'servico', 'valor', 'descricao'] as $campo) {
                $visivel[$campo] = \Illuminate\Support\Str::limit($visivel[$campo], $campo === 'descricao' ? 4000 : 300);
            }
            $visivel['ajustada'] = ! empty($linha['ajustes']);
            $visivel['novo_tomador'] = $linha['novo_tomador'] !== null && $linha['dados'] !== null;
            $visivel['endereco_integral'] = \Illuminate\Support\Str::limit($linha['cadastro']['endereco_integral'] ?? '', 500);
            return $visivel;
        }, $resultado['linhas']);
    }

    public function importar(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        if ($this->linhaEdicao !== null || ! isset($this->previa['conferencia'])) {
            $this->addError('arquivo', 'Faça a pré-conferência primeiro.');
            return;
        }
        $registro = app(ImportacaoPlanilha::class)->importar($this->empresaId, $this->arquivo, $this->competencia, $this->layoutId, $this->configNormalizada(), $this->nomeLayout, $this->previa['conferencia'], $this->ajustes);
        $this->reset('arquivo', 'nomeLayout');
        $this->limparLeitura();
        $this->uploadVersao++;
        $this->layoutId = $registro->layout_id;
        $this->historicoId = $registro->id;
        session()->flash('nfse_importacao_mensagem', "{$registro->quantidade_gerada} rascunhos gerados. Nenhuma nota transmitida.");
    }

    public function abrirHistorico(int $id): void
    {
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $this->historicoId = Importacao::daEmpresa($empresa)->findOrFail($id)->id;
    }

    public function fecharHistorico(): void
    {
        $this->historicoId = null;
    }

    public function render()
    {
        $empresa = app(PreparacaoNfse::class)->empresa($this->empresaId);
        $layouts = LayoutImportacao::daEmpresa($empresa)->orderBy('nome')->orderByDesc('versao')->get();
        $servicos = Servico::daEmpresa($empresa)->where('ativo', true)->orderBy('nome')->get();
        $historico = Importacao::daEmpresa($empresa)->latest('id')->limit(20)->get();
        $detalhe = $this->historicoId ? Importacao::daEmpresa($empresa)->findOrFail($this->historicoId) : null;
        return view('livewire.nfse.importar-planilha', compact('layouts', 'servicos', 'historico', 'detalhe'));
    }
}
