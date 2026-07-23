<?php

namespace App\Services\AutomacaoFiscal\Contratos;

class ResultadoAutomacao
{
    /**
     * @param  array<int, array{nivel: string, etapa: ?string, mensagem: string, contexto?: array<string, mixed>}>  $logs
     * @param  array<string, mixed>  $metadados
     */
    public function __construct(
        public readonly string $status,
        public readonly string $mensagemUsuario,
        public readonly int $quantidadeEncontrada = 0,
        public readonly int $quantidadeImportada = 0,
        public readonly int $quantidadeIgnorada = 0,
        public readonly int $quantidadeErros = 0,
        public readonly array $logs = [],
        public readonly array $metadados = []
    ) {
    }

    public static function sucesso(string $mensagem, int $encontrada = 0, int $importada = 0): self
    {
        return new self('sucesso', $mensagem, $encontrada, $importada);
    }

    public static function falha(string $mensagem): self
    {
        return new self('falha', $mensagem, quantidadeErros: 1);
    }
}
