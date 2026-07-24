<?php

namespace Tests\Unit;

use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use App\Services\AutomacaoFiscal\Sefaz\NfeIntegracaoContabilistaClient;
use Tests\TestCase;

class NfeIntegracaoContabilistaClientTest extends TestCase
{
    private NfeIntegracaoContabilistaClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new NfeIntegracaoContabilistaClient(
            $this->createMock(CertificadoDigitalService::class),
        );
    }

    public function test_montar_dist_nfe_por_chave(): void
    {
        $chave = '43260616679526000180550100000003431735351222';
        $cnpj = '16679526000180';
        $xml = $this->client->montarDistNFeRsPorChave($chave, $cnpj, '55');

        $this->assertStringContainsString('<distNFeRS versao="1.00"', $xml);
        $this->assertStringContainsString('<cUF>43</cUF>', $xml);
        $this->assertStringContainsString('<CNPJ>'.$cnpj.'</CNPJ>', $xml);
        $this->assertStringContainsString('<solDFe>', $xml);
        $this->assertStringContainsString('<chAcesso>'.$chave.'</chAcesso>', $xml);
        $this->assertStringNotContainsString('<solRel>', $xml);
    }

    public function test_montar_envelope_soap(): void
    {
        $inner = '<distNFeRS versao="1.00" xmlns="http://www.portalfiscal.inf.br/nfe"/>';
        $soap = $this->client->montarEnvelopeSoap($inner);

        $this->assertStringContainsString('soap12:Envelope', $soap);
        $this->assertStringContainsString('nfeIntegracaoContab', $soap);
        $this->assertStringContainsString('<nfeDadosMsg>'.$inner.'</nfeDadosMsg>', $soap);
    }

    public function test_extrair_nfe_proc_do_lote_gzip(): void
    {
        $chave = '43260616679526000180550100000003431735351222';
        $lote = '<?xml version="1.0"?>'
            .'<loteDistNFeRS versao="1.00">'
            .'<proc schema="procNFe_v4.00.xsd" NSU="123" chAcesso="'.$chave.'">'
            .'<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'
            .'<NFe><infNFe Id="NFe'.$chave.'"/></NFe>'
            .'<protNFe><infProt><chNFe>'.$chave.'</chNFe></infProt></protNFe>'
            .'</nfeProc>'
            .'</proc>'
            .'</loteDistNFeRS>';

        $b64 = base64_encode(gzencode($lote));
        $xml = $this->client->extrairNfeProcDoLote($b64, $chave);

        $this->assertNotNull($xml);
        $this->assertStringContainsString('<nfeProc', $xml);
        $this->assertStringContainsString($chave, $xml);
    }

    public function test_interpretar_resposta_cstat_118(): void
    {
        $chave = '43260616679526000180550100000003431735351222';
        $lote = '<loteDistNFeRS versao="1.00">'
            .'<proc schema="procNFe_v4.00.xsd" NSU="1" chAcesso="'.$chave.'">'
            .'<nfeProc><protNFe><infProt><chNFe>'.$chave.'</chNFe></infProt></protNFe></nfeProc>'
            .'</proc></loteDistNFeRS>';

        $soap = '<soap:Envelope><soap:Body><retDistNFeRS>'
            .'<cStat>118</cStat><xMotivo>Documento localizado</xMotivo>'
            .'<loteDistComp>'.base64_encode($lote).'</loteDistComp>'
            .'</retDistNFeRS></soap:Body></soap:Envelope>';

        $ret = $this->client->interpretarResposta($soap, $chave);

        $this->assertSame('118', $ret['c_stat']);
        $this->assertSame('ws-contabilista-rs', $ret['fonte']);
        $this->assertStringContainsString('<nfeProc>', $ret['xml']);
    }
}
