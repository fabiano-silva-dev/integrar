<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_perfis_tributarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('servico_id')->nullable()->constrained('nfse_servicos')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('escopo', 30);
            $table->date('vigencia');
            $table->json('dados');
            $table->timestamps();
            $table->unique(['empresa_operadora_id', 'empresa_id', 'escopo', 'vigencia'], 'nfse_perfil_vigencia_uq');
        });
        Schema::table('nfse_dps_locais', function (Blueprint $table) {
            $table->foreignId('perfil_tributario_id')->nullable()->constrained('nfse_perfis_tributarios')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nfse_dps_locais', function (Blueprint $table) {
            $table->dropConstrainedForeignId('perfil_tributario_id');
        });
        Schema::dropIfExists('nfse_perfis_tributarios');
    }
};
