<?php

namespace App\Livewire\AutomacaoFiscal;

use App\Models\ImportacaoEmpresa;
use App\Services\AutomacaoFiscal\ImportacaoEmpresasService;
use App\Services\OperadoraContext;
use App\Services\OperadoraStorage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class ImportadorEmpresas extends Component
{
    use WithFileUploads;

    protected $layout = 'components.layouts.app';

    public $arquivo;
    public string $delimitador = ';';
    public int $step = 1;
    public array $colunasArquivo = [];
    public array $mapeamento = [];
    public array $previewItens = [];
    public array $previewResumo = [];
    public string $arquivoDadosPath = '';
    public string $arquivoOriginalNome = '';
    public ?int $importacaoId = null;

    public function mount(): void
    {
        $this->mapeamento = array_fill_keys(array_keys(ImportacaoEmpresasService::CAMPOS), '');
    }

    public function processarArquivo(ImportacaoEmpresasService $service): void
    {
        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            $this->addError('arquivo', 'Selecione um escritório no menu superior.');

            return;
        }

        $this->validate([
            'arquivo' => 'required|file|extensions:csv,xls,xlsx|max:10240',
            'delimitador' => 'required|string|max:1',
        ]);

        $extensao = strtolower($this->arquivo->getClientOriginalExtension());
        $operadoraId = OperadoraContext::requireId();
        $nomeSalvo = Str::uuid() . '.' . $extensao;
        $relative = OperadoraStorage::put(
            'automacao-fiscal/importacoes-empresas',
            $nomeSalvo,
            file_get_contents($this->arquivo->getRealPath()),
            $operadoraId
        );

        $dados = $service->lerArquivo(Storage::path($relative), $extensao, $this->delimitador);
        $this->colunasArquivo = $dados['colunas'];
        $this->mapeamento = $service->detectarMapeamento($dados['colunas']);
        $this->arquivoDadosPath = $relative;
        $this->arquivoOriginalNome = $this->arquivo->getClientOriginalName();
        $this->step = 2;
    }

    public function gerarPrevia(ImportacaoEmpresasService $service): void
    {
        $this->validate([
            'mapeamento.cnpj' => 'required|string',
        ], [], ['mapeamento.cnpj' => 'CNPJ']);

        $extensao = pathinfo($this->arquivoDadosPath, PATHINFO_EXTENSION);
        $dados = $service->lerArquivo(Storage::path($this->arquivoDadosPath), $extensao, $this->delimitador);
        $previa = $service->gerarPrevia($dados['linhas'], $this->mapeamento, OperadoraContext::requireId());
        $this->previewItens = $previa['itens'];
        $this->previewResumo = $previa['resumo'];
        $this->step = 3;
    }

    public function confirmar(ImportacaoEmpresasService $service): void
    {
        if ($this->previewItens === []) {
            $this->addError('arquivo', 'Gere a prévia antes de confirmar.');

            return;
        }

        $importacao = ImportacaoEmpresa::create([
            'empresa_operadora_id' => OperadoraContext::requireId(),
            'user_id' => Auth::id(),
            'nome_arquivo' => $this->arquivoOriginalNome,
            'storage_path' => $this->arquivoDadosPath,
            'status' => 'processando',
        ]);

        $importacao = $service->gravar($importacao, $this->previewItens, $this->mapeamento);
        $this->importacaoId = $importacao->id;
        $this->step = 4;
        session()->flash('message', $importacao->mensagem);
    }

    public function voltar(int $step): void
    {
        if ($step >= 1 && $step < $this->step && $this->step < 4) {
            $this->step = $step;
        }
    }

    public function render()
    {
        return view('livewire.automacao-fiscal.importador-empresas', [
            'campos' => ImportacaoEmpresasService::CAMPOS,
            'precisaSelecionarEscritorio' => OperadoraContext::superAdminPrecisaSelecionarEscritorio(),
        ]);
    }
}
