<?php

namespace App\Enums\Documentos;

enum TipoDocumentoRecebido: string
{
    case Nfe = 'nfe';
    case Cupom = 'cupom';
    case Nfse = 'nfse';
    case Cte = 'cte';
    case Mdfe = 'mdfe';
    case Xmls = 'xmls';
    case ComprovantesPagamento = 'comprovantes-pagamento';
    case Extratos = 'extratos';
    case Outros = 'outros';

    public function rotulo(): string
    {
        return match ($this) {
            self::Nfe => 'NF-e',
            self::Cupom => 'Cupom',
            self::Nfse => 'NFS-e',
            self::Cte => 'CT-e',
            self::Mdfe => 'MDF-e',
            self::Xmls => 'XMLs',
            self::ComprovantesPagamento => 'Comprovantes de pagamento',
            self::Extratos => 'Extratos',
            self::Outros => 'Outros',
        };
    }

    public function pastaDrive(): string
    {
        return $this->value;
    }

    /**
     * @return list<self>
     */
    public static function pastasEstrutura(): array
    {
        return [
            self::Nfe,
            self::Cupom,
            self::Nfse,
            self::Cte,
            self::Mdfe,
            self::Xmls,
            self::ComprovantesPagamento,
            self::Extratos,
            self::Outros,
        ];
    }
}
