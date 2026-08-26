<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conexoes_whatsapp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->string('status', 32)->default('desconectado');
            $table->string('telefone_exibicao', 32)->nullable();
            $table->string('url_base_evolution', 255)->nullable();
            $table->string('nome_instancia', 120);
            $table->text('credenciais')->nullable();
            $table->timestamps();

            $table->unique('empresa_operadora_id', 'conexoes_whatsapp_operadora_unique');
            $table->unique('nome_instancia', 'conexoes_whatsapp_instancia_unique');
        });

        Schema::create('grupos_whatsapp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('conexao_whatsapp_id')
                ->constrained('conexoes_whatsapp')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->nullable()
                ->constrained('empresas')
                ->nullOnDelete();
            $table->string('jid', 128);
            $table->string('nome', 255)->nullable();
            $table->boolean('monitorar')->default(false);
            $table->timestamps();

            $table->unique(['conexao_whatsapp_id', 'jid'], 'grupos_whatsapp_conexao_jid_unique');
            $table->index(['empresa_operadora_id', 'monitorar'], 'grupos_whatsapp_op_monitorar_idx');
        });

        Schema::create('contas_google', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->string('google_email', 255)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 32)->default('desconectado');
            $table->string('scopes', 512)->nullable();
            $table->timestamps();

            $table->unique('empresa_operadora_id', 'contas_google_operadora_unique');
        });

        Schema::create('empresa_pastas_drive', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->cascadeOnDelete();
            $table->string('tipo', 64);
            $table->unsignedSmallInteger('ano')->nullable();
            $table->string('google_folder_id', 128);
            $table->string('google_folder_nome', 255)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'tipo', 'ano'], 'empresa_pastas_drive_empresa_tipo_ano_unique');
        });

        Schema::create('eventos_webhook_whatsapp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->nullable()
                ->constrained('empresas_operadoras')
                ->nullOnDelete();
            $table->foreignId('conexao_whatsapp_id')
                ->nullable()
                ->constrained('conexoes_whatsapp')
                ->nullOnDelete();
            $table->string('tipo_evento', 64);
            $table->string('chave_idempotencia', 64);
            $table->json('payload');
            $table->string('status', 32)->default('recebido');
            $table->text('erro')->nullable();
            $table->timestamp('processado_em')->nullable();
            $table->timestamps();

            $table->unique('chave_idempotencia', 'eventos_webhook_whatsapp_chave_unique');
            $table->index(['status', 'created_at'], 'eventos_webhook_whatsapp_status_idx');
        });

        Schema::create('documentos_recebidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->nullable()
                ->constrained('empresas')
                ->nullOnDelete();
            $table->foreignId('conexao_whatsapp_id')
                ->nullable()
                ->constrained('conexoes_whatsapp')
                ->nullOnDelete();
            $table->foreignId('grupo_whatsapp_id')
                ->nullable()
                ->constrained('grupos_whatsapp')
                ->nullOnDelete();
            $table->string('mensagem_whatsapp_id', 128)->nullable();
            $table->string('nome_original', 255);
            $table->string('mime', 128)->nullable();
            $table->string('hash_sha256', 64)->nullable();
            $table->string('tipo_documento', 64)->nullable();
            $table->unsignedSmallInteger('ano')->nullable();
            $table->string('status', 32)->default('recebido');
            $table->string('storage_path', 512)->nullable();
            $table->string('drive_file_id', 128)->nullable();
            $table->string('drive_web_link', 512)->nullable();
            $table->string('drive_path', 512)->nullable();
            $table->date('data_documento')->nullable();
            $table->json('metadados')->nullable();
            $table->text('erro_mensagem')->nullable();
            $table->timestamps();

            $table->unique(
                ['empresa_operadora_id', 'mensagem_whatsapp_id'],
                'documentos_recebidos_msg_unique'
            );
            $table->index(['empresa_id', 'hash_sha256'], 'documentos_recebidos_empresa_hash_idx');
            $table->index(['empresa_operadora_id', 'status'], 'documentos_recebidos_op_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_recebidos');
        Schema::dropIfExists('eventos_webhook_whatsapp');
        Schema::dropIfExists('empresa_pastas_drive');
        Schema::dropIfExists('contas_google');
        Schema::dropIfExists('grupos_whatsapp');
        Schema::dropIfExists('conexoes_whatsapp');
    }
};
