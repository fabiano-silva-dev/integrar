<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_filas_homologacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->date('competencia');
            $table->unsignedSmallInteger('quantidade');
            $table->timestamps();
        });
        Schema::table('nfse_transmissoes_restritas', function (Blueprint $table) {
            $table->foreignId('fila_homologacao_id')->nullable()->constrained('nfse_filas_homologacao')->restrictOnDelete();
            $table->string('fila_status', 20)->nullable();
            $table->timestamp('fila_iniciada_em')->nullable();
            $table->timestamp('fila_finalizada_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nfse_transmissoes_restritas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fila_homologacao_id');
            $table->dropColumn(['fila_status', 'fila_iniciada_em', 'fila_finalizada_em']);
        });
        Schema::dropIfExists('nfse_filas_homologacao');
    }
};
