<?php

namespace App\Livewire;

use App\Models\Empresa;
use App\Services\Importacao\ImportadorRegrasAmarracaoService;
use App\Services\Importacao\LeitorArquivoTabularService;
use App\Services\OperadoraContext;
use App\Services\OperadoraStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class ImportadorRegrasAmarracao extends Component
{
    use WithFileUploads;

    protected $layout = 'components.layouts.app';

    public $empresa_id = null;
    public $layout_padrao = '';
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

    public function mount(): void
    {
        $this->empresa_id = session('empresa_selecionada_id');
        $this->inicializarMapeamento();

        $layout = request()->query('layout');
        if (is_string($layout) && array_key_exists($layout, GerenciadorRegrasAmarracao::getLayoutsAvancado())) {
            $this->layout_padrao = $layout;
        }
    }

    private function inicializarMapeamento(): void
    {
        $this->mapeamento = array_fill_keys(array_keys(ImportadorRegrasAmarracaoService::CAMPOS), '');
    }

    public function proximoPasso(): void
    {
        $this->validate([
            'arquivo' => 'required|file|extensions:csv,xls,xlsx|max:10240',
        ]);

        if (!$this->empresa_id || !OperadoraContext::resolveEmpresa($this->empresa_id)) {
            $this->addError('arquivo', 'Selecione uma empresa válida no cabeçalho.');
            return;
        }

        $this->step = 2;
    }

    public function passoAnterior(): void
    {
        if ($this->step <= 1) {
            return;
        }

        if ($this->step === 4) {
            $this->preview = [];
            $this->step = 3;

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
        return 'CSV, XLS ou XLSX — até 10 MB';
    }

    public function processarArquivo(): void
    {
        $this->validate([
            'arquivo' => 'required|file|extensions:csv,xls,xlsx|max:10240',
            'estrategia' => 'required|in:' . implode(',', array_keys(ImportadorRegrasAmarracaoService::ESTRATEGIAS)),
            'linhaCabecalho' => 'required|integer|min:1|max:100',
            'layout_padrao' => 'nullable|string|max:50',
        ]);

        if (!$this->empresa_id || !OperadoraContext::resolveEmpresa($this->empresa_id)) {
            $this->addError('arquivo', 'Selecione uma empresa válida no cabeçalho.');
            return;
        }

        $this->processando = true;

        try {
            $extensao = strtolower($this->arquivo->getClientOriginalExtension());
            $leitor = new LeitorArquivoTabularService();
            $dados = $leitor->ler(
                $this->arquivo->getRealPath(),
                $extensao,
                (int) $this->linhaCabecalho,
                $this->delimitador,
                $this->temCabecalho
            );

            if ($dados['colunas'] === []) {
                $this->addError('arquivo', 'Não foi possível identificar colunas no arquivo.');
                return;
            }

            $this->limparArquivoDadosAnterior();

            $nomeJson = 'regras_amarracao_' . Str::uuid() . '.json';
            $relativePath = OperadoraStorage::put('temp', $nomeJson, json_encode($dados, JSON_UNESCAPED_UNICODE));

            $this->arquivoDadosPath = $relativePath;
            $this->arquivoOriginalNome = $this->arquivo->getClientOriginalName();
            $this->formato = $extensao;

            $nomeSalvo = time() . '_' . Str::slug(pathinfo($this->arquivoOriginalNome, PATHINFO_FILENAME)) . '.' . $extensao;
            OperadoraStorage::put('imports/regras_amarracao', $nomeSalvo, file_get_contents($this->arquivo->getRealPath()));
            $this->arquivoOriginalSalvo = $nomeSalvo;

            $this->colunasArquivo = $dados['colunas'];

            $service = new ImportadorRegrasAmarracaoService();
            $this->mapeamento = $service->sugerirMapeamento($this->colunasArquivo);
            $this->step = 3;
        } catch (\Throwable $e) {
            $this->addError('arquivo', 'Erro ao ler arquivo: ' . $e->getMessage());
        } finally {
            $this->processando = false;
        }
    }

    public function gerarPrevia(): void
    {
        if (!$this->empresa_id) {
            $this->addError('mapeamento.palavra_chave', 'Selecione uma empresa no cabeçalho.');
            return;
        }

        $dados = $this->carregarDadosArquivo();
        if ($dados === null) {
            return;
        }

        try {
            $service = new ImportadorRegrasAmarracaoService();
            $this->preview = $service->analisar(
                $dados['linhas'],
                $this->mapeamento,
                (int) $this->empresa_id,
                $this->estrategia,
                $this->layout_padrao ?: null
            );
            $this->step = 4;
        } catch (\InvalidArgumentException $e) {
            $this->addError('mapeamento.palavra_chave', $e->getMessage());
        }
    }

    public function confirmarImportacao(): void
    {
        if (!$this->empresa_id || empty($this->preview)) {
            $this->addError('arquivo', 'Gere a prévia antes de confirmar.');
            return;
        }

        $this->processando = true;

        try {
            $service = new ImportadorRegrasAmarracaoService();
            $resultado = $service->persistir(
                $this->preview['regras'] ?? [],
                (int) $this->empresa_id,
                $this->estrategia
            );

            $this->limparArquivoDadosAnterior();
            session()->flash('message', $this->mensagemSucesso($resultado));

            $params = $this->layout_padrao ? ['layout' => $this->layout_padrao] : [];
            $this->redirect(route('regras-amarracao', $params), navigate: true);
        } catch (\Throwable $e) {
            $this->addError('arquivo', 'Erro ao importar: ' . $e->getMessage());
        } finally {
            $this->processando = false;
        }
    }

    public function render()
    {
        $empresaAtual = $this->empresa_id ? Empresa::find($this->empresa_id) : null;
        $layouts = GerenciadorRegrasAmarracao::getLayoutsAvancado();
        $paramsVoltar = $this->layout_padrao ? ['layout' => $this->layout_padrao] : [];

        return view('livewire.importador-regras-amarracao', [
            'empresaAtual' => $empresaAtual,
            'campos' => ImportadorRegrasAmarracaoService::CAMPOS,
            'estrategias' => ImportadorRegrasAmarracaoService::ESTRATEGIAS,
            'layouts' => $layouts,
            'layoutNome' => $this->layout_padrao ? ($layouts[$this->layout_padrao] ?? $this->layout_padrao) : null,
            'urlVoltarRegras' => route('regras-amarracao', $paramsVoltar),
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

    /**
     * @param  array{novas: int, atualizadas: int, ignoradas: int, removidas: int}  $resultado
     */
    private function mensagemSucesso(array $resultado): string
    {
        if ($this->estrategia === 'validar_apenas') {
            return sprintf(
                'Validação concluída: %d novas, %d atualizações simuladas.',
                $resultado['novas'],
                $resultado['atualizadas']
            );
        }

        return sprintf(
            'Importação concluída: %d novas, %d atualizadas, %d ignoradas%s.',
            $resultado['novas'],
            $resultado['atualizadas'],
            $resultado['ignoradas'],
            $resultado['removidas'] > 0 ? ", {$resultado['removidas']} removidas" : ''
        );
    }
}
