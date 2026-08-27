<?php

namespace Tests\Feature;

use App\Livewire\GerenciadorUsuarios;
use App\Models\EmpresasOperadora;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class TenantGerenciadorUsuariosPermissoesTest extends TestCase
{
    use DatabaseTransactions;

    private EmpresasOperadora $operadora;

    private User $admin;

    private User $gerente;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operadora = EmpresasOperadora::factory()->create();

        $this->admin = User::factory()->admin()->create([
            'empresa_operadora_id' => $this->operadora->id,
        ]);

        $this->gerente = User::factory()->gerente()->create([
            'empresa_operadora_id' => $this->operadora->id,
        ]);

        $this->operador = User::factory()->create([
            'empresa_operadora_id' => $this->operadora->id,
            'role' => 'operador',
        ]);
    }

    public function test_gerente_nao_cadastra_gerente(): void
    {
        $this->actingAs($this->gerente);

        Livewire::test(GerenciadorUsuarios::class)
            ->set('name', 'Novo Gerente')
            ->set('email', 'novo.gerente@teste.com')
            ->set('password', 'secret12')
            ->set('role', 'gerente')
            ->call('salvarUsuario')
            ->assertHasErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'novo.gerente@teste.com']);
    }

    public function test_gerente_nao_cadastra_administrador(): void
    {
        $this->actingAs($this->gerente);

        Livewire::test(GerenciadorUsuarios::class)
            ->set('name', 'Novo Admin')
            ->set('email', 'novo.admin@teste.com')
            ->set('password', 'secret12')
            ->set('role', 'admin')
            ->call('salvarUsuario')
            ->assertHasErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'novo.admin@teste.com']);
    }

    public function test_gerente_cadastra_operador(): void
    {
        $this->actingAs($this->gerente);

        Livewire::test(GerenciadorUsuarios::class)
            ->set('name', 'Novo Operador')
            ->set('email', 'novo.operador@teste.com')
            ->set('password', 'secret12')
            ->set('role', 'operador')
            ->call('salvarUsuario')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'novo.operador@teste.com',
            'role' => 'operador',
            'empresa_operadora_id' => $this->operadora->id,
        ]);
    }

    public function test_gerente_nao_promove_o_proprio_usuario_a_admin(): void
    {
        $this->actingAs($this->gerente);

        Livewire::test(GerenciadorUsuarios::class)
            ->call('editarUsuario', $this->gerente->id)
            ->set('role', 'admin')
            ->call('salvarUsuario')
            ->assertHasErrors(['role']);

        $this->assertSame('gerente', $this->gerente->fresh()->role);
    }

    public function test_gerente_nao_promove_operador_a_gerente(): void
    {
        $this->actingAs($this->gerente);

        Livewire::test(GerenciadorUsuarios::class)
            ->call('editarUsuario', $this->operador->id)
            ->set('role', 'gerente')
            ->call('salvarUsuario')
            ->assertHasErrors(['role']);

        $this->assertSame('operador', $this->operador->fresh()->role);
    }

    public function test_gerente_nao_edita_administrador(): void
    {
        $this->actingAs($this->gerente);

        Livewire::test(GerenciadorUsuarios::class)
            ->call('editarUsuario', $this->admin->id)
            ->assertSet('modoEdicao', false)
            ->assertSet('usuario_id', null);

        $this->assertSame($this->admin->name, $this->admin->fresh()->name);
    }

    public function test_gerente_nao_exclui_administrador(): void
    {
        $this->actingAs($this->gerente);

        Livewire::test(GerenciadorUsuarios::class)
            ->call('excluirUsuario', $this->admin->id);

        $this->assertNotNull($this->admin->fresh());
    }

    public function test_admin_cadastra_gerente(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(GerenciadorUsuarios::class)
            ->set('name', 'Gerente do Admin')
            ->set('email', 'gerente.admin@teste.com')
            ->set('password', 'secret12')
            ->set('role', 'gerente')
            ->call('salvarUsuario')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'gerente.admin@teste.com',
            'role' => 'gerente',
        ]);
    }
}
