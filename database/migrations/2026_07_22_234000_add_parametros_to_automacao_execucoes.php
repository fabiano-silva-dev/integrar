<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automacao_execucoes', function (Blueprint $table) {
            $table->json('parametros')->nullable()->after('mensagem_usuario');
        });
    }

    public function down(): void
    {
        Schema::table('automacao_execucoes', function (Blueprint $table) {
            $table->dropColumn('parametros');
        });
    }
};
