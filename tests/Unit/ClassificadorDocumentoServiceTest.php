<?php

namespace Tests\Unit;

use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Services\Documentos\ClassificadorDocumentoService;
use DateTimeImmutable;
use Tests\TestCase;

class ClassificadorDocumentoServiceTest extends TestCase
{
    private ClassificadorDocumentoService $classificador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classificador = $this->app->make(ClassificadorDocumentoService::class);
    }

    public function test_xml_nfe_modelo_55(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc>
  <NFe>
    <infNFe>
      <ide>
        <mod>55</mod>
        <dhEmi>2026-03-15T10:00:00-03:00</dhEmi>
      </ide>
    </infNFe>
  </NFe>
</nfeProc>
XML;

        $resultado = $this->classificador->classificar('qualquer.xml', 'application/xml', $xml);

        $this->assertTrue($resultado['conclusivo']);
        $this->assertSame(TipoDocumentoRecebido::Nfe, $resultado['tipo']);
        $this->assertSame(2026, $resultado['ano']);
        $this->assertSame('2026-03-15', $resultado['data']);
    }

    public function test_xml_nfe_extrai_cnpj_emitente_e_destinatario(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc>
  <NFe>
    <infNFe>
      <ide>
        <mod>55</mod>
        <dhEmi>2026-03-15T10:00:00-03:00</dhEmi>
      </ide>
      <emit><CNPJ>11222333000181</CNPJ></emit>
      <dest><CNPJ>99888777000166</CNPJ></dest>
    </infNFe>
  </NFe>
</nfeProc>
XML;

        $resultado = $this->classificador->classificar('nfe.xml', 'application/xml', $xml);

        $this->assertSame('11222333000181', $resultado['metadados']['cnpj_emitente'] ?? null);
        $this->assertSame('99888777000166', $resultado['metadados']['cnpj_destinatario'] ?? null);
    }

    public function test_xml_nfce_modelo_65_vai_para_cupom(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc>
  <NFe>
    <infNFe>
      <ide>
        <mod>65</mod>
        <dhEmi>2025-11-02T08:00:00-03:00</dhEmi>
      </ide>
    </infNFe>
  </NFe>
</nfeProc>
XML;

        $resultado = $this->classificador->classificar('arquivo.xml', 'text/xml', $xml);

        $this->assertSame(TipoDocumentoRecebido::Cupom, $resultado['tipo']);
        $this->assertSame(2025, $resultado['ano']);
    }

    public function test_xml_cte_modelo_57(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<cteProc>
  <CTe>
    <infCte>
      <ide>
        <mod>57</mod>
        <dhEmi>2026-01-10T12:00:00-03:00</dhEmi>
      </ide>
    </infCte>
  </CTe>
</cteProc>
XML;

        $resultado = $this->classificador->classificar('cte.xml', 'application/xml', $xml);

        $this->assertSame(TipoDocumentoRecebido::Cte, $resultado['tipo']);
        $this->assertTrue($resultado['conclusivo']);
    }

    public function test_xml_mdfe_modelo_58(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<mdfeProc>
  <MDFe>
    <infMDFe>
      <ide>
        <mod>58</mod>
        <dhEmi>2026-04-01T09:00:00-03:00</dhEmi>
      </ide>
    </infMDFe>
  </MDFe>
</mdfeProc>
XML;

        $resultado = $this->classificador->classificar('mdfe.xml', 'application/xml', $xml);

        $this->assertSame(TipoDocumentoRecebido::Mdfe, $resultado['tipo']);
    }

    public function test_xml_nfse(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<CompNfse>
  <Nfse>
    <InfNfse>
      <DataEmissao>2024-07-20</DataEmissao>
    </InfNfse>
  </Nfse>
</CompNfse>
XML;

        $resultado = $this->classificador->classificar('nfse.xml', 'application/xml', $xml);

        $this->assertSame(TipoDocumentoRecebido::Nfse, $resultado['tipo']);
        $this->assertSame(2024, $resultado['ano']);
    }

    public function test_xml_generico_vai_para_xmls(): void
    {
        $xml = '<?xml version="1.0"?><root><foo>bar</foo></root>';

        $resultado = $this->classificador->classificar('arquivo.xml', 'application/xml', $xml, new DateTimeImmutable('2026-01-01'));

        $this->assertSame(TipoDocumentoRecebido::Xmls, $resultado['tipo']);
        $this->assertTrue($resultado['conclusivo']);
    }

    public function test_ofx_pelo_cabecalho_nao_pelo_nome(): void
    {
        $ofx = $this->classificador->classificar('movimento.bin', 'application/octet-stream', "OFXHEADER:100\n<OFX>", new DateTimeImmutable('2026-08-01'));
        $csv = $this->classificador->classificar('extrato-banco.csv', 'text/csv', 'data;valor', new DateTimeImmutable('2026-08-01'));

        $this->assertSame(TipoDocumentoRecebido::Extratos, $ofx['tipo']);
        $this->assertSame(TipoDocumentoRecebido::Outros, $csv['tipo']);
    }

    public function test_nome_extrato_pdf_nao_define_tipo(): void
    {
        $resultado = $this->classificador->classificar(
            'extrato.pdf',
            'application/pdf',
            'Contrato de prestacao de servicos entre as partes.',
            new DateTimeImmutable('2026-02-01'),
        );

        $this->assertFalse($resultado['conclusivo']);
        $this->assertNull($resultado['tipo']);
    }

    public function test_texto_danfe_vai_para_nfe(): void
    {
        $resultado = $this->classificador->classificarTextoDocumento(
            'DANFE Documento Auxiliar da Nota Fiscal Eletronica chave de acesso 35260312345678000199550010000001231234567890',
            new DateTimeImmutable('2026-01-01'),
        );

        $this->assertTrue($resultado['conclusivo']);
        $this->assertSame(TipoDocumentoRecebido::Nfe, $resultado['tipo']);
        $this->assertSame('12345678000199', $resultado['metadados']['cnpj_emitente'] ?? null);
    }

    public function test_texto_banrisul_vai_para_extratos(): void
    {
        $resultado = $this->classificador->classificarTextoDocumento(
            "BANRISUL\nMOVIMENTOS DA CONTA CORRENTE\nDIA HISTORICO DOCUMENTO VALOR",
            new DateTimeImmutable('2026-05-01'),
        );

        $this->assertTrue($resultado['conclusivo']);
        $this->assertSame(TipoDocumentoRecebido::Extratos, $resultado['tipo']);
        $this->assertSame('banrisul', $resultado['metadados']['layout_banco'] ?? null);
    }

    public function test_imagem_nao_e_conclusiva(): void
    {
        $resultado = $this->classificador->classificar('pix.jpg', 'image/jpeg', 'fake', new DateTimeImmutable('2026-02-01'));

        $this->assertFalse($resultado['conclusivo']);
        $this->assertNull($resultado['tipo']);
    }

    public function test_arquivo_desconhecido_vai_para_outros(): void
    {
        $resultado = $this->classificador->classificar('contrato.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'x', new DateTimeImmutable('2026-05-01'));

        $this->assertSame(TipoDocumentoRecebido::Outros, $resultado['tipo']);
        $this->assertTrue($resultado['conclusivo']);
    }
}
