<?php

namespace App\Services\Nfse\Dps;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** XMLDSig local; algoritmo deve ser selecionado explicitamente na homologação. */
class AssinadorDps
{
    public const NS = 'http://www.w3.org/2000/09/xmldsig#';
    public const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    public function assinar(DOMDocument $doc, string $certPem, #[\SensitiveParameter] string $keyPem, string $algoritmo): string
    {
        [$hash, $digestUri, $signUri, $openssl] = match ($algoritmo) {
            'rsa-sha1' => ['sha1', self::NS.'sha1', self::NS.'rsa-sha1', OPENSSL_ALGO_SHA1],
            'rsa-sha256' => ['sha256', 'http://www.w3.org/2001/04/xmlenc#sha256', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', OPENSSL_ALGO_SHA256],
            default => throw new RuntimeException('Algoritmo de assinatura não configurado.'),
        };
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('n', GeradorDps::NS);
        $xp->registerNamespace('s', self::NS);
        $infos = $xp->query('/n:DPS/n:infDPS');
        $ids = $xp->query('//*[@Id]');
        if ($doc->doctype || $infos->length !== 1 || $ids->length !== 1 || $xp->query('//s:Signature')->length !== 0
            || $xp->evaluate('string(/n:DPS/n:infDPS/n:tpAmb)') !== '2') {
            throw new RuntimeException('DPS local inválida ou já assinada.');
        }
        $inf = $infos->item(0);
        $id = $inf->getAttribute('Id');
        if (! preg_match('/^DPS\d{42}$/D', $id)) {
            throw new RuntimeException('Identificador de DPS inválido.');
        }
        $cert = openssl_x509_read($certPem);
        $key = openssl_pkey_get_private($keyPem);
        if (! $cert || ! $key || ! openssl_x509_check_private_key($cert, $key) || openssl_pkey_get_details($key)['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('Certificado e chave RSA incompatíveis.');
        }
        $canonical = $inf->C14N(false, false);
        if (! is_string($canonical)) {
            throw new RuntimeException('Falha na canonicalização.');
        }
        $sig = $this->no($doc->documentElement, 'Signature');
        $info = $this->no($sig, 'SignedInfo');
        $this->alg($info, 'CanonicalizationMethod', self::C14N);
        $this->alg($info, 'SignatureMethod', $signUri);
        $ref = $this->no($info, 'Reference');
        $ref->setAttribute('URI', '#'.$id);
        $transforms = $this->no($ref, 'Transforms');
        $this->alg($transforms, 'Transform', self::NS.'enveloped-signature');
        $this->alg($transforms, 'Transform', self::C14N);
        $this->alg($ref, 'DigestMethod', $digestUri);
        $this->no($ref, 'DigestValue', base64_encode(hash($hash, $canonical, true)));
        $bytes = $info->C14N(false, false);
        if (! is_string($bytes) || ! openssl_sign($bytes, $assinatura, $key, $openssl)
            || openssl_verify($bytes, $assinatura, $cert, $openssl) !== 1) {
            throw new RuntimeException('Falha ao assinar ou verificar a DPS.');
        }
        $this->no($sig, 'SignatureValue', base64_encode($assinatura));
        if (! openssl_x509_export($cert, $pem)) {
            throw new RuntimeException('Falha ao exportar certificado público.');
        }
        $x509 = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $pem);
        $this->no($this->no($this->no($sig, 'KeyInfo'), 'X509Data'), 'X509Certificate', $x509);
        $xml = $doc->saveXML();
        if (! is_string($xml)) {
            throw new RuntimeException('Falha ao serializar DPS.');
        }
        // Verificação independente da serialização, com referência única e chave pública esperada.
        $ver = new DOMDocument;
        if (! $ver->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Falha ao reler DPS assinada.');
        }
        $v = new DOMXPath($ver);
        $v->registerNamespace('n', GeradorDps::NS);
        $v->registerNamespace('s', self::NS);
        $infFinal = $v->query('/n:DPS/n:infDPS')->item(0);
        $infoFinal = $v->query('/n:DPS/s:Signature/s:SignedInfo')->item(0);
        if (! hash_equals(base64_encode(hash($hash, $infFinal->C14N(false, false), true)), $v->evaluate('string(/n:DPS/s:Signature/s:SignedInfo/s:Reference/s:DigestValue)'))
            || openssl_verify($infoFinal->C14N(false, false), $assinatura, $cert, $openssl) !== 1) {
            throw new RuntimeException('Assinatura não preservada após serialização.');
        }
        return $xml;
    }

    private function no(DOMElement $pai, string $tag, ?string $valor = null): DOMElement
    {
        $no = $pai->ownerDocument->createElementNS(self::NS, $tag);
        if ($valor !== null) {
            $no->appendChild($pai->ownerDocument->createTextNode($valor));
        }
        $pai->appendChild($no);
        return $no;
    }

    private function alg(DOMElement $pai, string $tag, string $uri): void
    {
        $this->no($pai, $tag)->setAttribute('Algorithm', $uri);
    }
}
