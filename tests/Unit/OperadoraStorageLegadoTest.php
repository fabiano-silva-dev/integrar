<?php

namespace Tests\Unit;

use App\Services\OperadoraStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperadoraStorageLegadoTest extends TestCase
{
    public function test_fallback_legado_respeita_flag(): void
    {
        Storage::fake('local');
        Storage::put('exports/arquivo.txt', 'legado');

        config(['operadora.allow_legacy_global_storage' => true]);
        $this->assertSame('exports/arquivo.txt', OperadoraStorage::resolveRelativePath('exports', 'arquivo.txt', 99));

        config(['operadora.allow_legacy_global_storage' => false]);
        $this->assertNull(OperadoraStorage::resolveRelativePath('exports', 'arquivo.txt', 99));
    }

    public function test_path_do_tenant_tem_prioridade(): void
    {
        Storage::fake('local');
        Storage::put('7/exports/arquivo.txt', 'tenant');
        Storage::put('exports/arquivo.txt', 'legado');

        config(['operadora.allow_legacy_global_storage' => true]);
        $this->assertSame('7/exports/arquivo.txt', OperadoraStorage::resolveRelativePath('exports', 'arquivo.txt', 7));
    }
}
