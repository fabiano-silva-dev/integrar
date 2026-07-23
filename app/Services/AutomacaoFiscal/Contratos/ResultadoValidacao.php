<?php

namespace App\Services\AutomacaoFiscal\Contratos;

class ResultadoValidacao
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $mensagem,
        public readonly string $status = 'ok'
    ) {
    }

    public static function sucesso(string $mensagem = 'Configuração válida.'): self
    {
        return new self(true, $mensagem, 'ok');
    }

    public static function falha(string $mensagem, string $status = 'erro'): self
    {
        return new self(false, $mensagem, $status);
    }
}
