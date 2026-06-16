<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_contas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->cascadeOnDelete();
            $table->string('codigo', 50);
            $table->string('codigo_reduzido', 20)->nullable();
            $table->string('descricao');
            $table->string('tipo', 20)->nullable();
            $table->string('natureza', 20)->nullable();
            $table->unsignedTinyInteger('nivel')->nullable();
            $table->string('codigo_pai', 50)->nullable();
            $table->boolean('aceita_lancamento')->default(true);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(
                ['empresa_operadora_id', 'empresa_id', 'codigo'],
                'plano_contas_empresa_codigo_unique'
            );
            $table->index(['empresa_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_contas');
    }
};
