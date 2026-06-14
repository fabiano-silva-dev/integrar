<?php

namespace Tests\Unit;

use App\Rules\CnpjOuCpfValido;
use App\Rules\CpfValido;
use PHPUnit\Framework\TestCase;

class CpfValidoTest extends TestCase
{
    public function test_aceita_cpf_valido_formatado(): void
    {
        $this->assertTrue(CpfValido::isValid('529.982.247-25'));
    }

    public function test_aceita_cpf_valido_sem_mascara(): void
    {
        $this->assertTrue(CpfValido::isValid('52998224725'));
    }

    public function test_rejeita_cpf_invalido(): void
    {
        $this->assertFalse(CpfValido::isValid('529.982.247-00'));
    }

    public function test_cnpj_ou_cpf_formata_cpf(): void
    {
        $this->assertSame('529.982.247-25', CnpjOuCpfValido::format('52998224725'));
    }

    public function test_cnpj_ou_cpf_formata_cnpj(): void
    {
        $this->assertSame('23.531.703/0001-41', CnpjOuCpfValido::format('23531703000141'));
    }
}
