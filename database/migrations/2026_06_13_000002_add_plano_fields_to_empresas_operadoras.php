<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas_operadoras', function (Blueprint $table) {
            $table->string('plano', 50)->default('basico')->after('ativo');
            $table->unsignedInteger('limite_empresas')->nullable()->after('plano');
            $table->unsignedInteger('limite_usuarios')->nullable()->after('limite_empresas');
            $table->string('subdominio', 100)->nullable()->unique()->after('limite_usuarios');
        });
    }

    public function down(): void
    {
        Schema::table('empresas_operadoras', function (Blueprint $table) {
            $table->dropColumn(['plano', 'limite_empresas', 'limite_usuarios', 'subdominio']);
        });
    }
};
