<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automacao_consultas_salvas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->cascadeOnDelete();
            $table->foreignId('empresa_integracao_id')
                ->constrained('empresa_integracoes')
                ->cascadeOnDelete();
            $table->foreignId('portal_recurso_id')
                ->constrained('portal_recursos')
                ->cascadeOnDelete();
            $table->string('nome', 120);
            $table->json('parametros')->nullable();
            $table->timestamps();

            $table->unique(
                ['empresa_integracao_id', 'portal_recurso_id', 'nome'],
                'auto_consulta_salva_int_rec_nome_uq'
            );
            $table->index(
                ['empresa_operadora_id', 'empresa_id'],
                'auto_consulta_salva_op_emp_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automacao_consultas_salvas');
    }
};
