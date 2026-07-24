<?php

namespace App\Services\AutomacaoFiscal\Sefaz;

use App\Models\CertificadoDigital;
use App\Services\AutomacaoFiscal\CertificadoDigitalService;
use Carbon\Carbon;
use RuntimeException;

/**
 * Manifestação do Destinatário via NFeRecepcaoEvento4 (Ambiente Nacional).
 * Ciência da Operação = tpEvento 210210.
 */
class NfeManifestacaoDestinatarioClient
{
    public function __construct(
        private readonly CertificadoDigitalService $certificados,
        private readonly NfeXmlDsigSigner $signer,
    ) {}

    /**
     * @return array{c_stat: string, x_motivo: string, sucesso: bool, ja_manifestada: bool}
     */
    public function cienciaDaOperacao(
        string $chaveAcesso,
        string $cnpjDestinatario,
        CertificadoDigital $certificado,
    ): array {
        return $this->enviarEvento($chaveAcesso, $cnpjDestinatario, $certificado, '210210', 'Ciencia da Operacao');
    }

    /**
     * @return array{c_stat: string, x_motivo: string, sucesso: bool, ja_manifestada: bool}
     */
    public function enviarEvento(
        string $chaveAcesso,
        string $cnpjDestinatario,
        CertificadoDigital $certificado,
        string $tpEvento,
        string $descEvento,
    ): array {
        $chave = preg_replace('/\D+/', '', $chaveAcesso) ?? '';
        $cnpj = preg_replace('/\D+/', '', $cnpjDestinatario) ?? '';

        if (strlen($chave) !== 44) {
            throw new RuntimeException('Chave de acesso inválida para manifestação.');
        }
        if (strlen($cnpj) !== 14) {
            throw new RuntimeException('CNPJ do destinatário inválido para manifestação.');
        }

        $nSeq = '1';
        $id = 'ID'.$tpEvento.$chave.$nSeq;
        $tpAmb = (string) config('automacao_fiscal.nfe_distdfe_tp_amb', '1');
        $dhEvento = Carbon::now('America/Sao_Paulo')->format('Y-m-d\TH:i:sP');
        $idLote = substr((string) time(), -15);

        $evento = '<evento versao="1.00" xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<infEvento Id="'.$this->esc($id).'">'
            .'<cOrgao>91</cOrgao>'
            .'<tpAmb>'.$this->esc($tpAmb).'</tpAmb>'
            .'<CNPJ>'.$this->esc($cnpj).'</CNPJ>'
            .'<chNFe>'.$this->esc($chave).'</chNFe>'
            .'<dhEvento>'.$this->esc($dhEvento).'</dhEvento>'
            .'<tpEvento>'.$this->esc($tpEvento).'</tpEvento>'
            .'<nSeqEvento>'.$nSeq.'</nSeqEvento>'
            .'<verEvento>1.00</verEvento>'
            .'<detEvento versao="1.00">'
            .'<descEvento>'.$this->esc($descEvento).'</descEvento>'
            .'</detEvento>'
            .'</infEvento>'
            .'</evento>';

        $pem = $this->certificados->extrairPem($certificado);
        $eventoAssinado = $this->signer->assinarPorId($evento, $id, $pem['cert'], $pem['key']);

        // Remover declaração XML se o signer serializou só o elemento
        $eventoAssinado = preg_replace('/^<\?xml[^>]*\?>\s*/i', '', $eventoAssinado) ?? $eventoAssinado;

        $envEvento = '<envEvento versao="1.00" xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<idLote>'.$this->esc($idLote).'</idLote>'
            .$eventoAssinado
            .'</envEvento>';

        $envelope = $this->montarEnvelopeSoap($envEvento);
        $raw = $this->postSoap($envelope, $certificado);

        return $this->interpretarResposta($raw);
    }

    public function montarEnvelopeSoap(string $envEvento): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            .' xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">'
            .'<soap12:Body>'
            .'<nfeRecepcaoEvento xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4">'
            .'<nfeDadosMsg>'.$envEvento.'</nfeDadosMsg>'
            .'</nfeRecepcaoEvento>'
            .'</soap12:Body>'
            .'</soap12:Envelope>';
    }

    /**
     * @return array{c_stat: string, x_motivo: string, sucesso: bool, ja_manifestada: bool}
     */
    public function interpretarResposta(string $soapXml): array
    {
        // Preferir cStat do retEvento (evento individual)
        $cStatEvento = null;
        $xMotivoEvento = null;
        if (preg_match('/<retEvento\b[\s\S]*?<cStat>(\d+)<\/cStat>[\s\S]*?<xMotivo>([\s\S]*?)<\/xMotivo>/i', $soapXml, $m)) {
            $cStatEvento = $m[1];
            $xMotivoEvento = html_entity_decode(trim(strip_tags($m[2])), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        $cStatLote = $this->primeiraTag($soapXml, 'cStat') ?? '';
        $xMotivoLote = $this->primeiraTag($soapXml, 'xMotivo') ?? '';

        $cStat = $cStatEvento ?? $cStatLote;
        $xMotivo = $xMotivoEvento ?? $xMotivoLote;

        $sucesso = in_array($cStat, ['135', '136', '573'], true);
        $jaManifestada = $cStat === '573';

        if ($cStatLote !== '' && $cStatLote !== '128' && ! $sucesso) {
            // Lote não processado e sem sucesso no evento
            if ($cStatEvento === null) {
                throw new RuntimeException("Manifestação rejeitada no lote: {$cStatLote} — {$xMotivoLote}");
            }
        }

        if (! $sucesso && $cStat !== '') {
            throw new RuntimeException("Manifestação rejeitada: {$cStat} — {$xMotivo}");
        }

        if ($cStat === '') {
            throw new RuntimeException('Manifestação: resposta SOAP sem cStat reconhecível.');
        }

        return [
            'c_stat' => $cStat,
            'x_motivo' => $xMotivo,
            'sucesso' => $sucesso,
            'ja_manifestada' => $jaManifestada,
        ];
    }

    private function postSoap(string $envelope, CertificadoDigital $certificado): string
    {
        $url = (string) config(
            'automacao_fiscal.nfe_recepcao_evento_url',
            'https://www.nfe.fazenda.gov.br/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx'
        );

        $mtls = $this->certificados->materializarCredenciaisMtls($certificado);

        try {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Falha ao iniciar cURL para Recepção de Evento.');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $envelope,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/soap+xml; charset=utf-8',
                ],
                CURLOPT_SSLCERT => $mtls['cert'],
                CURLOPT_SSLKEY => $mtls['key'],
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => (int) config('automacao_fiscal.nfe_distdfe_timeout_s', 60),
            ]);

            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                throw new RuntimeException('Falha de comunicação com Recepção de Evento: '.$error);
            }
            if (! is_string($body) || $body === '') {
                throw new RuntimeException('Recepção de Evento retornou resposta vazia (HTTP '.$http.').');
            }
            if ($http >= 400) {
                throw new RuntimeException('Recepção de Evento HTTP '.$http.': '.mb_substr(strip_tags($body), 0, 300));
            }

            return $body;
        } finally {
            ($mtls['cleanup'])();
        }
    }

    private function primeiraTag(string $xml, string $tag): ?string
    {
        if (preg_match('/<'.$tag.'\b[^>]*>([\s\S]*?)<\/'.$tag.'>/i', $xml, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return null;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
