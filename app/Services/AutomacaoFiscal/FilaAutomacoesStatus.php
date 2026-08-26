<?php

namespace App\Services\AutomacaoFiscal;

class FilaAutomacoesStatus
{
    public function ambienteDesenvolvimento(): bool
    {
        return app()->environment('local');
    }

    public function precisaWorker(): bool
    {
        if ((bool) config('automacao_fiscal.fake_mode', true)) {
            return false;
        }

        $conexao = (string) config('queue.default');

        return $conexao !== '' && $conexao !== 'sync';
    }

    public function workerAutomacoesAtivo(): bool
    {
        foreach (glob('/proc/[0-9]*') as $dir) {
            $cmd = @file_get_contents($dir.'/cmdline');
            if (! is_string($cmd) || $cmd === '') {
                continue;
            }

            if ($this->cmdlineEhWorkerAutomacoes(str_replace("\0", ' ', $cmd))) {
                return true;
            }
        }

        return false;
    }

    public function cmdlineEhWorkerAutomacoes(string $cmd): bool
    {
        if (str_contains($cmd, 'php -r')) {
            return false;
        }

        return str_contains($cmd, 'queue:work') && str_contains($cmd, 'automacoes');
    }

    /**
     * @return array{titulo: string, texto: string, comando: string}|null
     */
    public function avisoDesenvolvimento(?bool $workerAtivo = null): ?array
    {
        if (! $this->ambienteDesenvolvimento() || ! $this->precisaWorker()) {
            return null;
        }

        $ativo = $workerAtivo ?? $this->workerAutomacoesAtivo();
        if ($ativo) {
            return null;
        }

        return [
            'titulo' => 'Fila de automações parada',
            'texto' => 'Consultas e validação de acesso ficam em “Na fila” e não executam até o worker estar no ar.',
            'comando' => $this->comandoWorker(),
        ];
    }

    public function mensagemBloqueioDesenvolvimento(?bool $workerAtivo = null): ?string
    {
        $aviso = $this->avisoDesenvolvimento($workerAtivo);
        if ($aviso === null) {
            return null;
        }

        return $aviso['titulo'].'. '.$aviso['texto'].' No terminal: '.$aviso['comando'];
    }

    public function comandoWorker(): string
    {
        if (is_file('/.dockerenv')) {
            return 'docker compose exec -d app php artisan queue:work database --queue=automacoes,documentos,default --timeout=900 --sleep=2 --tries=1';
        }

        return 'php artisan queue:work database --queue=automacoes,documentos,default --timeout=900 --sleep=2 --tries=1';
    }
}
