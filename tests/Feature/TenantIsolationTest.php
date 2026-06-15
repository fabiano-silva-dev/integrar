<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\Terceiro;
use App\Models\User;
use App\Services\ConversaoPdfOfxService;
use App\Services\OperadoraContext;
use App\Services\OperadoraStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private EmpresasOperadora $operadoraA;
    private EmpresasOperadora $operadoraB;
    private User $userA;
    private Empresa $empresaA;
    private Empresa $empresaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operadoraA = EmpresasOperadora::factory()->create(['nome_fantasia' => 'Escritório A']);
        $this->operadoraB = EmpresasOperadora::factory()->create(['nome_fantasia' => 'Escritório B']);

        $this->userA = User::factory()->create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'role' => 'operador',
        ]);

        $this->empresaA = Empresa::factory()->create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'nome' => 'Empresa A',
        ]);

        $this->empresaB = Empresa::factory()->create([
            'empresa_operadora_id' => $this->operadoraB->id,
            'nome' => 'Empresa B',
        ]);
    }

    public function test_usuario_ve_apenas_empresas_do_seu_escritorio(): void
    {
        $this->actingAs($this->userA);

        $empresas = Empresa::all();

        $this->assertCount(1, $empresas);
        $this->assertEquals($this->empresaA->id, $empresas->first()->id);
    }

    public function test_usuario_nao_acessa_empresa_de_outro_escritorio_via_rota(): void
    {
        $this->actingAs($this->userA);

        $response = $this->get(route('trocar-empresa', ['id' => $this->empresaB->id]));

        $response->assertNotFound();
        $this->assertNull(session('empresa_selecionada_id'));
    }

    public function test_usuario_pode_trocar_para_empresa_do_proprio_escritorio(): void
    {
        $this->actingAs($this->userA);

        $response = $this->get(route('trocar-empresa', ['id' => $this->empresaA->id]));

        $response->assertRedirect();
        $this->assertEquals($this->empresaA->id, session('empresa_selecionada_id'));
    }

    public function test_terceiros_sao_isolados_por_escritorio(): void
    {
        Terceiro::factory()->create([
            'nome' => 'Terceiro A',
            'empresa_operadora_id' => $this->operadoraA->id,
        ]);

        Terceiro::factory()->create([
            'nome' => 'Terceiro B',
            'empresa_operadora_id' => $this->operadoraB->id,
        ]);

        $this->actingAs($this->userA);

        $terceiros = Terceiro::all();

        $this->assertCount(1, $terceiros);
        $this->assertEquals('Terceiro A', $terceiros->first()->nome);
    }

    public function test_super_admin_ve_todos_os_escritorios_sem_contexto(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);

        $empresas = Empresa::whereIn('id', [$this->empresaA->id, $this->empresaB->id])->get();

        $this->assertCount(2, $empresas);
    }

    public function test_super_admin_com_contexto_ve_apenas_escritorio_selecionado(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);
        OperadoraContext::set($this->operadoraA->id);

        $empresas = Empresa::whereIn('id', [$this->empresaA->id, $this->empresaB->id])->get();

        $this->assertCount(1, $empresas);
        $this->assertEquals($this->empresaA->id, $empresas->first()->id);
    }

    public function test_usuarios_sao_isolados_por_escritorio(): void
    {
        User::factory()->create([
            'empresa_operadora_id' => $this->operadoraB->id,
            'email' => 'outro@escritorio.com',
        ]);

        $adminA = User::factory()->admin()->create([
            'empresa_operadora_id' => $this->operadoraA->id,
        ]);

        $this->actingAs($adminA);

        $usuarios = User::doEscritorio()
            ->where('role', '!=', 'super_admin')
            ->whereIn('id', [$this->userA->id, $adminA->id])
            ->get();

        $this->assertCount(2, $usuarios);
        $this->assertTrue($usuarios->every(fn ($u) => $u->empresa_operadora_id === $this->operadoraA->id));
    }

    public function test_download_de_arquivo_isolado_por_escritorio(): void
    {
        Storage::fake('local');

        $arquivoA = 'export_a.txt';
        $arquivoB = 'export_b.txt';

        Storage::put("{$this->operadoraA->id}/exports/{$arquivoA}", 'conteudo A');
        Storage::put("{$this->operadoraB->id}/exports/{$arquivoB}", 'conteudo B');

        $this->actingAs($this->userA);

        $this->get(route('download.arquivo', ['arquivo' => $arquivoA]))->assertOk();
        $this->get(route('download.arquivo', ['arquivo' => $arquivoB]))->assertNotFound();
    }

    public function test_storage_usa_diretorio_do_escritorio(): void
    {
        Storage::fake('local');

        $this->actingAs($this->userA);

        $path = OperadoraStorage::put('exports', 'teste.txt', 'conteudo');

        $this->assertStringStartsWith("{$this->operadoraA->id}/exports/", $path);
        Storage::assertExists($path);
    }

    public function test_super_admin_acessa_gerenciador_usuarios_sem_403(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('usuarios'))
            ->assertOk();
    }

    public function test_super_admin_sem_contexto_nao_cria_empresa_sem_escritorio(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);

        Livewire::test(\App\Livewire\GerenciadorEmpresas::class)
            ->set('nome', 'Nova Empresa Teste')
            ->set('cnpj', '12.345.678/0001-90')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('empresas', ['nome' => 'Nova Empresa Teste']);
    }

    public function test_conversao_extrato_recebe_empresa_operadora_id_do_contexto(): void
    {
        $this->actingAs($this->userA);

        $conversao = app(ConversaoPdfOfxService::class)->criarRegistro('sicredi', 'extrato.pdf');

        $this->assertSame($this->operadoraA->id, $conversao->empresa_operadora_id);
        $this->assertDatabaseHas('conversoes_extrato', [
            'id' => $conversao->id,
            'empresa_operadora_id' => $this->operadoraA->id,
        ]);
    }

    public function test_super_admin_sem_contexto_nao_cria_conversao_extrato(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(ConversaoPdfOfxService::class)->criarRegistro('sicredi', 'extrato.pdf');
    }

    public function test_super_admin_com_contexto_cria_conversao_extrato(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);
        OperadoraContext::set($this->operadoraA->id);

        $conversao = app(ConversaoPdfOfxService::class)->criarRegistro('sicredi', 'extrato.pdf');

        $this->assertSame($this->operadoraA->id, $conversao->empresa_operadora_id);
    }
}
