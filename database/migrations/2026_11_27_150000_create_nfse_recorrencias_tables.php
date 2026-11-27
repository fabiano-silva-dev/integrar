<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_recorrencias', function (Blueprint $table) {
            $this->base($table);
            $table->foreignId('tomador_id')->constrained('nfse_tomadores')->restrictOnDelete();
            $table->foreignId('servico_id')->constrained('nfse_servicos')->restrictOnDelete();
            $table->string('referencia', 100);
            $table->date('inicio');
            $table->date('fim')->nullable();
            $table->unsignedTinyInteger('dia');
            $table->unique(['empresa_operadora_id', 'empresa_id', 'referencia'], 'nfse_rec_ref_uq');
            $table->index(['empresa_operadora_id', 'empresa_id', 'inicio', 'fim'], 'nfse_rec_vigencia_idx');
        });
        Schema::create('nfse_recorrencia_reajustes', function (Blueprint $table) {
            $this->base($table);
            $table->foreignId('recorrencia_id')->constrained('nfse_recorrencias')->restrictOnDelete();
            $table->date('vigencia');
            $table->decimal('valor', 15, 2);
            $table->text('descricao');
            $table->unique(['recorrencia_id', 'vigencia'], 'nfse_rec_reajuste_uq');
        });
        Schema::create('nfse_recorrencia_pausas', function (Blueprint $table) {
            $this->base($table);
            $table->foreignId('recorrencia_id')->constrained('nfse_recorrencias')->restrictOnDelete();
            $table->date('competencia');
            $table->boolean('pausada')->default(true);
            $table->unique(['recorrencia_id', 'competencia'], 'nfse_rec_pausa_uq');
        });
        Schema::table('nfse_notas', function (Blueprint $table) {
            $table->foreignId('recorrencia_id')->nullable()->constrained('nfse_recorrencias')->restrictOnDelete();
            $table->date('recorrencia_competencia')->nullable();
            $table->unique(['recorrencia_id', 'recorrencia_competencia'], 'nfse_nota_rec_mes_uq');
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
        Schema::table('nfse_notas', function (Blueprint $table) {
            $table->dropUnique('nfse_nota_rec_mes_uq');
            $table->dropConstrainedForeignId('recorrencia_id');
            $table->dropColumn('recorrencia_competencia');
        });
        Schema::dropIfExists('nfse_recorrencia_pausas');
        Schema::dropIfExists('nfse_recorrencia_reajustes');
        Schema::dropIfExists('nfse_recorrencias');
    }
};
