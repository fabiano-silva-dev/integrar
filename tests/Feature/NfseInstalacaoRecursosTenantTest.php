<?php

namespace Tests\Feature;

use App\Services\Nfse\CatalogoIndicadoresNfse;
use App\Services\Nfse\CatalogoTributarioNfse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NfseInstalacaoRecursosTenantTest extends TestCase
{
    use DatabaseTransactions;

    public function test_instalacao_repetida_carrega_catalogos_sem_habilitar_transmissao_ou_edicao_futura(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 27));
        config(['automacao_fiscal.nfse_dps_restrita_enabled' => false, 'automacao_fiscal.nfse_dps_fila_enabled' => false]);
        $this->artisan('nfse:instalar-recursos --verificar')->assertSuccessful();
        $this->assertDatabaseCount('nfse_catalogo_versoes', 0);
        $this->artisan('nfse:instalar-recursos')->assertSuccessful();
        $this->artisan('nfse:instalar-recursos')->assertSuccessful();
        $this->assertDatabaseCount('nfse_catalogo_versoes', 1);
        $this->assertDatabaseCount('nfse_catalogo_classificacoes', 173);
        $this->assertDatabaseCount('nfse_indop_versoes', 1);
        $this->assertDatabaseCount('nfse_indop_codigos', 26);
        $catalogo = app(CatalogoTributarioNfse::class);
        $this->assertSame(73, $catalogo->consulta($catalogo->atual()->id, '2026-11')->count());
        $this->assertSame('AnexoC-v1.01-20260122', app(CatalogoIndicadoresNfse::class)->atual()->versao);
        $this->assertNull(app(CatalogoIndicadoresNfse::class)->pendente());
        $this->travelTo(now()->setDate(2026, 12, 8));
        $this->artisan('nfse:instalar-recursos')->assertSuccessful();
        $this->assertSame('AnexoC-v1.01-20260122', app(CatalogoIndicadoresNfse::class)->atual()->versao);
        $this->assertFalse(config('automacao_fiscal.nfse_dps_restrita_enabled'));
        $this->assertFalse(config('automacao_fiscal.nfse_dps_fila_enabled'));
        $this->travelBack();
    }

    public function test_conflito_na_segunda_carga_reverte_a_primeira(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 27));
        DB::table('nfse_indop_versoes')->insert([
            'versao' => 'AnexoC-v1.01-20260122', 'publicada_em' => '2026-01-22',
            'restrita_desde' => null, 'sha256' => str_repeat('a', 64), 'quantidade' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->artisan('nfse:instalar-recursos')->assertFailed();
        $this->assertDatabaseCount('nfse_catalogo_versoes', 0);
        $this->assertDatabaseCount('nfse_catalogo_classificacoes', 0);
        $this->assertDatabaseCount('nfse_indop_versoes', 1);
        $this->travelBack();
    }
}
