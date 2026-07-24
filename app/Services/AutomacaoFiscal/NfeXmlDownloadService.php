<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\CertificadoDigital;
use App\Models\DocumentoFiscal;
use App\Services\AutomacaoFiscal\Runners\NodeRunnerBridge;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Download avulso do XML da NF-e (modelo 55) via automação do portal nacional
 * (consultaRecaptcha.aspx + Download do documento com certificado A1).
 *
 * Não utiliza webservice DistDFe.
 */
class NfeXmlDownloadService
{
    public function __construct(
        private readonly CertificadoDigitalService $certificados,
        private readonly NodeRunnerBridge $runner,
    ) {}

    public function documentoElegivel(DocumentoFiscal $documento): bool
    {
        return $this->chaveNfeValida($documento)
            && $this->ehModelo55($documento)
            && $this->resolverCertificadoEmitente($documento) !== null;
    }

    /**
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @return array{xml: string, nome_arquivo: string, chave: string, certificado_id: int}
     */
    public function baixar(DocumentoFiscal $documento, ?callable $onEvent = null): array
    {
        if (! $this->ehModelo55($documento)) {
            throw new RuntimeException('Download de XML disponível apenas para NF-e modelo 55.');
        }

        $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso);
        if ($chave === null || strlen($chave) !== 44) {
            throw new RuntimeException('Chave de acesso da NF-e inválida (esperado 44 dígitos).');
        }

        $certificado = $this->resolverCertificadoEmitente($documento);
        if (! $certificado) {
            throw new RuntimeException(
                'Não há certificado A1 ativo do emitente desta nota. Cadastre o A1 do emitente em Configurações → Automação fiscal.'
            );
        }

        $runId = (string) Str::uuid();
        $timeoutMs = (int) config('automacao_fiscal.nfe_xml_timeout_ms', 300000);

        $resultado = $this->runner->executarAvulso(
            $runId,
            'nfe-fazenda',
            'download-nfe-xml',
            ['chaveAcesso' => $chave],
            'certificate',
            $certificado,
            $onEvent,
            null,
            $timeoutMs
        );

        if (($resultado['status'] ?? '') !== 'succeeded') {
            $msg = $resultado['result']['errorMessage']
                ?? $resultado['result']['resultData']['technicalMessage']
                ?? 'Falha na automação do portal da NF-e.';
            throw new RuntimeException((string) $msg);
        }

        $xml = $this->extrairXmlDosArtefatos($resultado['artifacts'] ?? [], $chave);
        if ($xml === null) {
            throw new RuntimeException('Automação concluiu, mas o XML da NF-e não foi encontrado nos artefatos.');
        }

        return [
            'xml' => $xml,
            'nome_arquivo' => $chave.'-nfe.xml',
            'chave' => $chave,
            'certificado_id' => $certificado->id,
        ];
    }

    public function resolverCertificadoEmitente(DocumentoFiscal $documento): ?CertificadoDigital
    {
        $cnpjEmitente = ExtratoNfeEcacRsParser::somenteDigitos($documento->cnpj_emitente);
        if (strlen($cnpjEmitente) !== 14) {
            $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso);
            if ($chave !== null && strlen($chave) === 44) {
                $cnpjEmitente = substr($chave, 6, 14);
            }
        }

        if (strlen($cnpjEmitente) !== 14) {
            return null;
        }

        $porTitular = $this->certificados->resolverAtivoPorDocumento($cnpjEmitente);
        if ($porTitular) {
            return $porTitular;
        }

        return CertificadoDigital::query()
            ->where('ativo', true)
            ->where('tipo', 'A1')
            ->whereNotNull('empresa_id')
            ->where(function ($q) {
                $q->whereNull('valido_ate')->orWhere('valido_ate', '>=', now());
            })
            ->whereHas('empresa', function ($q) use ($cnpjEmitente) {
                $q->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cnpj, ''), '.', ''), '/', ''), '-', ''), ' ', '') = ?",
                    [$cnpjEmitente]
                );
            })
            ->orderByDesc('id')
            ->first();
    }

    public function ehModelo55(DocumentoFiscal $documento): bool
    {
        $modelo = trim((string) $documento->modelo);
        if ($modelo === '55') {
            return true;
        }

        $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso);
        if ($chave !== null && strlen($chave) === 44 && substr($chave, 20, 2) === '55') {
            return true;
        }

        return false;
    }

    public function chaveNfeValida(DocumentoFiscal $documento): bool
    {
        $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso);

        return $chave !== null && strlen($chave) === 44;
    }

    /**
     * @param  list<array<string, mixed>>  $artifacts
     */
    public function extrairXmlDosArtefatos(array $artifacts, string $chave): ?string
    {
        foreach ($artifacts as $artifact) {
            $filename = (string) ($artifact['filename'] ?? '');
            $path = (string) ($artifact['absolutePath'] ?? '');
            $mime = strtolower((string) ($artifact['mimeType'] ?? ''));

            $binario = null;
            if (! empty($artifact['contentBase64']) && is_string($artifact['contentBase64'])) {
                $decoded = base64_decode($artifact['contentBase64'], true);
                if ($decoded !== false) {
                    $binario = $decoded;
                }
            } elseif ($path !== '' && is_readable($path)) {
                $binario = file_get_contents($path) ?: null;
            }

            if ($binario === null || $binario === '') {
                continue;
            }

            $ext = strtolower(pathinfo($filename !== '' ? $filename : $path, PATHINFO_EXTENSION));

            if ($ext === 'xml' || str_contains($mime, 'xml') || str_contains($binario, '<nfeProc') || str_contains($binario, '<NFe')) {
                $xml = $this->normalizarXml($binario);
                if ($xml !== null) {
                    return $xml;
                }
            }

            if ($ext === 'zip' || str_starts_with($binario, 'PK')) {
                $xml = $this->extrairXmlDeZip($binario, $chave);
                if ($xml !== null) {
                    return $xml;
                }
            }
        }

        return null;
    }

    public function extrairXmlDeZip(string $zipBinary, string $chave): ?string
    {
        $tmp = sys_get_temp_dir().'/nfe-xml-'.$chave.'-'.Str::random(8).'.zip';
        file_put_contents($tmp, $zipBinary);

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                return null;
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! is_string($name) || ! str_ends_with(strtolower($name), '.xml')) {
                    continue;
                }
                $content = $zip->getFromIndex($i);
                if (! is_string($content) || $content === '') {
                    continue;
                }
                $xml = $this->normalizarXml($content);
                if ($xml !== null) {
                    $zip->close();

                    return $xml;
                }
            }

            $zip->close();
        } finally {
            @unlink($tmp);
        }

        return null;
    }

    private function normalizarXml(string $content): ?string
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        if (! str_contains($content, '<')) {
            return null;
        }

        // HTML wrapper ocasional
        if (preg_match('/(<\?xml[\s\S]+)/i', $content, $m)) {
            $content = $m[1];
        }

        if (
            str_contains($content, 'nfeProc')
            || str_contains($content, '<NFe')
            || str_contains($content, '<nfe')
            || str_contains($content, 'infNFe')
        ) {
            return $content;
        }

        return null;
    }
}
