<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('razao_social')->nullable()->after('nome');
            $table->string('nome_fantasia')->nullable()->after('razao_social');
            $table->string('inscricao_estadual', 32)->nullable()->after('cnpj');
            $table->string('inscricao_municipal', 32)->nullable()->after('inscricao_estadual');
            $table->string('uf', 2)->nullable()->after('inscricao_municipal');
            $table->string('codigo_municipio_ibge', 10)->nullable()->after('uf');
            $table->string('municipio')->nullable()->after('codigo_municipio_ibge');
            $table->boolean('ativo')->default(true)->after('codigo_conta_banco');
        });

        DB::table('empresas')->orderBy('id')->chunkById(200, function ($empresas) {
            foreach ($empresas as $empresa) {
                DB::table('empresas')
                    ->where('id', $empresa->id)
                    ->update([
                        'nome_fantasia' => $empresa->nome,
                        'razao_social' => $empresa->nome,
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn([
                'razao_social',
                'nome_fantasia',
                'inscricao_estadual',
                'inscricao_municipal',
                'uf',
                'codigo_municipio_ibge',
                'municipio',
                'ativo',
            ]);
        });
    }
};
