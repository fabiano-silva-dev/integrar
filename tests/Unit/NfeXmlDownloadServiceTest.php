<?php

namespace Tests\Unit;

use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use App\Services\AutomacaoFiscal\NfeXmlDownloadService;
use App\Services\AutomacaoFiscal\Runners\NodeRunnerBridge;
use Tests\TestCase;
use ZipArchive;

class NfeXmlDownloadServiceTest extends TestCase
{
    private NfeXmlDownloadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NfeXmlDownloadService(
            $this->createMock(CertificadoDigitalService::class),
            $this->createMock(NodeRunnerBridge::class),
        );
    }

    public function test_extrair_xml_de_artefato_base64(): void
    {
        $chave = '43260711222333000181550010000003511000000015';
        $nfeXml = '<?xml version="1.0"?><nfeProc><protNFe><infProt><chNFe>'.$chave.'</chNFe></infProt></protNFe></nfeProc>';

        $xml = $this->service->extrairXmlDosArtefatos([
            [
                'filename' => $chave.'-nfe.xml',
                'mimeType' => 'application/xml',
                'contentBase64' => base64_encode($nfeXml),
            ],
        ], $chave);

        $this->assertNotNull($xml);
        $this->assertStringContainsString('<nfeProc>', $xml);
        $this->assertStringContainsString($chave, $xml);
    }

    public function test_extrair_xml_de_zip(): void
    {
        $chave = '43260711222333000181550010000003511000000015';
        $nfeXml = '<?xml version="1.0"?><nfeProc><chNFe>'.$chave.'</chNFe></nfeProc>';

        $tmp = tempnam(sys_get_temp_dir(), 'nfezip');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString($chave.'.xml', $nfeXml);
        $zip->close();
        $bin = file_get_contents($tmp);
        @unlink($tmp);

        $xml = $this->service->extrairXmlDeZip($bin, $chave);

        $this->assertNotNull($xml);
        $this->assertStringContainsString('<nfeProc>', $xml);
    }
}
