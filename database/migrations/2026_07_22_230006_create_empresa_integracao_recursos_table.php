<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_integracao_recursos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_integracao_id')
                ->constrained('empresa_integracoes')
                ->cascadeOnDelete();
            $table->foreignId('portal_recurso_id')
                ->constrained('portal_recursos')
                ->cascadeOnDelete();
            $table->boolean('ativo')->default(true);
            $table->foreignId('agenda_automacao_id')
                ->nullable()
                ->constrained('agendas_automacao')
                ->nullOnDelete();
            $table->json('parametros')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['empresa_integracao_id', 'portal_recurso_id'],
                'emp_int_rec_integracao_recurso_unique'
            );
            $table->index(['empresa_operadora_id', 'ativo', 'next_run_at'], 'emp_int_rec_op_ativo_next_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_integracao_recursos');
    }
};
