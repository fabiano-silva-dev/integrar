<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AuditarStorageLegadosCommand extends Command
{
    protected $signature = 'storage:auditar-legados';

    protected $description = 'Lista arquivos em storage sem prefixo de escritório (legado pré-multi-tenant)';

    public function handle(): int
    {
        $legados = [];

        foreach (Storage::allFiles() as $path) {
            if ($this->ehLegado($path)) {
                $legados[] = $path;
            }
        }

        if ($legados === []) {
            $this->info('Nenhum arquivo legado encontrado.');

            return self::SUCCESS;
        }

        foreach ($legados as $path) {
            $this->line($path);
        }

        $this->warn(count($legados).' arquivo(s) sem prefixo de escritório. Rode storage:migrar-legados após revisar.');

        return self::SUCCESS;
    }

    private function ehLegado(string $path): bool
    {
        $primeiro = explode('/', $path)[0] ?? '';

        return $primeiro !== '' && ! ctype_digit($primeiro);
    }
}
