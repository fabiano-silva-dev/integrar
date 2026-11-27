<?php

namespace App\Services\Nfse;

use Symfony\Component\Process\Process;

class PortalWebNfseRunner
{
    public const CODIGOS = ['ACCESS_CONFIRMED', 'INVALID_INPUT', 'LOGIN_REJECTED', 'IDENTITY_MISMATCH',
        'REPRESENTATION_NOT_CONFIRMED', 'MANUAL_REQUIRED', 'LAYOUT_CHANGED', 'UNAVAILABLE'];

    public function testar(#[\SensitiveParameter] array $entrada): array
    {
        // Processo separado do coletor: não gera input.json, stdout.log, trace ou screenshots.
        $runner = base_path('scripts/automacao-fiscal/runner');
        $process = new Process(['node', $runner.'/dist/nfse-access-cli.js'], $runner, [
            'PLAYWRIGHT_BROWSERS_PATH' => config('automacao_fiscal.playwright_browsers_path', '/var/cache/integrar-playwright'),
            'DEBUG' => false, 'PWDEBUG' => false, 'NODE_OPTIONS' => false,
        ]);
        $process->setTimeout(90);
        $process->setInput(json_encode($entrada, JSON_THROW_ON_ERROR));
        try {
            $process->run();
            $result = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            if (! $process->isSuccessful() || ! is_array($result) || ! in_array($result['code'] ?? '', self::CODIGOS, true)) {
                return $this->indisponivel();
            }
            return ['code' => $result['code'], 'identityConfirmed' => ($result['identityConfirmed'] ?? null) === true,
                'representationConfirmed' => ($result['representationConfirmed'] ?? null) === true];
        } catch (\Throwable) {
            return $this->indisponivel();
        } finally {
            $process->setInput(null);
            $process->clearOutput();
            $process->clearErrorOutput();
        }
    }

    private function indisponivel(): array
    {
        return ['code' => 'UNAVAILABLE', 'identityConfirmed' => false, 'representationConfirmed' => false];
    }
}
