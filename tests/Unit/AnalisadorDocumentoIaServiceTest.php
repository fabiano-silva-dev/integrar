<?php

namespace Tests\Unit;

use App\Services\Documentos\AnalisadorDocumentoIaService;
use App\Services\Documentos\CredenciaisIaDocumentoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalisadorDocumentoIaServiceTest extends TestCase
{
    public function test_gemini_429_cai_no_groq(): void
    {
        Cache::flush();

        $this->mock(CredenciaisIaDocumentoService::class, function ($mock) {
            $mock->shouldReceive('credenciais')->andReturn([
                'gemini' => 'chave-gemini',
                'groq' => 'chave-groq',
                'llama_cloud' => '',
            ]);
        });

        Http::fake([
            '*googleapis.com*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429),
            '*groq.com*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode(['tipo_documento' => 'comprovante pix', 'data_emissao' => '2026-08-20']),
                    ],
                ]],
            ], 200),
        ]);

        $resultado = $this->app->make(AnalisadorDocumentoIaService::class)->analisar(
            1,
            'conteudo-imagem',
            'image/jpeg',
            'foto.jpg',
        );

        $this->assertNotNull($resultado);
        $this->assertSame('ia_groq', $resultado['origem']);
        $this->assertSame('comprovante pix', $resultado['saida']['tipo_documento'] ?? null);
    }
}
