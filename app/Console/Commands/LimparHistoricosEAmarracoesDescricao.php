<?php

namespace App\Console\Commands;

use App\Models\HistoricoPadraoDescricao;
use App\Models\HistoricoPadraoLayout;
use App\Models\RegraAmarracaoDescricao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LimparHistoricosEAmarracoesDescricao extends Command
{
    protected $signature = 'limpar:historicos-amarracoes 
                            {--force : Força a limpeza sem confirmação}
                            {--só-historico : Remove apenas históricos padrão}
                            {--só-amarracoes : Remove apenas regras de amarração por descrição}';

    protected $description = 'Remove todos os históricos padrão por layout e regras de amarração por descrição (para testes)';

    public function handle(): int
    {
        $sóHistorico = $this->option('só-historico');
        $sóAmarracoes = $this->option('só-amarracoes');

        $countLayouts = HistoricoPadraoLayout::count();
        $countDescricoes = HistoricoPadraoDescricao::count();
        $countRegras = RegraAmarracaoDescricao::count();

        $this->info('=== LIMPEZA DE HISTÓRICOS E AMARRAÇÕES POR DESCRIÇÃO ===');
        $this->info("Históricos padrão (layouts): {$countLayouts}");
        $this->info("Históricos padrão (descrições): {$countDescricoes}");
        $this->info("Regras de amarração por descrição: {$countRegras}");

        if ($countLayouts === 0 && $countDescricoes === 0 && $countRegras === 0) {
            $this->warn('Nenhum registro para remover.');
            return 0;
        }

        $this->newLine();
        $this->warn('Esta operação irá remover:');
        if (!$sóAmarracoes) {
            $this->warn('  - Históricos padrão por layout e descrições');
        }
        if (!$sóHistorico) {
            $this->warn('  - Regras de amarração por descrição');
        }
        $this->warn('  - Ação irreversível!');

        if (!$this->option('force') && !$this->confirm('Deseja continuar?')) {
            $this->info('Operação cancelada.');
            return 0;
        }

        try {
            DB::beginTransaction();

            if (!$sóAmarracoes) {
                $this->info('Removendo descrições de históricos padrão...');
                $descRem = HistoricoPadraoDescricao::query()->delete();
                $this->info("  → {$descRem} descrições removidas");

                $this->info('Removendo layouts de históricos padrão...');
                $layoutRem = HistoricoPadraoLayout::query()->delete();
                $this->info("  → {$layoutRem} layouts removidos");
            }

            if (!$sóHistorico) {
                $this->info('Removendo regras de amarração por descrição...');
                $regraRem = RegraAmarracaoDescricao::query()->delete();
                $this->info("  → {$regraRem} regras removidas");
            }

            DB::commit();
            $this->newLine();
            $this->info('✅ Limpeza concluída com sucesso!');
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('❌ Erro: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
