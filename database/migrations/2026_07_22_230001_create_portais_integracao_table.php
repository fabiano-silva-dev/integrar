<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portais_integracao', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 64)->unique();
            $table->string('nome');
            $table->string('driver', 64);
            $table->boolean('ativo')->default(true);
            $table->json('modos_autenticacao')->nullable();
            $table->json('configuracoes_publicas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portais_integracao');
    }
};
