<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_layouts_importacao', function (Blueprint $table) {
            $this->base($table);
            $table->string('nome', 120);
            $table->unsignedInteger('versao');
            $table->char('assinatura', 64);
            $table->json('configuracao');
            $table->unique(['empresa_operadora_id', 'empresa_id', 'nome', 'versao'], 'nfse_layout_versao_uq');
        });
        Schema::create('nfse_importacoes', function (Blueprint $table) {
            $this->base($table);
            $table->foreignId('layout_id')->constrained('nfse_layouts_importacao')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->date('competencia');
            $table->char('hash_arquivo', 64);
            $table->string('arquivo_original');
            $table->string('storage_path');
            $table->json('relatorio');
            $table->unsignedInteger('quantidade_gerada');
            $table->unsignedInteger('quantidade_pendente');
            $table->unique(['empresa_operadora_id', 'empresa_id', 'competencia', 'hash_arquivo'], 'nfse_importacao_arquivo_uq');
        });
        Schema::table('nfse_notas', function (Blueprint $table) {
            $table->foreignId('importacao_id')->nullable()->constrained('nfse_importacoes')->restrictOnDelete();
            $table->unsignedInteger('linha_importacao')->nullable();
            $table->unique(['importacao_id', 'linha_importacao'], 'nfse_nota_importacao_linha_uq');
        });
    }

    private function base(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->restrictOnDelete();
        $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
        $table->timestamps();
    }

    public function down(): void
    {
        Schema::table('nfse_notas', function (Blueprint $table) {
            $table->dropUnique('nfse_nota_importacao_linha_uq');
            $table->dropConstrainedForeignId('importacao_id');
            $table->dropColumn('linha_importacao');
        });
        Schema::dropIfExists('nfse_importacoes');
        Schema::dropIfExists('nfse_layouts_importacao');
    }
};
