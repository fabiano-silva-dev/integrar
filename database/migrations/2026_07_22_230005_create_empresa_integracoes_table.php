<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_integracoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->cascadeOnDelete();
            $table->foreignId('portal_integracao_id')
                ->constrained('portais_integracao')
                ->cascadeOnDelete();
            $table->boolean('ativo')->default(true);
            $table->string('modo_autenticacao', 32)->nullable();
            $table->foreignId('certificado_digital_id')
                ->nullable()
                ->constrained('certificados_digitais')
                ->nullOnDelete();
            $table->string('status_configuracao', 32)->default('pendente');
            $table->timestamp('ultima_validacao_em')->nullable();
            $table->string('ultima_validacao_status', 32)->nullable();
            $table->text('ultima_validacao_mensagem')->nullable();
            $table->json('configuracoes')->nullable();
            $table->timestamps();

            $table->unique(
                ['empresa_operadora_id', 'empresa_id', 'portal_integracao_id'],
                'empresa_integracoes_op_emp_portal_unique'
            );
            $table->index(['empresa_operadora_id', 'ativo'], 'empresa_integracoes_op_ativo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_integracoes');
    }
};
