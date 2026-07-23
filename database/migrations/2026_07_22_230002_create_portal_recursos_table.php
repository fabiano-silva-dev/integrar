<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_recursos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_integracao_id')
                ->constrained('portais_integracao')
                ->cascadeOnDelete();
            $table->string('codigo', 64);
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->boolean('ativo')->default(true);
            $table->json('parametros_schema')->nullable();
            $table->timestamps();

            $table->unique(['portal_integracao_id', 'codigo'], 'portal_recursos_portal_codigo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_recursos');
    }
};
