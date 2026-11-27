<?php

namespace App\Services\Nfse;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecursosOficiaisNfse
{
    public function __construct(
        private CatalogoTributarioNfse $tributario,
        private CatalogoIndicadoresNfse $indicadores,
    ) {}

    /** Verifica somente arquivos locais versionados; não acessa portais nem certificados. */
    public function verificar(): array
    {
        $pacote = json_decode(file_get_contents(resource_path('nfse/pacote-oficial.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($pacote['arquivos'] as $arquivo => $hash) {
            $caminho = $this->caminho($arquivo);
            if (! hash_equals($hash, hash_file('sha256', $caminho))) {
                throw new RuntimeException('Recurso oficial NFS-e alterado: '.$arquivo.'. Atualize os arquivos pelo repositório.');
            }
        }
        foreach (['catalogo_tributario', 'indicadores'] as $tipo) {
            $arquivo = $pacote[$tipo]['arquivo'];
            if (! isset($pacote['arquivos'][$arquivo])) {
                throw new RuntimeException('Catálogo sem hash no pacote oficial.');
            }
            $csv = file_get_contents($this->caminho($arquivo));
            $linhas = $tipo === 'catalogo_tributario' ? $this->tributario->ler($csv) : $this->indicadores->ler($csv);
            if (count($linhas) !== $pacote[$tipo]['quantidade']) {
                throw new RuntimeException('Quantidade divergente no catálogo oficial NFS-e.');
            }
        }

        return $pacote;
    }

    /** Instalação idempotente pelo atualizador, sem alterar flags ou liberar edições futuras. */
    public function instalar(): array
    {
        $pacote = $this->verificar();

        return DB::transaction(function () use ($pacote) {
            $t = $pacote['catalogo_tributario'];
            $i = $pacote['indicadores'];
            $tributario = $this->tributario->importar(file_get_contents($this->caminho($t['arquivo'])),
                $t['fonte'], $t['versao'], $t['publicada_em'], $pacote['arquivos'][$t['arquivo']]);
            $indicadores = $this->indicadores->importar(file_get_contents($this->caminho($i['arquivo'])),
                $i['versao'], $i['publicada_em'], $i['restrita_desde'], $pacote['arquivos'][$i['arquivo']]);

            return ['classificacoes' => $tributario->quantidade, 'indicadores' => $indicadores->quantidade];
        });
    }

    private function caminho(string $arquivo): string
    {
        $raiz = realpath(resource_path('nfse'));
        $caminho = realpath(resource_path('nfse/'.$arquivo));
        if (! $raiz || ! $caminho || ! str_starts_with($caminho, $raiz.DIRECTORY_SEPARATOR)
            || ! is_file($caminho) || ! is_readable($caminho)) {
            throw new RuntimeException('Recurso oficial NFS-e ausente ou inválido: '.$arquivo.'. Atualize os arquivos pelo repositório.');
        }

        return $caminho;
    }
}
