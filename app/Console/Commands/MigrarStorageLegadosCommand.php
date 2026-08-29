<?php

namespace App\Console\Commands;

use App\Models\CertificadoDigital;
use App\Services\OperadoraContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrarStorageLegadosCommand extends Command
{
    protected $signature = 'storage:migrar-legados
                            {--dry-run : Mostra o que seria movido sem alterar}';

    protected $description = 'Move arquivos de certificado mapeáveis para o prefixo do escritório';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $movidos = 0;
        $orfaos = 0;

        OperadoraContext::disableScope();

        try {
            CertificadoDigital::query()
                ->orderBy('id')
                ->each(function (CertificadoDigital $certificado) use ($dryRun, &$movidos, &$orfaos) {
                    $origem = (string) $certificado->arquivo_path;
                    if ($origem === '') {
                        return;
                    }

                    $prefixo = $certificado->empresa_operadora_id.'/';
                    if (str_starts_with($origem, $prefixo)) {
                        return;
                    }

                    if (preg_match('#^\d+/#', $origem) === 1) {
                        $orfaos++;
                        $this->warn("Certificado {$certificado->id}: path já tem prefixo de outro escritório ({$origem}).");

                        return;
                    }

                    $destino = $prefixo.ltrim($origem, '/');

                    if (! Storage::exists($origem)) {
                        $orfaos++;
                        $this->warn("Certificado {$certificado->id}: arquivo origem ausente ({$origem}).");

                        return;
                    }

                    $this->line("Certificado {$certificado->id}: {$origem} → {$destino}");

                    if ($dryRun) {
                        $movidos++;

                        return;
                    }

                    Storage::makeDirectory(dirname($destino));
                    if (! Storage::exists($destino)) {
                        Storage::move($origem, $destino);
                    }
                    $certificado->update(['arquivo_path' => $destino]);
                    $movidos++;
                });
        } finally {
            OperadoraContext::enableScope();
        }

        $this->info(($dryRun ? 'Seriam migrados' : 'Migrados')." {$movidos} certificado(s). Órfãos: {$orfaos}.");

        return self::SUCCESS;
    }
}
