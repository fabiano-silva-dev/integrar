<?php

namespace App\Livewire\Nfse;

use App\Models\Nfse\Importacao;
use App\Models\Nfse\LayoutImportacao;
use App\Models\Nfse\Servico;
use App\Services\Nfse\ImportacaoPlanilha;
use App\Services\Nfse\PreparacaoNfse;
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
    ];

    #[Locked]
    public array $colunas = [];

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
            $this->colunas = [];
        }
    }

    private function configNormalizada(): array
    {
        return array_map(fn ($v) => $v === '' ? null : $v, $this->config);
    }

    public function lerCabecalho(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        $this->previa = [];
        $this->colunas = app(ImportacaoPlanilha::class)->ler($this->empresaId, $this->arquivo, $this->configNormalizada())['colunas'];
        foreach (['coluna_tomador', 'coluna_valor', 'coluna_servico', 'coluna_descricao'] as $campo) {
            $this->config[$campo] = null;
        }
    }

    public function conferir(): void
    {
        $this->validate(['arquivo' => 'required|file|max:2048|extensions:csv,xls,xlsx']);
        $this->previa = [];
        $resultado = app(ImportacaoPlanilha::class)->previa($this->empresaId, $this->arquivo, $this->competencia, $this->layoutId, $this->configNormalizada());
        $this->previa = array_intersect_key($resultado, array_flip(['conferencia', 'aptas', 'pendentes', 'total']));
        $this->previa['linhas'] = array_map(function ($linha) {
            $visivel = array_intersect_key($linha, array_flip(['linha', 'tomador', 'servico', 'valor', 'descricao', 'erro']));
            foreach (['tomador', 'servico', 'valor', 'descricao'] as $campo) {
                $visivel[$campo] = \Illuminate\Support\Str::limit($visivel[$campo], $campo === 'descricao' ? 4000 : 300);
            }
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
        $this->reset('arquivo', 'previa', 'colunas', 'nomeLayout');
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
