<?php

namespace Tests\Feature;

use App\Enums\Documentos\StatusConexaoWhatsapp;
use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Models\Documentos\ConexaoWhatsapp;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\GrupoWhatsapp;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantIsolationDocumentosWebhookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_webhook_sem_apikey_configurada_aceita_payload(): void
    {
        config(['evolution.api_key' => '']);

        $response = $this->postJson('/webhooks/evolution', [
            'event' => 'connection.update',
            'instance' => 'integrar-op-999',
            'data' => ['state' => 'open'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('eventos_webhook_whatsapp', [
            'tipo_evento' => 'connection.update',
            'status' => 'ignorado',
        ]);
    }

    public function test_webhook_apikey_invalida_retorna_401(): void
    {
        config(['evolution.api_key' => 'chave-secreta']);

        $response = $this->postJson('/webhooks/evolution', [
            'event' => 'connection.update',
            'instance' => 'x',
        ], ['apikey' => 'errada']);

        $response->assertUnauthorized();
    }

    public function test_webhook_instancia_desconhecida_e_ignorado(): void
    {
        config(['evolution.api_key' => '']);

        $antes = \App\Models\Documentos\EventoWebhookWhatsapp::query()->count();
        $antesDocs = DocumentoRecebido::withoutGlobalScope('operadora')->count();

        $this->postJson('/webhooks/evolution', [
            'event' => 'messages.upsert',
            'instance' => 'nao-existe',
            'data' => [
                'key' => [
                    'id' => 'MSG1',
                    'remoteJid' => '120363@g.us',
                    'fromMe' => false,
                ],
                'message' => [
                    'documentMessage' => [
                        'fileName' => 'nfe.xml',
                        'mimetype' => 'application/xml',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame($antesDocs, DocumentoRecebido::withoutGlobalScope('operadora')->count());
        $this->assertSame($antes, \App\Models\Documentos\EventoWebhookWhatsapp::query()->count());
    }

    public function test_mensagem_de_conversa_ou_grupo_nao_monitorado_nao_enfileira(): void
    {
        config(['evolution.api_key' => '', 'queue.default' => 'sync']);

        $operadora = EmpresasOperadora::factory()->create();
        $conexao = ConexaoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'status' => StatusConexaoWhatsapp::Conectado,
            'nome_instancia' => 'integrar-op-'.$operadora->id,
        ]);

        $antes = \App\Models\Documentos\EventoWebhookWhatsapp::query()->count();

        $this->postJson('/webhooks/evolution', [
            'event' => 'messages.upsert',
            'instance' => $conexao->nome_instancia,
            'data' => [
                'key' => [
                    'id' => 'MSG-PV',
                    'remoteJid' => '5551999999999@s.whatsapp.net',
                    'fromMe' => false,
                ],
                'message' => ['conversation' => 'oi'],
            ],
        ])->assertOk();

        $this->assertSame($antes, \App\Models\Documentos\EventoWebhookWhatsapp::query()->count());
    }

    public function test_mensagem_duplicada_nao_cria_segundo_documento(): void
    {
        config(['evolution.api_key' => '', 'evolution.url_base' => 'http://evolution.test', 'queue.default' => 'sync']);
        Storage::fake();

        $operadora = EmpresasOperadora::factory()->create();
        $empresa = Empresa::factory()->create(['empresa_operadora_id' => $operadora->id]);
        $conexao = ConexaoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'status' => StatusConexaoWhatsapp::Conectado,
            'nome_instancia' => 'integrar-op-'.$operadora->id,
            'credenciais' => ['apikey' => 'k'],
        ]);
        GrupoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'conexao_whatsapp_id' => $conexao->id,
            'empresa_id' => $empresa->id,
            'jid' => '120363@g.us',
            'nome' => 'Docs',
            'monitorar' => true,
        ]);

        Http::fake([
            'http://evolution.test/*' => Http::response([
                'base64' => base64_encode('conteudo-xml'),
                'mimetype' => 'application/xml',
                'fileName' => 'nfe.xml',
            ], 200),
        ]);

        $payload = [
            'event' => 'messages.upsert',
            'instance' => $conexao->nome_instancia,
            'data' => [
                'key' => [
                    'id' => 'MSG-DUP',
                    'remoteJid' => '120363@g.us',
                    'fromMe' => false,
                ],
                'message' => [
                    'documentMessage' => [
                        'fileName' => 'nfe.xml',
                        'mimetype' => 'application/xml',
                    ],
                ],
            ],
        ];

        $this->postJson('/webhooks/evolution', $payload)->assertOk();
        $this->postJson('/webhooks/evolution', $payload)->assertOk();

        $this->assertSame(1, DocumentoRecebido::withoutGlobalScope('operadora')->where('mensagem_whatsapp_id', 'MSG-DUP')->count());
    }

    public function test_arquivo_enviado_pelo_proprio_numero_no_grupo_monitorado_e_recebido(): void
    {
        config(['evolution.api_key' => '', 'evolution.url_base' => 'http://evolution.test', 'queue.default' => 'sync']);
        Storage::fake();

        $operadora = EmpresasOperadora::factory()->create();
        $empresa = Empresa::factory()->create(['empresa_operadora_id' => $operadora->id]);
        $conexao = ConexaoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'status' => StatusConexaoWhatsapp::Conectado,
            'nome_instancia' => 'integrar-op-'.$operadora->id,
            'credenciais' => ['apikey' => 'k'],
        ]);
        GrupoWhatsapp::withoutGlobalScope('operadora')->create([
            'empresa_operadora_id' => $operadora->id,
            'conexao_whatsapp_id' => $conexao->id,
            'empresa_id' => $empresa->id,
            'jid' => '120363411193685778@g.us',
            'nome' => 'TESTE DE DOCUMENTOS',
            'monitorar' => true,
        ]);

        Http::fake();

        $this->postJson('/webhooks/evolution', [
            'event' => 'messages.upsert',
            'instance' => $conexao->nome_instancia,
            'data' => [
                'key' => [
                    'id' => '3EB04BF22BBD5974ADB03F',
                    'remoteJid' => '120363411193685778@g.us',
                    'fromMe' => true,
                ],
                'message' => [
                    'documentMessage' => [
                        'fileName' => 'extrato.pdf',
                        'mimetype' => 'application/pdf',
                    ],
                    'base64' => base64_encode('%PDF-1.4 teste'),
                ],
                'messageType' => 'documentMessage',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('documentos_recebidos', [
            'mensagem_whatsapp_id' => '3EB04BF22BBD5974ADB03F',
            'nome_original' => 'extrato.pdf',
        ]);
        Http::assertNothingSent();
    }

    public function test_mesmo_hash_na_empresa_e_ignorado(): void
    {
        $operadora = EmpresasOperadora::factory()->create();
        $empresa = Empresa::factory()->create(['empresa_operadora_id' => $operadora->id]);
        $user = User::factory()->admin()->create(['empresa_operadora_id' => $operadora->id]);
        $this->actingAs($user);

        DocumentoRecebido::query()->create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'mensagem_whatsapp_id' => 'A',
            'nome_original' => 'a.xml',
            'hash_sha256' => hash('sha256', 'igual'),
            'status' => StatusDocumentoRecebido::EnviadoDrive,
        ]);

        $segundo = DocumentoRecebido::query()->create([
            'empresa_operadora_id' => $operadora->id,
            'empresa_id' => $empresa->id,
            'mensagem_whatsapp_id' => 'B',
            'nome_original' => 'b.xml',
            'hash_sha256' => hash('sha256', 'igual'),
            'status' => StatusDocumentoRecebido::Ignorado,
            'erro_mensagem' => 'Arquivo duplicado (mesmo conteúdo já recebido).',
        ]);

        $this->assertSame(StatusDocumentoRecebido::Ignorado, $segundo->status);
        $this->assertSame(1, DocumentoRecebido::query()->where('status', StatusDocumentoRecebido::EnviadoDrive)->count());
    }
}
