<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_validacoes_portal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')->constrained('empresas_operadoras');
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('acesso_id')->constrained('nfse_acessos_emissao');
            $table->foreignId('usuario_id')->constrained('users');
            $table->string('modo', 30);
            $table->char('config_hash', 64);
            $table->string('status', 30)->default('pendente');
            $table->string('codigo', 40)->nullable();
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();
            $table->index(['empresa_operadora_id', 'empresa_id', 'status'], 'nfse_validacao_portal_empresa_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_validacoes_portal');
    }
};
