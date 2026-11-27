<?php

namespace App\Services\Nfse\Dps;

use DOMDocument;
use RuntimeException;

class ValidadorDps
{
    /** Apenas XSD oficial instalado pelo administrador, nunca caminho fornecido por operador. */
    public function validar(string $xml): void
    {
        $configurado = (string) config('automacao_fiscal.nfse_dps_xsd_restrita', '');
        $schema = $configurado !== '' ? realpath($configurado) : false;
        $raiz = realpath(resource_path('nfse/xsd/restrita'));
        if (! $schema || ! $raiz || ! str_starts_with($schema, $raiz.DIRECTORY_SEPARATOR) || ! is_file($schema)
            || strtolower(pathinfo($schema, PATHINFO_EXTENSION)) !== 'xsd') {
            throw new RuntimeException('Instale o pacote XSD oficial de produção restrita e configure o arquivo raiz.');
        }
        if ($xml === '' || strlen($xml) > 1048576 || stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException('XML da DPS inválido.');
        }
        $anterior = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $doc = new DOMDocument;
            if (! $doc->loadXML($xml, LIBXML_NONET) || $doc->doctype || ! $doc->schemaValidate($schema)) {
                // Não expõe conteúdo fiscal, caminhos internos nem dados do certificado.
                $codigo = libxml_get_last_error()?->code ?? 0;
                throw new RuntimeException('DPS não passou no XSD oficial (código '.$codigo.').');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }
}
