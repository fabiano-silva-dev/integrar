<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_fiscais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->cascadeOnDelete();
            $table->foreignId('portal_integracao_id')
                ->nullable()
                ->constrained('portais_integracao')
                ->nullOnDelete();
            $table->foreignId('portal_recurso_id')
                ->nullable()
                ->constrained('portal_recursos')
                ->nullOnDelete();
            $table->foreignId('automacao_execucao_id')
                ->nullable()
                ->constrained('automacao_execucoes')
                ->nullOnDelete();
            $table->foreignId('automacao_artefato_id')
                ->nullable()
                ->constrained('automacao_artefatos')
                ->nullOnDelete();
            $table->string('tipo_documento', 32)->default('nfe');
            $table->string('chave_acesso', 44)->nullable();
            $table->string('identificador_externo', 128)->nullable();
            $table->string('numero', 32)->nullable();
            $table->string('serie', 16)->nullable();
            $table->string('modelo', 8)->nullable();
            $table->date('data_emissao')->nullable();
            $table->date('data_entrada_saida')->nullable();
            $table->string('competencia', 7)->nullable()->comment('YYYY-MM');
            $table->string('cnpj_emitente', 18)->nullable();
            $table->string('nome_emitente')->nullable();
            $table->string('ie_emitente', 32)->nullable();
            $table->string('uf_emitente', 2)->nullable();
            $table->string('cnpj_destinatario', 18)->nullable();
            $table->string('nome_destinatario')->nullable();
            $table->string('ie_destinatario', 32)->nullable();
            $table->string('uf_destinatario', 2)->nullable();
            $table->decimal('valor_total', 15, 2)->default(0);
            $table->decimal('valor_bc_icms', 15, 2)->default(0);
            $table->decimal('valor_icms', 15, 2)->default(0);
            $table->decimal('valor_bc_icms_st', 15, 2)->default(0);
            $table->decimal('valor_icms_st', 15, 2)->default(0);
            $table->string('cfop', 8)->nullable()->comment('Ausente no extrato TXT do e-CAC RS; preenchível via XML');
            $table->string('situacao', 16)->nullable();
            $table->string('entrada_saida', 8)->nullable();
            $table->timestamp('cancelado_em')->nullable();
            $table->json('dados_complementares')->nullable();
            $table->string('hash_registro', 64)->nullable();
            $table->string('origem', 64)->default('ecac_rs_extrato_txt');
            $table->timestamps();

            $table->unique(
                ['empresa_operadora_id', 'empresa_id', 'chave_acesso'],
                'doc_fiscais_op_emp_chave_unique'
            );
            $table->index(['empresa_operadora_id', 'empresa_id', 'competencia'], 'doc_fiscais_op_emp_comp_idx');
            $table->index(['empresa_operadora_id', 'data_emissao'], 'doc_fiscais_op_emissao_idx');
            $table->index(['empresa_operadora_id', 'cfop'], 'doc_fiscais_op_cfop_idx');
        });

        Schema::create('documentos_fiscais_coletas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->cascadeOnDelete();
            $table->foreignId('automacao_execucao_id')
                ->nullable()
                ->constrained('automacao_execucoes')
                ->nullOnDelete();
            $table->foreignId('automacao_artefato_id')
                ->nullable()
                ->constrained('automacao_artefatos')
                ->nullOnDelete();
            $table->string('origem', 64)->default('ecac_rs_extrato_txt');
            $table->string('nome_arquivo')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('hash_arquivo', 64)->nullable();
            $table->unsignedInteger('quantidade_documentos')->default(0);
            $table->unsignedInteger('quantidade_novos')->default(0);
            $table->unsignedInteger('quantidade_atualizados')->default(0);
            $table->unsignedInteger('quantidade_ignorados')->default(0);
            $table->date('periodo_inicio')->nullable();
            $table->date('periodo_fim')->nullable();
            $table->json('resumo')->nullable();
            $table->timestamps();

            $table->index(['empresa_operadora_id', 'empresa_id', 'created_at'], 'doc_fiscais_coletas_op_emp_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_fiscais_coletas');
        Schema::dropIfExists('documentos_fiscais');
    }
};
