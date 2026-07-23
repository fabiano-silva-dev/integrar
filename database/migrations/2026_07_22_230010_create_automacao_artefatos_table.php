<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automacao_artefatos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('automacao_execucao_id')
                ->constrained('automacao_execucoes')
                ->cascadeOnDelete();
            $table->string('tipo', 32);
            $table->string('nome_original')->nullable();
            $table->string('storage_path');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('tamanho')->default(0);
            $table->string('hash_sha256', 64)->nullable();
            $table->json('metadados')->nullable();
            $table->timestamp('retencao_ate')->nullable();
            $table->timestamps();

            $table->index(['automacao_execucao_id', 'tipo'], 'automacao_artefatos_exec_tipo_idx');
            $table->index(['empresa_operadora_id', 'retencao_ate'], 'automacao_artefatos_op_retencao_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automacao_artefatos');
    }
};
