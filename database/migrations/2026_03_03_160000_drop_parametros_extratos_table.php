<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('parametros_extratos');
    }

    public function down(): void
    {
        Schema::create('parametros_extratos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('tipo_periodo', 30);
            $table->unsignedSmallInteger('ano')->nullable();
            $table->unsignedTinyInteger('mes')->nullable();
            $table->date('data_inicial')->nullable();
            $table->date('data_final')->nullable();
            $table->string('conta_banco')->nullable();
            $table->decimal('saldo_inicial', 15, 2)->nullable();
            $table->decimal('saldo_final', 15, 2)->nullable();
            $table->boolean('eh_conferencia')->default(false);
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->onDelete('set null');
            $table->boolean('ativo')->default(true);
            $table->text('observacoes')->nullable();
            $table->timestamps();
        });
    }
};
