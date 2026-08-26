<?php

namespace Tests\Unit;

use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Services\Documentos\IdentificadorPdfFiscalService;
use Tests\TestCase;

class IdentificadorPdfFiscalServiceTest extends TestCase
{
    public function test_nfce_pelo_texto(): void
    {
        $id = new IdentificadorPdfFiscalService;
        $r = $id->identificar('DANFE NFC-e Nota Fiscal de Consumidor Eletronica');

        $this->assertNotNull($r);
        $this->assertSame(TipoDocumentoRecebido::Cupom, $r['tipo']);
    }

    public function test_cte_pelo_dacte(): void
    {
        $id = new IdentificadorPdfFiscalService;
        $r = $id->identificar('DACTE Documento Auxiliar do Conhecimento de Transporte Eletronico');

        $this->assertNotNull($r);
        $this->assertSame(TipoDocumentoRecebido::Cte, $r['tipo']);
    }

    public function test_chave_44_modelo_55(): void
    {
        $id = new IdentificadorPdfFiscalService;
        $chave = '35260312345678000199550010000001231234567890';
        $r = $id->identificar('chave de acesso '.$chave);

        $this->assertNotNull($r);
        $this->assertSame(TipoDocumentoRecebido::Nfe, $r['tipo']);
        $this->assertSame('55', $r['metadados']['modelo'] ?? null);
    }

    public function test_mdfe_pelo_damdfe(): void
    {
        $id = new IdentificadorPdfFiscalService;
        $r = $id->identificar('DAMDFE Manifesto Eletronico de Documentos Fiscais');

        $this->assertNotNull($r);
        $this->assertSame(TipoDocumentoRecebido::Mdfe, $r['tipo']);
    }
}
