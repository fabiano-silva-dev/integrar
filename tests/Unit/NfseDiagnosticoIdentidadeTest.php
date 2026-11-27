<?php

namespace Tests\Unit;

use App\Services\Nfse\DiagnosticoApiNfse;
use PHPUnit\Framework\TestCase;

class NfseDiagnosticoIdentidadeTest extends TestCase
{
    private const CHAVE = '43080031216679526000180000000000001726066074736113';
    private const CNPJ = '16679526000180';

    private function xml(): string
    {
        return file_get_contents(__DIR__.'/../fixtures/nfse-nacional-minima.xml');
    }

    public function test_aceita_apenas_identidade_e_ambiente_corretos(): void
    {
        $this->assertTrue(DiagnosticoApiNfse::corresponde($this->xml(), self::CHAVE, self::CNPJ, 'producao'));
        $this->assertFalse(DiagnosticoApiNfse::corresponde($this->xml(), self::CHAVE, self::CNPJ, 'restrita'));
        $this->assertFalse(DiagnosticoApiNfse::corresponde($this->xml(), str_repeat('1', 50), self::CNPJ, 'producao'));
        $this->assertFalse(DiagnosticoApiNfse::corresponde($this->xml(), self::CHAVE, '95591764000105', 'producao'));
    }

    public function test_rejeita_xml_ambiguo_ou_com_doctype(): void
    {
        $xml = str_replace('<CNPJ>'.self::CNPJ.'</CNPJ>', '<CNPJ>'.self::CNPJ.'</CNPJ><CNPJ>95591764000105</CNPJ>', $this->xml());
        $this->assertFalse(DiagnosticoApiNfse::corresponde($xml, self::CHAVE, self::CNPJ, 'producao'));
        $this->assertFalse(DiagnosticoApiNfse::corresponde('<!DOCTYPE NFSe [<!ENTITY x SYSTEM "file:///etc/passwd">]><NFSe>&x;</NFSe>', self::CHAVE, self::CNPJ, 'producao'));
        $this->assertFalse(DiagnosticoApiNfse::corresponde('<NFSe/>', self::CHAVE, self::CNPJ, 'producao'));
    }
}
