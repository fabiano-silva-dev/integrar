<?php

namespace Tests\Unit;

use App\Services\Nfse\CatalogoServicosNfse;
use Tests\TestCase;

class NfseCatalogoServicosTest extends TestCase
{
    public function test_anexo_b_carrega_codigos_com_zeros_e_descarta_cabecalhos(): void
    {
        $r = (new CatalogoServicosNfse)->ler();
        $this->assertSame('Advocacia', $r['nacional']['171401']);
        $this->assertSame('Análise e desenvolvimento de sistemas.', $r['nacional']['010101']);
        $this->assertArrayNotHasKey('010000', $r['nacional']);
        $this->assertArrayHasKey('101011100', $r['nbs']);
        $this->assertArrayNotHasKey('10101', $r['nbs']);
        foreach (array_keys($r['nbs']) as $codigo) {
            $this->assertMatchesRegularExpression('/^\d{9}$/D', (string) $codigo);
        }
    }

    public function test_pesquisa_por_codigo_pontuado_e_descricao_sem_acentos(): void
    {
        $catalogo = app(CatalogoServicosNfse::class);
        $this->assertSame('171401', $catalogo->buscar('nacional', '17.14')[0]['codigo']);
        $this->assertSame('171401', $catalogo->buscar('nacional', 'advocacia')[0]['codigo']);
        $this->assertSame('010101', $catalogo->buscar('nacional', 'analise desenvolvimento sistemas')[0]['codigo']);
        $this->assertSame('101011100', $catalogo->buscar('nbs', '1.0101.11.00')[0]['codigo']);
        $this->assertLessThanOrEqual(30, count($catalogo->buscar('nbs', '')));
    }
}
