<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('terceiros') || Schema::hasColumn('terceiros', 'empresa_id')) {
            return;
        }

        Schema::table('terceiros', function (Blueprint $table) {
            $table->foreignId('empresa_id')
                ->nullable()
                ->after('empresa_operadora_id')
                ->constrained('empresas')
                ->nullOnDelete();
            $table->index(['empresa_id', 'nome']);
        });

        $this->associarTerceirosAsEmpresasDosLancamentos();
    }

    public function down(): void
    {
        if (!Schema::hasColumn('terceiros', 'empresa_id')) {
            return;
        }

        Schema::table('terceiros', function (Blueprint $table) {
            $table->dropForeign(['empresa_id']);
            $table->dropIndex(['empresa_id', 'nome']);
            $table->dropColumn('empresa_id');
        });
    }

    private function associarTerceirosAsEmpresasDosLancamentos(): void
    {
        if (!Schema::hasTable('lancamentos')) {
            return;
        }

        $pares = DB::table('lancamentos')
            ->select('terceiro_id', 'empresa_id')
            ->whereNotNull('terceiro_id')
            ->whereNotNull('empresa_id')
            ->distinct()
            ->get()
            ->groupBy('terceiro_id');

        foreach ($pares as $terceiroId => $linhas) {
            $empresaIds = $linhas->pluck('empresa_id')->unique()->values();
            $terceiro = DB::table('terceiros')->where('id', $terceiroId)->first();

            if (!$terceiro) {
                continue;
            }

            if ($empresaIds->count() === 1) {
                DB::table('terceiros')
                    ->where('id', $terceiroId)
                    ->update(['empresa_id' => $empresaIds->first()]);

                continue;
            }

            $primeiraEmpresa = true;

            foreach ($empresaIds as $empresaId) {
                if ($primeiraEmpresa) {
                    DB::table('terceiros')
                        ->where('id', $terceiroId)
                        ->update(['empresa_id' => $empresaId]);
                    $primeiraEmpresa = false;

                    continue;
                }

                $novoId = DB::table('terceiros')->insertGetId([
                    'nome' => $terceiro->nome,
                    'cnpj_cpf' => $terceiro->cnpj_cpf,
                    'tipo' => $terceiro->tipo,
                    'observacoes' => $terceiro->observacoes,
                    'ativo' => $terceiro->ativo,
                    'empresa_operadora_id' => $terceiro->empresa_operadora_id,
                    'empresa_id' => $empresaId,
                    'created_at' => $terceiro->created_at ?? now(),
                    'updated_at' => now(),
                ]);

                DB::table('lancamentos')
                    ->where('terceiro_id', $terceiroId)
                    ->where('empresa_id', $empresaId)
                    ->update(['terceiro_id' => $novoId]);
            }
        }
    }
};
