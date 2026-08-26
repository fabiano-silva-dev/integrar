<?php

namespace App\Livewire\Documentos;

use App\Enums\Documentos\StatusContaGoogle;
use App\Jobs\Documentos\ArquivarDocumentoRecebidoJob;
use App\Models\Documentos\ConfiguracaoGoogle;
use App\Models\Documentos\ContaGoogle;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\EmpresaPastaDrive;
use App\Models\Documentos\GrupoWhatsapp;
use App\Models\Empresa;
use App\Services\Documentos\GoogleDriveService;
use App\Services\Documentos\NomePastaDriveEmpresa;
use App\Services\OperadoraContext;
use Livewire\Component;

class ContaGoogleDrive extends Component
{
    use AutorizaModuloDocumentos;

    protected $layout = 'components.layouts.app';

    public ?int $empresaSeletorId = null;

    public ?string $pastaPaiId = null;

    public string $pastaPaiNome = 'Drive';

    /** @var list<array{id: string, nome: string}> */
    public array $breadcrumb = [];

    public bool $seletorAberto = false;

    public string $seletorPasso = 'nome';

    public string $pastaNome = '';

    public string $empresaSeletorNome = '';

    public ?string $erro = null;

    public string $googleClientId = '';

    public string $googleClientSecret = '';

    public bool $editandoApp = false;

    public function mount(): void
    {
        $this->garantirAcessoDocumentos();

        if ($this->precisaSelecionarEscritorio() || ! OperadoraContext::id()) {
            return;
        }

        $cfg = ConfiguracaoGoogle::daOperadora();
        if ($cfg?->pronta()) {
            $this->googleClientId = (string) $cfg->client_id;
        }
    }

    public function salvarAplicativo(GoogleDriveService $drive): void
    {
        $this->garantirAcessoDocumentos();
        $this->erro = null;

        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        $this->validate([
            'googleClientId' => 'required|string|min:12|max:255',
            'googleClientSecret' => $drive->configurado() ? 'nullable|string|max:255' : 'required|string|min:8|max:255',
        ], [
            'googleClientId.required' => 'Informe o ID do cliente.',
            'googleClientSecret.required' => 'Informe a chave secreta.',
        ], [
            'googleClientId' => 'ID do cliente',
            'googleClientSecret' => 'chave secreta',
        ]);

        try {
            $drive->salvarCredenciais(
                OperadoraContext::requireId(),
                trim($this->googleClientId),
                trim($this->googleClientSecret),
            );
            $this->googleClientSecret = '';
            $this->editandoApp = false;
            session()->flash('message', 'Aplicativo Google salvo. Agora conecte a conta do escritório.');
        } catch (\Throwable $exception) {
            $this->erro = $exception->getMessage();
        }
    }

    public function editarAplicativo(): void
    {
        $this->editandoApp = true;
        $this->googleClientSecret = '';
    }

    public function abrirSeletor(int $empresaId): void
    {
        $this->garantirAcessoDocumentos();
        $this->erro = null;

        if (! $this->empresaComGrupoMonitorado($empresaId)) {
            $this->erro = 'Só empresas com grupo monitorado podem ter pasta no Drive.';

            return;
        }

        $empresa = Empresa::query()->find($empresaId);

        if ($empresa === null) {
            return;
        }

        $this->empresaSeletorId = $empresaId;
        $this->pastaPaiId = null;
        $this->pastaPaiNome = 'Drive';
        $this->breadcrumb = [];
        $this->pastaNome = app(NomePastaDriveEmpresa::class)->sugerir($empresa);
        $this->empresaSeletorNome = (string) ($empresa->nome_fantasia ?: $empresa->nome ?: $empresa->razao_social);
        $this->seletorPasso = 'nome';
        $this->seletorAberto = true;
    }

    public function confirmarNomePasta(): void
    {
        $this->garantirAcessoDocumentos();
        $this->erro = null;

        $this->validate([
            'pastaNome' => 'required|string|min:2|max:255',
        ], [], [
            'pastaNome' => 'nome da pasta',
        ]);

        $empresa = Empresa::query()->find($this->empresaSeletorId);

        if ($empresa === null || ! $this->empresaComGrupoMonitorado((int) $empresa->id)) {
            $this->erro = 'Só empresas com grupo monitorado podem ter pasta no Drive.';

            return;
        }

        $empresa->update(['pasta_drive_nome' => trim($this->pastaNome)]);
        $this->seletorPasso = 'local';
    }

    public function voltarParaNomePasta(): void
    {
        $this->seletorPasso = 'nome';
        $this->pastaPaiId = null;
        $this->pastaPaiNome = 'Drive';
        $this->breadcrumb = [];
    }

    public function fecharSeletor(): void
    {
        $this->seletorAberto = false;
        $this->seletorPasso = 'nome';
        $this->pastaNome = '';
        $this->empresaSeletorNome = '';
        $this->empresaSeletorId = null;
        $this->pastaPaiId = null;
        $this->breadcrumb = [];
    }

    public function entrarPasta(string $folderId, string $nome): void
    {
        $this->breadcrumb[] = [
            'id' => $this->pastaPaiId ?? '',
            'nome' => $this->pastaPaiNome,
        ];
        $this->pastaPaiId = $folderId;
        $this->pastaPaiNome = $nome;
    }

    public function voltarPasta(): void
    {
        $anterior = array_pop($this->breadcrumb);

        if ($anterior === null) {
            $this->pastaPaiId = null;
            $this->pastaPaiNome = 'Drive';

            return;
        }

        $this->pastaPaiId = $anterior['id'] !== '' ? $anterior['id'] : null;
        $this->pastaPaiNome = $anterior['nome'];
    }

    public function confirmarPasta(string $folderId, string $nome, GoogleDriveService $drive): void
    {
        $this->garantirAcessoDocumentos();

        if ($this->empresaSeletorId === null) {
            return;
        }

        $empresa = Empresa::query()->find($this->empresaSeletorId);
        $conta = ContaGoogle::daOperadora();

        if ($empresa === null || $conta === null || ! $conta->conectada()) {
            $this->erro = 'Conecte a conta Google antes de escolher a pasta.';

            return;
        }

        if (! $this->empresaComGrupoMonitorado((int) $empresa->id)) {
            $this->erro = 'Só empresas com grupo monitorado podem ter pasta no Drive.';

            return;
        }

        try {
            $drive->definirPastaRaiz($empresa, $conta, $folderId, $nome);
            $empresa->update(['pasta_drive_nome' => $nome]);
            $this->fecharSeletor();
            session()->flash('message', 'Pasta raiz definida para '.$empresa->nome.'.');
        } catch (\Throwable $exception) {
            $this->erro = $exception->getMessage();
        }
    }

    public function criarEVincular(GoogleDriveService $drive): void
    {
        $this->garantirAcessoDocumentos();
        $this->erro = null;

        $this->validate([
            'pastaNome' => 'required|string|min:2|max:255',
        ], [], [
            'pastaNome' => 'nome da pasta',
        ]);

        if ($this->empresaSeletorId === null) {
            return;
        }

        $empresa = Empresa::query()->find($this->empresaSeletorId);
        $conta = ContaGoogle::daOperadora();

        if ($empresa === null || $conta === null || ! $conta->conectada()) {
            $this->erro = 'Conecte a conta Google antes de escolher a pasta.';

            return;
        }

        if (! $this->empresaComGrupoMonitorado((int) $empresa->id)) {
            $this->erro = 'Só empresas com grupo monitorado podem ter pasta no Drive.';

            return;
        }

        $nome = trim($this->pastaNome);

        try {
            $empresa->update(['pasta_drive_nome' => $nome]);
            $drive->criarEDefinirPastaRaiz($empresa, $conta, (string) ($this->pastaPaiId ?: 'root'), $nome);
            $this->fecharSeletor();
            session()->flash('message', 'Pasta criada e vinculada: '.$nome);
        } catch (\Throwable $exception) {
            $this->erro = $exception->getMessage();
        }
    }

    public function criarEstrutura(int $empresaId, GoogleDriveService $drive): void
    {
        $this->garantirAcessoDocumentos();
        $this->erro = null;

        $empresa = Empresa::query()->find($empresaId);
        $conta = ContaGoogle::daOperadora();

        if ($empresa === null || $conta === null || ! $conta->conectada()) {
            $this->erro = 'Conecte a conta Google e defina a pasta raiz.';

            return;
        }

        if (! $this->empresaComGrupoMonitorado((int) $empresa->id)) {
            $this->erro = 'Só empresas com grupo monitorado podem ter pasta no Drive.';

            return;
        }

        try {
            $drive->garantirEstruturaAno($conta, $empresa, (int) now()->format('Y'));
            $drive->liberarLinksDaEmpresa($conta, $empresa);
            session()->flash('message', 'Estrutura do ano '.now()->format('Y').' criada. Pastas e arquivos desta empresa abrem pelo link.');
        } catch (\Throwable $exception) {
            $this->erro = $exception->getMessage();
        }
    }

    public function liberarLinks(int $empresaId, GoogleDriveService $drive): void
    {
        $this->garantirAcessoDocumentos();
        $this->erro = null;

        $empresa = Empresa::query()->find($empresaId);
        $conta = ContaGoogle::daOperadora();

        if ($empresa === null || $conta === null || ! $conta->conectada()) {
            $this->erro = 'Conecte a conta Google e defina a pasta raiz.';

            return;
        }

        if (! $this->empresaComGrupoMonitorado((int) $empresa->id)) {
            $this->erro = 'Só empresas com grupo monitorado podem ter pasta no Drive.';

            return;
        }

        try {
            $drive->liberarLinksDaEmpresa($conta, $empresa);
            session()->flash('message', 'Links das pastas e arquivos de '.$empresa->nome.' liberados para abertura.');
        } catch (\Throwable $exception) {
            $this->erro = $exception->getMessage();
        }
    }

    public function desconectar(): void
    {
        $this->garantirAcessoDocumentos();

        $conta = ContaGoogle::daOperadora();

        if ($conta === null) {
            return;
        }

        $conta->update([
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'status' => StatusContaGoogle::Desconectado,
        ]);

        session()->flash('message', 'Conta Google desconectada. Os arquivos no Drive não foram apagados.');
    }

    public function reprocessarPendentes(): void
    {
        $this->garantirAcessoDocumentos();

        $ids = DocumentoRecebido::query()
            ->whereIn('status', ['pendente', 'erro', 'recebido', 'classificado'])
            ->pluck('id');

        foreach ($ids as $id) {
            ArquivarDocumentoRecebidoJob::dispatch((int) $id);
        }

        session()->flash('message', $ids->count().' documento(s) enviados para reprocessar.');
    }

    public function render(GoogleDriveService $drive)
    {
        $conta = null;
        $empresas = collect();
        $pastas = [];
        $raizes = collect();

        if (! $this->precisaSelecionarEscritorio() && OperadoraContext::id()) {
            $conta = ContaGoogle::daOperadora();
            $empresas = $this->empresasComGrupoMonitorado();
            $raizes = EmpresaPastaDrive::query()
                ->where('tipo', EmpresaPastaDrive::TIPO_RAIZ)
                ->whereIn('empresa_id', $empresas->pluck('id'))
                ->get()
                ->keyBy('empresa_id');

            if ($this->seletorAberto && $this->seletorPasso === 'local' && $conta?->conectada()) {
                try {
                    $pastas = $drive->listarPastas($conta, $this->pastaPaiId);
                } catch (\Throwable $exception) {
                    $this->erro = $exception->getMessage();
                }
            }
        }

        $googleConfigurado = $drive->configurado();
        $contaConectada = (bool) ($conta?->conectada());
        $passo = ! $googleConfigurado || $this->editandoApp ? 1 : (! $contaConectada ? 2 : 3);

        return view('livewire.documentos.conta-google-drive', [
            'precisaSelecionarEscritorio' => $this->precisaSelecionarEscritorio(),
            'googleConfigurado' => $googleConfigurado,
            'uriRedirecionamento' => $drive->uriRedirecionamento(),
            'origemAplicativo' => rtrim((string) config('app.url'), '/'),
            'conta' => $conta,
            'empresas' => $empresas,
            'raizes' => $raizes,
            'pastas' => $pastas,
            'contaConectada' => $contaConectada,
            'passo' => $passo,
        ]);
    }

    private function empresaComGrupoMonitorado(int $empresaId): bool
    {
        return GrupoWhatsapp::query()
            ->where('empresa_id', $empresaId)
            ->where('monitorar', true)
            ->exists();
    }

    private function empresasComGrupoMonitorado()
    {
        $ids = GrupoWhatsapp::query()
            ->where('monitorar', true)
            ->whereNotNull('empresa_id')
            ->distinct()
            ->pluck('empresa_id');

        return Empresa::query()
            ->where('ativo', true)
            ->whereIn('id', $ids)
            ->orderBy('nome')
            ->get();
    }
}
