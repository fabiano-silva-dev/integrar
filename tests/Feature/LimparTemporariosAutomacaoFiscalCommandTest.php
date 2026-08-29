<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LimparTemporariosAutomacaoFiscalCommandTest extends TestCase
{
    public function test_remove_xml_antigo_e_preserva_recente(): void
    {
        Storage::fake('local');
        Storage::put('temp/nfe-xml/antigo.xml', '<xml/>');
        Storage::put('temp/nfe-xml/novo.xml', '<xml/>');

        $antigo = Storage::path('temp/nfe-xml/antigo.xml');
        touch($antigo, now()->subHours(30)->getTimestamp());

        $this->artisan('automacao-fiscal:limpar-temporarios', ['--horas' => 24])
            ->assertSuccessful();

        Storage::assertMissing('temp/nfe-xml/antigo.xml');
        Storage::assertExists('temp/nfe-xml/novo.xml');
    }
}
