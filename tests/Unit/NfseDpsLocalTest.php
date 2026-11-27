<?php

namespace Tests\Unit;

use App\Services\Nfse\Dps\AssinadorDps;
use App\Services\Nfse\Dps\GeradorDps;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NfseDpsLocalTest extends TestCase
{
    private function dados(): array
    {
        return [
            'prestador' => '16679526000180', 'tomador' => '52998224725', 'nome_tomador' => 'Tomador & teste',
            'municipio_emissor' => '4308003', 'municipio_prestacao' => '4308003', 'serie' => '1', 'numero' => '17',
            'competencia' => '2026-11-01', 'emissao' => new DateTimeImmutable('2026-11-27T10:00:00-03:00'),
            'opSimpNac' => '3', 'regApTribSN' => '1', 'escopo_confirmado' => true, 'ibscbs_nao_exigido' => true,
            'valor' => '123.45', 'descricao' => 'Serviço <teste>', 'codigo_tributacao_nacional' => '170101',
            'percentuais_tributos' => ['pTotTribFed' => '5.00', 'pTotTribEst' => '0.00', 'pTotTribMun' => '2.00'],
        ];
    }

    public function test_id_dps_e_identidade_cpf_cnpj(): void
    {
        $id = GeradorDps::id('4308003', '16679526000180', '1', '17');
        $this->assertSame('DPS43080032'.'16679526000180'.'00001'.'000000000000017', $id);
        $this->assertSame(45, strlen($id));
        $this->assertSame('DPS43080031'.'00052998224725'.'00001'.'000000000000017', GeradorDps::id('4308003', '52998224725', '1', '17'));
    }

    public function test_xml_restrito_preserva_valor_e_texto(): void
    {
        $doc = (new GeradorDps)->gerar($this->dados());
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('n', GeradorDps::NS);
        $this->assertSame('2', $xp->evaluate('string(/n:DPS/n:infDPS/n:tpAmb)'));
        $this->assertSame('16679526000180', $xp->evaluate('string(//n:prest/n:CNPJ)'));
        $this->assertSame('52998224725', $xp->evaluate('string(//n:toma/n:CPF)'));
        $this->assertSame('123.45', $xp->evaluate('string(//n:vServ)'));
        $this->assertSame('Serviço <teste>', $xp->evaluate('string(//n:xDescServ)'));
        $this->assertSame('Tomador & teste', $xp->evaluate('string(//n:toma/n:xNome)'));
    }

    public function test_recusa_omissao_de_dados_fiscais(): void
    {
        $dados = $this->dados();
        $dados['ibscbs_nao_exigido'] = false;
        $this->expectException(InvalidArgumentException::class);
        (new GeradorDps)->gerar($dados);
    }

    public function test_assina_e_verifica_digest_com_certificado_de_teste(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'Teste local'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_pkey_export($key, $privado);
        openssl_x509_export($cert, $publico);
        $xml = (new AssinadorDps)->assinar((new GeradorDps)->gerar($this->dados()), $publico, $privado, 'rsa-sha256');
        $doc = new DOMDocument;
        $this->assertTrue($doc->loadXML($xml, LIBXML_NONET));
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('n', GeradorDps::NS);
        $xp->registerNamespace('s', AssinadorDps::NS);
        $info = $xp->query('/n:DPS/s:Signature/s:SignedInfo')->item(0);
        $inf = $xp->query('/n:DPS/n:infDPS')->item(0);
        $this->assertSame('#'.$inf->getAttribute('Id'), $xp->evaluate('string(//s:Reference/@URI)'));
        $this->assertSame(base64_encode(hash('sha256', $inf->C14N(), true)), $xp->evaluate('string(//s:DigestValue)'));
        $this->assertSame(1, openssl_verify($info->C14N(), base64_decode($xp->evaluate('string(//s:SignatureValue)'), true), $publico, OPENSSL_ALGO_SHA256));
        $inf->setAttribute('Id', str_repeat('1', 45));
        $this->assertNotSame(base64_encode(hash('sha256', $inf->C14N(), true)), $xp->evaluate('string(//s:DigestValue)'));
        $this->expectException(RuntimeException::class);
        (new AssinadorDps)->assinar($doc, $publico, $privado, 'rsa-sha256');
    }
}
