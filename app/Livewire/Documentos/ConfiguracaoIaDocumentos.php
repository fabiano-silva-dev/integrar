<?php

namespace App\Livewire\Documentos;

use App\Models\Documentos\ConfiguracaoIaDocumento;
use App\Services\Documentos\CredenciaisIaDocumentoService;
use App\Services\Documentos\TestarCredencialIaDocumentoService;
use App\Services\OperadoraContext;
use Livewire\Component;

class ConfiguracaoIaDocumentos extends Component
{
    use AutorizaModuloDocumentos;

    protected $layout = 'components.layouts.app';

    public string $geminiApiKey = '';

    public string $groqApiKey = '';

    public string $llamaCloudApiKey = '';

    public ?string $erro = null;

    /** @var array{ok: bool, mensagem: string}|null */
    public ?array $testeGemini = null;

    /** @var array{ok: bool, mensagem: string}|null */
    public ?array $testeGroq = null;

    /** @var array{ok: bool, mensagem: string}|null */
    public ?array $testeLlama = null;

    public function mount(): void
    {
        $this->garantirAcessoDocumentos();
    }

    public function testarGemini(CredenciaisIaDocumentoService $credenciais, TestarCredencialIaDocumentoService $teste): void
    {
        $this->testeGemini = $this->executarTeste('gemini', $this->geminiApiKey, $credenciais, $teste);
    }

    public function testarGroq(CredenciaisIaDocumentoService $credenciais, TestarCredencialIaDocumentoService $teste): void
    {
        $this->testeGroq = $this->executarTeste('groq', $this->groqApiKey, $credenciais, $teste);
    }

    public function testarLlamaParse(CredenciaisIaDocumentoService $credenciais, TestarCredencialIaDocumentoService $teste): void
    {
        $this->testeLlama = $this->executarTeste('llama_cloud', $this->llamaCloudApiKey, $credenciais, $teste);
    }

    public function salvar(CredenciaisIaDocumentoService $credenciais): void
    {
        $this->garantirAcessoDocumentos();
        $this->erro = null;
        $this->testeGemini = null;
        $this->testeGroq = null;
        $this->testeLlama = null;

        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        $this->validate([
            'geminiApiKey' => 'nullable|string|max:500',
            'groqApiKey' => 'nullable|string|max:500',
            'llamaCloudApiKey' => 'nullable|string|max:500',
        ]);

        try {
            $credenciais->salvar(
                OperadoraContext::requireId(),
                trim($this->geminiApiKey),
                trim($this->groqApiKey),
                trim($this->llamaCloudApiKey),
            );
            $this->geminiApiKey = '';
            $this->groqApiKey = '';
            $this->llamaCloudApiKey = '';
            session()->flash('message', 'Chaves de leitura de documentos salvas.');
        } catch (\Throwable $exception) {
            $this->erro = $exception->getMessage();
        }
    }

    public function render(CredenciaisIaDocumentoService $credenciais)
    {
        $status = ['gemini' => false, 'groq' => false, 'llama_cloud' => false];
        $cfg = null;

        if (! $this->precisaSelecionarEscritorio() && OperadoraContext::id()) {
            $status = $credenciais->status(OperadoraContext::id());
            $cfg = ConfiguracaoIaDocumento::daOperadora();
        }

        return view('livewire.documentos.configuracao-ia-documentos', [
            'precisaSelecionarEscritorio' => $this->precisaSelecionarEscritorio(),
            'status' => $status,
            'configuradoEm' => $cfg?->configurado_em,
        ]);
    }

    /**
     * @return array{ok: bool, mensagem: string}
     */
    private function executarTeste(
        string $provedor,
        string $digitada,
        CredenciaisIaDocumentoService $credenciais,
        TestarCredencialIaDocumentoService $teste,
    ): array {
        $this->garantirAcessoDocumentos();

        if ($this->precisaSelecionarEscritorio()) {
            return ['ok' => false, 'mensagem' => 'Selecione um escritório no menu superior para testar.'];
        }

        $chave = trim($digitada);

        if ($chave === '') {
            $chave = $credenciais->credenciais(OperadoraContext::id())[$provedor] ?? '';
        }

        return $teste->testar($provedor, $chave);
    }
}
