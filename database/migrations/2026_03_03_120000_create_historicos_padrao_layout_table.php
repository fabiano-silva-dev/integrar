<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela mãe: conjunto de históricos padrão por layout (e opcionalmente empresa).
     */
    public function up(): void
    {
        Schema::create('historicos_padrao_layout', function (Blueprint $table) {
            $table->id();
            $table->string('layout_avancado', 50)->comment('Chave do layout: dominio, grafeno, sicoob, caixa_federal, ofx, registros, sicredi');
            $table->string('nome_sugerido')->comment('Nome amigável do conjunto, ex: SICREDI');
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->onDelete('cascade')->comment('Opcional: personalização por empresa no futuro');
            $table->timestamps();

            $table->index(['layout_avancado', 'empresa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historicos_padrao_layout');
    }
};
