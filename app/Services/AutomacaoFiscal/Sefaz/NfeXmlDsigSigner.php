<?php

namespace App\Services\AutomacaoFiscal\Sefaz;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Assinatura XML-DSig (RSA-SHA1 + SHA1) no padrão NF-e para o nó com atributo Id.
 */
class NfeXmlDsigSigner
{
    /**
     * Assina o elemento identificado por $idAttr (ex.: Id="ID210210…") e insere <Signature>
     * como último filho do pai desse elemento.
     */
    public function assinarPorId(string $xml, string $idAttr, string $certPem, string $keyPem): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (@$dom->loadXML($xml) !== true) {
            throw new RuntimeException('XML inválido para assinatura.');
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query("//*[@Id='{$idAttr}' or @id='{$idAttr}']");
        if ($nodes === false || $nodes->length === 0) {
            throw new RuntimeException("Elemento com Id={$idAttr} não encontrado para assinatura.");
        }

        /** @var DOMElement $node */
        $node = $nodes->item(0);
        $canonical = $node->C14N(false, false);
        if ($canonical === false || $canonical === '') {
            throw new RuntimeException('Falha ao canonicalizar o elemento a assinar.');
        }

        $digest = base64_encode(hash('sha1', $canonical, true));
        $ds = 'http://www.w3.org/2000/09/xmldsig#';

        $signature = $dom->createElementNS($ds, 'Signature');
        $signedInfo = $dom->createElementNS($ds, 'SignedInfo');

        $c14nMethod = $dom->createElementNS($ds, 'CanonicalizationMethod');
        $c14nMethod->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($c14nMethod);

        $sigMethod = $dom->createElementNS($ds, 'SignatureMethod');
        $sigMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1');
        $signedInfo->appendChild($sigMethod);

        $reference = $dom->createElementNS($ds, 'Reference');
        $reference->setAttribute('URI', '#'.$idAttr);

        $transforms = $dom->createElementNS($ds, 'Transforms');
        $t1 = $dom->createElementNS($ds, 'Transform');
        $t1->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $t2 = $dom->createElementNS($ds, 'Transform');
        $t2->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $transforms->appendChild($t1);
        $transforms->appendChild($t2);
        $reference->appendChild($transforms);

        $digestMethod = $dom->createElementNS($ds, 'DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $reference->appendChild($digestMethod);
        $reference->appendChild($dom->createElementNS($ds, 'DigestValue', $digest));
        $signedInfo->appendChild($reference);

        $signature->appendChild($signedInfo);
        $signatureValue = $dom->createElementNS($ds, 'SignatureValue');
        $signature->appendChild($signatureValue);

        $keyInfo = $dom->createElementNS($ds, 'KeyInfo');
        $x509Data = $dom->createElementNS($ds, 'X509Data');
        $x509Data->appendChild($dom->createElementNS($ds, 'X509Certificate', $this->certificadoBase64($certPem)));
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        $parent = $node->parentNode;
        if ($parent === null) {
            throw new RuntimeException('Elemento assinado sem nó pai.');
        }
        $parent->appendChild($signature);

        $siCanon = $signedInfo->C14N(false, false);
        if (! is_string($siCanon) || $siCanon === '') {
            throw new RuntimeException('Falha ao canonicalizar SignedInfo.');
        }

        $pkey = openssl_pkey_get_private($keyPem);
        if ($pkey === false) {
            throw new RuntimeException('Chave privada inválida para assinatura XML.');
        }

        $signatureBin = '';
        if (! openssl_sign($siCanon, $signatureBin, $pkey, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('Falha ao assinar SignedInfo (RSA-SHA1).');
        }

        $signatureValue->appendChild($dom->createTextNode(base64_encode($signatureBin)));

        $out = $dom->saveXML($dom->documentElement);
        if (! is_string($out) || $out === '') {
            throw new RuntimeException('Falha ao serializar XML assinado.');
        }

        return $out;
    }

    private function certificadoBase64(string $certPem): string
    {
        $clean = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $certPem) ?? '';
        if ($clean === '') {
            throw new RuntimeException('Certificado X509 vazio para KeyInfo.');
        }

        return $clean;
    }
}
