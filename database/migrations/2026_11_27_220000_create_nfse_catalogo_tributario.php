<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Referência pública nacional, sem dados ou credenciais dos escritórios.
        Schema::create('nfse_catalogo_versoes', function (Blueprint $table) {
            $table->id();
            $table->string('versao', 80);
            $table->date('publicada_em');
            $table->string('fonte', 20);
            $table->char('sha256', 64)->unique();
            $table->unsignedInteger('quantidade');
            $table->timestamps();
            $table->unique(['fonte', 'versao'], 'nfse_cat_fonte_versao_uq');
        });
        Schema::create('nfse_catalogo_classificacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('versao_id')->constrained('nfse_catalogo_versoes')->restrictOnDelete();
            $table->char('cst', 3);
            $table->char('cclass_trib', 6);
            $table->string('descricao_cst', 255);
            $table->text('descricao');
            $table->date('inicio');
            $table->date('fim')->nullable();
            $table->boolean('ind_nfse');
            $table->boolean('ind_gibscbs')->nullable();
            $table->decimal('reducao_ibs', 5, 2)->nullable();
            $table->decimal('reducao_cbs', 5, 2)->nullable();
            $table->unique(['versao_id', 'cclass_trib', 'inicio'], 'nfse_cat_codigo_inicio_uq');
            $table->index(['versao_id', 'ind_nfse', 'cst'], 'nfse_cat_busca_idx');
        });
        Schema::table('nfse_perfis_tributarios', function (Blueprint $table) {
            $table->foreignId('classificacao_catalogo_id')->nullable()->constrained('nfse_catalogo_classificacoes', indexName: 'nfse_perfil_classif_fk')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nfse_perfis_tributarios', function (Blueprint $table) {
            $table->dropForeign('nfse_perfil_classif_fk');
            $table->dropColumn('classificacao_catalogo_id');
        });
        Schema::dropIfExists('nfse_catalogo_classificacoes');
        Schema::dropIfExists('nfse_catalogo_versoes');
    }
};
