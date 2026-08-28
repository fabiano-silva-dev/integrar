<?php

namespace App\Services\AutomacaoFiscal;

use DanfseNacional\DanfseGenerator;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Throwable;

/**
 * Gera o DANFSe v2.0 (NT 008/2026) a partir do XML nacional autorizado.
 */
class NfseDanfseGenerator
{
    public function gerarPdf(string $xml): string
    {
        $nfseXml = $this->extrairXmlNfse($xml);

        try {
            $pdf = (new DanfseGenerator())->generateFromXml($nfseXml);
        } catch (Throwable $e) {
            throw new RuntimeException('Não foi possível gerar o DANFSe: '.$e->getMessage(), 0, $e);
        }

        if ($pdf === '' || ! str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('Não foi possível gerar o DANFSe.');
        }

        return $pdf;
    }

    public function extrairXmlNfse(string $xml): string
    {
        $xml = ltrim($xml);
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $xml = substr($xml, 3);
        }

        if ($xml === '' || ! str_contains($xml, '<')) {
            throw new RuntimeException('O arquivo não é um XML de NFS-e.');
        }

        $anterior = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        if (! $ok || $doc->documentElement === null) {
            throw new RuntimeException('XML da NFS-e inválido.');
        }

        $raiz = $doc->documentElement;
        if (strcasecmp($raiz->localName, 'NFSe') === 0) {
            return $xml;
        }

        $xpath = new DOMXPath($doc);
        $nos = $xpath->query('//*[local-name()="NFSe"]');
        $nfse = ($nos !== false && $nos->length > 0) ? $nos->item(0) : null;
        if (! $nfse instanceof DOMElement) {
            throw new RuntimeException('O arquivo não é um XML de NFS-e.');
        }

        if ($nfse->namespaceURI && ! $nfse->hasAttribute('xmlns')) {
            $nfse->setAttribute('xmlns', $nfse->namespaceURI);
        }

        $saida = new DOMDocument('1.0', 'UTF-8');
        $importado = $saida->importNode($nfse, true);
        $saida->appendChild($importado);
        $exportado = $saida->saveXML();
        if (! is_string($exportado) || $exportado === '') {
            throw new RuntimeException('XML da NFS-e inválido.');
        }

        return $exportado;
    }
}
