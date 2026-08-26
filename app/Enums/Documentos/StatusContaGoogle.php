<?php

namespace App\Enums\Documentos;

enum StatusContaGoogle: string
{
    case Desconectado = 'desconectado';
    case Conectado = 'conectado';
    case Expirado = 'expirado';
    case Revogado = 'revogado';
    case Erro = 'erro';

    public function rotulo(): string
    {
        return match ($this) {
            self::Desconectado => 'Desconectado',
            self::Conectado => 'Conectado',
            self::Expirado => 'Sessão expirada',
            self::Revogado => 'Acesso revogado',
            self::Erro => 'Erro',
        };
    }
}
