<?php

namespace Tests\Unit;

use App\Services\Nfse\CatalogoTributarioNfse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NfseCatalogoTributarioTest extends TestCase
{
    private function csv(string $linhas): string
    {
        return implode(';', CatalogoTributarioNfse::CABECALHO)."\n".$linhas."\n";
    }

    public function test_preserva_zeros_datas_e_indicadores_desconhecidos(): void
    {
        $l = (new CatalogoTributarioNfse)->ler("\xEF\xBB\xBF".$this->csv('000;CST teste;000001;"Descrição; exemplo";2026-01-01;;1;;0.00;'));
        $this->assertSame('000', $l[0]['cst']);
        $this->assertSame('000001', $l[0]['cclass_trib']);
        $this->assertSame('Descrição; exemplo', $l[0]['descricao']);
        $this->assertNull($l[0]['fim']);
        $this->assertNull($l[0]['ind_gibscbs']);
        $this->assertNull($l[0]['reducao_cbs']);
        $this->assertSame('0.00', $l[0]['reducao_ibs']);
    }

    public function test_rejeita_vigencias_sobrepostas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CatalogoTributarioNfse)->ler($this->csv("000;CST teste;000001;A;2026-01-01;2026-11-30;1;1;;\n000;CST teste;000001;B;2026-11-30;;1;1;;"));
    }

    public function test_rejeita_codigo_com_prefixo_divergente(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CatalogoTributarioNfse)->ler($this->csv('000;CST teste;200001;A;2026-01-01;;1;1;;'));
    }

    public function test_rejeita_data_impossivel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CatalogoTributarioNfse)->ler($this->csv('000;CST teste;000001;A;2026-02-30;;1;1;;'));
    }

    public function test_rejeita_indicador_nao_explicito(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CatalogoTributarioNfse)->ler($this->csv('000;CST teste;000001;A;2026-01-01;;;;;'));
    }
}
