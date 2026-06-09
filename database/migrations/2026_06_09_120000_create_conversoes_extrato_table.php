<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversoes_extrato', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained()->nullOnDelete();
            $table->string('familia_layout', 50);
            $table->string('layout', 50);
            $table->string('nome_arquivo_origem');
            $table->string('nome_arquivo_ofx')->nullable();
            $table->enum('status', ['pendente', 'processando', 'concluida', 'erro'])->default('pendente');
            $table->text('erro_mensagem')->nullable();
            $table->unsignedInteger('total_lancamentos')->default(0);
            $table->date('data_inicial')->nullable();
            $table->date('data_final')->nullable();
            $table->json('metadados')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['layout', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversoes_extrato');
    }
};
