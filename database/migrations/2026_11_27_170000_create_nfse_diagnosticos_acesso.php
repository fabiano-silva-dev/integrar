<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_diagnosticos_acesso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras')->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('acesso_id')->constrained('nfse_acessos_emissao')->restrictOnDelete();
            $table->foreignId('certificado_id')->constrained('certificados_digitais')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('ambiente', 20);
            $table->char('config_hash', 64);
            $table->char('chave_hash', 64);
            $table->string('status', 30);
            $table->timestamps();
            $table->index(['empresa_operadora_id', 'empresa_id', 'created_at'], 'nfse_diag_empresa_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_diagnosticos_acesso');
    }
};
