<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class LimparTemporariosAutomacaoFiscalCommand extends Command
{
    protected $signature = 'automacao-fiscal:limpar-temporarios
                            {--horas=24 : Idade mínima em horas para apagar}
                            {--dry-run : Lista os arquivos sem removê-los}';

    protected $description = 'Remove XMLs e artefatos temporários expirados da automação fiscal';

    public function handle(): int
    {
        $horas = max(1, (int) $this->option('horas'));
        $dryRun = (bool) $this->option('dry-run');
        $limite = now()->subHours($horas)->getTimestamp();
        $apagados = 0;

        foreach (Storage::disk('local')->files('temp/nfe-xml') as $arquivo) {
            if (Storage::disk('local')->lastModified($arquivo) > $limite) {
                continue;
            }

            $apagados++;
            if ($dryRun) {
                $this->line("XML: {$arquivo}");

                continue;
            }

            Storage::disk('local')->delete($arquivo);
        }

        $runnerRoot = storage_path('app/automacao-fiscal-runner');
        if (is_dir($runnerRoot)) {
            foreach (File::directories($runnerRoot) as $dir) {
                if (File::lastModified($dir) > $limite) {
                    continue;
                }

                $apagados++;
                if ($dryRun) {
                    $this->line('Runner: '.$dir);

                    continue;
                }

                File::deleteDirectory($dir);
            }
        }

        $this->info(($dryRun ? 'Seriam removidos' : 'Removidos')." {$apagados} item(ns) com mais de {$horas}h.");

        return self::SUCCESS;
    }
}
