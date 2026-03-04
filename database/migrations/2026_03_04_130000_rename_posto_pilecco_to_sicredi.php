<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Atualiza layout_avancado de 'posto_pilecco' para 'sicredi' nas tabelas existentes.
     */
    public function up(): void
    {
        $tabelas = [
            'importacoes' => 'layout_avancado',
            'regras_amarracoes_descricoes' => 'layout_avancado',
            'historicos_padrao_layout' => 'layout_avancado',
        ];

        foreach ($tabelas as $tabela => $coluna) {
            if (Schema::hasTable($tabela) && Schema::hasColumn($tabela, $coluna)) {
                DB::table($tabela)->where($coluna, 'posto_pilecco')->update([$coluna => 'sicredi']);
            }
        }
    }

    public function down(): void
    {
        $tabelas = [
            'importacoes' => 'layout_avancado',
            'regras_amarracoes_descricoes' => 'layout_avancado',
            'historicos_padrao_layout' => 'layout_avancado',
        ];

        foreach ($tabelas as $tabela => $coluna) {
            if (Schema::hasTable($tabela) && Schema::hasColumn($tabela, $coluna)) {
                DB::table($tabela)->where($coluna, 'sicredi')->update([$coluna => 'posto_pilecco']);
            }
        }
    }
};
