<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plano_contas', function (Blueprint $table) {
            $table->string('classificacao', 50)->nullable()->after('codigo_reduzido');
        });
    }

    public function down(): void
    {
        Schema::table('plano_contas', function (Blueprint $table) {
            $table->dropColumn('classificacao');
        });
    }
};
