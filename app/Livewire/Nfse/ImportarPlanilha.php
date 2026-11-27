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
        if ($nome === 'nomeLayout') {
            return;
        }
        $this->previa = [];
        if ($nome === 'arquivo' || $nome === 'layoutId' || in_array($nome, ['config.cabecalho', 'config.aba', 'config.delimitador'], true)) {
            $this->colunas = $this->amostra = $this->ignoradas = [];
            $this->leitura = '';
        }
        if (in_array($nome, ['arquivo', 'layoutId'], true) && $this->arquivo && ! $this->layoutId) {
            $this->detectarColunas();
        }
    }

    private function configNormalizada(): array
    {
        return array_map(fn ($v) => $v === '' ? null : $v, $this->config);
    }

    public function detectarColunas(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        $this->resetValidation();
        $this->previa = [];
        try {
            $r = app(ImportacaoPlanilha::class)->detectar($this->empresaId, $this->arquivo);
            $this->config['cabecalho'] = $r['cabecalho'];
            $this->config['aba'] = $r['aba'];
            $this->config['delimitador'] = match ($r['delimitador']) { ',' => 'virgula', "\t" => 'tab', default => 'ponto_virgula' };
            $this->exibirLeitura($r);
        } catch (ValidationException $e) {
            // A detecção automática é uma sugestão; a leitura manual continua disponível.
            $this->addError('arquivo', collect($e->errors())->flatten()->first() ?? 'Não foi possível detectar as colunas.');
        }
    }

    private function exibirLeitura(array $r): void
    {
        $this->colunas = $r['colunas'];
        $this->amostra = array_slice($r['linhas'], 0, 5, true);
        $this->amostra = array_map(fn ($linha) => array_map(fn ($v) => \Illuminate\Support\Str::limit($v, 200), $linha), $this->amostra);
        $this->ignoradas = $r['ignoradas'];
        $this->leitura = $r['nome_aba'].' — '.count($r['linhas']).' linhas de dados';
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

    public function lerCabecalho(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        $this->previa = [];
        $this->resetValidation();
        $this->exibirLeitura(app(ImportacaoPlanilha::class)->ler($this->empresaId, $this->arquivo, $this->configNormalizada()));
    }

    public function conferir(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        $this->previa = [];
        $resultado = app(ImportacaoPlanilha::class)->previa($this->empresaId, $this->arquivo, $this->competencia, $this->layoutId, $this->configNormalizada());
        $this->previa = array_intersect_key($resultado, array_flip(['conferencia', 'aptas', 'pendentes', 'total', 'ignoradas', 'novos_tomadores']));
        $this->previa['linhas'] = array_map(function ($linha) {
            $visivel = array_intersect_key($linha, array_flip(['linha', 'tomador', 'servico', 'valor', 'descricao', 'erro', 'formulas']));
            foreach (['tomador', 'servico', 'valor', 'descricao'] as $campo) {
                $visivel[$campo] = \Illuminate\Support\Str::limit($visivel[$campo], $campo === 'descricao' ? 4000 : 300);
            }
            $visivel['novo_tomador'] = $linha['novo_tomador'] !== null && $linha['dados'] !== null;
            $visivel['endereco_integral'] = \Illuminate\Support\Str::limit($linha['cadastro']['endereco_integral'] ?? '', 500);
            return $visivel;
        }, $resultado['linhas']);
    }

    public function importar(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        if (! isset($this->previa['conferencia'])) {
            $this->addError('arquivo', 'Faça a pré-conferência primeiro.');
            return;
        }
        $registro = app(ImportacaoPlanilha::class)->importar($this->empresaId, $this->arquivo, $this->competencia, $this->layoutId, $this->configNormalizada(), $this->nomeLayout, $this->previa['conferencia']);
        $this->reset('arquivo', 'previa', 'colunas', 'amostra', 'ignoradas', 'leitura', 'nomeLayout');
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
