<?php

namespace App\Services\AutomacaoFiscal;

use App\Jobs\AutomacaoFiscal\BaixarNfseXmlJob;
use App\Models\CertificadoDigital;
use App\Models\DocumentoFiscal;
use App\Models\EmpresaIntegracao;
use App\Services\AutomacaoFiscal\Sefaz\NfseSefinNacionalClient;
use App\Services\OperadoraStorage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Download persistente do XML da NFS-e nacional (Sefin, mTLS do A1 da empresa).
 */
class NfseXmlDownloadService
{
    public function __construct(
        private readonly NfseSefinNacionalClient $sefin,
    ) {}

    public function chaveNfseValida(DocumentoFiscal|string|null $documentoOuChave): bool
    {
        $chave = $documentoOuChave instanceof DocumentoFiscal
            ? AnaliseFiscalService::normalizarChaveAcesso($documentoOuChave->chave_acesso)
            : AnaliseFiscalService::normalizarChaveAcesso($documentoOuChave);

        return $chave !== null && strlen($chave) === 50;
    }

    public function documentoElegivel(DocumentoFiscal $documento): bool
    {
        if ((string) $documento->tipo_documento !== 'nfse' || ! $this->chaveNfseValida($documento)) {
            return false;
        }

        return $this->resolverCertificadoEmpresa($documento) !== null;
    }

    /**
     * @param  list<int>  $documentoIds
     */
    public function enfileirarPendentes(array $documentoIds, ?int $operadoraId = null): int
    {
        if (config('automacao_fiscal.fake_mode', true)) {
            return 0;
        }

        $ids = array_values(array_unique(array_filter($documentoIds, fn ($id) => (int) $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $enfileirados = 0;
        $docs = DocumentoFiscal::withoutGlobalScope('operadora')
            ->whereIn('id', $ids)
            ->where('tipo_documento', 'nfse')
            ->get();

        foreach ($docs as $documento) {
            if ($documento->temXmlPersistido()) {
                continue;
            }
            if (! $this->documentoElegivel($documento)) {
                continue;
            }

            BaixarNfseXmlJob::dispatch(
                (int) $documento->id,
                $operadoraId ?? (int) $documento->empresa_operadora_id,
            );
            $enfileirados++;
        }

        return $enfileirados;
    }

    /**
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @return array{xml: string, nome_arquivo: string, chave: string, certificado_id: int, fonte: string, storage_path?: string}
     */
    public function baixar(
        DocumentoFiscal $documento,
        ?callable $onEvent = null,
        bool $forcar = false,
    ): array {
        if ((string) $documento->tipo_documento !== 'nfse') {
            throw new RuntimeException('Download de XML da Sefin disponível apenas para NFS-e.');
        }

        $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso);
        if ($chave === null || strlen($chave) !== 50) {
            throw new RuntimeException('Chave de acesso da NFS-e inválida (esperado 50 dígitos).');
        }

        if (! $forcar && $documento->temXmlPersistido()) {
            $xml = Storage::get((string) $documento->xml_storage_path);
            if (is_string($xml) && $xml !== '') {
                $this->emitirEvento($onEvent, 'info', 'RUN_FINISHED', 'XML já estava gravado; Sefin não foi consultada.', [
                    'fonte' => (string) ($documento->xml_fonte ?: NfseSefinNacionalClient::FONTE),
                ]);

                return [
                    'xml' => $xml,
                    'nome_arquivo' => $chave.'-nfse.xml',
                    'chave' => $chave,
                    'certificado_id' => 0,
                    'fonte' => (string) ($documento->xml_fonte ?: NfseSefinNacionalClient::FONTE),
                    'storage_path' => (string) $documento->xml_storage_path,
                ];
            }
        }

        $certificado = $this->resolverCertificadoEmpresa($documento);
        if (! $certificado) {
            throw new RuntimeException(
                'Cadastre o certificado A1 da empresa na integração com o Portal Nacional da NFS-e.'
            );
        }

        $resultado = $this->baixarPorChave($chave, $certificado, $onEvent);
        $path = $this->persistirXml($documento, $resultado['xml'], $resultado['fonte']);
        $resultado['storage_path'] = $path;

        return $resultado;
    }

    /**
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @return array{xml: string, nome_arquivo: string, chave: string, certificado_id: int, fonte: string}
     */
    public function baixarPorChave(
        string $chaveAcesso,
        CertificadoDigital $certificado,
        ?callable $onEvent = null,
    ): array {
        if (config('automacao_fiscal.fake_mode', true)) {
            throw new RuntimeException('Download da NFS-e na Sefin está desligado no modo fake.');
        }

        $chave = $this->sefin->normalizarChave($chaveAcesso);

        $this->emitirEvento($onEvent, 'info', 'NAVIGATION_STARTED', 'Consultando Sefin Nacional por chave…', [
            'fonte' => NfseSefinNacionalClient::FONTE,
            'url' => $this->sefin->montarUrl($chave),
        ]);

        $xml = $this->sefin->baixarXmlPorChave($chave, $certificado);

        $this->emitirEvento($onEvent, 'info', 'RUN_FINISHED', 'XML obtido via Sefin Nacional.', [
            'fonte' => NfseSefinNacionalClient::FONTE,
        ]);

        return [
            'xml' => $xml,
            'nome_arquivo' => $chave.'-nfse.xml',
            'chave' => $chave,
            'certificado_id' => (int) $certificado->id,
            'fonte' => NfseSefinNacionalClient::FONTE,
        ];
    }

    public function resolverCertificadoEmpresa(DocumentoFiscal $documento): ?CertificadoDigital
    {
        $integracao = EmpresaIntegracao::withoutGlobalScope('operadora')
            ->where('empresa_id', $documento->empresa_id)
            ->where('empresa_operadora_id', $documento->empresa_operadora_id)
            ->where('ativo', true)
            ->whereHas('portal', static fn ($q) => $q->where('codigo', 'nfse_nacional'))
            ->with('certificadoDigital')
            ->first();

        $cert = $integracao?->certificadoDigital;
        if (! $cert || ! $cert->ativo || strtoupper((string) $cert->tipo) !== 'A1') {
            return null;
        }

        return $cert;
    }

    public function persistirXml(DocumentoFiscal $documento, string $xml, string $fonte): string
    {
        $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso) ?? (string) $documento->id;
        $relative = OperadoraStorage::put(
            'automacao-fiscal/nfse/'.$documento->empresa_id,
            $chave.'.xml',
            $xml,
            (int) $documento->empresa_operadora_id
        );

        $documento->forceFill([
            'xml_storage_path' => $relative,
            'xml_baixado_em' => now(),
            'xml_fonte' => $fonte,
            'xml_erro' => null,
        ])->save();

        return $relative;
    }

    public function registrarErro(DocumentoFiscal $documento, string $mensagem): void
    {
        $documento->forceFill([
            'xml_erro' => mb_substr($mensagem, 0, 2000),
        ])->save();
    }

    /**
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @param  array<string, mixed>  $metadata
     */
    private function emitirEvento(?callable $onEvent, string $level, string $eventType, string $message, array $metadata = []): void
    {
        if (! $onEvent) {
            return;
        }

        $onEvent([
            'type' => 'event',
            'level' => $level,
            'eventType' => $eventType,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }
}
