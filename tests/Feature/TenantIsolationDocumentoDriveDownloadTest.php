<?php

namespace Tests\Feature;

use App\Enums\Documentos\StatusContaGoogle;
use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Models\Documentos\ContaGoogle;
use App\Models\Documentos\DocumentoProcessoLog;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\EmpresaPastaDrive;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\User;
use App\Services\Documentos\DocumentoDriveException;
use App\Services\Documentos\GoogleDriveService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantIsolationDocumentoDriveDownloadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_usuario_nao_baixa_documento_de_outro_escritorio(): void
    {
        [$opA, $userA] = $this->escritorioComDocumento('file-a', 'nota-a.pdf');
        [, , $docB] = $this->escritorioComDocumento('file-b', 'secreto-b.pdf');

        $this->mock(GoogleDriveService::class, function ($mock) {
            $mock->shouldReceive('streamArquivo')->never();
        });

        $this->actingAs($userA);

        $this->get(route('documentos.download', $docB->id))->assertNotFound();
        $this->get(route('documentos.visualizar', $docB->id))->assertNotFound();
    }

    public function test_download_devolve_attachment_com_nome_e_mime(): void
    {
        [, $user, $documento] = $this->escritorioComDocumento('file-a', 'nota.pdf', 'application/pdf');

        $this->mock(GoogleDriveService::class, function ($mock) {
            $mock->shouldReceive('streamArquivo')->once()->andReturn([
                'body' => '%PDF-conteudo',
                'nome' => 'nota.pdf',
                'mime' => 'application/pdf',
                'tamanho' => 13,
            ]);
        });

        $this->actingAs($user);

        $response = $this->get(route('documentos.download', ['documento' => $documento->id, 'empresa' => $documento->empresa_id]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('nota.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertSame('%PDF-conteudo', $response->streamedContent());
    }

    public function test_visualizar_pdf_devolve_inline(): void
    {
        [, $user, $documento] = $this->escritorioComDocumento('file-a', 'nota.pdf', 'application/pdf');

        $this->mock(GoogleDriveService::class, function ($mock) {
            $mock->shouldReceive('streamArquivo')->once()->andReturn([
                'body' => '%PDF-inline',
                'nome' => 'nota.pdf',
                'mime' => 'application/pdf',
                'tamanho' => 11,
            ]);
        });

        $this->actingAs($user);

        $response = $this->get(route('documentos.visualizar', $documento->id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));
    }

    public function test_conta_google_ausente_mostra_mensagem_amigavel(): void
    {
        [$operadora, $user, $empresa] = $this->escritorioBase();
        $documento = $this->documento($operadora->id, $empresa->id, 'file-a', 'nota.pdf');

        $this->actingAs($user);

        $this->get(route('documentos.download', $documento->id))
            ->assertStatus(409)
            ->assertSee('A conta Google deste escritório não está conectada.');
    }

    public function test_arquivo_inexistente_no_drive_nao_retorna_500_e_registra_log(): void
    {
        config(['documentos.debug' => false]);

        [, $user, $documento] = $this->escritorioComDocumento('file-sumiu', 'nota.pdf');

        $this->mock(GoogleDriveService::class, function ($mock) {
            $mock->shouldReceive('streamArquivo')->once()->andThrow(DocumentoDriveException::arquivoNaoEncontrado());
        });

        $this->actingAs($user);

        $this->get(route('documentos.download', $documento->id))
            ->assertNotFound()
            ->assertSee('O arquivo não foi encontrado no Google Drive.');

        $this->assertTrue(
            DocumentoProcessoLog::query()
                ->where('documento_recebido_id', $documento->id)
                ->where('mensagem', 'like', '%não foi encontrado%')
                ->exists()
        );
    }

    public function test_oauth_expirado_mostra_mensagem_para_reconectar(): void
    {
        [, $user, $documento] = $this->escritorioComDocumento('file-a', 'nota.pdf');

        $this->mock(GoogleDriveService::class, function ($mock) {
            $mock->shouldReceive('streamArquivo')->once()->andThrow(DocumentoDriveException::oauthExpirado());
        });

        $this->actingAs($user);

        $this->get(route('documentos.download', $documento->id))
            ->assertStatus(409)
            ->assertSee('A conexão com o Google Drive deste escritório expirou.');
    }

    public function test_download_com_token_renovado_conclui(): void
    {
        [, $user, $documento] = $this->escritorioComDocumento('file-a', 'nota.pdf');

        $this->mock(GoogleDriveService::class, function ($mock) {
            $mock->shouldReceive('streamArquivo')->once()->andReturn([
                'body' => 'ok-apos-refresh',
                'nome' => 'nota.pdf',
                'mime' => 'application/pdf',
                'tamanho' => 16,
            ]);
        });

        $this->actingAs($user);

        $response = $this->get(route('documentos.download', $documento->id));

        $response->assertOk();
        $this->assertSame('ok-apos-refresh', $response->streamedContent());
    }

    public function test_acesso_publico_nao_autenticado_nao_baixa(): void
    {
        [, , $documento] = $this->escritorioComDocumento('file-a', 'nota.pdf');

        $this->get(route('documentos.download', $documento->id))->assertRedirect();
        $this->get(route('documentos.visualizar', $documento->id))->assertRedirect();
    }

    public function test_gerente_nao_ve_abrir_no_drive_e_usa_rotas_internas(): void
    {
        [$operadora, , $documento] = $this->escritorioComDocumento('file-a', 'nota-visivel.pdf');
        $this->pastaTipoDrive($operadora->id, (int) $documento->empresa_id, TipoDocumentoRecebido::Nfe, 2026, 'pasta-nfe');
        $gerente = User::factory()->gerente()->create(['empresa_operadora_id' => $operadora->id]);

        $this->actingAs($gerente);

        \Livewire\Livewire::test(\App\Livewire\Documentos\ExploradorDocumentos::class)
            ->call('abrirEmpresa', (int) $documento->empresa_id)
            ->call('abrirAno', 2026)
            ->call('abrirTipo', 'nfe')
            ->assertSee('nota-visivel.pdf')
            ->assertSee('Visualizar')
            ->assertDontSee('Abrir no Drive')
            ->assertDontSee('https://drive.google.com');
    }

    public function test_admin_ve_abrir_no_drive(): void
    {
        [$operadora, $admin, $empresa] = $this->escritorioBase();
        $this->pastaTipoDrive($operadora->id, $empresa->id, TipoDocumentoRecebido::Nfe, 2026, 'pasta-nfe');

        $this->actingAs($admin);

        \Livewire\Livewire::test(\App\Livewire\Documentos\ExploradorDocumentos::class)
            ->call('abrirEmpresa', $empresa->id)
            ->assertSee('Abrir no Drive');
    }

    public function test_download_registra_log_mesmo_com_debug_desligado(): void
    {
        config(['documentos.debug' => false]);
        [, $user, $documento] = $this->escritorioComDocumento('file-a', 'nota.pdf');

        $this->mock(GoogleDriveService::class, function ($mock) {
            $mock->shouldReceive('streamArquivo')->once()->andReturn([
                'body' => 'x',
                'nome' => 'nota.pdf',
                'mime' => 'application/pdf',
                'tamanho' => 1,
            ]);
        });

        $this->actingAs($user);
        $this->get(route('documentos.download', $documento->id))->assertOk();

        $this->assertTrue(
            DocumentoProcessoLog::query()
                ->where('documento_recebido_id', $documento->id)
                ->where('mensagem', 'Documento baixado pelo usuário.')
                ->exists()
        );
    }

    /**
     * @return array{0: EmpresasOperadora, 1: User, 2: Empresa}
     */
    private function escritorioBase(): array
    {
        $operadora = EmpresasOperadora::factory()->create();
        $user = User::factory()->admin()->create(['empresa_operadora_id' => $operadora->id]);
        $empresa = Empresa::factory()->create([
            'empresa_operadora_id' => $operadora->id,
            'nome' => 'Matriz Documentos',
            'nome_fantasia' => 'Matriz Documentos',
            'ativo' => true,
        ]);

        EmpresaPastaDrive::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'tipo' => EmpresaPastaDrive::TIPO_RAIZ,
            'ano' => 0,
            'google_folder_id' => 'raiz-'.$empresa->id,
            'google_folder_nome' => 'Matriz Documentos',
            'google_web_link' => 'https://drive.google.com/drive/folders/raiz-'.$empresa->id,
        ]);
        EmpresaPastaDrive::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'tipo' => 'ano-2026',
            'ano' => 2026,
            'google_folder_id' => 'ano-'.$empresa->id,
            'google_folder_nome' => '2026',
        ]);

        return [$operadora, $user, $empresa];
    }

    /**
     * @return array{0: EmpresasOperadora, 1: User, 2: DocumentoRecebido}
     */
    private function escritorioComDocumento(string $fileId, string $nome, string $mime = 'application/pdf'): array
    {
        [$operadora, $user, $empresa] = $this->escritorioBase();
        $this->contaGoogleConectada($operadora->id);
        $documento = $this->documento($operadora->id, $empresa->id, $fileId, $nome, $mime);

        return [$operadora, $user, $documento];
    }

    private function documento(int $operadoraId, int $empresaId, string $fileId, string $nome, string $mime = 'application/pdf'): DocumentoRecebido
    {
        return DocumentoRecebido::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadoraId,
            'empresa_id' => $empresaId,
            'nome_original' => $nome,
            'mime' => $mime,
            'status' => StatusDocumentoRecebido::EnviadoDrive,
            'drive_file_id' => $fileId,
            'drive_web_link' => 'https://drive.google.com/file/d/'.$fileId.'/view',
            'tipo_documento' => TipoDocumentoRecebido::Nfe,
            'ano' => 2026,
        ]);
    }

    private function pastaTipoDrive(int $operadoraId, int $empresaId, TipoDocumentoRecebido $tipo, int $ano, string $folderId): void
    {
        EmpresaPastaDrive::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadoraId,
            'empresa_id' => $empresaId,
            'tipo' => $tipo->value,
            'ano' => $ano,
            'google_folder_id' => $folderId,
            'google_folder_nome' => $tipo->pastaDrive(),
        ]);
    }

    private function contaGoogleConectada(int $operadoraId): void
    {
        ContaGoogle::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadoraId,
            'google_email' => 'drive@escritorio.test',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'status' => StatusContaGoogle::Conectado,
        ]);
    }
}
