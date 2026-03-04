<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('importacoes', 'layout_avancado')) {
            return;
        }
        Schema::table('importacoes', function (Blueprint $table) {
            $table->string('layout_avancado', 50)->nullable()->after('empresa_id')
                ->comment('Chave do layout na importação: dominio, grafeno, sicoob, caixa_federal, ofx, registros, sicredi');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('importacoes', 'layout_avancado')) {
            Schema::table('importacoes', function (Blueprint $table) {
                $table->dropColumn('layout_avancado');
            });
        }
    }
};
