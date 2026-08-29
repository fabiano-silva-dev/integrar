<?php

namespace Tests\Feature;

use App\Models\AgendaAutomacao;
use App\Models\AutomacaoExecucao;
use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\EmpresaIntegracao;
use App\Models\EmpresaIntegracaoCredencial;
use App\Models\EmpresaIntegracaoRecurso;
use App\Models\EmpresasOperadora;
use App\Models\PortalIntegracao;
use App\Models\PortalRecurso;
use App\Models\User;
use App\Services\OperadoraStorage;
use Database\Seeders\PortaisIntegracaoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantAutomacaoFiscalTest extends TestCase
{
    use DatabaseTransactions;

    private EmpresasOperadora $operadoraA;

    private EmpresasOperadora $operadoraB;

    private User $userA;

    private Empresa $empresaA;

    private Empresa $empresaB;

    private PortalIntegracao $portal;

    private PortalRecurso $recurso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PortaisIntegracaoSeeder::class);

        $this->operadoraA = EmpresasOperadora::factory()->create(['nome_fantasia' => 'Escritório Auto A']);
        $this->operadoraB = EmpresasOperadora::factory()->create(['nome_fantasia' => 'Escritório Auto B']);

        $this->userA = User::factory()->create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'role' => 'operador',
        ]);

        $this->empresaA = Empresa::factory()->create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'nome' => 'Cliente Auto A',
        ]);

        $this->empresaB = Empresa::factory()->create([
            'empresa_operadora_id' => $this->operadoraB->id,
            'nome' => 'Cliente Auto B',
        ]);

        $this->portal = PortalIntegracao::query()->where('codigo', 'ecac_rs')->firstOrFail();
        $this->recurso = PortalRecurso::query()
            ->where('portal_integracao_id', $this->portal->id)
            ->where('codigo', 'nfe_emitidas')
            ->firstOrFail();
    }

    public function test_seeder_cria_portais_e_recursos_globais(): void
    {
        $this->assertTrue(PortalIntegracao::query()->where('codigo', 'ecac_rs')->exists());
        $this->assertTrue(PortalIntegracao::query()->where('codigo', 'nfse_nacional')->exists());

        $ecac = PortalIntegracao::query()->where('codigo', 'ecac_rs')->firstOrFail();
        $this->assertGreaterThanOrEqual(3, $ecac->recursos()->count());
    }

    public function test_integracoes_sao_isoladas_por_escritorio(): void
    {
        $integracaoA = EmpresaIntegracao::create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'empresa_id' => $this->empresaA->id,
            'portal_integracao_id' => $this->portal->id,
            'ativo' => true,
            'modo_autenticacao' => 'certificado_a1',
            'status_configuracao' => 'pendente',
        ]);

        EmpresaIntegracao::create([
            'empresa_operadora_id' => $this->operadoraB->id,
            'empresa_id' => $this->empresaB->id,
            'portal_integracao_id' => $this->portal->id,
            'ativo' => true,
            'modo_autenticacao' => 'certificado_a1',
            'status_configuracao' => 'pendente',
        ]);

        $this->actingAs($this->userA);

        $visiveis = EmpresaIntegracao::all();

        $this->assertCount(1, $visiveis);
        $this->assertEquals($integracaoA->id, $visiveis->first()->id);
    }

    public function test_certificado_criptografa_senha_e_nao_vaza_no_array(): void
    {
        Storage::fake('local');

        $path = OperadoraStorage::put(
            'automacao-fiscal/certificados',
            'cert-teste.pfx',
            'conteudo-fake-pfx',
            $this->operadoraA->id
        );

        $certificado = CertificadoDigital::create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'empresa_id' => null,
            'nome' => 'Certificado Contador A',
            'tipo' => 'A1',
            'arquivo_path' => $path,
            'senha_criptografada' => 'senha-secreta-teste',
            'ativo' => true,
        ]);

        $certificado->refresh();

        $this->assertSame('senha-secreta-teste', $certificado->senha_criptografada);
        $this->assertArrayNotHasKey('senha_criptografada', $certificado->toArray());
        $this->assertNotEquals('senha-secreta-teste', $certificado->getRawOriginal('senha_criptografada'));
    }

    public function test_credencial_criptografa_segredo(): void
    {
        $integracao = EmpresaIntegracao::create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'empresa_id' => $this->empresaA->id,
            'portal_integracao_id' => $this->portal->id,
            'ativo' => true,
            'modo_autenticacao' => 'usuario_senha',
            'status_configuracao' => 'pendente',
        ]);

        $credencial = EmpresaIntegracaoCredencial::create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'empresa_integracao_id' => $integracao->id,
            'usuario_criptografado' => 'usuario.portal',
            'segredo_criptografado' => 'segredo-portal',
            'ativo' => true,
        ]);

        $credencial->refresh();

        $this->assertSame('usuario.portal', $credencial->usuario_criptografado);
        $this->assertSame('segredo-portal', $credencial->segredo_criptografado);
        $this->assertArrayNotHasKey('segredo_criptografado', $credencial->toArray());
    }

    public function test_execucao_e_recurso_ficam_no_tenant_correto(): void
    {
        $agenda = AgendaAutomacao::create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'nome' => 'Diária 06:00',
            'ativo' => true,
            'frequencia' => 'diaria',
            'horarios' => ['06:00'],
        ]);

        $integracao = EmpresaIntegracao::create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'empresa_id' => $this->empresaA->id,
            'portal_integracao_id' => $this->portal->id,
            'ativo' => true,
            'modo_autenticacao' => 'certificado_a1',
            'status_configuracao' => 'configurado',
        ]);

        $vinculo = EmpresaIntegracaoRecurso::create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'empresa_integracao_id' => $integracao->id,
            'portal_recurso_id' => $this->recurso->id,
            'ativo' => true,
            'agenda_automacao_id' => $agenda->id,
            'parametros' => ['modelo' => 'nfe'],
        ]);

        $execucao = AutomacaoExecucao::create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'empresa_id' => $this->empresaA->id,
            'empresa_integracao_id' => $integracao->id,
            'portal_recurso_id' => $this->recurso->id,
            'agenda_automacao_id' => $agenda->id,
            'gatilho' => 'manual',
            'status' => 'pendente',
            'mensagem_usuario' => 'Aguardando fila',
        ]);

        $this->actingAs($this->userA);

        $this->assertCount(1, AgendaAutomacao::all());
        $this->assertCount(1, EmpresaIntegracaoRecurso::all());
        $this->assertCount(1, AutomacaoExecucao::all());
        $this->assertNotEmpty($execucao->fresh()->uuid);
        $this->assertEquals($vinculo->id, EmpresaIntegracaoRecurso::first()->id);
    }

    public function test_storage_de_automacao_respeita_operadora(): void
    {
        Storage::fake('local');

        $dirA = OperadoraStorage::certificadosDirectory($this->operadoraA->id);
        $dirB = OperadoraStorage::certificadosDirectory($this->operadoraB->id);

        $this->assertStringContainsString((string) $this->operadoraA->id, $dirA);
        $this->assertStringContainsString((string) $this->operadoraB->id, $dirB);
        $this->assertNotSame($dirA, $dirB);

        OperadoraStorage::put('automacao-fiscal/artefatos/exec-1', 'saida.txt', 'conteudo', $this->operadoraA->id);

        $this->assertTrue(Storage::exists($this->operadoraA->id.'/automacao-fiscal/artefatos/exec-1/saida.txt'));
        $this->assertFalse(Storage::exists($this->operadoraB->id.'/automacao-fiscal/artefatos/exec-1/saida.txt'));
    }

    public function test_nao_vincula_certificado_de_outro_escritorio(): void
    {
        $this->actingAs($this->userA);

        $certificadoB = CertificadoDigital::create([
            'empresa_operadora_id' => $this->operadoraB->id,
            'nome' => 'Certificado B',
            'tipo' => 'A1',
            'arquivo_path' => 'fake.pfx',
            'senha_criptografada' => 'x',
            'ativo' => true,
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(\App\Services\AutomacaoFiscal\EmpresaIntegracaoService::class)->sincronizar($this->empresaA, [
            [
                'portal_codigo' => 'ecac_rs',
                'ativo' => true,
                'certificado_digital_id' => $certificadoB->id,
                'recursos' => [
                    'nfe_emitidas' => ['ativo' => true],
                ],
            ],
        ]);
    }

    public function test_nao_vincula_agenda_de_outro_escritorio(): void
    {
        $this->actingAs($this->userA);

        $agendaB = AgendaAutomacao::create([
            'empresa_operadora_id' => $this->operadoraB->id,
            'nome' => 'Agenda B',
            'ativo' => true,
            'frequencia' => 'diaria',
            'horarios' => ['06:00'],
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(\App\Services\AutomacaoFiscal\EmpresaIntegracaoService::class)->sincronizar($this->empresaA, [
            [
                'portal_codigo' => 'ecac_rs',
                'ativo' => true,
                'recursos' => [
                    'nfe_emitidas' => [
                        'ativo' => true,
                        'agenda_automacao_id' => $agendaB->id,
                    ],
                ],
            ],
        ]);
    }

    public function test_vincula_certificado_e_agenda_do_mesmo_escritorio(): void
    {
        $this->actingAs($this->userA);

        $certificado = CertificadoDigital::create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'nome' => 'Certificado A',
            'tipo' => 'A1',
            'arquivo_path' => 'fake-a.pfx',
            'senha_criptografada' => 'x',
            'ativo' => true,
        ]);

        $agenda = AgendaAutomacao::create([
            'empresa_operadora_id' => $this->operadoraA->id,
            'nome' => 'Agenda A',
            'ativo' => true,
            'frequencia' => 'diaria',
            'horarios' => ['06:00'],
        ]);

        app(\App\Services\AutomacaoFiscal\EmpresaIntegracaoService::class)->sincronizar($this->empresaA, [
            [
                'portal_codigo' => 'ecac_rs',
                'ativo' => true,
                'certificado_digital_id' => $certificado->id,
                'recursos' => [
                    'nfe_emitidas' => [
                        'ativo' => true,
                        'agenda_automacao_id' => $agenda->id,
                    ],
                ],
            ],
        ]);

        $integracao = EmpresaIntegracao::query()
            ->where('empresa_id', $this->empresaA->id)
            ->where('portal_integracao_id', $this->portal->id)
            ->firstOrFail();

        $this->assertSame($certificado->id, $integracao->certificado_digital_id);

        $vinculo = EmpresaIntegracaoRecurso::query()
            ->where('empresa_integracao_id', $integracao->id)
            ->where('portal_recurso_id', $this->recurso->id)
            ->firstOrFail();

        $this->assertSame($agenda->id, $vinculo->agenda_automacao_id);
    }
}
