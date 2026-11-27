<?php

namespace App\Livewire\Nfse;

use App\Models\Nfse\AcessoEmissao;
use App\Models\Nfse\DiagnosticoAcesso;
use App\Models\Nfse\ValidacaoPortal;
use App\Services\Nfse\AcessoEmissaoNfse;
use App\Services\Nfse\DiagnosticoApiNfse;
use App\Services\Nfse\LogTesteAcessoNfse;
use App\Services\Nfse\PreparacaoNfse;
use App\Services\Nfse\ValidacaoPortalNfse;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        if (! str_ends_with($this->modo, '_representante')) {
            $this->procuracaoAte = '';
        }
        if (str_starts_with($this->modo, 'senha_')) {
            $this->canal = 'portal';
        }
    }

    public function salvar(#[\SensitiveParameter] string $login = '', #[\SensitiveParameter] string $senha = ''): bool
    {
        abort_unless($this->empresaId, 422);
        $salvo = app(AcessoEmissaoNfse::class)->salvar($this->empresaId, [
            'modo' => $this->modo, 'canal' => $this->canal, 'certificado_digital_id' => $this->certificadoId ?: null,
            'login' => $login, 'senha' => $senha, 'procuracao_ate' => $this->procuracaoAte ?: null,
        ]);
        $this->certificadoId = $salvo->certificado_digital_id;
        $this->procuracaoAte = $salvo->procuracao_ate?->toDateString() ?? '';
        session()->flash('nfse_acesso_mensagem', 'Configuração salva. Teste o acesso para confirmar o perfil ativo.');
        return true;
    }

    public function testarPortal(): void
    {
        abort_unless($this->empresaId, 422);
        app(ValidacaoPortalNfse::class)->solicitar($this->empresaId);
        session()->flash('nfse_acesso_mensagem', 'Teste de acesso solicitado. Aguarde o resultado abaixo.');
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

    public function baixarLog(string $tipo, int $id): StreamedResponse
    {
        abort_unless($this->empresaId, 422);
        abort_unless((int) session('empresa_selecionada_id') === $this->empresaId, 403);
        $empresa = app(AcessoEmissaoNfse::class)->empresa($this->empresaId);
        $registro = match ($tipo) {
            'portal' => ValidacaoPortal::daEmpresa($empresa)->findOrFail($id),
            'api' => DiagnosticoAcesso::daEmpresa($empresa)->findOrFail($id),
            default => abort(404),
        };
        $conteudo = json_encode(LogTesteAcessoNfse::relatorio($registro), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return response()->streamDownload(static function () use ($conteudo) {
            echo $conteudo."\n";
        }, 'nfse-teste-'.$tipo.'-'.$registro->id.'.json', ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function render()
    {
        abort_unless(app(PreparacaoNfse::class)->podeConfigurar(), 403);
        $empresa = $this->empresaId ? app(AcessoEmissaoNfse::class)->empresa($this->empresaId) : null;
        $certificados = $empresa ? app(AcessoEmissaoNfse::class)->certificados($empresa, $this->modo)->orderBy('nome')->get(['id', 'nome', 'valido_ate']) : collect();
        $status = $empresa ? AcessoEmissao::daEmpresa($empresa)->first() : null;
        $mesmoModo = $status && $status->modo === $this->modo && $status->documento_prestador === preg_replace('/\D/', '', (string) $empresa->cnpj);
        $credenciaisSalvas = false;
        $loginMascarado = null;
        if ($mesmoModo && str_starts_with($this->modo, 'senha_')
            && filled($status->getRawOriginal('usuario_criptografado')) && filled($status->getRawOriginal('senha_criptografada'))) {
            try {
                $login = $status->usuario_criptografado;
                if (is_string($login) && preg_match('/^(?:\d{11}|\d{14})$/D', $login)) {
                    $loginMascarado = str_repeat('•', strlen($login) - 4).substr($login, -4);
                    $credenciaisSalvas = true;
                }
            } catch (\Throwable) {
                // Credencial ilegível deve ser substituída, nunca exposta.
            }
        }
        $certificadoDisponivel = $mesmoModo && str_starts_with($this->modo, 'certificado_')
            && $certificados->contains('id', $status->certificado_digital_id);
        $alterado = $status && (! $mesmoModo || $status->canal !== $this->canal
            || (int) $status->certificado_digital_id !== (int) $this->certificadoId
            || ($status->procuracao_ate?->toDateString() ?? '') !== $this->procuracaoAte);
        $acessoPronto = $status && ! $alterado && ($credenciaisSalvas || $certificadoDisponivel)
            && (! $status->procuracao_ate || $status->procuracao_ate->toDateString() >= now()->toDateString());
        $diagnosticos = $empresa ? DiagnosticoAcesso::daEmpresa($empresa)->latest('id')->limit(10)->get(['id', 'ambiente', 'status', 'certificado_id', 'created_at', 'diagnostico_tecnico']) : collect();
        $validacoesPortal = $empresa ? ValidacaoPortal::daEmpresa($empresa)->latest('id')->limit(10)->get(['id', 'modo', 'status', 'codigo', 'created_at', 'concluida_em', 'diagnostico_tecnico']) : collect();
        return view('livewire.nfse.acessos', compact('empresa', 'certificados', 'status', 'diagnosticos', 'validacoesPortal', 'credenciaisSalvas', 'loginMascarado', 'certificadoDisponivel', 'alterado', 'acessoPronto'))->layout($this->layout);
    }
}
