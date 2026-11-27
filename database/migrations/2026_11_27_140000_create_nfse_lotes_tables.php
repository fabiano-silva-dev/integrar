<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_lotes', function (Blueprint $table) {
            $this->base($table);
            $table->date('competencia');
            $table->string('status', 30)->default('preparado');
            $table->unsignedInteger('quantidade');
            $table->decimal('valor_total', 18, 2);
            $table->json('snapshot');
            $table->text('justificativa_alertas')->nullable();
            $table->foreignId('desfeito_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('desfeito_em')->nullable();
        });
        Schema::table('nfse_notas', function (Blueprint $table) {
            $table->unsignedInteger('versao')->default(1);
            $table->foreignId('lote_id')->nullable()->constrained('nfse_lotes')->restrictOnDelete();
        });
        Schema::create('nfse_conferencia_eventos', function (Blueprint $table) {
            $this->base($table);
            $table->foreignId('nota_id')->constrained('nfse_notas')->restrictOnDelete();
            $table->string('acao', 30);
            $table->json('antes');
            $table->json('depois');
            $table->index(['empresa_operadora_id', 'empresa_id', 'nota_id'], 'nfse_conf_evento_idx');
        });
    }

    private function base(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->restrictOnDelete();
        $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
        $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
        $table->timestamps();
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_conferencia_eventos');
        Schema::table('nfse_notas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lote_id');
            $table->dropColumn('versao');
        });
        Schema::dropIfExists('nfse_lotes');
    }
};
