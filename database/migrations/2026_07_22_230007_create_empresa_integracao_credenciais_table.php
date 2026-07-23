<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_integracao_credenciais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_integracao_id')
                ->constrained('empresa_integracoes')
                ->cascadeOnDelete();
            $table->text('usuario_criptografado')->nullable();
            $table->text('segredo_criptografado')->nullable();
            $table->text('dados_autenticacao_criptografados')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamp('validado_em')->nullable();
            $table->string('status_validacao', 32)->nullable();
            $table->timestamps();

            $table->index(['empresa_operadora_id', 'empresa_integracao_id'], 'emp_int_cred_op_integracao_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_integracao_credenciais');
    }
};
