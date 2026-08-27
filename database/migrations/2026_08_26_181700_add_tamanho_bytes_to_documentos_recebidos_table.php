<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_recebidos', function (Blueprint $table) {
            $table->unsignedBigInteger('tamanho_bytes')->nullable()->after('storage_path');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_recebidos', function (Blueprint $table) {
            $table->dropColumn('tamanho_bytes');
        });
    }
};
