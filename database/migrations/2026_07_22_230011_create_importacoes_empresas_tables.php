<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacoes_empresas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('nome_arquivo');
            $table->string('storage_path')->nullable();
            $table->string('status', 32)->default('pendente');
            $table->unsignedInteger('total_linhas')->default(0);
            $table->unsignedInteger('linhas_validas')->default(0);
            $table->unsignedInteger('linhas_com_erro')->default(0);
            $table->unsignedInteger('linhas_gravadas')->default(0);
            $table->json('mapeamento_colunas')->nullable();
            $table->text('mensagem')->nullable();
            $table->timestamps();

            $table->index(['empresa_operadora_id', 'status'], 'importacoes_empresas_op_status_idx');
        });

        Schema::create('importacao_empresa_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('importacao_empresa_id')
                ->constrained('importacoes_empresas')
                ->cascadeOnDelete();
            $table->unsignedInteger('numero_linha');
            $table->json('dados_brutos')->nullable();
            $table->json('dados_normalizados')->nullable();
            $table->string('status', 32)->default('pendente');
            $table->text('mensagem_erro')->nullable();
            $table->foreignId('empresa_id')
                ->nullable()
                ->constrained('empresas')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['importacao_empresa_id', 'numero_linha'],
                'importacao_empresa_itens_linha_unique'
            );
            $table->index(['empresa_operadora_id', 'status'], 'importacao_empresa_itens_op_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacao_empresa_itens');
        Schema::dropIfExists('importacoes_empresas');
    }
};
