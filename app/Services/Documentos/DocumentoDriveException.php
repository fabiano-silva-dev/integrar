<?php

namespace App\Services\Documentos;

use RuntimeException;

class DocumentoDriveException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function semArquivo(): self
    {
        return new self('Este documento ainda não possui arquivo disponível no Google Drive.', 404);
    }

    public static function contaDesconectada(): self
    {
        return new self('A conta Google deste escritório não está conectada.', 409);
    }

    public static function arquivoNaoEncontrado(): self
    {
        return new self(
            'O arquivo não foi encontrado no Google Drive. Ele pode ter sido removido ou movido fora do IntegraExpert.',
            404,
        );
    }

    public static function oauthExpirado(): self
    {
        return new self(
            'A conexão com o Google Drive deste escritório expirou. Reconecte a conta em Configurações > Documentos > Google Drive.',
            409,
        );
    }

    public static function falhaComunicacao(): self
    {
        return new self(
            'Não foi possível acessar o Google Drive agora. Tente novamente em alguns instantes.',
            503,
        );
    }
}
