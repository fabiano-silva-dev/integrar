<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // NFS-e limita o filtro a 30 dias; 31 quebra o runner do Portal Nacional.
        DB::table('automacao_configuracoes')
            ->where('periodo_padrao_dias', '>', 30)
            ->update(['periodo_padrao_dias' => 30]);

        DB::statement('ALTER TABLE automacao_configuracoes MODIFY periodo_padrao_dias INT UNSIGNED NOT NULL DEFAULT 30');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE automacao_configuracoes MODIFY periodo_padrao_dias INT UNSIGNED NOT NULL DEFAULT 31');
    }
};
