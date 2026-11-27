<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_acessos_emissao', function (Blueprint $table) {
            $this->base($table);
            $table->string('modo', 40);
            $table->string('canal', 20);
            $table->foreignId('certificado_digital_id')->nullable()->constrained('certificados_digitais')->restrictOnDelete();
            $table->text('usuario_criptografado')->nullable();
            $table->text('senha_criptografada')->nullable();
            $table->string('documento_prestador', 14);
            $table->date('procuracao_ate')->nullable();
            $table->string('status_validacao', 30)->default('pendente');
            $table->unique(['empresa_operadora_id', 'empresa_id'], 'nfse_acesso_empresa_uq');
        });
        Schema::create('nfse_simulacoes', function (Blueprint $table) {
            $this->base($table);
            $table->foreignId('lote_id')->constrained('nfse_lotes')->restrictOnDelete();
            $table->string('cenario', 20);
            $table->string('status', 30)->default('pendente');
            $table->json('snapshot');
            $table->timestamp('concluida_em')->nullable();
            $table->unique(['lote_id'], 'nfse_sim_lote_uq');
        });
        Schema::create('nfse_simulacao_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('simulacao_id')->constrained('nfse_simulacoes')->restrictOnDelete();
            $table->foreignId('nota_id')->constrained('nfse_notas')->restrictOnDelete();
            $table->string('status', 30);
            $table->string('mensagem', 255);
            $table->foreignId('reconciliado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reconciliado_em')->nullable();
            $table->timestamps();
            $table->unique(['simulacao_id', 'nota_id'], 'nfse_sim_item_uq');
        });
    }

    private function base(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->restrictOnDelete();
        $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
        $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
        $table->timestamps();
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_simulacao_itens');
        Schema::dropIfExists('nfse_simulacoes');
        Schema::dropIfExists('nfse_acessos_emissao');
    }
};
