<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('lancamentos', 'historico_original')) {
            Schema::table('lancamentos', function (Blueprint $table) {
                $table->string('historico_original')->nullable()->after('historico');
            });
            // Preencher com terceiro.nome (quando tem) ou historico, para lançamentos já importados
            \DB::statement("
                UPDATE lancamentos l
                LEFT JOIN terceiros t ON t.id = l.terceiro_id
                SET l.historico_original = COALESCE(NULLIF(TRIM(t.nome), ''), l.historico)
                WHERE l.historico_original IS NULL
            ");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lancamentos', 'historico_original')) {
            Schema::table('lancamentos', function (Blueprint $table) {
                $table->dropColumn('historico_original');
            });
        }
    }
};
