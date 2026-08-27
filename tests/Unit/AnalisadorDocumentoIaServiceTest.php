<?php

namespace Tests\Unit;

use App\Models\Empresa;
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
        $this->assertNotEmpty($resultado['prompt']);
        $this->assertStringContainsString('Empresas vinculadas ao grupo WhatsApp', $resultado['prompt']);
        $this->assertStringContainsString('comprovante pix', (string) $resultado['resposta']);
    }

    public function test_prompt_inclui_cnpj_e_nomes_das_empresas_do_grupo(): void
    {
        Cache::flush();

        $this->mock(CredenciaisIaDocumentoService::class, function ($mock) {
            $mock->shouldReceive('credenciais')->andReturn([
                'gemini' => '',
                'groq' => 'chave-groq',
                'llama_cloud' => '',
            ]);
        });

        Http::fake([
            '*groq.com*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'tipo_documento' => 'danfe',
                            'empresa_id' => 3,
                            'empresa_razao_social' => 'POSTO PILECO LTDA',
                            'empresa_cnpj' => '89.889.604/0001-44',
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $pileco = new Empresa([
            'razao_social' => 'POSTO PILECO LTDA',
            'nome_fantasia' => 'Posto Pileco',
            'nome' => 'POSTO PILECO LTDA',
            'cnpj' => '89.889.604/0001-44',
        ]);
        $pileco->id = 3;

        $sandra = new Empresa([
            'razao_social' => 'SANDRA QUATRIN',
            'nome' => 'SANDRA QUATRIN',
            'cnpj' => '11.222.333/0001-81',
        ]);
        $sandra->id = 112;

        $resultado = $this->app->make(AnalisadorDocumentoIaService::class)->analisar(
            1,
            'conteudo-imagem',
            'image/jpeg',
            'foto.jpg',
            null,
            [$pileco, $sandra],
        );

        $this->assertSame(3, $resultado['saida']['empresa_id'] ?? null);
        $this->assertStringContainsString('id=3', $resultado['prompt']);
        $this->assertStringContainsString('89.889.604/0001-44', $resultado['prompt']);
        $this->assertStringContainsString('POSTO PILECO LTDA', $resultado['prompt']);
        $this->assertStringContainsString('SANDRA QUATRIN', $resultado['prompt']);
        $this->assertStringContainsString('pasta Drive:', $resultado['prompt']);
    }
}
