<?php

namespace App\Console\Commands;

use App\Services\Nfse\CatalogoTributarioNfse;
use Illuminate\Console\Command;
use InvalidArgumentException;

class NfseImportarCatalogoTributario extends Command
{
    protected $signature = 'nfse:importar-catalogo {arquivo : CSV normalizado completo} {--fonte=} {--versao=} {--publicada-em=} {--sha256=} {--confirmar-origem : Confirma revisão da fonte e completude da tabela}';
    protected $description = 'Registra uma versão pública de CST/cClassTrib a partir de CSV conferido pelo administrador do servidor';

    public function handle(CatalogoTributarioNfse $catalogo): int
    {
        if (! $this->option('confirmar-origem')) {
            $this->error('Confirme a origem e completude do arquivo com --confirmar-origem.');
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
            $v = $catalogo->importar($csv, (string) $this->option('fonte'), (string) $this->option('versao'),
                (string) $this->option('publicada-em'), (string) $this->option('sha256'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info('Versão #'.$v->id.' registrada: '.$v->quantidade.' linhas. Origem declarada pelo administrador, não autenticada pela aplicação.');
        return self::SUCCESS;
    }
}
