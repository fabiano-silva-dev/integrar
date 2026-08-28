<?php

namespace App\Services\Documentos;

use Google\Service\Drive;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Support\Facades\Log;

class AcessoLinkDrive
{
    /**
     * @param  list<array{id?: string, type?: string, role?: string}>  $permissoes
     */
    public function jaLiberado(array $permissoes): bool
    {
        foreach ($permissoes as $perm) {
            if ($this->ehPermissaoPublica($perm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{id?: string, type?: string, role?: string}  $perm
     */
    public function ehPermissaoPublica(array $perm): bool
    {
        $tipo = (string) ($perm['type'] ?? '');
        $papel = (string) ($perm['role'] ?? '');

        return in_array($tipo, ['anyone', 'domain'], true)
            && in_array($papel, ['reader', 'commenter', 'writer', 'organizer', 'fileOrganizer'], true);
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

    /**
     * @return list<array{id: string, type: string, role: string}>
     */
    public function listarPermissoes(Drive $drive, string $fileId): array
    {
        $lista = $drive->permissions->listPermissions($fileId, [
            'fields' => 'permissions(id, type, role)',
            'supportsAllDrives' => true,
        ]);

        $resumo = [];

        foreach ($lista->getPermissions() ?? [] as $perm) {
            $resumo[] = [
                'id' => (string) $perm->getId(),
                'type' => (string) $perm->getType(),
                'role' => (string) $perm->getRole(),
            ];
        }

        return $resumo;
    }

    /**
     * Remove permissões `anyone` e `domain` criadas para acesso por link.
     *
     * @return array{file_id: string, removidas: int, pulado: bool, erro: ?string, permissoes: list<array{id: string, type: string, role: string}>}
     */
    public function removerPublicas(Drive $drive, string $fileId, bool $dryRun = false): array
    {
        $vazio = [
            'file_id' => $fileId,
            'removidas' => 0,
            'pulado' => false,
            'erro' => null,
            'permissoes' => [],
        ];

        if ($fileId === '' || $fileId === 'root') {
            $vazio['pulado'] = true;

            return $vazio;
        }

        try {
            $permissoes = $this->listarPermissoes($drive, $fileId);
        } catch (GoogleServiceException $exception) {
            if ((int) $exception->getCode() === 404) {
                $vazio['pulado'] = true;
                $vazio['erro'] = 'Arquivo não encontrado no Google Drive.';

                return $vazio;
            }

            Log::warning('Google: falha ao listar permissões para remoção de link público.', [
                'file_id' => $fileId,
                'erro' => $exception->getMessage(),
            ]);

            $vazio['erro'] = 'Falha ao listar permissões.';

            return $vazio;
        } catch (\Throwable $exception) {
            Log::warning('Google: falha ao listar permissões para remoção de link público.', [
                'file_id' => $fileId,
                'erro' => $exception->getMessage(),
            ]);

            $vazio['erro'] = 'Falha ao listar permissões.';

            return $vazio;
        }

        $publicas = array_values(array_filter(
            $permissoes,
            fn (array $perm) => $this->ehPermissaoPublica($perm) && ($perm['id'] ?? '') !== '',
        ));

        $removidas = 0;

        foreach ($publicas as $perm) {
            if ($dryRun) {
                $removidas++;

                continue;
            }

            try {
                $drive->permissions->delete($fileId, $perm['id'], [
                    'supportsAllDrives' => true,
                ]);
                $removidas++;
            } catch (\Throwable $exception) {
                Log::warning('Google: não foi possível remover permissão pública.', [
                    'file_id' => $fileId,
                    'permission_id' => $perm['id'],
                    'erro' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'file_id' => $fileId,
            'removidas' => $removidas,
            'pulado' => false,
            'erro' => null,
            'permissoes' => $publicas,
        ];
    }
}
