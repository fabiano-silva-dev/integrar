<?php

namespace Tests\Feature;

use App\Enums\Documentos\StatusConexaoWhatsapp;
use App\Models\Documentos\ConexaoWhatsapp;
use App\Models\Documentos\GrupoWhatsapp;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantIsolationDocumentosTest extends TestCase
{
    use DatabaseTransactions;

    public function test_usuario_nao_ve_conexao_whatsapp_de_outro_escritorio(): void
    {
        $opA = EmpresasOperadora::factory()->create();
        $opB = EmpresasOperadora::factory()->create();
        $userA = User::factory()->admin()->create(['empresa_operadora_id' => $opA->id]);

        ConexaoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $opA->id,
            'status' => StatusConexaoWhatsapp::Desconectado,
            'nome_instancia' => 'integrar-op-'.$opA->id,
        ]);
        ConexaoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $opB->id,
            'status' => StatusConexaoWhatsapp::Conectado,
            'nome_instancia' => 'integrar-op-'.$opB->id,
        ]);

        $this->actingAs($userA);

        $this->assertCount(1, ConexaoWhatsapp::all());
        $this->assertSame('integrar-op-'.$opA->id, ConexaoWhatsapp::query()->first()?->nome_instancia);
    }

    public function test_admin_abre_telas_do_modulo_documentos(): void
    {
        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->admin()->create(['empresa_operadora_id' => $operadora->id]);

        $this->actingAs($user);

        config([
            'documentos.google.client_id' => '',
            'documentos.google.client_secret' => '',
        ]);

        $this->get(route('documentos.whatsapp'))
            ->assertOk()
            ->assertSee('Conectar o WhatsApp do escritório')
            ->assertSee('Conectar WhatsApp')
            ->assertDontSee('Instância')
            ->assertDontSee('>Erro<', false);
        $this->get(route('documentos.grupos'))->assertOk();
        $this->get(route('documentos.drive'))
            ->assertOk()
            ->assertSee('Liberar o Google Drive')
            ->assertSee('ID do cliente')
            ->assertDontSee('GOOGLE_CLIENT_ID');
        $this->get(route('documentos.ia'))
            ->assertOk()
            ->assertSee('Leitura automática')
            ->assertSee('Gemini')
            ->assertSee('Testar');
        $this->get(route('documentos.recebidos'))->assertOk();
        $this->get(route('documentos'))
            ->assertOk()
            ->assertSee('Selecione a empresa');
    }

    public function test_chaves_ia_nao_vazam_entre_escritorios(): void
    {
        config([
            'documentos.ia.gemini_api_key' => '',
            'documentos.ia.groq_api_key' => '',
            'documentos.ia.llama_cloud_api_key' => '',
        ]);

        $opA = EmpresasOperadora::factory()->create();
        $opB = EmpresasOperadora::factory()->create();
        $userA = User::factory()->admin()->create(['empresa_operadora_id' => $opA->id]);
        $userB = User::factory()->admin()->create(['empresa_operadora_id' => $opB->id]);
        $credenciais = app(\App\Services\Documentos\CredenciaisIaDocumentoService::class);

        $this->actingAs($userA);

        \Livewire\Livewire::test(\App\Livewire\Documentos\ConfiguracaoIaDocumentos::class)
            ->assertSee('Leitura automática')
            ->set('geminiApiKey', 'chave-gemini-escritorio-a')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertTrue($credenciais->status($opA->id)['gemini']);
        $this->assertFalse($credenciais->status($opB->id)['gemini']);

        $this->actingAs($userB);

        $this->assertFalse($credenciais->status()['gemini']);
        $this->assertNull(\App\Models\Documentos\ConfiguracaoIaDocumento::daOperadora());
        $this->get(route('documentos.ia'))
            ->assertOk()
            ->assertSee('Faltando');
    }

    public function test_admin_testa_chave_gemini_sem_salvar(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*googleapis.com*' => \Illuminate\Support\Facades\Http::response(['models' => []], 200),
        ]);

        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->admin()->create(['empresa_operadora_id' => $operadora->id]);

        $this->actingAs($user);

        \Livewire\Livewire::test(\App\Livewire\Documentos\ConfiguracaoIaDocumentos::class)
            ->set('geminiApiKey', 'AIza-digitada')
            ->call('testarGemini')
            ->assertSet('testeGemini.ok', true)
            ->assertSee('Gemini aceitou a chave.');

        $this->assertNull(\App\Models\Documentos\ConfiguracaoIaDocumento::daOperadora());
    }

    public function test_tela_whatsapp_explica_falha_sem_jargao(): void
    {
        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->admin()->create(['empresa_operadora_id' => $operadora->id]);

        ConexaoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'status' => StatusConexaoWhatsapp::Erro,
            'nome_instancia' => 'integrar-op-'.$operadora->id,
        ]);

        $this->actingAs($user);

        $this->get(route('documentos.whatsapp'))
            ->assertOk()
            ->assertSee('Tentar de novo')
            ->assertSee('A última conexão não concluiu')
            ->assertDontSee('Instância')
            ->assertDontSee('integrar-op-'.$operadora->id);
    }

    public function test_credenciais_google_sao_isoladas_por_escritorio(): void
    {
        config([
            'documentos.google.client_id' => '',
            'documentos.google.client_secret' => '',
        ]);

        $opA = EmpresasOperadora::factory()->create();
        $opB = EmpresasOperadora::factory()->create();
        $userA = User::factory()->admin()->create(['empresa_operadora_id' => $opA->id]);
        $userB = User::factory()->admin()->create(['empresa_operadora_id' => $opB->id]);
        $drive = app(\App\Services\Documentos\GoogleDriveService::class);

        $this->actingAs($userA);

        \Livewire\Livewire::test(\App\Livewire\Documentos\ContaGoogleDrive::class)
            ->set('googleClientId', '111111111111-abc.apps.googleusercontent.com')
            ->set('googleClientSecret', 'secret-escritorio-a')
            ->call('salvarAplicativo')
            ->assertHasNoErrors()
            ->assertSee('Conectar conta Google');

        $this->assertTrue($drive->configurado($opA->id));
        $this->assertFalse($drive->configurado($opB->id));

        $this->actingAs($userB);

        $this->assertFalse($drive->configurado());
        $this->assertNull(\App\Models\Documentos\ConfiguracaoGoogle::daOperadora());
        $this->get(route('documentos.drive'))
            ->assertOk()
            ->assertSee('Liberar o Google Drive')
            ->assertDontSee('Conectar conta Google');
    }

    public function test_grupos_sao_isolados_por_escritorio(): void
    {
        $opA = EmpresasOperadora::factory()->create();
        $opB = EmpresasOperadora::factory()->create();
        $empresaA = Empresa::factory()->create(['empresa_operadora_id' => $opA->id]);
        $empresaB = Empresa::factory()->create(['empresa_operadora_id' => $opB->id]);
        $userA = User::factory()->admin()->create(['empresa_operadora_id' => $opA->id]);

        $conexaoA = ConexaoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $opA->id,
            'status' => StatusConexaoWhatsapp::Conectado,
            'nome_instancia' => 'integrar-op-'.$opA->id,
        ]);
        $conexaoB = ConexaoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $opB->id,
            'status' => StatusConexaoWhatsapp::Conectado,
            'nome_instancia' => 'integrar-op-'.$opB->id,
        ]);

        GrupoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $opA->id,
            'conexao_whatsapp_id' => $conexaoA->id,
            'empresa_id' => $empresaA->id,
            'jid' => 'aaa@g.us',
            'nome' => 'Grupo A',
            'monitorar' => true,
        ]);
        GrupoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $opB->id,
            'conexao_whatsapp_id' => $conexaoB->id,
            'empresa_id' => $empresaB->id,
            'jid' => 'bbb@g.us',
            'nome' => 'Grupo B',
            'monitorar' => true,
        ]);

        $this->actingAs($userA);

        $grupos = GrupoWhatsapp::all();
        $this->assertCount(1, $grupos);
        $this->assertSame('Grupo A', $grupos->first()?->nome);
    }

    public function test_tela_drive_lista_so_empresas_com_grupo_monitorado(): void
    {
        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->admin()->create(['empresa_operadora_id' => $operadora->id]);
        $monitorada = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'nome' => 'Empresa Com Grupo Ativo',
        ]);
        $semMonitoramento = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'nome' => 'Empresa Sem Monitoramento',
        ]);

        $conexao = ConexaoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'status' => StatusConexaoWhatsapp::Conectado,
            'nome_instancia' => 'integrar-op-'.$operadora->id,
        ]);
        GrupoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'conexao_whatsapp_id' => $conexao->id,
            'empresa_id' => $monitorada->id,
            'jid' => 'grupo-ativo@g.us',
            'nome' => 'Grupo ativo',
            'monitorar' => true,
        ]);
        GrupoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'conexao_whatsapp_id' => $conexao->id,
            'empresa_id' => $semMonitoramento->id,
            'jid' => 'grupo-parado@g.us',
            'nome' => 'Grupo parado',
            'monitorar' => false,
        ]);

        \App\Models\Documentos\ContaGoogle::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'google_email' => 'drive@escritorio.test',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'status' => \App\Enums\Documentos\StatusContaGoogle::Conectado,
        ]);

        config([
            'documentos.google.client_id' => '111111111111-abc.apps.googleusercontent.com',
            'documentos.google.client_secret' => 'secret-teste',
        ]);

        $this->actingAs($user);

        \Livewire\Livewire::test(\App\Livewire\Documentos\ContaGoogleDrive::class)
            ->assertSee('Empresa Com Grupo Ativo')
            ->assertDontSee('Empresa Sem Monitoramento');
    }

    public function test_explorador_lista_so_empresas_com_pasta_no_drive(): void
    {
        $operadora = EmpresasOperadora::factory()->create(['cnpj' => '98.733.333/0001-93']);
        $user = User::factory()->admin()->create(['empresa_operadora_id' => $operadora->id]);
        $comDrive = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'nome' => 'Empresa Com Drive',
            'nome_fantasia' => 'Empresa Com Drive',
            'ativo' => true,
        ]);
        Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'nome' => 'Empresa Sem Drive',
            'nome_fantasia' => 'Empresa Sem Drive',
            'ativo' => true,
        ]);

        \App\Models\Documentos\EmpresaPastaDrive::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $comDrive->id,
            'tipo' => \App\Models\Documentos\EmpresaPastaDrive::TIPO_RAIZ,
            'ano' => 0,
            'google_folder_id' => 'raiz-com-drive',
            'google_folder_nome' => 'Empresa Com Drive',
            'google_web_link' => 'https://drive.google.com/drive/folders/raiz-com-drive',
        ]);

        $this->actingAs($user);

        \Livewire\Livewire::test(\App\Livewire\Documentos\ExploradorDocumentos::class)
            ->assertSee('Empresa Com Drive')
            ->assertDontSee('Empresa Sem Drive');
    }

    public function test_explorador_nao_lista_empresa_de_outro_escritorio(): void
    {
        $opA = EmpresasOperadora::factory()->create(['cnpj' => '98.711.111/0001-91']);
        $opB = EmpresasOperadora::factory()->create(['cnpj' => '98.722.222/0001-92']);
        $userA = User::factory()->admin()->create(['empresa_operadora_id' => $opA->id]);
        $empresaA = Empresa::factory()->create([
            'empresa_operadora_id' => $opA->id,
            'nome' => 'Empresa Escritorio A',
            'nome_fantasia' => 'Empresa Escritorio A',
            'ativo' => true,
        ]);
        $empresaB = Empresa::factory()->create([
            'empresa_operadora_id' => $opB->id,
            'nome' => 'Empresa Escritorio B',
            'nome_fantasia' => 'Empresa Escritorio B',
            'ativo' => true,
        ]);

        \App\Models\Documentos\EmpresaPastaDrive::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $opA->id,
            'empresa_id' => $empresaA->id,
            'tipo' => \App\Models\Documentos\EmpresaPastaDrive::TIPO_RAIZ,
            'ano' => 0,
            'google_folder_id' => 'raiz-a',
            'google_folder_nome' => 'Empresa Escritorio A',
        ]);
        \App\Models\Documentos\EmpresaPastaDrive::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $opB->id,
            'empresa_id' => $empresaB->id,
            'tipo' => \App\Models\Documentos\EmpresaPastaDrive::TIPO_RAIZ,
            'ano' => 0,
            'google_folder_id' => 'raiz-b',
            'google_folder_nome' => 'Empresa Escritorio B',
        ]);

        $this->actingAs($userA);

        \Livewire\Livewire::test(\App\Livewire\Documentos\ExploradorDocumentos::class)
            ->assertSee('Empresa Escritorio A')
            ->assertDontSee('Empresa Escritorio B');
    }

    public function test_explorador_abre_pastas_da_empresa_pelo_catalogo_local(): void
    {
        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->admin()->create(['empresa_operadora_id' => $operadora->id]);
        $empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'nome' => 'Matriz Documentos',
            'nome_fantasia' => 'Matriz Documentos',
            'ativo' => true,
        ]);

        \App\Models\Documentos\EmpresaPastaDrive::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'tipo' => \App\Models\Documentos\EmpresaPastaDrive::TIPO_RAIZ,
            'ano' => 0,
            'google_folder_id' => 'raiz-1',
            'google_folder_nome' => 'Matriz Documentos',
            'google_web_link' => 'https://drive.google.com/drive/folders/raiz-1',
        ]);
        \App\Models\Documentos\EmpresaPastaDrive::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'tipo' => 'ano-2026',
            'ano' => 2026,
            'google_folder_id' => 'ano-1',
            'google_folder_nome' => '2026',
            'google_web_link' => 'https://drive.google.com/drive/folders/ano-1',
        ]);

        $this->actingAs($user);

        \Livewire\Livewire::test(\App\Livewire\Documentos\ExploradorDocumentos::class)
            ->call('abrirEmpresa', $empresa->id)
            ->assertSee('2026')
            ->assertSee('Abrir no Drive');
    }

    public function test_compacta_arquivos_locais_do_escritorio(): void
    {
        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->admin()->create(['empresa_operadora_id' => $operadora->id]);
        $empresa = Empresa::factory()->create(['empresa_operadora_id' => $operadora->id]);

        $this->actingAs($user);

        $path1 = \App\Services\OperadoraStorage::put('documentos/inbox', 'nota.pdf', 'conteudo-a', $operadora->id);
        $path2 = \App\Services\OperadoraStorage::put('documentos/inbox', 'extrato.pdf', 'conteudo-b', $operadora->id);

        $doc1 = \App\Models\Documentos\DocumentoRecebido::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'nome_original' => 'nota.pdf',
            'status' => \App\Enums\Documentos\StatusDocumentoRecebido::EnviadoDrive,
            'drive_file_id' => 'file-a',
            'drive_web_link' => 'https://drive.google.com/file/d/file-a/view',
            'tipo_documento' => \App\Enums\Documentos\TipoDocumentoRecebido::Nfe,
            'ano' => 2026,
            'storage_path' => $path1,
        ]);
        $doc2 = \App\Models\Documentos\DocumentoRecebido::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'nome_original' => 'extrato.pdf',
            'status' => \App\Enums\Documentos\StatusDocumentoRecebido::EnviadoDrive,
            'drive_file_id' => 'file-b',
            'drive_web_link' => 'https://drive.google.com/file/d/file-b/view',
            'tipo_documento' => \App\Enums\Documentos\TipoDocumentoRecebido::Extratos,
            'ano' => 2026,
            'storage_path' => $path2,
        ]);

        $response = app(\App\Services\Documentos\CompactarDocumentosDriveService::class)
            ->baixar([$doc1->id, $doc2->id], 'lote.zip');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('lote.zip', (string) $response->headers->get('content-disposition'));
    }
}
