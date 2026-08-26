<?php

namespace App\Services\Documentos;

use App\Models\Empresa;

class NomePastaDriveEmpresa
{
    public function sugerir(Empresa $empresa): string
    {
        $salvo = trim((string) ($empresa->pasta_drive_nome ?? ''));
        if ($salvo !== '') {
            return $salvo;
        }

        $codigo = trim((string) ($empresa->codigo_sistema ?? ''));
        $razao = $this->limparRazao((string) ($empresa->razao_social ?: $empresa->nome_fantasia ?: $empresa->nome ?: ''));

        if ($codigo !== '' && $razao !== '') {
            return $codigo.' - '.$razao;
        }

        return $codigo !== '' ? $codigo : $razao;
    }

    public function limparRazao(string $razao): string
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $razao) ?? $razao);

        $sufixos = [
            '/\s*&\s*CIA\.?\s*LTDA\.?\s*$/iu',
            '/\s+E\s+CIA\.?\s*LTDA\.?\s*$/iu',
            '/\s+CIA\.?\s*LTDA\.?\s*$/iu',
            '/\s+LTDA\.?\s*$/iu',
            '/\s+EIRELI\s*$/iu',
            '/\s+S\/A\s*$/iu',
            '/\s+S\.A\.?\s*$/iu',
            '/\s+EPP\s*$/iu',
            '/\s+MEI\s*$/iu',
            '/\s+ME\s*$/iu',
            '/\s+CIA\.?\s*$/iu',
        ];

        foreach ($sufixos as $padrao) {
            $nome = preg_replace($padrao, '', $nome) ?? $nome;
        }

        $nome = trim(preg_replace('/[.,]+$/u', '', $nome) ?? $nome);

        return trim(preg_replace('/\s+/u', ' ', $nome) ?? $nome);
    }
}
