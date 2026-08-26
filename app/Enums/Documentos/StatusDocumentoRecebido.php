<?php

namespace App\Enums\Documentos;

enum StatusDocumentoRecebido: string
{
    case Recebido = 'recebido';
    case Classificado = 'classificado';
    case EnviadoDrive = 'enviado_drive';
    case Pendente = 'pendente';
    case Erro = 'erro';
    case Ignorado = 'ignorado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Recebido => 'Recebido',
            self::Classificado => 'Classificado',
            self::EnviadoDrive => 'Enviado ao Drive',
            self::Pendente => 'Pendente',
            self::Erro => 'Erro',
            self::Ignorado => 'Ignorado',
        };
    }
}
