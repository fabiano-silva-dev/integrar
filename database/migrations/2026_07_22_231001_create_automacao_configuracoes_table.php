<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automacao_configuracoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->unique()
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->unsignedInteger('periodo_padrao_dias')->default(31);
            $table->unsignedInteger('max_execucoes_simultaneas')->default(1);
            $table->unsignedInteger('politica_tentativas')->default(3);
            $table->unsignedInteger('retencao_logs_dias')->default(90);
            $table->unsignedInteger('retencao_artefatos_dias')->default(30);
            $table->unsignedInteger('aviso_certificado_dias')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automacao_configuracoes');
    }
};
