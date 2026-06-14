<?php

namespace Tests\Unit;

use App\Rules\CnpjValido;
use PHPUnit\Framework\TestCase;

class CnpjValidoTest extends TestCase
{
    public function test_aceita_cnpj_valido_formatado(): void
    {
        $this->assertTrue(CnpjValido::isValid('23.531.703/0001-41'));
    }

    public function test_aceita_cnpj_valido_sem_mascara(): void
    {
        $this->assertTrue(CnpjValido::isValid('23531703000141'));
    }

    public function test_rejeita_cnpj_com_digitos_repetidos(): void
    {
        $this->assertFalse(CnpjValido::isValid('11.111.111/1111-11'));
    }

    public function test_rejeita_cnpj_com_digito_verificador_incorreto(): void
    {
        $this->assertFalse(CnpjValido::isValid('23.531.703/0001-40'));
    }

    public function test_formata_cnpj_padrao(): void
    {
        $this->assertSame('23.531.703/0001-41', CnpjValido::format('23531703000141'));
    }
}
