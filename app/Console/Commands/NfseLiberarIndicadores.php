<?php

namespace App\Console\Commands;

use App\Services\Nfse\CatalogoIndicadoresNfse;
use Illuminate\Console\Command;
use InvalidArgumentException;

class NfseLiberarIndicadores extends Command
{
    protected $signature = 'nfse:liberar-indop {versao} {--restrita-desde=} {--sha256=} {--confirmar-implantacao}';
    protected $description = 'Libera versão arquivada de cIndOp após confirmação da implantação em produção restrita';

    public function handle(CatalogoIndicadoresNfse $catalogo): int
    {
        if (! $this->option('confirmar-implantacao')) {
            $this->error('Confirme a implantação efetiva, não somente a previsão de calendário.');
            return self::FAILURE;
        }
        try {
            $v = $catalogo->liberar((string) $this->argument('versao'), (string) $this->option('restrita-desde'), (string) $this->option('sha256'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info('Versão #'.$v->id.' liberada desde '.$v->restrita_desde.'.');
        return self::SUCCESS;
    }
}
