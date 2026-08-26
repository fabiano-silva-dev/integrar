<?php

namespace App\Services\Documentos;

class ExtratorTextoPdfService
{
    public function extrair(?string $caminhoArquivo, string $conteudo = ''): string
    {
        if (is_string($caminhoArquivo) && is_file($caminhoArquivo)) {
            $texto = $this->pdftotext($caminhoArquivo);

            if (trim($texto) !== '') {
                return $texto;
            }
        }

        if (str_starts_with($conteudo, '%PDF')) {
            $tmp = tempnam(sys_get_temp_dir(), 'docpdf');

            if ($tmp === false) {
                return '';
            }

            file_put_contents($tmp, $conteudo);
            $texto = $this->pdftotext($tmp);
            @unlink($tmp);

            return $texto;
        }

        return $conteudo;
    }

    private function pdftotext(string $caminho): string
    {
        $comando = sprintf(
            'pdftotext -layout %s - 2>/dev/null',
            escapeshellarg($caminho)
        );

        $saida = shell_exec($comando);

        if (! is_string($saida)) {
            return '';
        }

        return str_replace("\f", "\n", $saida);
    }
}
