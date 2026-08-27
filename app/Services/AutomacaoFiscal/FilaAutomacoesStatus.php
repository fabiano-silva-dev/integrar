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

        return $this->filaAssincrona();
    }

    public function workerAutomacoesAtivo(): bool
    {
        return $this->workerCobreFila('automacoes');
    }

    public function workerCobreFila(string $fila): bool
    {
        foreach (glob('/proc/[0-9]*') as $dir) {
            $cmd = @file_get_contents($dir.'/cmdline');
            if (! is_string($cmd) || $cmd === '') {
                continue;
            }

            if ($this->cmdlineCobreFila(str_replace("\0", ' ', $cmd), $fila)) {
                return true;
            }
        }

        return false;
    }

    public function cmdlineEhWorkerAutomacoes(string $cmd): bool
    {
        return $this->cmdlineCobreFila($cmd, 'automacoes');
    }

    public function cmdlineCobreFila(string $cmd, string $fila): bool
    {
        if (str_contains($cmd, 'php -r')) {
            return false;
        }

        if (! str_contains($cmd, 'queue:work')) {
            return false;
        }

        if (preg_match('/--queue[= ]([^\s]+)/', $cmd, $matches) === 1) {
            $filas = array_map('trim', explode(',', $matches[1]));

            return in_array($fila, $filas, true);
        }

        return $fila === 'default';
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

    /**
     * Aviso no cabeçalho (só local). Independente do modo simulado da automação fiscal:
     * documentos do WhatsApp também usam a fila.
     *
     * @return array{titulo: string, texto: string, comando: string}|null
     */
    public function avisoCabecalhoDesenvolvimento(?bool $cobreDocumentos = null, ?bool $cobreAutomacoes = null): ?array
    {
        if (! $this->ambienteDesenvolvimento() || ! $this->filaAssincrona()) {
            return null;
        }

        $documentos = $cobreDocumentos ?? $this->workerCobreFila('documentos');
        $automacoes = $cobreAutomacoes ?? $this->workerCobreFila('automacoes');

        if ($documentos && $automacoes) {
            return null;
        }

        $faltando = [];
        if (! $automacoes) {
            $faltando[] = 'automações';
        }
        if (! $documentos) {
            $faltando[] = 'documentos do WhatsApp';
        }

        return [
            'titulo' => 'Fila parada no desenvolvimento',
            'texto' => 'Não há worker ouvindo '.implode(' e ', $faltando).'. Os jobs ficam parados até iniciar o comando abaixo.',
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

    private function filaAssincrona(): bool
    {
        $conexao = (string) config('queue.default');

        return $conexao !== '' && $conexao !== 'sync';
    }
}
