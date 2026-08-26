<?php

namespace App\Services\Documentos;

use App\Enums\Documentos\TipoDocumentoRecebido;
use DateTimeImmutable;

class IdentificadorPdfFiscalService
{
    /**
     * @return array{tipo: TipoDocumentoRecebido, data: ?DateTimeImmutable, metadados: array<string, mixed>}|null
     */
    public function identificar(string $texto): ?array
    {
        $normalizado = $this->normalizar($texto);
        $chave = $this->primeiraChaveAcesso($texto);
        $modelo = $chave !== null ? substr($chave, 20, 2) : null;
        $data = $chave !== null ? $this->dataDaChave($chave) : $this->primeiraData($texto);

        if ($this->pareceNfse($normalizado)) {
            return $this->resultado(TipoDocumentoRecebido::Nfse, $data, $this->metadadosFiscais($chave, [
                'origem' => 'pdf_fiscal',
                'sinal' => 'nfse',
            ]));
        }

        if ($modelo === '65' || $this->pareceNfce($normalizado)) {
            return $this->resultado(TipoDocumentoRecebido::Cupom, $data, $this->metadadosFiscais($chave, [
                'origem' => 'pdf_fiscal',
                'modelo' => $modelo ?? '65',
            ]));
        }

        if ($modelo === '57' || $this->pareceCte($normalizado)) {
            return $this->resultado(TipoDocumentoRecebido::Cte, $data, $this->metadadosFiscais($chave, [
                'origem' => 'pdf_fiscal',
                'modelo' => $modelo ?? '57',
            ]));
        }

        if ($modelo === '58' || $this->pareceMdfe($normalizado)) {
            return $this->resultado(TipoDocumentoRecebido::Mdfe, $data, $this->metadadosFiscais($chave, [
                'origem' => 'pdf_fiscal',
                'modelo' => $modelo ?? '58',
            ]));
        }

        if ($modelo === '55' || $this->pareceNfe($normalizado)) {
            return $this->resultado(TipoDocumentoRecebido::Nfe, $data, $this->metadadosFiscais($chave, [
                'origem' => 'pdf_fiscal',
                'modelo' => $modelo ?? '55',
            ]));
        }

        return null;
    }

    private function pareceNfe(string $texto): bool
    {
        return str_contains($texto, 'danfe')
            || str_contains($texto, 'documento auxiliar da nota fiscal eletronica')
            || (str_contains($texto, 'nota fiscal eletronica') && str_contains($texto, 'chave de acesso'));
    }

    private function pareceNfce(string $texto): bool
    {
        return str_contains($texto, 'nfc-e')
            || str_contains($texto, 'nfce')
            || str_contains($texto, 'danfe nfc')
            || str_contains($texto, 'nota fiscal de consumidor');
    }

    private function pareceNfse(string $texto): bool
    {
        return str_contains($texto, 'nfs-e')
            || str_contains($texto, 'nfse')
            || str_contains($texto, 'nota fiscal de servico eletronica')
            || str_contains($texto, 'nota fiscal de servicos eletronica');
    }

    private function pareceCte(string $texto): bool
    {
        return str_contains($texto, 'dacte')
            || str_contains($texto, 'conhecimento de transporte eletronico')
            || preg_match('/\bct-?e\b/', $texto) === 1;
    }

    private function pareceMdfe(string $texto): bool
    {
        return str_contains($texto, 'damdfe')
            || str_contains($texto, 'manifesto eletronico de documentos fiscais')
            || preg_match('/\bmdf-?e\b/', $texto) === 1;
    }

    private function primeiraChaveAcesso(string $texto): ?string
    {
        $soDigitosEspacos = preg_replace('/[^\d]/', '', $texto) ?? '';

        if (preg_match('/(\d{44})/', $soDigitosEspacos, $m)) {
            return $m[1];
        }

        return null;
    }

    private function dataDaChave(string $chave): ?DateTimeImmutable
    {
        $aamm = substr($chave, 2, 4);
        $ano = (int) ('20'.substr($aamm, 0, 2));
        $mes = (int) substr($aamm, 2, 2);

        if ($ano < 2006 || $mes < 1 || $mes > 12) {
            return null;
        }

        try {
            return new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function metadadosFiscais(?string $chave, array $base): array
    {
        $base['chave_acesso'] = $chave;

        if (is_string($chave) && strlen($chave) === 44) {
            $base['cnpj_emitente'] = substr($chave, 6, 14);
        }

        return $base;
    }

    private function primeiraData(string $texto): ?DateTimeImmutable
    {
        if (preg_match('/\b(\d{2})\/(\d{2})\/(\d{4})\b/', $texto, $m)) {
            try {
                return new DateTimeImmutable(sprintf('%s-%s-%s', $m[3], $m[2], $m[1]));
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadados
     * @return array{tipo: TipoDocumentoRecebido, data: ?DateTimeImmutable, metadados: array<string, mixed>}
     */
    private function resultado(TipoDocumentoRecebido $tipo, ?DateTimeImmutable $data, array $metadados): array
    {
        return [
            'tipo' => $tipo,
            'data' => $data,
            'metadados' => $metadados,
        ];
    }

    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto);
        $semAcento = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

        return is_string($semAcento) ? $semAcento : $texto;
    }
}
