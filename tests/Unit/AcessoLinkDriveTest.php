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
        $this->acesso = new AcessoLinkDrive();
    }

    public function test_permissao_anyone_e_somente_leitura_sem_busca_publica(): void
    {
        $perm = $this->acesso->permissaoAnyone();

        $this->assertSame('anyone', $perm->getType());
        $this->assertSame('reader', $perm->getRole());
        $this->assertFalse($perm->getAllowFileDiscovery());
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
}
