<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_pastas_drive', function (Blueprint $table) {
            $table->string('google_web_link', 512)->nullable()->after('google_folder_nome');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_pastas_drive', function (Blueprint $table) {
            $table->dropColumn('google_web_link');
        });
    }
};
