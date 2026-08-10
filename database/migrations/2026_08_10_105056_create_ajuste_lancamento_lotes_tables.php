<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajuste_lancamento_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('importacao_id')->constrained('importacoes')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario_nome')->nullable();
            $table->json('filtros')->nullable();
            $table->json('alteracoes')->nullable();
            $table->unsignedInteger('total_lancamentos')->default(0);
            $table->unsignedInteger('total_campos')->default(0);
            $table->string('status', 20)->default('aplicado'); // aplicado|revertido
            $table->timestamp('revertido_em')->nullable();
            $table->foreignId('revertido_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revertido_por_nome')->nullable();
            $table->timestamps();

            $table->index(['empresa_operadora_id', 'created_at']);
            $table->index(['empresa_id', 'created_at']);
            $table->index(['importacao_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('ajuste_lancamento_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ajuste_lancamento_lote_id')
                ->constrained('ajuste_lancamento_lotes')
                ->cascadeOnDelete();
            $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->cascadeOnDelete();
            $table->foreignId('lancamento_id')->constrained('lancamentos')->cascadeOnDelete();
            $table->string('campo_alterado', 64);
            $table->text('valor_anterior')->nullable();
            $table->text('valor_novo')->nullable();
            $table->string('tipo_alteracao', 32)->default('outro');
            $table->timestamps();

            $table->index(['ajuste_lancamento_lote_id', 'lancamento_id'], 'ajuste_itens_lote_lanc_idx');
            $table->index(['empresa_operadora_id', 'lancamento_id'], 'ajuste_itens_op_lanc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajuste_lancamento_itens');
        Schema::dropIfExists('ajuste_lancamento_lotes');
    }
};
