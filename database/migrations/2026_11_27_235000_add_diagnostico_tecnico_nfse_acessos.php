<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['nfse_validacoes_portal', 'nfse_diagnosticos_acesso'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->json('diagnostico_tecnico')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['nfse_validacoes_portal', 'nfse_diagnosticos_acesso'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropColumn('diagnostico_tecnico');
            });
        }
    }
};
