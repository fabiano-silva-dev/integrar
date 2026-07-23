<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificados_digitais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->nullable()
                ->constrained('empresas')
                ->nullOnDelete();
            $table->string('nome');
            $table->string('tipo', 16)->default('A1');
            $table->string('arquivo_path');
            $table->text('senha_criptografada');
            $table->string('fingerprint', 128)->nullable();
            $table->string('serial', 128)->nullable();
            $table->string('titular')->nullable();
            $table->string('documento_titular', 32)->nullable();
            $table->string('emissor')->nullable();
            $table->timestamp('valido_de')->nullable();
            $table->timestamp('valido_ate')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamp('validado_em')->nullable();
            $table->string('status_validacao', 32)->nullable();
            $table->timestamps();

            $table->index(['empresa_operadora_id', 'ativo'], 'cert_digitais_op_ativo_idx');
            $table->index(['empresa_operadora_id', 'empresa_id'], 'cert_digitais_op_empresa_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificados_digitais');
    }
};
