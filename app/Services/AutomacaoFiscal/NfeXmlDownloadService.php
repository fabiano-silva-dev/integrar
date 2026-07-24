<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\CertificadoDigital;
use App\Models\DocumentoFiscal;
use App\Services\AutomacaoFiscal\Sefaz\NfeDistribuicaoDfeClient;
use App\Services\AutomacaoFiscal\Sefaz\NfeIntegracaoContabilistaClient;
use App\Services\AutomacaoFiscal\Sefaz\NfeManifestacaoDestinatarioClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Download avulso do XML da NF-e (modelo 55).
 *
 * Ordem:
 * 1) DistDFe AN com A1 do destinatário (ciência 210210 → download)
 * 2) WS Contabilista SEFAZ-RS com A1 do escritório (por chave)
 */
class NfeXmlDownloadService
{
    public function __construct(
        private readonly CertificadoDigitalService $certificados,
        private readonly NfeIntegracaoContabilistaClient $contabilista,
        private readonly NfeDistribuicaoDfeClient $distdfe,
        private readonly NfeManifestacaoDestinatarioClient $manifestacao,
    ) {}

    public function documentoElegivel(DocumentoFiscal $documento): bool
    {
        if (! $this->chaveNfeValida($documento) || ! $this->ehModelo55($documento)) {
            return false;
        }

        $cnpjDest = ExtratoNfeEcacRsParser::somenteDigitos((string) ($documento->cnpj_destinatario ?? ''));

        return $this->certificados->resolverCertificadoEscritorio() !== null
            || (strlen($cnpjDest) === 14 && $this->resolverCertificadoPorCnpj($cnpjDest) !== null);
    }

    /**
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @return array{xml: string, nome_arquivo: string, chave: string, certificado_id: int, fonte?: string}
     */
    public function baixar(
        DocumentoFiscal $documento,
        ?callable $onEvent = null,
    ): array {
        if (! $this->ehModelo55($documento)) {
            throw new RuntimeException('Download de XML disponível apenas para NF-e modelo 55.');
        }

        $chave = AnaliseFiscalService::normalizarChaveAcesso($documento->chave_acesso);
        if ($chave === null || strlen($chave) !== 44) {
            throw new RuntimeException('Chave de acesso da NF-e inválida (esperado 44 dígitos).');
        }

        $cnpjDest = ExtratoNfeEcacRsParser::somenteDigitos((string) ($documento->cnpj_destinatario ?? ''));

        return $this->baixarPorChave(
            $chave,
            null,
            $onEvent,
            strlen($cnpjDest) === 14 ? $cnpjDest : null,
        );
    }

    /**
     * Download avulso só com a chave.
     * $certificadoPreferencial: A1 do destinatário para o DistDFe.
     *
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @return array{xml: string, nome_arquivo: string, chave: string, certificado_id: int, fonte?: string}
     */
    public function baixarPorChave(
        string $chaveAcesso,
        ?CertificadoDigital $certificadoPreferencial = null,
        ?callable $onEvent = null,
        ?string $cnpjDestinatario = null,
    ): array {
        $chave = AnaliseFiscalService::normalizarChaveAcesso($chaveAcesso);
        if ($chave === null || strlen($chave) !== 44) {
            throw new RuntimeException('Chave de acesso da NF-e inválida (esperado 44 dígitos).');
        }

        if (substr($chave, 20, 2) !== '55') {
            throw new RuntimeException('A chave informada não é de NF-e modelo 55.');
        }

        $cnpjEmitente = substr($chave, 6, 14);
        $erros = [];

        // 1) DistDFe + ciência com A1 do destinatário (prioridade: rápido e estável)
        $dest = $this->resolverDestinatarioParaDistDfe($cnpjDestinatario, $certificadoPreferencial, $cnpjEmitente);
        if ($dest !== null) {
            try {
                return $this->baixarViaDistDfe(
                    $chave,
                    $dest['cnpj'],
                    $dest['certificado'],
                    $dest['uf'],
                    $onEvent,
                );
            } catch (Throwable $e) {
                $erros[] = 'DistDFe: '.$e->getMessage();
                Log::warning('DistDFe/ciência falhou no download por chave', [
                    'chave' => $chave,
                    'cnpj' => $dest['cnpj'],
                    'erro' => $e->getMessage(),
                ]);
                $this->emitirEvento($onEvent, 'warn', 'SEFAZ_RESPONSE', 'DistDFe: '.$e->getMessage(), [
                    'cnpj' => $dest['cnpj'],
                ]);
            }
        }

        // 2) WS Contabilista com A1 do escritório
        $escritorio = $this->certificados->resolverCertificadoEscritorio();
        if ($escritorio) {
            try {
                return $this->baixarViaContabilista($chave, $escritorio, $cnpjEmitente, $cnpjDestinatario, $onEvent);
            } catch (Throwable $e) {
                $erros[] = 'Contabilista: '.$e->getMessage();
                Log::warning('WS Contabilista falhou no download por chave', [
                    'chave' => $chave,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        $detalhe = $erros !== [] ? ' Tentativas: '.implode(' | ', $erros) : '';
        throw new RuntimeException(
            'Não foi possível baixar o XML via DistDFe ou WS Contabilista. '
            .'Para o DistDFe, cadastre ou selecione o A1 do destinatário da NF-e.'.$detalhe
        );
    }

    /**
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @return array{xml: string, nome_arquivo: string, chave: string, certificado_id: int, fonte?: string}
     */
    private function baixarViaContabilista(
        string $chave,
        CertificadoDigital $escritorio,
        string $cnpjEmitente,
        ?string $cnpjDestinatario,
        ?callable $onEvent,
    ): array {
        $cnpjs = [$cnpjEmitente];
        $dest = ExtratoNfeEcacRsParser::somenteDigitos((string) $cnpjDestinatario);
        if (strlen($dest) === 14 && $dest !== $cnpjEmitente) {
            $cnpjs[] = $dest;
        }

        $ultimoErro = null;
        foreach ($cnpjs as $cnpjConsulta) {
            try {
                $this->emitirEvento($onEvent, 'info', 'NAVIGATION_STARTED', 'Consultando WS Contabilista SEFAZ-RS por chave…', [
                    'cnpj' => $cnpjConsulta,
                    'fonte' => 'ws-contabilista-rs',
                ]);

                $ret = $this->contabilista->baixarXmlPorChave($chave, $cnpjConsulta, $escritorio, '55');

                $this->emitirEvento($onEvent, 'info', 'RUN_FINISHED', 'XML obtido via WS Contabilista SEFAZ-RS.', [
                    'c_stat' => $ret['c_stat'],
                    'fonte' => $ret['fonte'],
                ]);

                return [
                    'xml' => $ret['xml'],
                    'nome_arquivo' => $chave.'-nfe.xml',
                    'chave' => $chave,
                    'certificado_id' => $escritorio->id,
                    'fonte' => $ret['fonte'],
                ];
            } catch (Throwable $e) {
                $ultimoErro = $e;
                $this->emitirEvento($onEvent, 'warn', 'SEFAZ_RESPONSE', 'WS Contabilista: '.$e->getMessage(), [
                    'cnpj' => $cnpjConsulta,
                ]);
            }
        }

        throw $ultimoErro ?? new RuntimeException('WS Contabilista não retornou o XML.');
    }

    /**
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @return array{xml: string, nome_arquivo: string, chave: string, certificado_id: int, fonte?: string}
     */
    private function baixarViaDistDfe(
        string $chave,
        string $cnpjDestinatario,
        CertificadoDigital $certificado,
        ?string $uf,
        ?callable $onEvent,
    ): array {
        $this->emitirEvento($onEvent, 'info', 'NAVIGATION_STARTED', 'Consultando DistDFe (Ambiente Nacional) por chave…', [
            'cnpj' => $cnpjDestinatario,
            'fonte' => 'ws-distdfe-an',
        ]);

        $ret = $this->distdfe->baixarXmlPorChave($chave, $cnpjDestinatario, $certificado, $uf);
        if (is_string($ret['xml']) && $ret['xml'] !== '') {
            $this->emitirEvento($onEvent, 'info', 'RUN_FINISHED', 'XML obtido via DistDFe (sem manifestação).', [
                'c_stat' => $ret['c_stat'],
                'fonte' => $ret['fonte'],
            ]);

            return $this->resultadoXml($ret['xml'], $chave, $certificado->id, $ret['fonte']);
        }

        // Ciência da operação e novas tentativas de download
        $this->emitirEvento($onEvent, 'info', 'NAVIGATION_STARTED', 'Enviando Ciência da Operação (210210)…', [
            'cnpj' => $cnpjDestinatario,
        ]);

        try {
            $manif = $this->manifestacao->cienciaDaOperacao($chave, $cnpjDestinatario, $certificado);
            $this->emitirEvento($onEvent, 'info', 'SEFAZ_RESPONSE', 'Manifestação: '.$manif['c_stat'].' — '.$manif['x_motivo'], [
                'ja_manifestada' => $manif['ja_manifestada'],
            ]);
        } catch (Throwable $e) {
            // Se já havia resumo e a manif falhou, ainda assim tentamos o download (pode já estar liberado)
            Log::warning('Ciência da operação falhou', ['chave' => $chave, 'erro' => $e->getMessage()]);
            $this->emitirEvento($onEvent, 'warn', 'SEFAZ_RESPONSE', 'Ciência: '.$e->getMessage());
        }

        $retries = max(1, (int) config('automacao_fiscal.nfe_distdfe_retry_count', 4));
        $delay = max(5, (int) config('automacao_fiscal.nfe_distdfe_retry_delay_s', 45));
        $ultimoErro = null;

        for ($i = 1; $i <= $retries; $i++) {
            $this->emitirEvento($onEvent, 'info', 'NAVIGATION_STARTED', "Aguardando liberação do XML na SEFAZ ({$i}/{$retries})…", [
                'delay_s' => $delay,
            ]);
            sleep($delay);

            try {
                $ret = $this->distdfe->baixarXmlPorChave($chave, $cnpjDestinatario, $certificado, $uf);
                if (is_string($ret['xml']) && $ret['xml'] !== '') {
                    $this->emitirEvento($onEvent, 'info', 'RUN_FINISHED', 'XML obtido via DistDFe após ciência.', [
                        'c_stat' => $ret['c_stat'],
                        'fonte' => $ret['fonte'],
                        'tentativa' => $i,
                    ]);

                    return $this->resultadoXml($ret['xml'], $chave, $certificado->id, $ret['fonte']);
                }
                $ultimoErro = new RuntimeException(
                    'DistDFe ainda sem XML completo (cStat='.$ret['c_stat'].', resumo='.($ret['tem_resumo'] ? 'sim' : 'não').').'
                );
            } catch (Throwable $e) {
                $ultimoErro = $e;
                $this->emitirEvento($onEvent, 'warn', 'SEFAZ_RESPONSE', 'DistDFe retry: '.$e->getMessage());
            }
        }

        throw new RuntimeException(
            'XML ainda não disponível no DistDFe após ciência. Aguarde alguns minutos e tente novamente.'
            .($ultimoErro ? ' ('.$ultimoErro->getMessage().')' : '')
        );
    }

    /**
     * Destinatário (terceiros): CNPJ diferente do emitente da chave + A1 correspondente.
     *
     * @return array{cnpj: string, certificado: CertificadoDigital, uf: ?string}|null
     */
    private function resolverDestinatarioParaDistDfe(
        ?string $cnpjDestinatario,
        ?CertificadoDigital $certificadoPreferencial,
        string $cnpjEmitente,
    ): ?array {
        $cnpj = ExtratoNfeEcacRsParser::somenteDigitos((string) $cnpjDestinatario);
        $cert = null;

        if (strlen($cnpj) === 14 && $cnpj !== $cnpjEmitente) {
            $cert = $this->resolverCertificadoPorCnpj($cnpj);
        }

        if (! $cert && $certificadoPreferencial && ! $certificadoPreferencial->ehDoEscritorio()) {
            $cnpjCert = $this->cnpjDoCertificado($certificadoPreferencial);
            if (strlen($cnpjCert) === 14 && $cnpjCert !== $cnpjEmitente) {
                $cert = $certificadoPreferencial;
                $cnpj = $cnpjCert;
            }
        }

        if (! $cert || strlen($cnpj) !== 14 || $cnpj === $cnpjEmitente) {
            return null;
        }

        $cert->loadMissing('empresa:id,uf,cnpj');
        $uf = $cert->empresa?->uf;

        return [
            'cnpj' => $cnpj,
            'certificado' => $cert,
            'uf' => is_string($uf) ? $uf : null,
        ];
    }

    public function cnpjDoCertificado(CertificadoDigital $certificado): string
    {
        $doc = ExtratoNfeEcacRsParser::somenteDigitos((string) $certificado->documento_titular);
        if (strlen($doc) === 14) {
            return $doc;
        }

        $certificado->loadMissing('empresa:id,cnpj');
        $cnpjEmpresa = ExtratoNfeEcacRsParser::somenteDigitos((string) ($certificado->empresa?->cnpj ?? ''));

        return strlen($cnpjEmpresa) === 14 ? $cnpjEmpresa : '';
    }

    /**
     * @return array{xml: string, nome_arquivo: string, chave: string, certificado_id: int, fonte?: string}
     */
    private function resultadoXml(string $xml, string $chave, int $certificadoId, string $fonte): array
    {
        return [
            'xml' => $xml,
            'nome_arquivo' => $chave.'-nfe.xml',
            'chave' => $chave,
            'certificado_id' => $certificadoId,
            'fonte' => $fonte,
        ];
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

    public function resolverCertificadoPorCnpj(string $cnpj): ?CertificadoDigital
    {
        $cnpj = ExtratoNfeEcacRsParser::somenteDigitos($cnpj);
        if (strlen($cnpj) !== 14) {
            return null;
        }

        $porTitular = $this->certificados->resolverAtivoPorDocumento($cnpj);
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
            ->whereHas('empresa', function ($q) use ($cnpj) {
                $q->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cnpj, ''), '.', ''), '/', ''), '-', ''), ' ', '') = ?",
                    [$cnpj]
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

}
