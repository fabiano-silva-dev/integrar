<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regras_amarracoes_descricoes', function (Blueprint $table) {
            $table->string('conta_contrapartida', 50)->nullable()->after('conta_credito')
                ->comment('Uma única conta contra-partida do banco; débito/crédito definido pelo sinal do valor na importação');
            $table->string('parte_digitavel', 255)->nullable()->after('palavra_chave')
                ->comment('Opcional: restringe a regra quando o histórico contém este texto (ex: CPF)');
        });
    }

    public function down(): void
    {
        Schema::table('regras_amarracoes_descricoes', function (Blueprint $table) {
            $table->dropColumn(['conta_contrapartida', 'parte_digitavel']);
        });
    }
};
