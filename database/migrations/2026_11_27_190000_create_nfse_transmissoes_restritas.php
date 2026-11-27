<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_transmissoes_restritas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('dps_local_id')->constrained('nfse_dps_locais')->restrictOnDelete();
            $table->foreignId('nota_id')->constrained('nfse_notas')->restrictOnDelete();
            $table->foreignId('aprovado_por')->constrained('users')->restrictOnDelete();
            $table->string('status', 30);
            $table->char('xml_hash', 64);
            $table->char('acesso_hash', 64);
            $table->string('chave_acesso', 50)->nullable();
            $table->longText('nfse_xml_criptografado')->nullable();
            $table->char('nfse_xml_hash', 64)->nullable();
            $table->timestamp('envio_iniciado_em')->nullable();
            $table->timestamp('ultima_consulta_em')->nullable();
            $table->timestamps();
            $table->unique('dps_local_id', 'nfse_trans_dps_uq');
            $table->unique('nota_id', 'nfse_trans_nota_uq');
        });
        Schema::create('nfse_transmissoes_restritas_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('transmissao_id')->constrained('nfse_transmissoes_restritas')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('acao', 30);
            $table->string('resultado', 30);
            $table->unsignedSmallInteger('http')->nullable();
            $table->json('codigos')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_transmissoes_restritas_eventos');
        Schema::dropIfExists('nfse_transmissoes_restritas');
    }
};
