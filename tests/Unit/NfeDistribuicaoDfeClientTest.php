<?php

namespace Tests\Unit;

use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use App\Services\AutomacaoFiscal\Sefaz\NfeDistribuicaoDfeClient;
use Tests\TestCase;

class NfeDistribuicaoDfeClientTest extends TestCase
{
    private NfeDistribuicaoDfeClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new NfeDistribuicaoDfeClient(
            $this->createMock(CertificadoDigitalService::class),
        );
    }

    public function test_montar_dist_dfe_por_chave(): void
    {
        $chave = '43260616679526000180550100000003431735351222';
        $cnpj = '11222333000181';
        $xml = $this->client->montarDistDfePorChave($chave, $cnpj, 'RS');

        $this->assertStringContainsString('<distDFeInt versao="1.01"', $xml);
        $this->assertStringContainsString('<cUFAutor>43</cUFAutor>', $xml);
        $this->assertStringContainsString('<CNPJ>'.$cnpj.'</CNPJ>', $xml);
        $this->assertStringContainsString('<consChNFe>', $xml);
        $this->assertStringContainsString('<chNFe>'.$chave.'</chNFe>', $xml);
    }

    public function test_interpretar_cstat_138_com_nfe_proc(): void
    {
        $chave = '43260616679526000180550100000003431735351222';
        $nfe = '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><protNFe><infProt><chNFe>'.$chave.'</chNFe></infProt></protNFe></nfeProc>';
        $docZip = base64_encode(gzencode($nfe));

        $soap = '<soap:Envelope><soap:Body><retDistDFeInt>'
            .'<cStat>138</cStat><xMotivo>Documento localizado</xMotivo>'
            .'<loteDistDFeInt>'
            .'<docZip schema="procNFe_v4.00.xsd">'.$docZip.'</docZip>'
            .'</loteDistDFeInt>'
            .'</retDistDFeInt></soap:Body></soap:Envelope>';

        $ret = $this->client->interpretarResposta($soap, $chave);

        $this->assertSame('138', $ret['c_stat']);
        $this->assertNotNull($ret['xml']);
        $this->assertStringContainsString('<nfeProc', $ret['xml']);
        $this->assertFalse($ret['tem_resumo']);
    }

    public function test_interpretar_cstat_138_so_resumo(): void
    {
        $chave = '43260616679526000180550100000003431735351222';
        $res = '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>'.$chave.'</chNFe></resNFe>';
        $docZip = base64_encode(gzencode($res));

        $soap = '<retDistDFeInt><cStat>138</cStat><xMotivo>Documento localizado</xMotivo>'
            .'<docZip schema="resNFe_v1.01.xsd">'.$docZip.'</docZip></retDistDFeInt>';

        $ret = $this->client->interpretarResposta($soap, $chave);

        $this->assertNull($ret['xml']);
        $this->assertTrue($ret['tem_resumo']);
    }
}
