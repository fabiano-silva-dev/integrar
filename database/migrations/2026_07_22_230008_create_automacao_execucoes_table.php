<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automacao_execucoes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->cascadeOnDelete();
            $table->foreignId('empresa_integracao_id')
                ->constrained('empresa_integracoes')
                ->cascadeOnDelete();
            $table->foreignId('portal_recurso_id')
                ->constrained('portal_recursos')
                ->cascadeOnDelete();
            $table->foreignId('agenda_automacao_id')
                ->nullable()
                ->constrained('agendas_automacao')
                ->nullOnDelete();
            $table->foreignId('solicitado_por_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('gatilho', 32);
            $table->date('periodo_inicio')->nullable();
            $table->date('periodo_fim')->nullable();
            $table->string('status', 32)->default('pendente');
            $table->string('etapa_atual', 64)->nullable();
            $table->text('mensagem_usuario')->nullable();
            $table->unsignedInteger('quantidade_encontrada')->default(0);
            $table->unsignedInteger('quantidade_importada')->default(0);
            $table->unsignedInteger('quantidade_ignorada')->default(0);
            $table->unsignedInteger('quantidade_erros')->default(0);
            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('finalizada_em')->nullable();
            $table->unsignedBigInteger('duracao_ms')->nullable();
            $table->unsignedInteger('tentativa')->default(1);
            $table->string('idempotency_key', 191)->nullable();
            $table->timestamps();

            $table->index(['empresa_operadora_id', 'status'], 'automacao_exec_op_status_idx');
            $table->index(['empresa_operadora_id', 'empresa_id'], 'automacao_exec_op_empresa_idx');
            $table->index(['empresa_operadora_id', 'portal_recurso_id'], 'automacao_exec_op_recurso_idx');
            $table->index(['empresa_operadora_id', 'created_at'], 'automacao_exec_op_created_idx');
            $table->unique(['empresa_operadora_id', 'idempotency_key'], 'automacao_exec_op_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automacao_execucoes');
    }
};
