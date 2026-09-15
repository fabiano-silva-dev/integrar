<?php

namespace Tests\Feature;

use App\Models\EmpresasOperadora;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantAuthenticationAtivoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_usuario_inativo_nao_autentica(): void
    {
        $user = User::factory()->create(['ativo' => false]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertInvalid(['email' => 'Este usuário está inativo.']);
    }

    public function test_usuario_de_escritorio_desativado_nao_autentica(): void
    {
        $operadora = EmpresasOperadora::factory()->create(['ativo' => false]);
        $user = User::factory()->create([
            'empresa_operadora_id' => $operadora->id,
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertInvalid(['email' => 'Este escritório está desativado.']);
    }

    public function test_usuario_inativo_e_desconectado_ao_acessar_area_logada(): void
    {
        $user = User::factory()->create(['ativo' => false]);

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_usuario_ativo_autentica(): void
    {
        $user = User::factory()->create(['ativo' => true]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('home'));
    }
}
