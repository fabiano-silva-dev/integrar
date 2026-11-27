<?php

namespace App\Console\Commands;

use App\Services\Nfse\RecursosOficiaisNfse;
use Illuminate\Console\Command;
use Throwable;

class NfseInstalarRecursos extends Command
{
    protected $signature = 'nfse:instalar-recursos {--verificar : Confere os arquivos sem gravar no banco}';
    protected $description = 'Confere XSD e instala os catálogos oficiais NFS-e incluídos no repositório, sem transmissão';

    public function handle(RecursosOficiaisNfse $recursos): int
    {
        try {
            if ($this->option('verificar')) {
                $recursos->verificar();
                $this->info('Arquivos oficiais NFS-e íntegros. Nenhum dado alterado.');

                return self::SUCCESS;
            }
            $resultado = $recursos->instalar();
        } catch (Throwable $e) {
            report($e);
            $this->error('Não foi possível instalar os recursos NFS-e. Confira os arquivos do repositório e as migrations. Detalhes no log da aplicação.');

            return self::FAILURE;
        }
        $this->info('Recursos NFS-e instalados: '.$resultado['classificacoes'].' classificações e '.$resultado['indicadores'].' indicadores.');
        $this->info('XSD de produção restrita incluído. Transmissão e edições futuras permanecem com a habilitação anterior.');

        return self::SUCCESS;
    }
}
