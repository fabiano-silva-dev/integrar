<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_dps_sequencias', function (Blueprint $table) {
            $this->base($table);
            $table->string('documento_prestador', 14);
            $table->unsignedTinyInteger('ambiente');
            $table->string('serie', 5);
            $table->unsignedBigInteger('numero_inicial');
            $table->unsignedBigInteger('ultimo_numero');
            $table->unique(['empresa_operadora_id', 'empresa_id', 'documento_prestador', 'ambiente', 'serie'], 'nfse_dps_seq_uq');
        });
        Schema::create('nfse_dps_locais', function (Blueprint $table) {
            $this->base($table);
            $table->foreignId('sequencia_id')->constrained('nfse_dps_sequencias')->restrictOnDelete();
            $table->foreignId('nota_id')->constrained('nfse_notas')->restrictOnDelete();
            $table->foreignId('lote_id')->constrained('nfse_lotes')->restrictOnDelete();
            $table->foreignId('certificado_id')->constrained('certificados_digitais')->restrictOnDelete();
            $table->unsignedInteger('versao_nota');
            $table->unsignedTinyInteger('ambiente');
            $table->unsignedBigInteger('numero');
            $table->char('id_dps', 45);
            $table->char('config_hash', 64);
            $table->char('xml_hash', 64);
            $table->longText('xml_criptografado');
            $table->string('status', 30)->default('preparada_local');
            $table->unique(['sequencia_id', 'numero'], 'nfse_dps_numero_uq');
            $table->unique(['nota_id', 'versao_nota', 'ambiente'], 'nfse_dps_nota_versao_uq');
            $table->unique(['empresa_operadora_id', 'ambiente', 'id_dps'], 'nfse_dps_identidade_uq');
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
        Schema::dropIfExists('nfse_dps_locais');
        Schema::dropIfExists('nfse_dps_sequencias');
    }
};
