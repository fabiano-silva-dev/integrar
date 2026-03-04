<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela filha: descrições que se repetem no extrato e se serão usadas para amarração.
     */
    public function up(): void
    {
        Schema::create('historicos_padrao_descricoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('historico_padrao_layout_id')->constrained('historicos_padrao_layout')->onDelete('cascade');
            $table->string('descricao', 500)->comment('Texto da descrição que se repete no extrato');
            $table->boolean('usar_para_amarracao')->default(true)->comment('Se será usado como base para amarrações por descrição');
            $table->unsignedInteger('total_ocorrencias')->default(1)->comment('Quantas vezes apareceu no arquivo analisado');
            $table->timestamps();

            $table->unique(['historico_padrao_layout_id', 'descricao'], 'historicos_padrao_desc_layout_desc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historicos_padrao_descricoes');
    }
};
