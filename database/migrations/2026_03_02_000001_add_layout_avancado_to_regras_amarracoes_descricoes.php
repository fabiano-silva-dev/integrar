<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regras_amarracoes_descricoes', function (Blueprint $table) {
            $table->string('layout_avancado', 50)->nullable()->after('empresa_id')
                ->comment('Layout do importador-avancado: dominio, grafeno, sicoob, caixa_federal, ofx, registros, sicredi. Null = todos os layouts');
        });
    }

    public function down(): void
    {
        Schema::table('regras_amarracoes_descricoes', function (Blueprint $table) {
            $table->dropColumn('layout_avancado');
        });
    }
};
