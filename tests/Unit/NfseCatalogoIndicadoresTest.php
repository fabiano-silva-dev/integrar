<?php

namespace Tests\Unit;

use App\Services\Nfse\CatalogoIndicadoresNfse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NfseCatalogoIndicadoresTest extends TestCase
{
    private function csv(string $linhas): string
    {
        return implode(';', CatalogoIndicadoresNfse::CABECALHO)."\n".$linhas."\n";
    }

    public function test_preserva_codigo_e_fundamento_sem_inventar_vigencia(): void
    {
        $l = (new CatalogoIndicadoresNfse)->ler("\xEF\xBB\xBF".$this->csv('010001;"Operação; teste";Fundamento fictício;;'));
        $this->assertSame('010001', $l[0]['codigo']);
        $this->assertSame('Operação; teste', $l[0]['descricao']);
        $this->assertSame('Fundamento fictício', $l[0]['fundamento']);
        $this->assertNull($l[0]['inicio']);
        $this->assertNull($l[0]['fim']);
    }

    public function test_rejeita_duplicados_na_mesma_edicao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CatalogoIndicadoresNfse)->ler($this->csv("010001;A;;;\n010001;B;;;"));
    }

    public function test_rejeita_codigo_sem_zeros_a_esquerda(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CatalogoIndicadoresNfse)->ler($this->csv('10001;A;;;'));
    }

    public function test_rejeita_intervalo_invertido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CatalogoIndicadoresNfse)->ler($this->csv('010001;A;;2026-12-01;2026-11-01'));
    }
}
