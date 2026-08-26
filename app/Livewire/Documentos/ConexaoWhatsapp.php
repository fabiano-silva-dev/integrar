<?php

namespace App\Livewire\Documentos;

use App\Enums\Documentos\StatusConexaoWhatsapp;
use App\Services\Documentos\EvolutionAdaptador;
use App\Services\Documentos\EvolutionConexaoService;
use Livewire\Component;

class ConexaoWhatsapp extends Component
{
    use AutorizaModuloDocumentos;

    protected $layout = 'components.layouts.app';

    public string $status = 'desconectado';

    public string $statusRotulo = 'Não conectado';

    public ?string $telefone = null;

    public ?string $qrcode = null;

    public ?string $mensagem = null;

    public ?string $erro = null;

    public function mount(EvolutionConexaoService $conexoes): void
    {
        $this->garantirAcessoDocumentos();

        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        $this->carregarEstadoLocal($conexoes);
    }

    public function atualizarEstado(EvolutionConexaoService $conexoes): void
    {
        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        $this->carregarEstado($conexoes);
    }

    public function conectar(EvolutionConexaoService $conexoes): void
    {
        $this->garantirAcessoDocumentos();

        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        $this->erro = null;

        try {
            $conexao = $conexoes->garantirConexao();
            $estado = $conexoes->iniciarConexao($conexao);
            $this->aplicarEstado($estado);
        } catch (\Throwable $exception) {
            $this->erro = $this->humanizarErro($exception->getMessage());
        }
    }

    public function desconectar(EvolutionConexaoService $conexoes): void
    {
        $this->garantirAcessoDocumentos();

        if ($this->precisaSelecionarEscritorio()) {
            return;
        }

        $this->erro = null;

        try {
            $conexao = $conexoes->garantirConexao();
            $estado = $conexoes->desconectar($conexao);
            $this->aplicarEstado($estado);
        } catch (\Throwable $exception) {
            $this->erro = $this->humanizarErro($exception->getMessage());
        }
    }

    private function carregarEstadoLocal(EvolutionConexaoService $conexoes): void
    {
        $conexao = $conexoes->garantirConexao();
        $qrcode = null;
        $mensagem = null;

        if ($conexao->status === StatusConexaoWhatsapp::AguardandoQr) {
            $qrcode = app(EvolutionAdaptador::class)->qrcodeEmCache((string) $conexao->nome_instancia);
            $mensagem = 'No celular: WhatsApp → Aparelhos conectados → Conectar um aparelho.';
        }

        if ($conexao->status === StatusConexaoWhatsapp::Erro) {
            $mensagem = 'A última conexão não concluiu. Clique abaixo para tentar de novo.';
        }

        $this->aplicarEstado([
            'status' => $conexao->status->value,
            'status_rotulo' => $conexao->status->rotulo(),
            'telefone' => $conexao->telefone_exibicao,
            'qrcode' => $qrcode,
            'mensagem' => $mensagem,
        ]);
    }

    private function carregarEstado(EvolutionConexaoService $conexoes): void
    {
        $conexao = $conexoes->garantirConexao();
        $this->aplicarEstado($conexoes->consultarEstado($conexao));
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function aplicarEstado(array $estado): void
    {
        $this->status = (string) ($estado['status'] ?? 'desconectado');
        $this->statusRotulo = (string) ($estado['status_rotulo'] ?? 'Não conectado');
        $this->telefone = is_string($estado['telefone'] ?? null) ? $estado['telefone'] : null;
        $this->qrcode = is_string($estado['qrcode'] ?? null) ? $estado['qrcode'] : null;
        $this->mensagem = is_string($estado['mensagem'] ?? null) ? $estado['mensagem'] : null;
    }

    private function humanizarErro(string $mensagem): string
    {
        $lower = mb_strtolower($mensagem);

        if (str_contains($lower, 'docker')
            || str_contains($lower, 'não está no ar')
            || str_contains($lower, 'could not resolve')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'failed to connect')) {
            return 'Não foi possível falar com o WhatsApp agora. Tente de novo em instantes.';
        }

        if (strlen($mensagem) > 160 || str_contains($mensagem, '{')) {
            return 'Não foi possível conectar o WhatsApp. Tente de novo.';
        }

        return $mensagem;
    }

    public function render()
    {
        return view('livewire.documentos.conexao-whatsapp', [
            'precisaSelecionarEscritorio' => $this->precisaSelecionarEscritorio(),
            'aguardandoQr' => $this->status === StatusConexaoWhatsapp::AguardandoQr->value,
            'conectado' => $this->status === StatusConexaoWhatsapp::Conectado->value,
            'falhou' => $this->status === StatusConexaoWhatsapp::Erro->value || filled($this->erro),
        ]);
    }
}
