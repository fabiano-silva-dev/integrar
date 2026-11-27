<?php

namespace App\Livewire\Nfse;

use App\Models\Nfse\AcessoEmissao;
use App\Models\Nfse\DiagnosticoAcesso;
use App\Services\Nfse\DiagnosticoApiNfse;
use App\Services\Nfse\AcessoEmissaoNfse;
use App\Services\Nfse\PreparacaoNfse;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Acessos extends Component
{
    protected $layout = 'components.layouts.app';
    #[Locked]
    public ?int $empresaId = null;
    public string $modo = 'certificado_proprio';
    public string $canal = 'api';
    public $certificadoId = null;
    public string $procuracaoAte = '';
    // Credenciais são recebidas apenas no método de gravação, não em propriedades Livewire.

    public function mount(): void
    {
        abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        if ($id = session('empresa_selecionada_id')) {
            $empresa = app(AcessoEmissaoNfse::class)->empresa((int) $id);
            $this->empresaId = $empresa->id;
            $r = AcessoEmissao::daEmpresa($empresa)->first(['modo', 'canal', 'certificado_digital_id', 'procuracao_ate']);
            if ($r) {
                $this->modo = $r->modo;
                $this->canal = $r->canal;
                $this->certificadoId = $r->certificado_digital_id;
                $this->procuracaoAte = $r->procuracao_ate?->toDateString() ?? '';
            }
        }
    }

    public function updatedModo(): void
    {
        $this->certificadoId = null;
        if (str_starts_with($this->modo, 'senha_')) {
            $this->canal = 'portal';
        }
    }

    public function salvar(string $login = '', #[\SensitiveParameter] string $senha = ''): bool
    {
        abort_unless($this->empresaId, 422);
        app(AcessoEmissaoNfse::class)->salvar($this->empresaId, [
            'modo' => $this->modo, 'canal' => $this->canal, 'certificado_digital_id' => $this->certificadoId ?: null,
            'login' => $login, 'senha' => $senha, 'procuracao_ate' => $this->procuracaoAte ?: null,
        ]);
        session()->flash('nfse_acesso_mensagem', 'Acesso cadastrado. Validação no portal/API ainda pendente.');
        return true;
    }

    public function diagnosticar(string $ambiente, string $chave): void
    {
        abort_unless($this->empresaId, 422);
        $registro = app(DiagnosticoApiNfse::class)->executar($this->empresaId, $ambiente, trim($chave));
        session()->flash('nfse_acesso_mensagem', match ($registro->status) {
            'consulta_confirmada' => 'Consulta confirmada para o prestador. Não comprova autorização de emissão.',
            'identidade_divergente' => 'A resposta não confirmou chave, prestador e ambiente. Nenhum XML foi armazenado.',
            'config_alterada' => 'Configuração mudou durante o teste. Execute novamente.',
            default => 'Consulta não confirmada. Confira certificado, chave, ambiente e permissões.',
        });
    }

    public function render()
    {
        abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        $empresa = $this->empresaId ? app(AcessoEmissaoNfse::class)->empresa($this->empresaId) : null;
        $certificados = $empresa ? app(AcessoEmissaoNfse::class)->certificados($empresa, $this->modo)->orderBy('nome')->get(['id', 'nome', 'valido_ate']) : collect();
        $status = $empresa ? AcessoEmissao::daEmpresa($empresa)->first(['modo', 'status_validacao', 'certificado_digital_id', 'procuracao_ate']) : null;
        $diagnosticos = $empresa ? DiagnosticoAcesso::daEmpresa($empresa)->latest('id')->limit(10)->get(['id', 'ambiente', 'status', 'certificado_id', 'created_at']) : collect();
        return view('livewire.nfse.acessos', compact('empresa', 'certificados', 'status', 'diagnosticos'))->layout($this->layout);
    }
}
