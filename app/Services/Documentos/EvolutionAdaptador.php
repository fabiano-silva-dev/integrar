<?php

namespace App\Services\Documentos;

use App\Models\Documentos\ConexaoWhatsapp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EvolutionAdaptador
{
    private const CACHE_QR_TTL_SEGUNDOS = 120;

    public function garantirInstancia(ConexaoWhatsapp $conexao): void
    {
        $instancia = (string) $conexao->nome_instancia;

        if ($instancia === '') {
            throw new \RuntimeException('Nome da instância da API WhatsApp Web não configurado.');
        }

        if ($this->instanciaExiste($conexao, $instancia)) {
            return;
        }

        $response = $this->requisicao($conexao, 'POST', '/instance/create', [
            'instanceName' => $instancia,
            'token' => 'token-'.$instancia,
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS',
            'syncFullHistory' => false,
            'groupsIgnore' => false,
        ]);

        if (! $response->successful() && $response->status() !== 409) {
            throw new \RuntimeException('Falha ao criar instância da API WhatsApp Web: '.$response->body());
        }
    }

    public function configurarWebhook(ConexaoWhatsapp $conexao): void
    {
        $instancia = (string) $conexao->nome_instancia;
        $webhookUrl = (string) config('evolution.webhook_url');
        $apikeyWebhook = (string) config('evolution.api_key', '');

        $webhook = [
            'enabled' => true,
            'url' => $webhookUrl,
            'webhookByEvents' => false,
            'byEvents' => false,
            'webhookBase64' => true,
            'base64' => true,
            'events' => [
                'MESSAGES_UPSERT',
                'CONNECTION_UPDATE',
                'QRCODE_UPDATED',
            ],
        ];

        if ($apikeyWebhook !== '') {
            $webhook['headers'] = [
                'apikey' => $apikeyWebhook,
            ];
        }

        $response = $this->requisicao($conexao, 'POST', "/webhook/set/{$instancia}", [
            'webhook' => $webhook,
        ]);

        if (! $response->successful()) {
            Log::warning('Evolution: webhook não configurado.', ['body' => $response->body()]);
        }
    }

    public function aplicarDefinicoesInstancia(ConexaoWhatsapp $conexao): void
    {
        $instancia = (string) $conexao->nome_instancia;

        try {
            $this->requisicao($conexao, 'POST', "/settings/set/{$instancia}", [
                'rejectCall' => false,
                'msgCall' => '',
                'groupsIgnore' => false,
                'alwaysOnline' => false,
                'readMessages' => false,
                'readStatus' => false,
                'syncFullHistory' => false,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Evolution: falha ao aplicar definições da instância.', [
                'instancia' => $instancia,
                'erro' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function conectarInstancia(ConexaoWhatsapp $conexao): array
    {
        $instancia = (string) $conexao->nome_instancia;
        $response = $this->requisicao($conexao, 'GET', "/instance/connect/{$instancia}");

        if (! $response->successful()) {
            throw new \RuntimeException('Falha ao conectar instância da API WhatsApp Web: '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * @return array{estado: ?string, bruto: array<string, mixed>, instancia_inexistente?: bool}
     */
    public function obterEstadoConexao(ConexaoWhatsapp $conexao): array
    {
        $instancia = (string) $conexao->nome_instancia;
        $response = $this->requisicao($conexao, 'GET', "/instance/connectionState/{$instancia}");

        if (! $response->successful()) {
            if ($this->respostaIndicaInstanciaInexistente($response)) {
                $bruto = $response->json();

                return [
                    'estado' => 'close',
                    'bruto' => is_array($bruto) ? $bruto : [],
                    'instancia_inexistente' => true,
                ];
            }

            throw new \RuntimeException('Falha ao obter estado da API WhatsApp Web: '.$response->body());
        }

        $bruto = $response->json() ?? [];
        $estado = $bruto['instance']['state']
            ?? $bruto['state']
            ?? ($bruto['response']['state'] ?? null);

        return [
            'estado' => is_string($estado) ? $estado : null,
            'bruto' => $bruto,
        ];
    }

    public function respostaIndicaInstanciaInexistente(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        return $this->mensagemIndicaInstanciaInexistente($response->body());
    }

    public function mensagemIndicaInstanciaInexistente(string $mensagem): bool
    {
        $texto = mb_strtolower($mensagem);

        return str_contains($texto, 'does not exist')
            || str_contains($texto, 'instance does not exist')
            || str_contains($texto, 'instância não existe')
            || str_contains($texto, 'instancia nao existe');
    }

    public function desconectarInstancia(ConexaoWhatsapp $conexao): void
    {
        $instancia = (string) $conexao->nome_instancia;
        $response = $this->requisicao($conexao, 'DELETE', "/instance/logout/{$instancia}");

        if (! $response->successful() && $response->status() !== 404) {
            throw new \RuntimeException('Falha ao desconectar instância da API WhatsApp Web: '.$response->body());
        }

        $this->limparQrcode($instancia);
    }

    public function limparQrcode(string $instancia): void
    {
        Cache::forget($this->chaveCacheQrcode($instancia));
    }

    /**
     * @return list<array{id: string, subject: ?string}>
     */
    public function listarGrupos(ConexaoWhatsapp $conexao): array
    {
        $instancia = (string) $conexao->nome_instancia;
        $response = $this->requisicao(
            $conexao,
            'GET',
            "/group/fetchAllGroups/{$instancia}?getParticipants=false",
            null,
            120,
        );

        if (! $response->successful()) {
            Log::warning('Evolution: falha ao listar grupos.', [
                'instancia' => $instancia,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $json = $response->json();
        $items = [];

        if (is_array($json)) {
            if (array_is_list($json)) {
                $items = $json;
            } else {
                $candidatos = $json['groups'] ?? $json['data'] ?? $json['response'] ?? null;
                $items = is_array($candidatos) ? $candidatos : [];
            }
        }

        $grupos = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? $item['jid'] ?? null;
            if (! is_string($id) || ! str_contains($id, '@g.us')) {
                continue;
            }

            $subject = $item['subject'] ?? $item['name'] ?? $item['pushName'] ?? null;

            $grupos[] = [
                'id' => $id,
                'subject' => is_string($subject) && $subject !== '' ? $subject : null,
            ];
        }

        return $grupos;
    }

    /**
     * @return array{owner_jid: ?string, telefone: ?string}
     */
    public function obterInfoInstancia(ConexaoWhatsapp $conexao): array
    {
        $item = $this->encontrarInstancia($conexao, (string) $conexao->nome_instancia);

        if ($item === null) {
            return ['owner_jid' => null, 'telefone' => null];
        }

        $ownerJid = $item['ownerJid'] ?? $item['owner'] ?? null;
        $ownerJid = is_string($ownerJid) && $ownerJid !== '' ? $ownerJid : null;
        $numeroCampo = is_string($item['number'] ?? null) && $item['number'] !== ''
            ? $item['number']
            : null;

        $telefone = $this->telefoneDeJid($ownerJid ?? $numeroCampo);

        return [
            'owner_jid' => $ownerJid,
            'telefone' => $telefone,
        ];
    }

    public function obterQrcodeBase64(ConexaoWhatsapp $conexao): ?string
    {
        $instancia = (string) $conexao->nome_instancia;
        $emCache = $this->qrcodeEmCache($instancia);

        if ($emCache !== null) {
            return $emCache;
        }

        $response = $this->requisicao($conexao, 'GET', "/instance/qrcode/{$instancia}");

        if (! $response->successful()) {
            return null;
        }

        return $this->extrairQrcodeDePayload($response->json() ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extrairQrcodeDePayload(array $payload): ?string
    {
        $candidatos = [];

        if (isset($payload['qrcode'])) {
            $candidatos[] = $payload['qrcode'];
        }

        if (isset($payload['base64'])) {
            $candidatos[] = $payload['base64'];
        }

        if (isset($payload['response']) && is_array($payload['response'])) {
            $candidatos[] = $payload['response']['qrcode'] ?? null;
            $candidatos[] = $payload['response']['base64'] ?? null;
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            $candidatos[] = $payload['data']['qrcode'] ?? null;
            $candidatos[] = $payload['data']['base64'] ?? null;
        }

        foreach ($candidatos as $valor) {
            if (is_array($valor) && isset($valor['base64'])) {
                return $this->normalizarQrcodeBase64((string) $valor['base64']);
            }

            if (is_string($valor) && $valor !== '') {
                return $this->normalizarQrcodeBase64($valor);
            }
        }

        return null;
    }

    public function normalizarQrcodeBase64(string $valor): string
    {
        if (str_starts_with($valor, 'data:image')) {
            $partes = explode(',', $valor, 2);

            return $partes[1] ?? $valor;
        }

        return $valor;
    }

    public function armazenarQrcode(string $instancia, string $qrcodeBase64): void
    {
        Cache::put(
            $this->chaveCacheQrcode($instancia),
            $this->normalizarQrcodeBase64($qrcodeBase64),
            self::CACHE_QR_TTL_SEGUNDOS,
        );
    }

    public function qrcodeEmCache(string $instancia): ?string
    {
        $valor = Cache::get($this->chaveCacheQrcode($instancia));

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array{base64: string, mime: string, nome_arquivo: ?string}|null
     */
    public function baixarMidia(ConexaoWhatsapp $conexao, array $dados): ?array
    {
        $embutida = $this->midiaEmbutidaNoPayload($dados);

        if ($embutida !== null) {
            return $embutida;
        }

        $chave = $dados['key'] ?? [];
        $idMensagem = is_array($chave) ? ($chave['id'] ?? null) : null;

        if (! is_string($idMensagem) || $idMensagem === '') {
            return null;
        }

        $instancia = (string) $conexao->nome_instancia;

        try {
            $response = $this->requisicao($conexao, 'POST', "/chat/getBase64FromMediaMessage/{$instancia}", [
                'message' => [
                    'key' => [
                        'id' => $idMensagem,
                        'remoteJid' => is_string($chave['remoteJid'] ?? null) ? $chave['remoteJid'] : null,
                        'fromMe' => (bool) ($chave['fromMe'] ?? false),
                    ],
                ],
                'convertToMp4' => false,
            ], 60);
        } catch (\Throwable $exception) {
            Log::warning('Evolution: exceção ao baixar mídia.', [
                'mensagem' => $exception->getMessage(),
                'id' => $idMensagem,
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Evolution: falha ao baixar mídia.', [
                'id' => $idMensagem,
                'body' => $response->body(),
            ]);

            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        $base64 = $payload['base64'] ?? null;

        if (! is_string($base64) || $base64 === '') {
            return null;
        }

        $metadados = $this->metadadosMidiaDaMensagem($dados);

        return [
            'base64' => $this->normalizarQrcodeBase64($base64),
            'mime' => is_string($payload['mimetype'] ?? null)
                ? $payload['mimetype']
                : ($metadados['mime'] ?? 'application/octet-stream'),
            'nome_arquivo' => is_string($payload['fileName'] ?? null)
                ? $payload['fileName']
                : ($metadados['nome_arquivo'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array{mime: ?string, nome_arquivo: ?string, caption: ?string}
     */
    public function metadadosMidiaDaMensagem(array $dados): array
    {
        $mensagem = $this->desembrulharMensagem(is_array($dados['message'] ?? null) ? $dados['message'] : []);
        $bloco = $mensagem['documentMessage']
            ?? $mensagem['imageMessage']
            ?? $mensagem['videoMessage']
            ?? [];

        if (! is_array($bloco)) {
            $bloco = [];
        }

        $nome = $bloco['fileName'] ?? $bloco['title'] ?? null;
        $mime = $bloco['mimetype'] ?? $bloco['mimeType'] ?? null;
        $caption = $bloco['caption'] ?? null;

        return [
            'mime' => is_string($mime) ? $mime : null,
            'nome_arquivo' => is_string($nome) && $nome !== '' ? $nome : null,
            'caption' => is_string($caption) && $caption !== '' ? $caption : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $mensagem
     * @return array<string, mixed>
     */
    public function desembrulharMensagem(array $mensagem): array
    {
        foreach ([
            'ephemeralMessage',
            'viewOnceMessage',
            'viewOnceMessageV2',
            'documentWithCaptionMessage',
            'viewOnceMessageV2Extension',
        ] as $envelope) {
            if (isset($mensagem[$envelope]['message']) && is_array($mensagem[$envelope]['message'])) {
                return $this->desembrulharMensagem($mensagem[$envelope]['message']);
            }
        }

        return $mensagem;
    }

    public function mensagemTemMidia(array $dados): bool
    {
        $mensagem = $this->desembrulharMensagem(is_array($dados['message'] ?? null) ? $dados['message'] : []);

        return isset($mensagem['documentMessage'])
            || isset($mensagem['imageMessage'])
            || isset($mensagem['videoMessage']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarMensagensChat(ConexaoWhatsapp $conexao, string $remoteJid, int $limit = 50, int $page = 1): array
    {
        $instancia = (string) $conexao->nome_instancia;
        $response = $this->requisicao($conexao, 'POST', "/chat/findMessages/{$instancia}", [
            'where' => [
                'key' => [
                    'remoteJid' => $remoteJid,
                ],
            ],
            'page' => $page,
            'limit' => $limit,
        ], 60);

        if (! $response->successful()) {
            Log::warning('Evolution: falha ao listar mensagens do grupo.', [
                'jid' => $remoteJid,
                'body' => $response->body(),
            ]);

            return [];
        }

        $payload = $response->json() ?? [];
        $records = $payload['messages']['records']
            ?? $payload['records']
            ?? (is_array($payload) && array_is_list($payload) ? $payload : []);

        if (! is_array($records)) {
            return [];
        }

        $filtrados = [];

        foreach ($records as $item) {
            if (is_array($item)) {
                $filtrados[] = $item;
            }
        }

        return $filtrados;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array{base64: string, mime: string, nome_arquivo: ?string}|null
     */
    private function midiaEmbutidaNoPayload(array $dados): ?array
    {
        $mensagem = is_array($dados['message'] ?? null) ? $dados['message'] : [];
        $base64 = $mensagem['base64'] ?? $dados['base64'] ?? null;

        if (! is_string($base64) || $base64 === '') {
            return null;
        }

        $metadados = $this->metadadosMidiaDaMensagem($dados);

        return [
            'base64' => $this->normalizarQrcodeBase64($base64),
            'mime' => $metadados['mime'] ?? 'application/octet-stream',
            'nome_arquivo' => $metadados['nome_arquivo'] ?? null,
        ];
    }

    public function telefoneDeJid(?string $jid): ?string
    {
        if ($jid === null || $jid === '') {
            return null;
        }

        $antesArroba = explode('@', $jid, 2)[0];
        $digitos = preg_replace('/\D+/', '', $antesArroba) ?? '';

        return $digitos !== '' ? $digitos : null;
    }

    private function chaveCacheQrcode(string $instancia): string
    {
        return 'evolution:qrcode:'.mb_strtolower($instancia);
    }

    private function instanciaExiste(ConexaoWhatsapp $conexao, string $instancia): bool
    {
        return $this->encontrarInstancia($conexao, $instancia) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function encontrarInstancia(ConexaoWhatsapp $conexao, string $instancia): ?array
    {
        $response = $this->requisicao($conexao, 'GET', '/instance/fetchInstances');

        if (! $response->successful()) {
            return null;
        }

        $lista = $response->json();

        if (! is_array($lista)) {
            return null;
        }

        foreach ($lista as $item) {
            if (! is_array($item)) {
                continue;
            }

            $nome = $item['name'] ?? $item['instanceName'] ?? $item['instance']['instanceName'] ?? null;

            if ($nome === $instancia) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    public function requisicao(
        ConexaoWhatsapp $conexao,
        string $metodo,
        string $caminho,
        ?array $body = null,
        int $timeout = 30,
    ): Response {
        $baseUrl = rtrim((string) ($conexao->url_base_evolution ?: config('evolution.url_base')), '/');
        $apiKey = $conexao->apiKeyEvolution() ?? (string) config('evolution.api_key');

        if ($baseUrl === '') {
            throw new \RuntimeException('URL base da API WhatsApp Web não configurada.');
        }

        $cliente = Http::withHeaders([
            'apikey' => $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout($timeout);

        $url = $baseUrl.$caminho;

        try {
            return match (strtoupper($metodo)) {
                'GET' => $cliente->get($url),
                'POST' => $cliente->post($url, $body ?? []),
                'PUT' => $cliente->put($url, $body ?? []),
                'DELETE' => $cliente->delete($url),
                default => throw new \InvalidArgumentException("Método HTTP inválido: {$metodo}"),
            };
        } catch (ConnectionException $exception) {
            Log::error('Evolution indisponível.', [
                'url' => $url,
                'erro' => $exception->getMessage(),
            ]);

            throw new \RuntimeException(
                'Não foi possível falar com o WhatsApp agora. Tente de novo em instantes.',
                0,
                $exception,
            );
        }
    }
}
