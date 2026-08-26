<?php

namespace App\Services\Documentos;

use App\Enums\Documentos\TipoDocumentoRecebido;
use DateTimeImmutable;

class MapeadorTipoDocumentoIa
{
    /**
     * @param  array<string, mixed>  $saida
     * @return array{tipo: TipoDocumentoRecebido, ano: int, data: ?string, nome: ?string, metadados: array<string, mixed>}
     */
    public function mapear(array $saida, ?DateTimeImmutable $fallbackData = null): array
    {
        $tipoLivre = mb_strtolower(trim((string) ($saida['tipo_documento'] ?? $saida['categoria_arquivo'] ?? '')));
        $tipo = $this->tipoDeTexto($tipoLivre);
        $data = $this->dataDe($saida['data_emissao'] ?? null) ?? $fallbackData ?? new DateTimeImmutable('now');
        $nome = $saida['sugestao_nome_arquivo'] ?? null;

        return [
            'tipo' => $tipo,
            'ano' => (int) ($saida['ano'] ?? $data->format('Y')),
            'data' => $data->format('Y-m-d'),
            'nome' => is_string($nome) && trim($nome) !== '' ? trim($nome) : null,
            'metadados' => [
                'tipo_ia' => $saida['tipo_documento'] ?? null,
                'categoria_arquivo' => $saida['categoria_arquivo'] ?? null,
                'numero_documento' => $saida['numero_documento'] ?? null,
                'empresa_cnpj' => $saida['empresa_cnpj'] ?? null,
                'terceiro_cnpj' => $saida['terceiro_cnpj'] ?? null,
                'nome_funcionario' => $saida['nome_funcionario'] ?? null,
            ],
        ];
    }

    public function tipoDeTexto(string $texto): TipoDocumentoRecebido
    {
        $texto = $this->normalizar($texto);

        return match (true) {
            str_contains($texto, 'nfc') || str_contains($texto, 'cupom') || str_contains($texto, 'nfce') => TipoDocumentoRecebido::Cupom,
            str_contains($texto, 'nfs') || str_contains($texto, 'servico') => TipoDocumentoRecebido::Nfse,
            str_contains($texto, 'cte') || str_contains($texto, 'ct-e') || str_contains($texto, 'conhecimento de transporte') || str_contains($texto, 'dacte') => TipoDocumentoRecebido::Cte,
            str_contains($texto, 'mdfe') || str_contains($texto, 'mdf-e') || str_contains($texto, 'manifesto') || str_contains($texto, 'damdfe') => TipoDocumentoRecebido::Mdfe,
            str_contains($texto, 'nfe') || str_contains($texto, 'danfe') || str_contains($texto, 'nota fiscal') => TipoDocumentoRecebido::Nfe,
            str_contains($texto, 'xml') => TipoDocumentoRecebido::Xmls,
            str_contains($texto, 'extrato') || str_contains($texto, 'ofx') => TipoDocumentoRecebido::Extratos,
            str_contains($texto, 'comprovante') || str_contains($texto, 'pix') || str_contains($texto, 'ted') || str_contains($texto, 'boleto') || str_contains($texto, 'pagamento') => TipoDocumentoRecebido::ComprovantesPagamento,
            default => TipoDocumentoRecebido::Outros,
        };
    }

    private function dataDe(mixed $valor): ?DateTimeImmutable
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(substr(trim($valor), 0, 10));
        } catch (\Exception) {
            return null;
        }
    }

    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto);
        $semAcento = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

        return is_string($semAcento) ? $semAcento : $texto;
    }
}
