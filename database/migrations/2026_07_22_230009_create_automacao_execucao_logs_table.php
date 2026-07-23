<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automacao_execucao_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('automacao_execucao_id')
                ->constrained('automacao_execucoes')
                ->cascadeOnDelete();
            $table->string('nivel', 16);
            $table->string('etapa', 64)->nullable();
            $table->text('mensagem');
            $table->json('contexto_sanitizado')->nullable();
            $table->timestamp('ocorrido_em');

            $table->index(['automacao_execucao_id', 'ocorrido_em'], 'automacao_exec_logs_exec_ocorrido_idx');
            $table->index(['empresa_operadora_id', 'nivel'], 'automacao_exec_logs_op_nivel_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automacao_execucao_logs');
    }
};
