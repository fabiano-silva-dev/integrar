<?php

namespace Tests\Unit;

use App\Services\AutomacaoFiscal\Logs\LogSanitizer;
use PHPUnit\Framework\TestCase;

class AutomacaoFiscalLogSanitizerTest extends TestCase
{
    public function test_remove_chaves_sensiveis_do_contexto(): void
    {
        $resultado = LogSanitizer::sanitize([
            'etapa' => 'login',
            'password' => 'segredo',
            'senha_certificado' => '1234',
            'authorization' => 'Bearer abc.def',
            'cookie' => 'session=xyz',
            'nested' => [
                'token' => 'abc',
                'url' => 'https://exemplo.gov.br/painel',
            ],
        ]);

        $this->assertSame('login', $resultado['etapa']);
        $this->assertSame('[REDACTED]', $resultado['password']);
        $this->assertSame('[REDACTED]', $resultado['senha_certificado']);
        $this->assertSame('[REDACTED]', $resultado['authorization']);
        $this->assertSame('[REDACTED]', $resultado['cookie']);
        $this->assertSame('[REDACTED]', $resultado['nested']['token']);
        $this->assertSame('https://exemplo.gov.br/painel', $resultado['nested']['url']);
    }

    public function test_sanitiza_bearer_e_pem_em_mensagem(): void
    {
        $mensagem = LogSanitizer::sanitizeMessage(
            "Auth Bearer abc123xyz\n-----BEGIN PRIVATE KEY-----\nABC\n-----END PRIVATE KEY-----"
        );

        $this->assertStringContainsString('Bearer [REDACTED]', $mensagem);
        $this->assertStringContainsString('[REDACTED_PEM]', $mensagem);
        $this->assertStringNotContainsString('abc123xyz', $mensagem);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $mensagem);
    }
}
