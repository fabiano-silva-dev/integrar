<?php

namespace Tests\Unit;

use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use App\Services\AutomacaoFiscal\Sefaz\NfeManifestacaoDestinatarioClient;
use App\Services\AutomacaoFiscal\Sefaz\NfeXmlDsigSigner;
use Tests\TestCase;

class NfeManifestacaoDestinatarioClientTest extends TestCase
{
    public function test_interpretar_sucesso_135(): void
    {
        $client = new NfeManifestacaoDestinatarioClient(
            $this->createMock(CertificadoDigitalService::class),
            new NfeXmlDsigSigner(),
        );

        $soap = '<retEnvEvento><cStat>128</cStat><xMotivo>Lote processado</xMotivo>'
            .'<retEvento><infEvento><cStat>135</cStat><xMotivo>Evento registrado</xMotivo></infEvento></retEvento>'
            .'</retEnvEvento>';

        $ret = $client->interpretarResposta($soap);

        $this->assertSame('135', $ret['c_stat']);
        $this->assertTrue($ret['sucesso']);
        $this->assertFalse($ret['ja_manifestada']);
    }

    public function test_interpretar_duplicidade_573(): void
    {
        $client = new NfeManifestacaoDestinatarioClient(
            $this->createMock(CertificadoDigitalService::class),
            new NfeXmlDsigSigner(),
        );

        $soap = '<retEnvEvento><cStat>128</cStat>'
            .'<retEvento><infEvento><cStat>573</cStat><xMotivo>Duplicidade</xMotivo></infEvento></retEvento>'
            .'</retEnvEvento>';

        $ret = $client->interpretarResposta($soap);

        $this->assertTrue($ret['sucesso']);
        $this->assertTrue($ret['ja_manifestada']);
    }

    public function test_montar_envelope_contem_recepcao(): void
    {
        $client = new NfeManifestacaoDestinatarioClient(
            $this->createMock(CertificadoDigitalService::class),
            new NfeXmlDsigSigner(),
        );

        $soap = $client->montarEnvelopeSoap('<envEvento versao="1.00"/>');

        $this->assertStringContainsString('nfeRecepcaoEvento', $soap);
        $this->assertStringContainsString('NFeRecepcaoEvento4', $soap);
    }
}
