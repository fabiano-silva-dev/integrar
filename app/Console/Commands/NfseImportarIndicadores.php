<?php

namespace App\Console\Commands;

use App\Services\Nfse\CatalogoIndicadoresNfse;
use Illuminate\Console\Command;
use InvalidArgumentException;

class NfseImportarIndicadores extends Command
{
    protected $signature = 'nfse:importar-indop {arquivo : CSV normalizado integral do Anexo VII} {--versao=} {--publicada-em=} {--restrita-desde= : Data de implantação confirmada, vazia para acervo} {--sha256=} {--confirmar-origem}';
    protected $description = 'Importa uma edição conferida do catálogo nacional de indicadores de operação';

    public function handle(CatalogoIndicadoresNfse $catalogo): int
    {
        if (! $this->option('confirmar-origem')) {
            $this->error('Confirme origem, completude e data efetiva de implantação com --confirmar-origem.');
            return self::FAILURE;
        }
        $arquivo = (string) $this->argument('arquivo');
        if (! is_file($arquivo) || ! is_readable($arquivo) || filesize($arquivo) > 5242880) {
            $this->error('Arquivo local inválido ou maior que 5 MB.');
            return self::FAILURE;
        }
        $csv = file_get_contents($arquivo, false, null, 0, 5242881);
        if (! is_string($csv)) {
            $this->error('Não foi possível ler o arquivo.');
            return self::FAILURE;
        }
        try {
            $data = (string) $this->option('restrita-desde');
            $v = $catalogo->importar($csv, (string) $this->option('versao'), (string) $this->option('publicada-em'),
                $data === '' ? null : $data, (string) $this->option('sha256'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info('Versão #'.$v->id.' registrada: '.$v->quantidade.' indicadores. Origem e implantação declaradas pelo administrador.');
        return self::SUCCESS;
    }
}
