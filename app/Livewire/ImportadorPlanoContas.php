<?php

namespace App\Livewire;

use App\Models\Empresa;
use App\Services\Importacao\ExtratorPlanoContasPdfService;
use App\Services\Importacao\ImportadorPlanoContasService;
use App\Services\Importacao\LeitorArquivoTabularService;
use App\Services\OperadoraContext;
use App\Services\OperadoraStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class ImportadorPlanoContas extends Component
{
    use WithFileUploads;

    protected $layout = 'components.layouts.app';

    public $empresa_id = null;
    public $arquivo;
    public $estrategia = 'adicionar_atualizar';
    public $delimitador = ';';
    public $linhaCabecalho = 1;
    public $temCabecalho = true;
    public $step = 1;
    public $processando = false;

    public $colunasArquivo = [];
    public $mapeamento = [];
    public $arquivoDadosPath = '';
    public $arquivoOriginalNome = '';
    public $arquivoOriginalSalvo = '';
    public $formato = '';
    public $preview = [];
    public $arquivoEhPdf = false;

    public function mount(): void
    {
        $this->empresa_id = session('empresa_selecionada_id');
        $this->inicializarMapeamento();
    }

    private function inicializarMapeamento(): void
    {
        $this->mapeamento = array_fill_keys(array_keys(ImportadorPlanoContasService::CAMPOS), '');
    }

    public function updatedArquivo(): void
    {
        $this->arquivoEhPdf = false;

        if ($this->arquivo) {
            $this->arquivoEhPdf = strtolower($this->arquivo->getClientOriginalExtension()) === 'pdf';
        }
    }

    public function processarArquivo(): void
    {
        $this->updatedArquivo();

        $regras = [
            'arquivo' => 'required|file|extensions:csv,xls,xlsx,pdf|max:20480',
            'estrategia' => 'required|in:' . implode(',', array_keys(ImportadorPlanoContasService::ESTRATEGIAS)),
        ];

        if (!$this->arquivoEhPdf) {
            $regras['linhaCabecalho'] = 'required|integer|min:1|max:100';
        }

        $this->validate($regras);

        if (!$this->empresa_id || !OperadoraContext::resolveEmpresa($this->empresa_id)) {
            $this->addError('arquivo', 'Selecione uma empresa válida no cabeçalho.');
            return;
        }

        $this->processando = true;

        try {
            $extensao = strtolower($this->arquivo->getClientOriginalExtension());
            if ($extensao === 'pdf') {
                $extrator = new ExtratorPlanoContasPdfService();
                $dados = $extrator->extrairDominio($this->arquivo->getRealPath());
            } else {
                $leitor = new LeitorArquivoTabularService();
                $dados = $leitor->ler(
                    $this->arquivo->getRealPath(),
                    $extensao,
                    (int) $this->linhaCabecalho,
                    $this->delimitador,
                    $this->temCabecalho
                );
            }

            if ($dados['colunas'] === []) {
                $this->addError('arquivo', 'Não foi possível identificar colunas no arquivo.');
                return;
            }

            $this->limparArquivoDadosAnterior();

            $nomeJson = 'plano_contas_' . Str::uuid() . '.json';
            $relativePath = OperadoraStorage::put('temp', $nomeJson, json_encode($dados, JSON_UNESCAPED_UNICODE));

            $this->arquivoDadosPath = $relativePath;
            $this->arquivoOriginalNome = $this->arquivo->getClientOriginalName();
            $this->formato = $extensao;

            $nomeSalvo = time() . '_' . Str::slug(pathinfo($this->arquivoOriginalNome, PATHINFO_FILENAME)) . '.' . $extensao;
            OperadoraStorage::put('imports/plano_contas', $nomeSalvo, file_get_contents($this->arquivo->getRealPath()));
            $this->arquivoOriginalSalvo = $nomeSalvo;

            $this->colunasArquivo = $dados['colunas'];

            $service = new ImportadorPlanoContasService();
            $this->mapeamento = $service->sugerirMapeamento($this->colunasArquivo);

            if ($extensao === 'pdf') {
                foreach ($this->colunasArquivo as $coluna) {
                    if (array_key_exists($coluna, $this->mapeamento)) {
                        $this->mapeamento[$coluna] = $coluna;
                    }
                }
                $this->gerarPrevia();
            } else {
                $this->step = 3;
            }
        } catch (\Throwable $e) {
            $this->addError('arquivo', 'Erro ao ler arquivo: ' . $e->getMessage());
        } finally {
            $this->processando = false;
        }
    }

    public function gerarPrevia(): void
    {
        if (!$this->empresa_id) {
            $this->addError('mapeamento.codigo', 'Selecione uma empresa no cabeçalho.');
            return;
        }

        $dados = $this->carregarDadosArquivo();
        if ($dados === null) {
            return;
        }

        try {
            $service = new ImportadorPlanoContasService();
            $this->preview = $service->analisar(
                $dados['linhas'],
                $this->mapeamento,
                (int) $this->empresa_id,
                $this->estrategia
            );
            $this->step = 4;
        } catch (\InvalidArgumentException $e) {
            $this->addError('mapeamento.codigo', $e->getMessage());
        }
    }

    public function proximoPasso(): void
    {
        $this->validate([
            'arquivo' => 'required|file|extensions:csv,xls,xlsx,pdf|max:20480',
        ]);

        if (!$this->empresa_id || !OperadoraContext::resolveEmpresa($this->empresa_id)) {
            $this->addError('arquivo', 'Selecione uma empresa válida no cabeçalho.');
            return;
        }

        $this->updatedArquivo();
        $this->step = 2;
    }

    public function passoAnterior(): void
    {
        if ($this->step <= 1) {
            return;
        }

        if ($this->step === 4) {
            $this->preview = [];
            $this->step = $this->formato === 'pdf' ? 2 : 3;

            return;
        }

        if ($this->step === 3) {
            $this->limparArquivoDadosAnterior();
            $this->colunasArquivo = [];
            $this->inicializarMapeamento();
            $this->preview = [];
        }

        $this->step--;
    }

    public function barraProgresso(): int
    {
        return match ($this->step) {
            4 => 3,
            3 => 2,
            default => 1,
        };
    }

    public function descricaoFormatos(): string
    {
        return 'CSV, XLS, XLSX ou PDF — até 20 MB';
    }

    public function confirmarImportacao(): void
    {
        if (!$this->empresa_id || empty($this->preview)) {
            $this->addError('arquivo', 'Gere a prévia antes de confirmar.');
            return;
        }

        $this->processando = true;

        try {
            $arquivoSalvo = $this->arquivoOriginalSalvo ?: $this->arquivoOriginalNome;

            $service = new ImportadorPlanoContasService();
            $importacao = $service->persistir(
                $this->preview['contas'] ?? [],
                (int) $this->empresa_id,
                $this->estrategia,
                $arquivoSalvo,
                $this->formato,
                $this->preview['erros'] ?? []
            );

            $this->limparArquivoDadosAnterior();
            session()->flash('message', $this->mensagemSucesso($importacao));
            $this->redirect(route('plano-contas'));
        } catch (\Throwable $e) {
            $this->addError('arquivo', 'Erro ao importar: ' . $e->getMessage());
        } finally {
            $this->processando = false;
        }
    }

    public function voltar(int $step): void
    {
        $this->step = max(1, $step);
        if ($step <= 3) {
            $this->preview = [];
        }
    }

    public function render()
    {
        $empresaAtual = $this->empresa_id ? Empresa::find($this->empresa_id) : null;

        return view('livewire.importador-plano-contas', [
            'empresaAtual' => $empresaAtual,
            'campos' => ImportadorPlanoContasService::CAMPOS,
            'estrategias' => ImportadorPlanoContasService::ESTRATEGIAS,
        ]);
    }

    private function carregarDadosArquivo(): ?array
    {
        if ($this->arquivoDadosPath === '' || !Storage::exists($this->arquivoDadosPath)) {
            $this->addError('arquivo', 'Dados do arquivo expiraram. Faça o upload novamente.');
            $this->step = 1;
            return null;
        }

        $json = Storage::get($this->arquivoDadosPath);
        $dados = json_decode($json, true);

        if (!is_array($dados) || !isset($dados['linhas'])) {
            $this->addError('arquivo', 'Arquivo de pré-processamento inválido.');
            return null;
        }

        return $dados;
    }

    private function limparArquivoDadosAnterior(): void
    {
        if ($this->arquivoDadosPath !== '' && Storage::exists($this->arquivoDadosPath)) {
            Storage::delete($this->arquivoDadosPath);
        }
        $this->arquivoDadosPath = '';
    }

    private function mensagemSucesso($importacao): string
    {
        if ($this->estrategia === 'validar_apenas') {
            return sprintf(
                'Validação concluída: %d novas, %d atualizações simuladas, %d erros.',
                $importacao->contas_novas,
                $importacao->contas_atualizadas,
                $importacao->linhas_erro
            );
        }

        return sprintf(
            'Importação concluída: %d novas, %d atualizadas, %d inativadas, %d erros.',
            $importacao->contas_novas,
            $importacao->contas_atualizadas,
            $importacao->contas_inativadas,
            $importacao->linhas_erro
        );
    }
}
