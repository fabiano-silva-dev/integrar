<?php

namespace Tests\Unit;

use App\Services\AutomacaoFiscal\Runners\NodeRunnerBridge;
use RuntimeException;
use Tests\TestCase;

class NodeRunnerBridgeSenhaTemporariaTest extends TestCase
{
    public function test_arquivo_de_senha_e_apagado_quando_a_acao_lanca(): void
    {
        $path = sys_get_temp_dir().'/cert-password-'.uniqid('', true).'.txt';

        try {
            NodeRunnerBridge::comSenhaTemporaria($path, 'segredo-a1', function () use ($path) {
                $this->assertFileExists($path);
                $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
                throw new RuntimeException('falha simulada');
            });
            $this->fail('A exceção simulada deveria ter sido relançada.');
        } catch (RuntimeException $e) {
            $this->assertSame('falha simulada', $e->getMessage());
        }

        $this->assertFileDoesNotExist($path);
    }
}
