<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracoes_google', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->timestamp('configurado_em')->nullable();
            $table->timestamps();

            $table->unique('empresa_operadora_id', 'configuracoes_google_operadora_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_google');
    }
};
