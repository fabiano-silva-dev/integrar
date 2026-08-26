<?php

namespace App\Enums\Documentos;

enum StatusConexaoWhatsapp: string
{
    case Desconectado = 'desconectado';
    case AguardandoQr = 'aguardando_qr';
    case Conectado = 'conectado';
    case Erro = 'erro';

    public function rotulo(): string
    {
        return match ($this) {
            self::Desconectado => 'Não conectado',
            self::AguardandoQr => 'Escaneie o QR Code',
            self::Conectado => 'Conectado',
            self::Erro => 'Não foi possível conectar',
        };
    }
}
