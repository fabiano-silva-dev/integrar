<?php

namespace App\Console\Commands;

use App\Models\RegraAmarracaoDescricao;
use Illuminate\Console\Command;

class TestarParteDigitavel extends Command
{
    protected $signature = 'regras:testar-parte-digitavel {historico} {parte_digitavel} {--empresa=1} {--layout=sicredi}';

    protected $description = 'Testa se a parte digitável é encontrada no histórico (simula a busca usada nas regras)';

    public function handle(): int
    {
        $historico = $this->argument('historico');
        $parteDigitavel = $this->argument('parte_digitavel');
        $empresaId = (int) $this->option('empresa');
        $layout = $this->option('layout');

        $this->info("Histórico: {$historico}");
        $this->info("Parte digitável: {$parteDigitavel}");
        $this->info("Empresa ID: {$empresaId} | Layout: {$layout}");
        $this->newLine();

        $resultado = RegraAmarracaoDescricao::aplicarRegrasParaEmpresaLayout($empresaId, $layout, $historico, -100.00);
        $this->info('aplicarRegrasParaEmpresaLayout: ' . ($resultado ? 'ENCONTROU' : 'não encontrou'));

        $regras = RegraAmarracaoDescricao::where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->where(function ($q) use ($layout) {
                $q->where('layout_avancado', $layout)->orWhereNull('layout_avancado');
            })
            ->where(function ($q) use ($parteDigitavel) {
                $q->where('parte_digitavel', $parteDigitavel)
                    ->orWhereRaw('TRIM(parte_digitavel) = ?', [trim($parteDigitavel)]);
            })
            ->get();

        if ($regras->isEmpty()) {
            $this->warn("Nenhuma regra com parte_digitavel='{$parteDigitavel}' para empresa {$empresaId}. Buscando em todas as empresas:");
            $regras = RegraAmarracaoDescricao::where('parte_digitavel', 'like', '%' . $parteDigitavel . '%')
                ->orWhere('palavra_chave', 'like', '%PEDAGIO%')
                ->get();
            if ($regras->isEmpty()) {
                $this->error('Nenhuma regra encontrada no banco.');
            }
        }

        foreach ($regras as $r) {
            $ok = $r->aplicarRegra($historico, -100.00) !== null;
            $this->line("  Regra id={$r->id} | empresa_id={$r->empresa_id} | layout={$r->layout_avancado} | palavra_chave='{$r->palavra_chave}' | parte_digitavel='{$r->parte_digitavel}': " . ($ok ? 'OK' : 'não bateu'));
        }

        return 0;
    }
}
