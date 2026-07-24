<?php

namespace Tests\Unit;

use App\Services\AutomacaoFiscal\Sefaz\NfeXmlDsigSigner;
use Tests\TestCase;

class NfeXmlDsigSignerTest extends TestCase
{
    public function test_assinar_insere_signature(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(['commonName' => 'Teste'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert);

        openssl_pkey_export($key, $keyPem);
        openssl_x509_export($cert, $certPem);

        $id = 'ID210210432606166795260001805501000000034317353512221';
        $xml = '<evento versao="1.00" xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<infEvento Id="'.$id.'">'
            .'<cOrgao>91</cOrgao><tpAmb>1</tpAmb><CNPJ>11222333000181</CNPJ>'
            .'<chNFe>43260616679526000180550100000003431735351222</chNFe>'
            .'<dhEvento>2026-07-24T12:00:00-03:00</dhEvento>'
            .'<tpEvento>210210</tpEvento><nSeqEvento>1</nSeqEvento><verEvento>1.00</verEvento>'
            .'<detEvento versao="1.00"><descEvento>Ciencia da Operacao</descEvento></detEvento>'
            .'</infEvento></evento>';

        $assinado = (new NfeXmlDsigSigner())->assinarPorId($xml, $id, $certPem, $keyPem);

        $this->assertStringContainsString('<Signature', $assinado);
        $this->assertStringContainsString('<SignatureValue>', $assinado);
        $this->assertStringContainsString('<DigestValue>', $assinado);
        $this->assertStringContainsString('URI="#'.$id.'"', $assinado);
    }
}
