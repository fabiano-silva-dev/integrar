<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agendas_automacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->string('nome');
            $table->boolean('ativo')->default(true);
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->string('frequencia', 32);
            $table->unsignedInteger('intervalo')->nullable();
            $table->json('dias_semana')->nullable();
            $table->json('dias_mes')->nullable();
            $table->json('horarios')->nullable();
            $table->string('politica_periodo_consulta', 64)->nullable();
            $table->json('parametros_periodo')->nullable();
            $table->boolean('executar_atrasadas')->default(false);
            $table->unsignedInteger('limite_execucoes_atrasadas')->nullable();
            $table->timestamps();

            $table->index(['empresa_operadora_id', 'ativo'], 'agendas_automacao_op_ativo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agendas_automacao');
    }
};
