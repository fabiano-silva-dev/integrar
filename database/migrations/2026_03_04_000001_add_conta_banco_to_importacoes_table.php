<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('importacoes', 'conta_banco')) {
            return;
        }
        Schema::table('importacoes', function (Blueprint $table) {
            $table->string('conta_banco', 50)->nullable()->after('layout_avancado')
                ->comment('Código da conta banco informada na importação do extrato (obrigatório para layouts PDF)');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('importacoes', 'conta_banco')) {
            Schema::table('importacoes', function (Blueprint $table) {
                $table->dropColumn('conta_banco');
            });
        }
    }
};
