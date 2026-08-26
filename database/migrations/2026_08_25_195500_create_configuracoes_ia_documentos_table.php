<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracoes_ia_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->text('gemini_api_key')->nullable();
            $table->text('groq_api_key')->nullable();
            $table->text('llama_cloud_api_key')->nullable();
            $table->timestamp('configurado_em')->nullable();
            $table->timestamps();

            $table->unique('empresa_operadora_id', 'configuracoes_ia_documentos_operadora_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_ia_documentos');
    }
};
