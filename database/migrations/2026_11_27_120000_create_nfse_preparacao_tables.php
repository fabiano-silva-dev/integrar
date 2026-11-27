<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_configuracoes', function (Blueprint $table) {
            $this->base($table);
            $table->boolean('habilitado_preparo')->default(false);
            $table->string('regime_tributario', 30)->nullable();
            $table->string('serie_dps', 5)->nullable();
            $table->unique(['empresa_operadora_id', 'empresa_id'], 'nfse_config_empresa_uq');
        });
        Schema::create('nfse_tomadores', function (Blueprint $table) {
            $this->base($table);
            $table->string('documento', 14);
            $table->string('nome');
            $table->string('email')->nullable();
            $table->string('telefone', 30)->nullable();
            $table->string('codigo_externo', 100)->nullable();
            $table->string('cep', 8)->nullable();
            $table->string('logradouro')->nullable();
            $table->string('numero', 30)->nullable();
            $table->string('complemento')->nullable();
            $table->string('bairro')->nullable();
            $table->string('codigo_municipio_ibge', 7)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('inscricao_municipal', 50)->nullable();
            $table->boolean('ativo')->default(true);
            $table->unique(['empresa_operadora_id', 'empresa_id', 'documento'], 'nfse_tomador_doc_uq');
            $table->unique(['empresa_operadora_id', 'empresa_id', 'codigo_externo'], 'nfse_tomador_ext_uq');
        });
        Schema::create('nfse_servicos', function (Blueprint $table) {
            $this->base($table);
            $table->string('codigo', 50);
            $table->string('nome');
            $table->text('descricao');
            $table->string('codigo_tributacao_nacional', 6);
            $table->string('codigo_tributacao_municipal', 30)->nullable();
            $table->string('nbs', 9)->nullable();
            $table->decimal('valor_padrao', 15, 2)->nullable();
            $table->boolean('ativo')->default(true);
            $table->unique(['empresa_operadora_id', 'empresa_id', 'codigo'], 'nfse_servico_codigo_uq');
        });
        Schema::create('nfse_notas', function (Blueprint $table) {
            $this->base($table);
            $table->foreignId('tomador_id')->constrained('nfse_tomadores')->restrictOnDelete();
            $table->foreignId('servico_id')->constrained('nfse_servicos')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->uuid('intencao');
            $table->date('competencia');
            $table->text('descricao');
            $table->decimal('valor', 15, 2);
            $table->string('status', 30)->default('rascunho');
            $table->json('snapshot');
            $table->unique(['empresa_operadora_id', 'empresa_id', 'intencao'], 'nfse_nota_intencao_uq');
            $table->index(['empresa_operadora_id', 'empresa_id', 'competencia', 'status'], 'nfse_nota_comp_status_idx');
        });
    }

    private function base(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->restrictOnDelete();
        $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
        $table->timestamps();
    }

    public function down(): void
    {
        foreach (['nfse_notas', 'nfse_servicos', 'nfse_tomadores', 'nfse_configuracoes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
