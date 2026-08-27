<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_processo_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->nullable()
                ->constrained('empresas_operadoras')
                ->nullOnDelete();
            $table->foreignId('conexao_whatsapp_id')
                ->nullable()
                ->constrained('conexoes_whatsapp')
                ->nullOnDelete();
            $table->foreignId('grupo_whatsapp_id')
                ->nullable()
                ->constrained('grupos_whatsapp')
                ->nullOnDelete();
            $table->foreignId('documento_recebido_id')
                ->nullable()
                ->constrained('documentos_recebidos')
                ->nullOnDelete();
            $table->string('mensagem_whatsapp_id', 128)->nullable();
            $table->string('nivel', 16);
            $table->string('etapa', 32);
            $table->text('mensagem');
            $table->json('contexto')->nullable();
            $table->timestamps();

            $table->index(['empresa_operadora_id', 'created_at'], 'documentos_processo_logs_op_created_idx');
            $table->index(['empresa_operadora_id', 'etapa'], 'documentos_processo_logs_op_etapa_idx');
            $table->index(['empresa_operadora_id', 'nivel'], 'documentos_processo_logs_op_nivel_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_processo_logs');
    }
};
