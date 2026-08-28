<?php

namespace Tests\Unit;

use App\Services\AutomacaoFiscal\NfseDanfseGenerator;
use DanfseNacional\DanfseGenerator;
use RuntimeException;
use Tests\TestCase;

class NfseDanfseGeneratorTest extends TestCase
{
    private const CHAVE = '43080031216679526000180000000000001726066074736113';

    public function test_gera_danfse_nacional_a_partir_do_xml(): void
    {
        $xml = file_get_contents(base_path('tests/fixtures/nfse-nacional-minima.xml'));
        $this->assertIsString($xml);

        $gerador = new NfseDanfseGenerator();
        $pdf = $gerador->gerarPdf($xml);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
        $this->assertFalse(str_contains($pdf, 'PDF gerado localmente'));
        $this->assertTrue($this->pdfContem($pdf, 'DANFSe'));

        $html = (new DanfseGenerator())->generateHtml(
            (new DanfseGenerator())->parseXml($gerador->extrairXmlNfse($xml))
        );
        $this->assertStringContainsString('DANFSe', $html);
        $this->assertStringContainsString(self::CHAVE, $html);
        $this->assertStringContainsString('Prestador Teste', $html);
        $this->assertStringContainsString('Tomador Teste', $html);
        $this->assertStringContainsString('Documento Auxiliar da NFS-e', $html);
    }

    public function test_extrai_nfse_de_envelope(): void
    {
        $inner = file_get_contents(base_path('tests/fixtures/nfse-nacional-minima.xml'));
        $this->assertIsString($inner);

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<nfseProc xmlns="http://www.sped.fazenda.gov.br/nfse">'
            .preg_replace('/^<\?xml[^>]*>\s*/', '', $inner)
            .'</nfseProc>';

        $gerador = new NfseDanfseGenerator();
        $extraido = $gerador->extrairXmlNfse($envelope);
        $this->assertStringContainsString('<NFSe', $extraido);
        $this->assertStringNotContainsString('<nfseProc', $extraido);

        $pdf = $gerador->gerarPdf($envelope);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
    }

    public function test_xml_invalido_lanca_excecao(): void
    {
        $this->expectException(RuntimeException::class);
        (new NfseDanfseGenerator())->gerarPdf('nao e xml');
    }

    public function test_xml_sem_nfse_lanca_excecao(): void
    {
        $this->expectException(RuntimeException::class);
        (new NfseDanfseGenerator())->gerarPdf('<raiz><foo>1</foo></raiz>');
    }

    private function pdfContem(string $pdf, string $needle): bool
    {
        if (str_contains($pdf, $needle)) {
            return true;
        }

        if (preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $matches) === false) {
            return false;
        }

        foreach ($matches[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if (! is_string($decoded)) {
                $decoded = @gzinflate($stream);
            }
            if (is_string($decoded) && str_contains($decoded, $needle)) {
                return true;
            }
        }

        return false;
    }
}
