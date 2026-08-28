<?php

namespace Tests\Unit;

use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use App\Services\AutomacaoFiscal\Sefaz\NfseSefinNacionalClient;
use RuntimeException;
use Tests\TestCase;

class NfseSefinNacionalClientTest extends TestCase
{
    private NfseSefinNacionalClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new NfseSefinNacionalClient(
            $this->createMock(CertificadoDigitalService::class),
        );
    }

    public function test_montar_url_e_validar_chave_50(): void
    {
        $chave = '43080031216679526000180000000000001726066074736113';
        $url = $this->client->montarUrl($chave);

        $this->assertStringEndsWith('/nfse/'.$chave, $url);
        $this->assertStringContainsString('sefin.nfse.gov.br', $url);
        $this->assertSame($chave, $this->client->normalizarChave($chave));
    }

    public function test_chave_invalida(): void
    {
        $this->expectException(RuntimeException::class);
        $this->client->normalizarChave('35260112345678000155550010000000011234567890');
    }

    public function test_decodifica_xml_gzip_base64(): void
    {
        $xml = '<NFSe><infNFSe>ok</infNFSe></NFSe>';
        $b64 = base64_encode(gzencode($xml));

        $this->assertSame($xml, $this->client->decodificarXmlGzipBase64($b64));
    }

    public function test_interpretar_200_com_xml(): void
    {
        $chave = '43080031216679526000180000000000001726066074736113';
        $xml = '<NFSe><cChaveAcesso>'.$chave.'</cChaveAcesso></NFSe>';
        $body = json_encode([
            'chaveAcesso' => $chave,
            'nfseXmlGZipB64' => base64_encode(gzencode($xml)),
        ], JSON_THROW_ON_ERROR);

        $ret = $this->client->interpretarRespostaHttp(200, $body, $chave);

        $this->assertStringContainsString('<NFSe>', $ret);
        $this->assertStringContainsString($chave, $ret);
    }

    public function test_interpretar_403(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403');
        $this->client->interpretarRespostaHttp(403, json_encode(['erros' => [['descricao' => 'negado']]]));
    }

    public function test_interpretar_404(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');
        $this->client->interpretarRespostaHttp(404, '{}');
    }
}
