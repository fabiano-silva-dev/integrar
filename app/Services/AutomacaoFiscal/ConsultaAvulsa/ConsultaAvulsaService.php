<?php

namespace App\Services\AutomacaoFiscal\ConsultaAvulsa;

use App\Models\CertificadoDigital;
use App\Services\AutomacaoFiscal\NfeXmlDownloadProgresso;
use App\Services\AutomacaoFiscal\NfeXmlDownloadService;
use App\Services\AutomacaoFiscal\NfseXmlDownloadService;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orquestra consultas avulsas por tipo (XML NF-e / NFS-e por chave).
 */
class ConsultaAvulsaService
{
    public function __construct(
        private readonly NfeXmlDownloadService $nfeXml,
        private readonly NfseXmlDownloadService $nfseXml,
    ) {}

    /**
     * @param  array<string, mixed>  $entrada
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @return array{tipo: string, resultado: array<string, mixed>}
     */
    public function executar(
        string $tipo,
        array $entrada,
        ?callable $onEvent = null,
    ): array {
        $meta = ConsultaAvulsaCatalogo::porCodigo($tipo);
        if ($meta === null) {
            throw new InvalidArgumentException("Tipo de consulta avulsa desconhecido: {$tipo}");
        }

        return match ($tipo) {
            'xml_nfe_por_chave' => [
                'tipo' => $tipo,
                'resultado' => $this->executarXmlNfePorChave($entrada, $onEvent),
            ],
            'xml_nfse_por_chave' => [
                'tipo' => $tipo,
                'resultado' => $this->executarXmlNfsePorChave($entrada, $onEvent),
            ],
            default => throw new RuntimeException("Handler não implementado para {$tipo}."),
        };
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @return array{xml: string, nome_arquivo: string, chave: string, certificado_id: int}
     */
    private function executarXmlNfePorChave(
        array $entrada,
        ?callable $onEvent,
    ): array {
        $chave = (string) ($entrada['chave_acesso'] ?? '');
        $certificadoId = isset($entrada['certificado_digital_id']) && $entrada['certificado_digital_id'] !== ''
            ? (int) $entrada['certificado_digital_id']
            : null;

        $certificado = null;
        $cnpjDest = null;
        if ($certificadoId) {
            $certificado = CertificadoDigital::query()
                ->whereKey($certificadoId)
                ->where('ativo', true)
                ->where('tipo', 'A1')
                ->first();
            if (! $certificado) {
                throw new RuntimeException('Certificado A1 selecionado não encontrado ou inativo.');
            }
            $cnpjDest = $this->nfeXml->cnpjDoCertificado($certificado);
            if ($cnpjDest === '') {
                $cnpjDest = null;
            }
        }

        return $this->nfeXml->baixarPorChave(
            $chave,
            $certificado,
            $onEvent,
            $cnpjDest,
        );
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @return array{xml: string, nome_arquivo: string, chave: string, certificado_id: int, fonte?: string}
     */
    private function executarXmlNfsePorChave(
        array $entrada,
        ?callable $onEvent,
    ): array {
        $chave = (string) ($entrada['chave_acesso'] ?? '');
        $certificadoId = isset($entrada['certificado_digital_id']) && $entrada['certificado_digital_id'] !== ''
            ? (int) $entrada['certificado_digital_id']
            : null;

        if (! $certificadoId) {
            throw new RuntimeException('Selecione o certificado A1 da empresa para consultar a Sefin Nacional.');
        }

        $certificado = CertificadoDigital::query()
            ->whereKey($certificadoId)
            ->where('ativo', true)
            ->where('tipo', 'A1')
            ->first();
        if (! $certificado) {
            throw new RuntimeException('Certificado A1 selecionado não encontrado ou inativo.');
        }

        return $this->nfseXml->baixarPorChave($chave, $certificado, $onEvent);
    }

    /**
     * Persiste o XML gerado no storage temporário do progresso.
     *
     * @param  array{xml: string, nome_arquivo: string, chave: string, certificado_id: int, fonte?: string}  $resultado
     */
    public function persistirXmlNoProgresso(string $token, array $resultado): void
    {
        $path = NfeXmlDownloadProgresso::gravarXml($token, $resultado['xml']);
        NfeXmlDownloadProgresso::marcarSucesso($token, $path, $resultado['nome_arquivo'], $resultado['fonte'] ?? null);
    }
}
