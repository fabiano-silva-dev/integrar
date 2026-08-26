<?php

namespace App\Services\Documentos;

use App\Enums\Documentos\StatusConexaoWhatsapp;
use App\Models\Documentos\ConexaoWhatsapp;
use App\Models\Documentos\GrupoWhatsapp;
use App\Services\OperadoraContext;
use Illuminate\Support\Facades\Log;

class EvolutionConexaoService
{
    public function __construct(
        private readonly EvolutionAdaptador $adaptador,
    ) {}

    public function garantirConexao(?int $operadoraId = null): ConexaoWhatsapp
    {
        $operadoraId ??= OperadoraContext::requireId();

        $conexao = ConexaoWhatsapp::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $operadoraId)
            ->first();

        if ($conexao !== null) {
            return $conexao;
        }

        return ConexaoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadoraId,
            'status' => StatusConexaoWhatsapp::Desconectado,
            'nome_instancia' => $this->nomeInstancia($operadoraId),
            'url_base_evolution' => config('evolution.url_base'),
            'credenciais' => [
                'apikey' => (string) config('evolution.api_key'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function consultarEstado(ConexaoWhatsapp $conexao): array
    {
        $conexao->refresh();

        if ($conexao->status === StatusConexaoWhatsapp::Conectado) {
            $this->adaptador->limparQrcode((string) $conexao->nome_instancia);

            return $this->montarResposta($conexao, 'open', null);
        }

        try {
            $estado = $this->adaptador->obterEstadoConexao($conexao);
            $estadoEvolution = $estado['estado'] ?? null;
            $instanciaInexistente = (bool) ($estado['instancia_inexistente'] ?? false);

            $this->sincronizarStatus($conexao, $estadoEvolution);
            $this->sincronizarTelefone($conexao, $estadoEvolution);

            if ($estadoEvolution === 'open') {
                $this->adaptador->limparQrcode((string) $conexao->nome_instancia);
            }

            $conexaoAtual = $conexao->fresh() ?? $conexao;

            if ($instanciaInexistente) {
                if ($conexaoAtual->status !== StatusConexaoWhatsapp::Desconectado) {
                    $conexaoAtual->update(['status' => StatusConexaoWhatsapp::Desconectado]);
                    $conexaoAtual = $conexaoAtual->fresh() ?? $conexaoAtual;
                }

                return $this->montarResposta(
                    $conexaoAtual,
                    'close',
                    null,
                    'WhatsApp ainda não está conectado. Clique em Conectar para gerar o QR Code.',
                );
            }

            $qrcode = null;

            if (in_array($estadoEvolution, ['qr', 'connecting', 'close'], true)
                && $conexaoAtual->status !== StatusConexaoWhatsapp::Conectado) {
                $qrcode = $this->adaptador->obterQrcodeBase64($conexaoAtual);
            }

            return $this->montarResposta($conexaoAtual->fresh() ?? $conexaoAtual, $estadoEvolution, $qrcode);
        } catch (\Throwable $exception) {
            if ($this->adaptador->mensagemIndicaInstanciaInexistente($exception->getMessage())) {
                $conexao->update(['status' => StatusConexaoWhatsapp::Desconectado]);

                return $this->montarResposta(
                    $conexao->fresh() ?? $conexao,
                    'close',
                    null,
                    'WhatsApp ainda não está conectado. Clique em Conectar para gerar o QR Code.',
                );
            }

            if (($conexao->fresh() ?? $conexao)->status === StatusConexaoWhatsapp::Conectado) {
                Log::warning('Evolution: falha ao consultar estado com sessão já conectada.', [
                    'conexao_id' => $conexao->id,
                    'erro' => $exception->getMessage(),
                ]);

                return $this->montarResposta($conexao->fresh() ?? $conexao, 'open', null);
            }

            Log::error('Evolution: falha ao consultar estado.', [
                'conexao_id' => $conexao->id,
                'erro' => $exception->getMessage(),
            ]);

            $conexao->update(['status' => StatusConexaoWhatsapp::Erro]);

            return $this->montarResposta(
                $conexao->fresh() ?? $conexao,
                null,
                null,
                'Não foi possível falar com o WhatsApp agora. Tente de novo em instantes.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function iniciarConexao(ConexaoWhatsapp $conexao): array
    {
        try {
            $this->adaptador->garantirInstancia($conexao);
            $this->adaptador->aplicarDefinicoesInstancia($conexao);
            $this->adaptador->configurarWebhook($conexao);

            $resultadoConnect = $this->adaptador->conectarInstancia($conexao);
            $qrcode = $this->adaptador->extrairQrcodeDePayload($resultadoConnect)
                ?? $this->adaptador->obterQrcodeBase64($conexao);

            if ($qrcode !== null) {
                $this->adaptador->armazenarQrcode((string) $conexao->nome_instancia, $qrcode);
            }

            $estado = $this->adaptador->obterEstadoConexao($conexao);
            $estadoEvolution = $estado['estado'] ?? 'connecting';

            $this->sincronizarStatus($conexao, $estadoEvolution);
            $this->sincronizarTelefone($conexao, $estadoEvolution);

            if ($qrcode !== null && ($conexao->fresh()?->status !== StatusConexaoWhatsapp::Conectado)) {
                $conexao->update(['status' => StatusConexaoWhatsapp::AguardandoQr]);
            }

            return $this->montarResposta(
                $conexao->fresh() ?? $conexao,
                $estadoEvolution,
                $qrcode,
                $qrcode
                    ? 'No celular: WhatsApp → Aparelhos conectados → Conectar um aparelho.'
                    : 'Gerando o QR Code. Isso leva alguns segundos.',
            );
        } catch (\Throwable $exception) {
            Log::error('Evolution: falha ao iniciar conexão.', [
                'conexao_id' => $conexao->id,
                'erro' => $exception->getMessage(),
            ]);

            $conexao->update(['status' => StatusConexaoWhatsapp::Erro]);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function desconectar(ConexaoWhatsapp $conexao): array
    {
        try {
            $this->adaptador->desconectarInstancia($conexao);

            $conexao->update([
                'status' => StatusConexaoWhatsapp::Desconectado,
                'telefone_exibicao' => null,
            ]);

            return $this->montarResposta(
                $conexao->fresh() ?? $conexao,
                'close',
                null,
                'WhatsApp desconectado. Conecte de novo para gerar outro QR Code.',
            );
        } catch (\Throwable $exception) {
            Log::error('Evolution: falha ao desconectar.', [
                'conexao_id' => $conexao->id,
                'erro' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function processarAtualizacaoConexao(string $nomeInstancia, ?string $estadoEvolution): void
    {
        $conexao = ConexaoWhatsapp::withoutGlobalScope('operadora')
            ->where('nome_instancia', $nomeInstancia)
            ->first();

        if ($conexao === null) {
            return;
        }

        $this->sincronizarStatus($conexao, $estadoEvolution);

        if ($estadoEvolution === 'open') {
            $this->adaptador->limparQrcode((string) $conexao->nome_instancia);
            $this->sincronizarTelefone($conexao, $estadoEvolution);
        }

        if (in_array($estadoEvolution, ['close', 'closed'], true)) {
            $conexao->update(['telefone_exibicao' => null]);
        }
    }

    public function processarQrcode(string $nomeInstancia, array $payload): void
    {
        $qrcode = $this->adaptador->extrairQrcodeDePayload($payload);

        if ($qrcode === null) {
            return;
        }

        $this->adaptador->armazenarQrcode($nomeInstancia, $qrcode);

        $conexao = ConexaoWhatsapp::withoutGlobalScope('operadora')
            ->where('nome_instancia', $nomeInstancia)
            ->first();

        if ($conexao !== null && $conexao->status !== StatusConexaoWhatsapp::Conectado) {
            $conexao->update(['status' => StatusConexaoWhatsapp::AguardandoQr]);
        }
    }

    public function sincronizarGrupos(ConexaoWhatsapp $conexao): int
    {
        $grupos = $this->adaptador->listarGrupos($conexao);
        $sincronizados = 0;

        foreach ($grupos as $grupo) {
            GrupoWhatsapp::withoutGlobalScope('operadora')->updateOrCreate(
                [
                    'conexao_whatsapp_id' => $conexao->id,
                    'jid' => $grupo['id'],
                ],
                [
                    'empresa_operadora_id' => $conexao->empresa_operadora_id,
                    'nome' => $grupo['subject'],
                ],
            );
            $sincronizados++;
        }

        return $sincronizados;
    }

    private function nomeInstancia(int $operadoraId): string
    {
        return 'integrar-op-'.$operadoraId;
    }

    private function sincronizarStatus(ConexaoWhatsapp $conexao, ?string $estadoEvolution): void
    {
        $status = match ($estadoEvolution) {
            'open' => StatusConexaoWhatsapp::Conectado,
            'qr', 'connecting' => StatusConexaoWhatsapp::AguardandoQr,
            'close', 'closed' => StatusConexaoWhatsapp::Desconectado,
            default => null,
        };

        if ($status !== null && $conexao->status !== $status) {
            $conexao->update(['status' => $status]);
        }
    }

    private function sincronizarTelefone(ConexaoWhatsapp $conexao, ?string $estadoEvolution): void
    {
        if ($estadoEvolution !== 'open') {
            return;
        }

        try {
            $info = $this->adaptador->obterInfoInstancia($conexao);
            if (is_string($info['telefone'] ?? null) && $info['telefone'] !== '') {
                $conexao->update(['telefone_exibicao' => $info['telefone']]);
            }
        } catch (\Throwable $exception) {
            Log::info('Evolution: não foi possível ler o telefone da instância.', [
                'conexao_id' => $conexao->id,
                'erro' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function montarResposta(
        ConexaoWhatsapp $conexao,
        ?string $estadoEvolution,
        ?string $qrcode,
        ?string $mensagem = null,
    ): array {
        return [
            'status' => $conexao->status->value,
            'status_rotulo' => $conexao->status->rotulo(),
            'telefone' => $conexao->telefone_exibicao,
            'instancia' => $conexao->nome_instancia,
            'estado_evolution' => $estadoEvolution,
            'qrcode' => $qrcode,
            'mensagem' => $mensagem,
        ];
    }
}
