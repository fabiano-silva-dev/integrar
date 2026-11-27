<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_catalogo_municipal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->restrictOnDelete();
            $table->char('municipio_ibge', 7);
            $table->char('nacional', 6);
            $table->char('municipal', 3);
            $table->text('descricao');
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->char('arquivo_sha256', 64);
            $table->timestamps();
            $table->unique(['empresa_operadora_id', 'municipio_ibge', 'nacional', 'municipal'], 'nfse_cat_municipal_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_catalogo_municipal');
    }
};
