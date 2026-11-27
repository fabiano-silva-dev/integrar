<?php

namespace Tests\Unit;

use App\Services\Nfse\Dps\DanfseHomologacao;
use RuntimeException;
use Tests\TestCase;

class NfseDanfseHomologacaoTest extends TestCase
{
    public function test_gera_pdf_de_teste_com_marca_sem_valor_fiscal(): void
    {
        $xml = str_replace('<tpAmb>1</tpAmb>', '<tpAmb>2</tpAmb>', file_get_contents(base_path('tests/fixtures/nfse-nacional-minima.xml')));
        $pdf = (new DanfseHomologacao)->gerar($xml);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
        $needle = 'HOMOLOGACAO - SEM VALOR FISCAL';
        $texto = $pdf;
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches)) {
            foreach ($matches[1] as $stream) {
                // Apenas streams zlib podem ser descompactados.
                if (strlen($stream) >= 2 && ord($stream[0]) === 0x78) {
                    set_error_handler(static fn () => true, E_WARNING);
                    try {
                        $decoded = gzuncompress($stream);
                    } finally {
                        restore_error_handler();
                    }
                    if (is_string($decoded)) {
                        $texto .= $decoded;
                    }
                }
            }
        }
        $this->assertStringContainsString($needle, $texto);
    }

    public function test_recusa_xml_de_producao(): void
    {
        $this->expectException(RuntimeException::class);
        (new DanfseHomologacao)->gerar(file_get_contents(base_path('tests/fixtures/nfse-nacional-minima.xml')));
    }
}
