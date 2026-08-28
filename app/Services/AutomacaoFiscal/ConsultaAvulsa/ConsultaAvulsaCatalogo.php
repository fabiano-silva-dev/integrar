<?php

namespace App\Services\AutomacaoFiscal\ConsultaAvulsa;

/**
 * Catálogo de consultas avulsas (extensível).
 * Cada tipo pode ser liberado por role sem depender de empresa/portal cadastrado.
 */
final class ConsultaAvulsaCatalogo
{
    /**
     * @return list<array{
     *     codigo: string,
     *     nome: string,
     *     descricao: string,
     *     roles: list<string>,
     *     campos: list<array{chave: string, label: string, tipo: string, placeholder?: string, hint?: string}>
     * }>
     */
    public static function todos(): array
    {
        return [
            [
                'codigo' => 'xml_nfe_por_chave',
                'nome' => 'XML NF-e por chave',
                'descricao' => 'Baixa o XML da NF-e pelo DistDFe ou WS Contabilista.',
                'roles' => ['super_admin'],
                'campos' => [
                    [
                        'chave' => 'chave_acesso',
                        'label' => 'Chave de acesso',
                        'tipo' => 'text',
                        'placeholder' => '44 dígitos da NF-e',
                        'hint' => 'Informe a chave de 44 dígitos da NF-e.',
                    ],
                    [
                        'chave' => 'certificado_digital_id',
                        'label' => 'Certificado A1',
                        'tipo' => 'certificado',
                        'hint' => 'Selecione o A1 do destinatário para usar o DistDFe. Em automático, usa o certificado do escritório no WS Contabilista.',
                    ],
                ],
            ],
            [
                'codigo' => 'xml_nfse_por_chave',
                'nome' => 'XML NFS-e por chave',
                'descricao' => 'Baixa o XML da NFS-e nacional na Sefin (mTLS do A1 da empresa).',
                'roles' => ['super_admin'],
                'campos' => [
                    [
                        'chave' => 'chave_acesso',
                        'label' => 'Chave de acesso',
                        'tipo' => 'text',
                        'placeholder' => '50 dígitos da NFS-e',
                        'hint' => 'Informe a chave de 50 dígitos do Portal Nacional da NFS-e.',
                    ],
                    [
                        'chave' => 'certificado_digital_id',
                        'label' => 'Certificado A1',
                        'tipo' => 'certificado',
                        'hint' => 'Selecione o A1 da empresa (prestador ou tomador da nota).',
                    ],
                ],
            ],
            // Futuros: xml_cte_por_chave, consulta_cadastro, etc.
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function disponiveisParaRole(?string $role): array
    {
        if ($role === null || $role === '') {
            return [];
        }

        // super_admin vê todos os tipos (inclusive os futuros restritos a outros papéis).
        if ($role === 'super_admin') {
            return self::todos();
        }

        return array_values(array_filter(
            self::todos(),
            static fn (array $tipo) => in_array($role, $tipo['roles'], true)
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function porCodigo(string $codigo): ?array
    {
        foreach (self::todos() as $tipo) {
            if ($tipo['codigo'] === $codigo) {
                return $tipo;
            }
        }

        return null;
    }

    public static function rolePodeAcessar(string $codigo, ?string $role): bool
    {
        if ($role === 'super_admin') {
            return self::porCodigo($codigo) !== null;
        }

        $tipo = self::porCodigo($codigo);
        if ($tipo === null || $role === null) {
            return false;
        }

        return in_array($role, $tipo['roles'], true);
    }
}
