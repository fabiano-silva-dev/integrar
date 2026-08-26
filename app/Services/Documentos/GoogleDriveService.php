<?php

namespace App\Services\Documentos;

use App\Enums\Documentos\StatusContaGoogle;
use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Models\Documentos\ContaGoogle;
use App\Models\Documentos\ConfiguracaoGoogle;
use App\Models\Documentos\EmpresaPastaDrive;
use App\Models\Empresa;
use App\Services\OperadoraContext;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Oauth2;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
    public function configurado(?int $operadoraId = null): bool
    {
        if (! class_exists(GoogleClient::class)) {
            return false;
        }

        $credenciais = $this->credenciais($operadoraId);

        return $credenciais['client_id'] !== '' && $credenciais['client_secret'] !== '';
    }

    public function uriRedirecionamento(): string
    {
        return (string) config('documentos.google.redirect');
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    public function credenciais(?int $operadoraId = null): array
    {
        $operadoraId ??= OperadoraContext::id();

        if ($operadoraId !== null) {
            $cfg = ConfiguracaoGoogle::daOperadora($operadoraId);

            if ($cfg !== null && $cfg->pronta()) {
                return [
                    'client_id' => (string) $cfg->client_id,
                    'client_secret' => (string) $cfg->client_secret,
                ];
            }
        }

        return [
            'client_id' => (string) config('documentos.google.client_id'),
            'client_secret' => (string) config('documentos.google.client_secret'),
        ];
    }

    public function salvarCredenciais(int $operadoraId, string $clientId, string $clientSecret): ConfiguracaoGoogle
    {
        $existente = ConfiguracaoGoogle::daOperadora($operadoraId);
        $secret = $clientSecret !== '' ? $clientSecret : (string) ($existente?->client_secret ?? '');

        if ($clientId === '' || $secret === '') {
            throw new \RuntimeException('Informe o ID do cliente e a chave secreta do Google.');
        }

        return ConfiguracaoGoogle::withoutGlobalScope('operadora')->updateOrCreate(
            ['empresa_operadora_id' => $operadoraId],
            [
                'client_id' => $clientId,
                'client_secret' => $secret,
                'configurado_em' => now(),
            ],
        );
    }

    public function urlAutorizacao(string $state, ?int $operadoraId = null): string
    {
        $client = $this->clienteBase($operadoraId);
        $client->setState($state);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        $client->setIncludeGrantedScopes(true);

        return $client->createAuthUrl();
    }

    /**
     * @return array{email: ?string, access_token: string, refresh_token: ?string, expires_at: \DateTimeInterface, scopes: string}
     */
    public function trocarCode(string $code, ?int $operadoraId = null): array
    {
        $client = $this->clienteBase($operadoraId);
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \RuntimeException('Google recusou o código de autorização: '.($token['error_description'] ?? $token['error']));
        }

        $client->setAccessToken($token);
        $email = null;

        try {
            $oauth = new Oauth2($client);
            $email = $oauth->userinfo->get()->getEmail();
        } catch (\Throwable $exception) {
            Log::warning('Google: não foi possível ler o e-mail da conta.', [
                'erro' => $exception->getMessage(),
            ]);
        }

        $expiresIn = (int) ($token['expires_in'] ?? 3600);

        return [
            'email' => is_string($email) ? $email : null,
            'access_token' => (string) ($token['access_token'] ?? ''),
            'refresh_token' => isset($token['refresh_token']) ? (string) $token['refresh_token'] : null,
            'expires_at' => now()->addSeconds($expiresIn),
            'scopes' => is_string($token['scope'] ?? null)
                ? $token['scope']
                : implode(' ', config('documentos.google.scopes', [])),
        ];
    }

    public function clienteDaConta(ContaGoogle $conta): GoogleClient
    {
        $client = $this->clienteBase((int) $conta->empresa_operadora_id);
        $expiresAt = $conta->token_expires_at;
        $created = $expiresAt !== null ? $expiresAt->getTimestamp() - 3600 : time();

        $client->setAccessToken([
            'access_token' => (string) $conta->access_token,
            'refresh_token' => (string) $conta->refresh_token,
            'created' => $created,
            'expires_in' => $expiresAt !== null ? max(0, $expiresAt->getTimestamp() - time()) : 0,
        ]);

        if ($client->isAccessTokenExpired() && $conta->refresh_token) {
            try {
                $novo = $client->fetchAccessTokenWithRefreshToken($conta->refresh_token);
            } catch (\Throwable $exception) {
                $conta->update(['status' => StatusContaGoogle::Expirado]);

                throw new \RuntimeException('Sessão Google expirada. Conecte a conta de novo.');
            }

            if (isset($novo['error'])) {
                $conta->update(['status' => StatusContaGoogle::Expirado]);

                throw new \RuntimeException('Sessão Google expirada. Conecte a conta de novo.');
            }

            $expiresIn = (int) ($novo['expires_in'] ?? 3600);
            $refresh = isset($novo['refresh_token']) && $novo['refresh_token'] !== ''
                ? (string) $novo['refresh_token']
                : $conta->refresh_token;

            $conta->update([
                'access_token' => (string) ($novo['access_token'] ?? $conta->access_token),
                'refresh_token' => $refresh,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'status' => StatusContaGoogle::Conectado,
            ]);

            $client->setAccessToken($client->getAccessToken());
        }

        return $client;
    }

    /**
     * @return list<array{id: string, nome: string, tipo: string}>
     */
    public function listarPastas(ContaGoogle $conta, ?string $pastaPaiId = null): array
    {
        $drive = new Drive($this->clienteDaConta($conta));

        if ($pastaPaiId === null || $pastaPaiId === '') {
            $itens = [[
                'id' => 'root',
                'nome' => 'Meu Drive',
                'tipo' => 'raiz',
            ]];

            try {
                $drives = $drive->drives->listDrives(['pageSize' => 50]);
                foreach ($drives->getDrives() ?? [] as $unidade) {
                    $itens[] = [
                        'id' => (string) $unidade->getId(),
                        'nome' => (string) $unidade->getName(),
                        'tipo' => 'unidade',
                    ];
                }
            } catch (\Throwable $exception) {
                Log::info('Google: unidades compartilhadas indisponíveis.', [
                    'erro' => $exception->getMessage(),
                ]);
            }

            return $itens;
        }

        $pai = $this->escaparQuery($pastaPaiId);
        $lista = $drive->files->listFiles([
            'q' => "mimeType = 'application/vnd.google-apps.folder' and trashed = false and '{$pai}' in parents",
            'pageSize' => 100,
            'fields' => 'files(id, name)',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
            'orderBy' => 'name',
        ]);

        $pastas = [];

        foreach ($lista->getFiles() ?? [] as $arquivo) {
            $pastas[] = [
                'id' => (string) $arquivo->getId(),
                'nome' => (string) $arquivo->getName(),
                'tipo' => 'pasta',
            ];
        }

        return $pastas;
    }

    public function definirPastaRaiz(Empresa $empresa, ContaGoogle $conta, string $folderId, string $nome): EmpresaPastaDrive
    {
        $pasta = $this->persistirPasta(
            $empresa,
            EmpresaPastaDrive::TIPO_RAIZ,
            EmpresaPastaDrive::ANO_RAIZ,
            $folderId,
            $nome,
            $this->webViewLinkPasta($conta, $folderId),
        );

        try {
            $this->garantirEstruturaAno($conta, $empresa, (int) now()->format('Y'));
        } catch (\Throwable $exception) {
            Log::warning('Google: pasta raiz definida, mas a estrutura do ano não foi criada.', [
                'empresa_id' => $empresa->id,
                'erro' => $exception->getMessage(),
            ]);
        }

        return $pasta;
    }

    public function criarEDefinirPastaRaiz(
        Empresa $empresa,
        ContaGoogle $conta,
        string $pastaPaiId,
        string $nome,
    ): EmpresaPastaDrive {
        $pai = $pastaPaiId === '' ? 'root' : $pastaPaiId;
        $drive = new Drive($this->clienteDaConta($conta));
        $encontrada = $this->buscarPastaFilha($drive, $pai, $nome);
        $id = is_array($encontrada) ? $encontrada['id'] : null;

        if ($id === null) {
            $meta = new DriveFile([
                'name' => $nome,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$pai],
            ]);
            $criada = $drive->files->create($meta, [
                'fields' => 'id, name, webViewLink',
                'supportsAllDrives' => true,
            ]);
            $id = (string) $criada->getId();
        }

        return $this->definirPastaRaiz($empresa, $conta, $id, $nome);
    }

    public function garantirEstruturaAno(ContaGoogle $conta, Empresa $empresa, int $ano): void
    {
        $raiz = EmpresaPastaDrive::raizDaEmpresa($empresa->id);

        if ($raiz === null) {
            throw new \RuntimeException('Defina a pasta raiz do Drive desta empresa.');
        }

        $pastaAno = $this->garantirSubpasta(
            $conta,
            $empresa,
            $raiz->google_folder_id,
            (string) $ano,
            EmpresaPastaDrive::tipoAno($ano),
            $ano,
        );

        foreach (TipoDocumentoRecebido::pastasEstrutura() as $tipo) {
            $this->garantirSubpasta(
                $conta,
                $empresa,
                $pastaAno->google_folder_id,
                $tipo->pastaDrive(),
                $tipo->value,
                $ano,
            );
        }
    }

    /**
     * @return array{id: string, link: ?string, path: string}
     */
    public function enviarArquivo(
        ContaGoogle $conta,
        Empresa $empresa,
        TipoDocumentoRecebido $tipo,
        int $ano,
        string $nomeArquivo,
        string $conteudo,
        ?string $mime = null,
    ): array {
        $this->garantirEstruturaAno($conta, $empresa, $ano);

        $pasta = EmpresaPastaDrive::pastaTipo($empresa->id, $tipo, $ano);

        if ($pasta === null) {
            throw new \RuntimeException('Pasta de destino no Drive não encontrada.');
        }

        $drive = new Drive($this->clienteDaConta($conta));
        $nomeFinal = $this->nomeUnico($drive, $pasta->google_folder_id, $nomeArquivo);

        $meta = new DriveFile([
            'name' => $nomeFinal,
            'parents' => [$pasta->google_folder_id],
        ]);

        $criado = $drive->files->create($meta, [
            'data' => $conteudo,
            'mimeType' => $mime ?: 'application/octet-stream',
            'uploadType' => 'multipart',
            'fields' => 'id, name, webViewLink',
            'supportsAllDrives' => true,
        ]);

        $link = $criado->getWebViewLink() ?: EmpresaPastaDrive::urlArquivo((string) $criado->getId());

        return [
            'id' => (string) $criado->getId(),
            'link' => $link,
            'path' => $ano.'/'.$tipo->pastaDrive().'/'.$nomeFinal,
        ];
    }

    public function baixarConteudo(ContaGoogle $conta, string $fileId): string
    {
        $drive = new Drive($this->clienteDaConta($conta));
        $resposta = $drive->files->get($fileId, [
            'alt' => 'media',
            'supportsAllDrives' => true,
        ]);

        return $resposta->getBody()->getContents();
    }

    private function garantirSubpasta(
        ContaGoogle $conta,
        Empresa $empresa,
        string $paiId,
        string $nome,
        string $tipo,
        int $ano,
    ): EmpresaPastaDrive {
        $existente = EmpresaPastaDrive::withoutGlobalScope('operadora')
            ->where('empresa_id', $empresa->id)
            ->where('tipo', $tipo)
            ->where('ano', $ano)
            ->first();

        if ($existente !== null && $this->pastaExiste($conta, $existente->google_folder_id)) {
            if (trim((string) ($existente->google_web_link ?? '')) === '') {
                $existente->update([
                    'google_web_link' => $this->webViewLinkPasta($conta, $existente->google_folder_id),
                ]);
            }

            return $existente->fresh() ?? $existente;
        }

        $drive = new Drive($this->clienteDaConta($conta));
        $encontrada = $this->buscarPastaFilha($drive, $paiId, $nome);
        $id = is_array($encontrada) ? $encontrada['id'] : null;
        $link = is_array($encontrada) ? ($encontrada['link'] ?? null) : null;

        if ($id === null) {
            $meta = new DriveFile([
                'name' => $nome,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$paiId],
            ]);
            $criada = $drive->files->create($meta, [
                'fields' => 'id, name, webViewLink',
                'supportsAllDrives' => true,
            ]);
            $id = (string) $criada->getId();
            $link = $criada->getWebViewLink();
        }

        return $this->persistirPasta(
            $empresa,
            $tipo,
            $ano,
            $id,
            $nome,
            is_string($link) && $link !== '' ? $link : EmpresaPastaDrive::urlPasta($id),
        );
    }

    private function pastaExiste(ContaGoogle $conta, string $folderId): bool
    {
        try {
            $drive = new Drive($this->clienteDaConta($conta));
            $drive->files->get($folderId, [
                'fields' => 'id, trashed',
                'supportsAllDrives' => true,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{id: string, link: ?string}|null
     */
    private function buscarPastaFilha(Drive $drive, string $paiId, string $nome): ?array
    {
        $pai = $this->escaparQuery($paiId);
        $nomeEsc = $this->escaparQuery($nome);

        $lista = $drive->files->listFiles([
            'q' => "mimeType = 'application/vnd.google-apps.folder' and trashed = false and name = '{$nomeEsc}' and '{$pai}' in parents",
            'pageSize' => 1,
            'fields' => 'files(id, webViewLink)',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);

        $arquivos = $lista->getFiles() ?? [];

        if (! isset($arquivos[0])) {
            return null;
        }

        return [
            'id' => (string) $arquivos[0]->getId(),
            'link' => $arquivos[0]->getWebViewLink(),
        ];
    }

    private function persistirPasta(
        Empresa $empresa,
        string $tipo,
        int $ano,
        string $folderId,
        string $nome,
        ?string $webLink,
    ): EmpresaPastaDrive {
        $link = is_string($webLink) && $webLink !== '' ? $webLink : EmpresaPastaDrive::urlPasta($folderId);

        return EmpresaPastaDrive::withoutGlobalScope('operadora')->updateOrCreate(
            [
                'empresa_id' => $empresa->id,
                'tipo' => $tipo,
                'ano' => $ano,
            ],
            [
                'empresa_operadora_id' => $empresa->empresa_operadora_id,
                'google_folder_id' => $folderId,
                'google_folder_nome' => $nome,
                'google_web_link' => $link,
            ],
        );
    }

    private function webViewLinkPasta(ContaGoogle $conta, string $folderId): string
    {
        if ($folderId === '' || $folderId === 'root') {
            return EmpresaPastaDrive::urlPasta($folderId === '' ? 'root' : $folderId);
        }

        try {
            $drive = new Drive($this->clienteDaConta($conta));
            $pasta = $drive->files->get($folderId, [
                'fields' => 'id, webViewLink',
                'supportsAllDrives' => true,
            ]);
            $link = $pasta->getWebViewLink();

            if (is_string($link) && $link !== '') {
                return $link;
            }
        } catch (\Throwable $exception) {
            Log::info('Google: não foi possível ler o link da pasta.', [
                'folder_id' => $folderId,
                'erro' => $exception->getMessage(),
            ]);
        }

        return EmpresaPastaDrive::urlPasta($folderId);
    }

    private function nomeUnico(Drive $drive, string $pastaId, string $nome): string
    {
        $base = $nome;
        $ext = pathinfo($nome, PATHINFO_EXTENSION);
        $stem = $ext !== '' ? substr($nome, 0, -(strlen($ext) + 1)) : $nome;
        $tentativa = $base;
        $n = 2;

        while ($this->arquivoExiste($drive, $pastaId, $tentativa)) {
            $tentativa = $ext !== '' ? "{$stem}_{$n}.{$ext}" : "{$stem}_{$n}";
            $n++;
            if ($n > 50) {
                break;
            }
        }

        return $tentativa;
    }

    private function arquivoExiste(Drive $drive, string $pastaId, string $nome): bool
    {
        $pai = $this->escaparQuery($pastaId);
        $nomeEsc = $this->escaparQuery($nome);
        $lista = $drive->files->listFiles([
            'q' => "trashed = false and name = '{$nomeEsc}' and '{$pai}' in parents",
            'pageSize' => 1,
            'fields' => 'files(id)',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);

        return ($lista->getFiles() ?? []) !== [];
    }

    private function escaparQuery(string $valor): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $valor);
    }

    private function clienteBase(?int $operadoraId = null): GoogleClient
    {
        $credenciais = $this->credenciais($operadoraId);

        if ($credenciais['client_id'] === '' || $credenciais['client_secret'] === '') {
            throw new \RuntimeException('Conclua a configuração do aplicativo Google neste escritório antes de conectar a conta.');
        }

        $client = new GoogleClient();
        $client->setClientId($credenciais['client_id']);
        $client->setClientSecret($credenciais['client_secret']);
        $client->setRedirectUri($this->uriRedirecionamento());
        $client->setScopes(config('documentos.google.scopes', []));
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');

        return $client;
    }
}
