<?php

namespace App\Services\Documentos;

use App\Enums\Documentos\TipoDocumentoRecebido;
use DateTimeImmutable;

class ClassificadorDocumentoService
{
    public function __construct(
        private readonly IdentificadorPdfFiscalService $fiscal,
        private readonly IdentificadorExtratoPdfService $extrato,
        private readonly ExtratorTextoPdfService $extratorPdf,
    ) {}

    /**
     * @return array{tipo: ?TipoDocumentoRecebido, ano: int, data: ?string, metadados: array<string, mixed>, conclusivo: bool}
     */
    public function classificar(
        string $nomeArquivo,
        ?string $mime,
        string $conteudo,
        ?DateTimeImmutable $fallbackData = null,
        ?string $caminhoArquivo = null,
    ): array {
        $mime = strtolower((string) $mime);

        if ($this->pareceXml($mime, $conteudo)) {
            return $this->classificarXml($conteudo, $fallbackData);
        }

        if ($this->pareceOfx($mime, $conteudo)) {
            return $this->resultado(TipoDocumentoRecebido::Extratos, $fallbackData, [
                'origem' => 'ofx',
            ], conclusivo: true);
        }

        if ($this->parecePdf($mime, $conteudo, $nomeArquivo)) {
            $texto = $this->extratorPdf->extrair($caminhoArquivo, $conteudo);

            return $this->classificarTextoDocumento($texto, $fallbackData);
        }

        if ($this->pareceImagem($mime, $nomeArquivo)) {
            return $this->resultado(null, $fallbackData, [
                'origem' => 'imagem',
            ], conclusivo: false);
        }

        return $this->resultado(TipoDocumentoRecebido::Outros, $fallbackData, [
            'origem' => 'desconhecido',
        ], conclusivo: true);
    }

    /**
     * Reaplica regras fiscais/extrato sobre texto extraído (pdftotext ou LlamaParse).
     *
     * @return array{tipo: ?TipoDocumentoRecebido, ano: int, data: ?string, metadados: array<string, mixed>, conclusivo: bool}
     */
    public function classificarTextoDocumento(string $texto, ?DateTimeImmutable $fallbackData = null): array
    {
        $fiscal = $this->fiscal->identificar($texto);

        if ($fiscal !== null) {
            return $this->resultado(
                $fiscal['tipo'],
                $fiscal['data'] ?? $fallbackData,
                $fiscal['metadados'],
                conclusivo: true,
            );
        }

        $extrato = $this->extrato->identificar($texto);

        if ($extrato !== null) {
            return $this->resultado(
                TipoDocumentoRecebido::Extratos,
                $fallbackData,
                $extrato['metadados'],
                conclusivo: true,
            );
        }

        return $this->resultado(null, $fallbackData, [
            'origem' => 'pdf_inconclusivo',
            'tem_texto' => trim($texto) !== '',
        ], conclusivo: false);
    }

    /**
     * @return array{tipo: ?TipoDocumentoRecebido, ano: int, data: ?string, metadados: array<string, mixed>, conclusivo: bool}
     */
    private function classificarXml(string $conteudo, ?DateTimeImmutable $fallbackData): array
    {
        $xml = @simplexml_load_string($conteudo);

        if ($xml === false) {
            return $this->resultado(TipoDocumentoRecebido::Xmls, $fallbackData, [
                'origem' => 'xml_invalido',
            ], conclusivo: true);
        }

        $texto = $this->xmlComoTexto($xml);
        $data = $this->extrairDataXml($texto) ?? $fallbackData;
        $modelo = $this->extrairModelo($texto);

        $cnpjs = $this->extrairCnpjsXml($conteudo);

        if ($this->pareceNfse($texto, $xml)) {
            return $this->resultado(TipoDocumentoRecebido::Nfse, $data, array_merge([
                'origem' => 'xml_nfse',
            ], $cnpjs), conclusivo: true);
        }

        if ($modelo === '65') {
            return $this->resultado(TipoDocumentoRecebido::Cupom, $data, array_merge([
                'origem' => 'xml_nfce',
                'modelo' => '65',
            ], $cnpjs), conclusivo: true);
        }

        if ($modelo === '57' || str_contains($texto, '<infcte') || str_contains($texto, '<cteproc') || str_contains($texto, '<cte ')) {
            return $this->resultado(TipoDocumentoRecebido::Cte, $data, array_merge([
                'origem' => 'xml_cte',
                'modelo' => $modelo ?? '57',
            ], $cnpjs), conclusivo: true);
        }

        if ($modelo === '58' || str_contains($texto, '<infmdfe') || str_contains($texto, '<mdfeproc') || str_contains($texto, '<mdfe ')) {
            return $this->resultado(TipoDocumentoRecebido::Mdfe, $data, array_merge([
                'origem' => 'xml_mdfe',
                'modelo' => $modelo ?? '58',
            ], $cnpjs), conclusivo: true);
        }

        if ($modelo === '55' || str_contains($texto, '<nfe') || str_contains($texto, '<nfeproc') || str_contains($texto, '<infnfe')) {
            return $this->resultado(TipoDocumentoRecebido::Nfe, $data, array_merge([
                'origem' => 'xml_nfe',
                'modelo' => $modelo ?? '55',
            ], $cnpjs), conclusivo: true);
        }

        return $this->resultado(TipoDocumentoRecebido::Xmls, $data, array_merge([
            'origem' => 'xml',
        ], $cnpjs), conclusivo: true);
    }

    /**
     * @return array<string, string>
     */
    private function extrairCnpjsXml(string $conteudo): array
    {
        $meta = [];

        if (preg_match('/<emit\b[^>]*>.*?<(?:cnpj)>\s*(\d{14})\s*<\/(?:cnpj)>/is', $conteudo, $m)) {
            $meta['cnpj_emitente'] = $m[1];
        }

        if (preg_match('/<dest\b[^>]*>.*?<(?:cnpj)>\s*(\d{14})\s*<\/(?:cnpj)>/is', $conteudo, $m)) {
            $meta['cnpj_destinatario'] = $m[1];
        }

        return $meta;
    }

    private function pareceNfse(string $texto, \SimpleXMLElement $xml): bool
    {
        $raiz = strtolower($xml->getName());

        if (str_contains($raiz, 'nfse') || $raiz === 'compnfse') {
            return true;
        }

        return str_contains($texto, '<infnfse')
            || str_contains($texto, '<nfse')
            || str_contains($texto, '<compnfse')
            || str_contains($texto, 'infdeclaracaoprestacaoservico');
    }

    private function extrairModelo(string $texto): ?string
    {
        if (preg_match('/<mod>\s*(55|65|57|58)\s*<\/mod>/i', $texto, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extrairDataXml(string $texto): ?DateTimeImmutable
    {
        $padroes = [
            '/<dhEmi>([^<]+)<\/dhEmi>/i',
            '/<dEmi>([^<]+)<\/dEmi>/i',
            '/<DataEmissao>([^<]+)<\/DataEmissao>/i',
            '/<dataEmissao>([^<]+)<\/dataEmissao>/i',
            '/<Competencia>([^<]+)<\/Competencia>/i',
        ];

        foreach ($padroes as $padrao) {
            if (preg_match($padrao, $texto, $m)) {
                $bruto = trim($m[1]);
                try {
                    return new DateTimeImmutable(substr($bruto, 0, 10));
                } catch (\Exception) {
                    continue;
                }
            }
        }

        return null;
    }

    private function xmlComoTexto(\SimpleXMLElement $xml): string
    {
        $serializado = $xml->asXML();

        return mb_strtolower(is_string($serializado) ? $serializado : '');
    }

    private function pareceXml(?string $mime, string $conteudo): bool
    {
        $inicio = ltrim($conteudo);

        if (str_starts_with($inicio, '<?xml') || str_starts_with($inicio, '<nfe') || str_starts_with($inicio, '<NFe')) {
            return true;
        }

        return in_array($mime, ['application/xml', 'text/xml'], true)
            || str_ends_with((string) $mime, '+xml');
    }

    private function pareceOfx(?string $mime, string $conteudo): bool
    {
        $inicio = strtoupper(substr(ltrim($conteudo), 0, 80));

        return str_starts_with($inicio, 'OFXHEADER')
            || str_contains($inicio, '<OFX')
            || str_contains($inicio, 'OFCHEADER')
            || in_array($mime, ['application/x-ofx', 'application/ofx', 'application/x-ofc'], true);
    }

    private function parecePdf(?string $mime, string $conteudo, string $nomeArquivo): bool
    {
        return str_contains((string) $mime, 'pdf')
            || str_starts_with($conteudo, '%PDF')
            || str_ends_with(strtolower($nomeArquivo), '.pdf');
    }

    private function pareceImagem(?string $mime, string $nomeArquivo): bool
    {
        if (str_starts_with((string) $mime, 'image/')) {
            return true;
        }

        $ext = strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    /**
     * @param  array<string, mixed>  $metadados
     * @return array{tipo: ?TipoDocumentoRecebido, ano: int, data: ?string, metadados: array<string, mixed>, conclusivo: bool}
     */
    private function resultado(?TipoDocumentoRecebido $tipo, ?DateTimeImmutable $data, array $metadados, bool $conclusivo): array
    {
        $data ??= new DateTimeImmutable('now');

        return [
            'tipo' => $tipo,
            'ano' => (int) $data->format('Y'),
            'data' => $data->format('Y-m-d'),
            'metadados' => $metadados,
            'conclusivo' => $conclusivo,
        ];
    }
}
