<?php

namespace Tests\Unit;

use App\Services\Documentos\IdentificadorExtratoPdfService;
use Tests\TestCase;

class IdentificadorExtratoPdfServiceTest extends TestCase
{
    public function test_sicoob(): void
    {
        $id = new IdentificadorExtratoPdfService;
        $r = $id->identificar("COOP.: 3067-8 / SICOOB\nSALDO ANTERIOR 1.000,00");

        $this->assertNotNull($r);
        $this->assertSame('sicoob', $r['layout']);
    }

    public function test_banrisul(): void
    {
        $id = new IdentificadorExtratoPdfService;
        $r = $id->identificar("BANRISUL\nMOVIMENTOS DA CONTA CORRENTE\nDIA HISTORICO DOCUMENTO VALOR");

        $this->assertNotNull($r);
        $this->assertSame('banrisul', $r['layout']);
    }

    public function test_cora(): void
    {
        $id = new IdentificadorExtratoPdfService;
        $r = $id->identificar(
            "UP ESPACO MULTIPROFISSIONAL LTDA\nAgência: 0001 - Conta: 6557797-8\nCora SCFI - CNPJ 37.880.206/0001-63"
        );

        $this->assertNotNull($r);
        $this->assertSame('cora', $r['layout']);
    }

    public function test_texto_generico_nao_e_extrato(): void
    {
        $id = new IdentificadorExtratoPdfService;
        $this->assertNull($id->identificar('Recibo de pagamento de aluguel'));
    }
}
