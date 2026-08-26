<?php

namespace Tests\Unit;

use App\Services\Documentos\CredenciaisIaDocumentoService;
use App\Services\Documentos\LlamaParseAdaptador;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlamaParseAdaptadorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->mock(CredenciaisIaDocumentoService::class, function ($mock) {
            $mock->shouldReceive('credenciais')->andReturn([
                'gemini' => '',
                'groq' => '',
                'llama_cloud' => 'llx-teste',
            ]);
        });
    }

    public function test_402_nao_retenta_e_marca_esgotado(): void
    {
        Http::fake([
            '*llamaindex.ai*' => Http::response(['detail' => 'Payment Required'], 402),
        ]);

        $markdown = $this->app->make(LlamaParseAdaptador::class)->extrairMarkdown(1, '%PDF-1.4', 'scan.pdf');

        $this->assertNull($markdown);
        $this->assertTrue(Cache::get('documentos:ia:esgotado:llamaparse'));
        Http::assertSentCount(1);
    }

    public function test_markdown_quando_job_conclui(): void
    {
        Http::fake([
            '*parsing/upload' => Http::response(['id' => 'job-1'], 200),
            '*parsing/job/job-1/result/markdown' => Http::response(['markdown' => 'DANFE chave 55'], 200),
            '*parsing/job/job-1' => Http::response(['status' => 'SUCCESS'], 200),
        ]);

        $markdown = $this->app->make(LlamaParseAdaptador::class)->extrairMarkdown(1, '%PDF-1.4', 'scan.pdf');

        $this->assertSame('DANFE chave 55', $markdown);
    }
}
