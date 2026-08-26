<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupo_whatsapp_empresas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_operadora_id')
                ->constrained('empresas_operadoras')
                ->cascadeOnDelete();
            $table->foreignId('grupo_whatsapp_id')
                ->constrained('grupos_whatsapp')
                ->cascadeOnDelete();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['grupo_whatsapp_id', 'empresa_id'], 'grupo_whatsapp_empresas_grupo_empresa_unique');
            $table->index(['empresa_operadora_id', 'empresa_id'], 'grupo_whatsapp_empresas_op_empresa_idx');
        });

        $agora = now();

        foreach (DB::table('grupos_whatsapp')->whereNotNull('empresa_id')->get() as $grupo) {
            DB::table('grupo_whatsapp_empresas')->insertOrIgnore([
                'empresa_operadora_id' => $grupo->empresa_operadora_id,
                'grupo_whatsapp_id' => $grupo->id,
                'empresa_id' => $grupo->empresa_id,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grupo_whatsapp_empresas');
    }
};
