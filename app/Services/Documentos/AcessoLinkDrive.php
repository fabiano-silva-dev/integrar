<?php

namespace App\Services\Documentos;

use Google\Service\Drive;
use Google\Service\Drive\Permission;
use Illuminate\Support\Facades\Log;

class AcessoLinkDrive
{
    /**
     * Qualquer pessoa com o link lê o item (não aparece em buscas públicas).
     * Se o Google Workspace bloquear "anyone", tenta o domínio da conta do escritório.
     */
    public function garantir(Drive $drive, string $fileId, ?string $dominioWorkspace = null): void
    {
        if ($fileId === '' || $fileId === 'root') {
            return;
        }

        try {
            if ($this->jaLiberado($this->resumoPermissoes($drive, $fileId))) {
                return;
            }

            $drive->permissions->create($fileId, $this->permissaoAnyone(), [
                'fields' => 'id',
                'supportsAllDrives' => true,
            ]);
        } catch (\Throwable $exception) {
            if ($this->tentarDominio($drive, $fileId, $dominioWorkspace)) {
                return;
            }

            Log::warning('Google: não foi possível liberar o acesso pelo link.', [
                'file_id' => $fileId,
                'erro' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<array{type?: string, role?: string}>  $permissoes
     */
    public function jaLiberado(array $permissoes): bool
    {
        foreach ($permissoes as $perm) {
            $tipo = (string) ($perm['type'] ?? '');
            $papel = (string) ($perm['role'] ?? '');

            if (in_array($tipo, ['anyone', 'domain'], true)
                && in_array($papel, ['reader', 'commenter', 'writer', 'organizer', 'fileOrganizer'], true)) {
                return true;
            }
        }

        return false;
    }

    public function dominioWorkspace(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));
        $pos = strrpos($email, '@');

        if ($pos === false) {
            return null;
        }

        $dominio = substr($email, $pos + 1);
        $pessoais = ['gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'icloud.com'];

        if ($dominio === '' || in_array($dominio, $pessoais, true)) {
            return null;
        }

        return $dominio;
    }

    public function permissaoAnyone(): Permission
    {
        $perm = new Permission();
        $perm->setType('anyone');
        $perm->setRole('reader');
        $perm->setAllowFileDiscovery(false);

        return $perm;
    }

    /**
     * @return list<array{type: string, role: string}>
     */
    private function resumoPermissoes(Drive $drive, string $fileId): array
    {
        $lista = $drive->permissions->listPermissions($fileId, [
            'fields' => 'permissions(id, type, role)',
            'supportsAllDrives' => true,
        ]);

        $resumo = [];

        foreach ($lista->getPermissions() ?? [] as $perm) {
            $resumo[] = [
                'type' => (string) $perm->getType(),
                'role' => (string) $perm->getRole(),
            ];
        }

        return $resumo;
    }

    private function tentarDominio(Drive $drive, string $fileId, ?string $dominio): bool
    {
        if ($dominio === null || $dominio === '') {
            return false;
        }

        try {
            $perm = new Permission();
            $perm->setType('domain');
            $perm->setRole('reader');
            $perm->setDomain($dominio);
            $perm->setAllowFileDiscovery(false);

            $drive->permissions->create($fileId, $perm, [
                'fields' => 'id',
                'supportsAllDrives' => true,
                'sendNotificationEmail' => false,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
