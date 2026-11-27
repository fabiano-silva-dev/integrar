<?php

namespace Tests\Unit;

use App\Services\Nfse\ConferenciaNfse;
use PHPUnit\Framework\TestCase;

class NfseConferenciaValorTest extends TestCase
{
    public function test_total_decimal_sem_ponto_flutuante(): void
    {
        $notas = [(object) ['valor' => '0.10'], (object) ['valor' => '0.20']];
        $this->assertSame('0.30', ConferenciaNfse::total($notas));
        $this->assertSame('0.00', ConferenciaNfse::total([]));
        $this->assertSame('1999999999999998.00', ConferenciaNfse::total(array_fill(0, 200, (object) ['valor' => '9999999999999.99'])));
        $this->assertSame('1.999.999.999.999.998,00', ConferenciaNfse::formatarValor('1999999999999998.00'));
    }
}
