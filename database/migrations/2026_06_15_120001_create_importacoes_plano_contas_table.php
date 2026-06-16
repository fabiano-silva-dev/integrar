<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacoes_plano_contas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('arquivo_original');
            $table->string('formato', 10);
            $table->string('estrategia', 30);
            $table->unsignedInteger('total_linhas')->default(0);
            $table->unsignedInteger('contas_novas')->default(0);
            $table->unsignedInteger('contas_atualizadas')->default(0);
            $table->unsignedInteger('contas_inativadas')->default(0);
            $table->unsignedInteger('linhas_erro')->default(0);
            $table->json('relatorio_erros')->nullable();
            $table->string('status', 20)->default('concluida');
            $table->timestamps();

            $table->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacoes_plano_contas');
    }
};
