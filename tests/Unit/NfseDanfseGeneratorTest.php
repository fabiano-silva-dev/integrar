<?php

namespace Tests\Unit;

use App\Services\AutomacaoFiscal\NfseDanfseGenerator;
use Tests\TestCase;

class NfseDanfseGeneratorTest extends TestCase
{
    public function test_extrai_campos_e_gera_pdf_local(): void
    {
        $chave = '43080031216679526000180000000000001726066074736113';
        $xml = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse">'
            .'<infNFSe>'
            .'<cChaveAcesso>'.$chave.'</cChaveAcesso>'
            .'<nNFSe>17</nNFSe>'
            .'<dCompet>2026-06</dCompet>'
            .'<xLocEmi>Faxinal do Soturno/RS</xLocEmi>'
            .'<prest><CNPJ>16679526000180</CNPJ><xNome>Prestador Teste</xNome></prest>'
            .'<toma><CNPJ>95591764000105</CNPJ><xNome>UFSM</xNome></toma>'
            .'<xDescServ>Servico de teste</xDescServ>'
            .'<vServ>1558.70</vServ>'
            .'</infNFSe>'
            .'</NFSe>';

        $gerador = new NfseDanfseGenerator();
        $campos = $gerador->extrairCampos($xml);

        $this->assertSame($chave, $campos['chave']);
        $this->assertSame('17', $campos['numero']);
        $this->assertSame('Prestador Teste', $campos['prestador_nome']);
        $this->assertSame('16679526000180', $campos['prestador_cnpj']);
        $this->assertSame('UFSM', $campos['tomador_nome']);

        $pdf = $gerador->gerarViaPdfLocal($xml);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(200, strlen($pdf));
    }
}
