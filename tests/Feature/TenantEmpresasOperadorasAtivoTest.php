<?php

namespace Tests\Feature;

use App\Livewire\EmpresasOperadorasForm;
use App\Models\EmpresasOperadora;
use App\Models\User;
use App\Services\OperadoraContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class TenantEmpresasOperadorasAtivoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_super_admin_desativa_e_reativa_escritorio(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operadora = EmpresasOperadora::factory()->create(['ativo' => true]);

        $this->actingAs($superAdmin);

        Livewire::test(EmpresasOperadorasForm::class)
            ->call('toggleAtivo', $operadora->id);

        $this->assertFalse($operadora->fresh()->ativo);

        Livewire::test(EmpresasOperadorasForm::class)
            ->call('toggleAtivo', $operadora->id);

        $this->assertTrue($operadora->fresh()->ativo);
    }

    public function test_desativar_escritorio_limpo_contexto_selecionado(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operadora = EmpresasOperadora::factory()->create(['ativo' => true]);

        $this->actingAs($superAdmin);
        OperadoraContext::set($operadora->id);

        $this->assertSame($operadora->id, session('operadora_context_id'));

        Livewire::test(EmpresasOperadorasForm::class)
            ->call('toggleAtivo', $operadora->id);

        $this->assertFalse($operadora->fresh()->ativo);
        $this->assertNull(session('operadora_context_id'));
    }

    public function test_nao_seleciona_escritorio_desativado(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operadora = EmpresasOperadora::factory()->create(['ativo' => false]);

        $this->actingAs($superAdmin);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        OperadoraContext::set($operadora->id);
    }
}
