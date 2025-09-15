<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Limpar todas as amarrações existentes
        \DB::table('amarracoes')->truncate();
        
        // Alterar a coluna para aceitar valores nulos
        Schema::table('amarracoes', function (Blueprint $table) {
            $table->string('detalhes_operacao', 512)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Limpar dados antes de reverter a coluna
        \DB::table('amarracoes')->truncate();
        
        // Reverter a coluna para NOT NULL
        Schema::table('amarracoes', function (Blueprint $table) {
            $table->string('detalhes_operacao', 512)->nullable(false)->change();
        });
    }
};
