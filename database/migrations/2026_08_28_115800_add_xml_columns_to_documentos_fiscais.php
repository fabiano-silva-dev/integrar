<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_fiscais', function (Blueprint $table) {
            $table->string('xml_storage_path', 512)->nullable()->after('origem');
            $table->timestamp('xml_baixado_em')->nullable()->after('xml_storage_path');
            $table->string('xml_fonte', 64)->nullable()->after('xml_baixado_em');
            $table->text('xml_erro')->nullable()->after('xml_fonte');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_fiscais', function (Blueprint $table) {
            $table->dropColumn(['xml_storage_path', 'xml_baixado_em', 'xml_fonte', 'xml_erro']);
        });
    }
};
