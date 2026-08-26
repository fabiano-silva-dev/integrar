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

    public function abrirCriacaoPasta(int $empresaId): void
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

        $this->pastaNome = trim($this->pastaNome);
        $this->seletorPasso = 'confirmar';
    }

    public function voltarParaNomePasta(): void
    {
        $this->seletorPasso = 'nome';
    }

    public function fecharSeletor(): void
    {
        $this->seletorAberto = false;
        $this->seletorPasso = 'nome';
        $this->pastaNome = '';
        $this->empresaSeletorNome = '';
        $this->empresaSeletorId = null;
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

        if ($this->empresaSeletorId === null || $this->seletorPasso !== 'confirmar') {
            return;
        }

        $empresa = Empresa::query()->find($this->empresaSeletorId);
        $conta = ContaGoogle::daOperadora();

        if ($empresa === null || $conta === null || ! $conta->conectada()) {
            $this->erro = 'Conecte a conta Google do escritório.';

            return;
        }

        if (! $this->empresaComGrupoMonitorado((int) $empresa->id)) {
            $this->erro = 'Só empresas com grupo monitorado podem ter pasta no Drive.';

            return;
        }

        $nome = trim($this->pastaNome);

        try {
            $empresa->update(['pasta_drive_nome' => $nome]);
            $drive->criarEDefinirPastaRaiz($empresa, $conta, 'root', $nome);
            $this->fecharSeletor();
            session()->flash('message', 'Pasta criada no Drive: '.$nome);
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
        $raizes = collect();

        if (! $this->precisaSelecionarEscritorio() && OperadoraContext::id()) {
            $conta = ContaGoogle::daOperadora();
            $empresas = $this->empresasComGrupoMonitorado();
            $raizes = EmpresaPastaDrive::query()
                ->where('tipo', EmpresaPastaDrive::TIPO_RAIZ)
                ->whereIn('empresa_id', $empresas->pluck('id'))
                ->get()
                ->keyBy('empresa_id');
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
            'contaConectada' => $contaConectada,
            'passo' => $passo,
        ]);
    }

    private function empresaComGrupoMonitorado(int $empresaId): bool
    {
        return GrupoWhatsapp::query()
            ->where('monitorar', true)
            ->where(function ($query) use ($empresaId) {
                $query->where('empresa_id', $empresaId)
                    ->orWhereHas('empresas', fn ($inner) => $inner->where('empresas.id', $empresaId));
            })
            ->exists();
    }

    private function empresasComGrupoMonitorado()
    {
        $ids = GrupoWhatsapp::query()
            ->where('monitorar', true)
            ->with('empresas')
            ->get()
            ->flatMap(fn (GrupoWhatsapp $grupo) => $grupo->idsEmpresas())
            ->unique()
            ->filter()
            ->values();

        return Empresa::query()
            ->where('ativo', true)
            ->whereIn('id', $ids)
            ->orderBy('nome')
            ->get();
    }
}
