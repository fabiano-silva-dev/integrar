<?php

namespace App\Services\AutomacaoFiscal\Sefaz;

use RuntimeException;

class NfseConsultaDiagnosticoException extends RuntimeException
{
    public function __construct(public readonly string $codigoTecnico, public readonly ?int $http = null, public readonly ?int $curl = null)
    {
        parent::__construct('Consulta de diagnóstico não confirmada.');
    }
}
