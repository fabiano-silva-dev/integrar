<?php

namespace Tests\Unit;

use App\Services\Documentos\AcessoLinkDrive;
use Tests\TestCase;

class AcessoLinkDriveTest extends TestCase
{
    private AcessoLinkDrive $acesso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acesso = new AcessoLinkDrive;
    }

    public function test_nao_cria_permissao_anyone(): void
    {
        $this->assertFalse(method_exists($this->acesso, 'garantir'));
        $this->assertFalse(method_exists($this->acesso, 'permissaoAnyone'));
    }

    public function test_ja_liberado_quando_anyone_leitor(): void
    {
        $this->assertTrue($this->acesso->jaLiberado([
            ['type' => 'user', 'role' => 'owner'],
            ['type' => 'anyone', 'role' => 'reader'],
        ]));
    }

    public function test_ja_liberado_quando_dominio_leitor(): void
    {
        $this->assertTrue($this->acesso->jaLiberado([
            ['type' => 'domain', 'role' => 'reader'],
        ]));
    }

    public function test_nao_liberado_so_com_dono(): void
    {
        $this->assertFalse($this->acesso->jaLiberado([
            ['type' => 'user', 'role' => 'owner'],
        ]));
        $this->assertFalse($this->acesso->jaLiberado([]));
    }

    public function test_dominio_workspace_ignora_gmail(): void
    {
        $this->assertNull($this->acesso->dominioWorkspace('contador@gmail.com'));
        $this->assertSame('escritorio.com.br', $this->acesso->dominioWorkspace('ana@escritorio.com.br'));
        $this->assertNull($this->acesso->dominioWorkspace('invalido'));
    }

    public function test_identifica_somente_permissoes_publicas(): void
    {
        $this->assertTrue($this->acesso->ehPermissaoPublica(['id' => 'anyoneWithLink', 'type' => 'anyone', 'role' => 'reader']));
        $this->assertTrue($this->acesso->ehPermissaoPublica(['id' => 'd1', 'type' => 'domain', 'role' => 'reader']));
        $this->assertFalse($this->acesso->ehPermissaoPublica(['id' => 'u1', 'type' => 'user', 'role' => 'owner']));
        $this->assertFalse($this->acesso->ehPermissaoPublica(['id' => 'g1', 'type' => 'group', 'role' => 'reader']));
    }

    public function test_remocao_padrao_remove_anyone_e_preserva_domain(): void
    {
        $anyone = ['id' => 'anyoneWithLink', 'type' => 'anyone', 'role' => 'reader'];
        $domain = ['id' => 'd1', 'type' => 'domain', 'role' => 'reader'];
        $owner = ['id' => 'u1', 'type' => 'user', 'role' => 'owner'];
        $group = ['id' => 'g1', 'type' => 'group', 'role' => 'reader'];

        $this->assertTrue($this->acesso->deveRemoverPermissao($anyone, false));
        $this->assertFalse($this->acesso->deveRemoverPermissao($domain, false));
        $this->assertFalse($this->acesso->deveRemoverPermissao($owner, false));
        $this->assertFalse($this->acesso->deveRemoverPermissao($group, false));

        $this->assertTrue($this->acesso->deveRemoverPermissao($domain, true));
        $this->assertFalse($this->acesso->deveRemoverPermissao($owner, true));
        $this->assertFalse($this->acesso->deveRemoverPermissao($group, true));
    }

    public function test_upload_nao_publica_arquivo_como_anyone(): void
    {
        $fonte = file_get_contents(app_path('Services/Documentos/GoogleDriveService.php'));

        $this->assertIsString($fonte);
        $this->assertStringNotContainsString('tornarAcessivelPorLink', $fonte);
        $this->assertStringNotContainsString('liberarLinksDaEmpresa', $fonte);
        $this->assertDoesNotMatchRegularExpression('/permissions\s*->\s*create/', $fonte);
        $this->assertStringNotContainsString("'anyone'", $fonte);
    }
}
