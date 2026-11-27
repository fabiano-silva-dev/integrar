<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_indop_versoes', function (Blueprint $table) {
            $table->id();
            $table->string('versao', 80)->unique();
            $table->date('publicada_em');
            $table->date('restrita_desde')->nullable();
            $table->char('sha256', 64)->unique();
            $table->unsignedInteger('quantidade');
            $table->timestamps();
        });
        Schema::create('nfse_indop_codigos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('versao_id')->constrained('nfse_indop_versoes')->restrictOnDelete();
            $table->char('codigo', 6);
            $table->text('descricao');
            $table->text('fundamento')->nullable();
            $table->date('inicio')->nullable();
            $table->date('fim')->nullable();
            $table->unique(['versao_id', 'codigo'], 'nfse_indop_codigo_uq');
        });
        Schema::table('nfse_perfis_tributarios', function (Blueprint $table) {
            $table->foreignId('indop_catalogo_id')->nullable()->constrained('nfse_indop_codigos', indexName: 'nfse_perfil_indop_fk')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nfse_perfis_tributarios', function (Blueprint $table) {
            $table->dropForeign('nfse_perfil_indop_fk');
            $table->dropColumn('indop_catalogo_id');
        });
        Schema::dropIfExists('nfse_indop_codigos');
        Schema::dropIfExists('nfse_indop_versoes');
    }
};
