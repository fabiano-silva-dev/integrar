<?php

namespace Tests\Unit;

use App\Services\Documentos\TestarCredencialIaDocumentoService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TestarCredencialIaDocumentoServiceTest extends TestCase
{
    public function test_gemini_aceita_chave(): void
    {
        Http::fake([
            '*googleapis.com*' => Http::response(['models' => [['name' => 'models/gemini-2.5-flash']]], 200),
        ]);

        $r = (new TestarCredencialIaDocumentoService)->testar('gemini', 'AIza-teste');

        $this->assertTrue($r['ok']);
        $this->assertSame('Gemini aceitou a chave.', $r['mensagem']);
    }

    public function test_gemini_rejeita_chave(): void
    {
        Http::fake([
            '*googleapis.com*' => Http::response(['error' => ['status' => 'API_KEY_INVALID']], 400),
        ]);

        $r = (new TestarCredencialIaDocumentoService)->testar('gemini', 'invalida');

        $this->assertFalse($r['ok']);
        $this->assertSame('Esta chave do Gemini não foi aceita.', $r['mensagem']);
    }

    public function test_groq_cota_acabou_ainda_e_chave_valida(): void
    {
        Http::fake([
            '*groq.com*' => Http::response(['error' => ['code' => 'rate_limit']], 429),
        ]);

        $r = (new TestarCredencialIaDocumentoService)->testar('groq', 'gsk-teste');

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('cota', $r['mensagem']);
    }

    public function test_llamaparse_404_de_job_e_chave_valida(): void
    {
        Http::fake([
            '*llamaindex.ai*/parsing/job/*' => Http::response(['detail' => 'Job not found'], 404),
        ]);

        $r = (new TestarCredencialIaDocumentoService)->testar('llama_cloud', 'llx-teste');

        $this->assertTrue($r['ok']);
        $this->assertSame('LlamaParse aceitou a chave.', $r['mensagem']);
    }

    public function test_llamaparse_401_rejeita_chave(): void
    {
        Http::fake([
            '*llamaindex.ai*' => Http::response(['detail' => 'Invalid API Key. Please check your region'], 401),
        ]);

        $r = (new TestarCredencialIaDocumentoService)->testar('llama_cloud', 'llx-invalida');

        $this->assertFalse($r['ok']);
        $this->assertSame('Esta chave do LlamaParse não foi aceita.', $r['mensagem']);
    }

    public function test_llamaparse_402_e_chave_valida(): void
    {
        Http::fake([
            '*llamaindex.ai*/parsing/job/*' => Http::response(['detail' => 'Payment Required'], 402),
        ]);

        $r = (new TestarCredencialIaDocumentoService)->testar('llama_cloud', 'llx-teste');

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('créditos', $r['mensagem']);
    }

    public function test_sem_chave_pede_para_informar(): void
    {
        Http::fake();

        $r = (new TestarCredencialIaDocumentoService)->testar('gemini', '  ');

        $this->assertFalse($r['ok']);
        $this->assertSame('Informe a chave do Gemini para testar.', $r['mensagem']);
        Http::assertNothingSent();
    }
}
