<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Modo fake
    |--------------------------------------------------------------------------
    |
    | Quando true, todos os drivers usam o FakePortalDriver (fila/agenda/logs).
    | Em desenvolvimento o padrão é true até a homologação dos runners reais.
    |
    */
    'fake_mode' => (bool) env('AUTOMACAO_FISCAL_FAKE_MODE', true),

    'timeout_ms' => (int) env('AUTOMACAO_FISCAL_TIMEOUT_MS', 300000),

    'runner_token' => env('AUTOMACAO_FISCAL_RUNNER_TOKEN', 'integrar-dev-runner-token'),

    /*
    | URL de entrada do e-CAC RS (modo certificate).
    | Alinhado ao automacao-portais: login direto por certificado A1.
    */
    'ecac_rs_entry_url' => env(
        'ECAC_RS_ENTRY_URL',
        'https://www.sefaz.rs.gov.br/Login/LoginCertACRS.aspx?codTpLogin=1'
    ),

    'ecac_rs_cert_origins' => env('ECAC_RS_CERT_ORIGINS', 'https://www.sefaz.rs.gov.br'),

    'nfse_entry_url' => env(
        'NFSE_EMISSOR_ENTRY_URL',
        'https://www.nfse.gov.br/EmissorNacional/Login'
    ),

    'nfse_cert_origins' => env('NFSE_EMISSOR_CERT_ORIGINS', 'https://certificado.nfse.gov.br'),

    /*
    |--------------------------------------------------------------------------
    | WS Contabilista SEFAZ-RS (download XML por chave com A1 do escritório)
    |--------------------------------------------------------------------------
    */
    'nfe_contabilista_url' => env(
        'NFE_CONTABILISTA_URL',
        'https://nfe-rs-integracao.sefazvirtual.rs.gov.br/ws/NfeIntegracao/NfeIntegracao.asmx'
    ),
    'nfe_contabilista_tp_amb' => env('NFE_CONTABILISTA_TP_AMB', '1'),
    'nfe_contabilista_ver_aplic' => env('NFE_CONTABILISTA_VER_APLIC', 'IntegrarExpert1.0'),
    /** Timeout curto: o host Contabilista RS costuma ficar inacessível; não bloquear a fila. */
    'nfe_contabilista_timeout_s' => (int) env('NFE_CONTABILISTA_TIMEOUT_S', 12),
    'nfe_contabilista_connect_timeout_s' => (int) env('NFE_CONTABILISTA_CONNECT_TIMEOUT_S', 5),

    /*
    |--------------------------------------------------------------------------
    | DistDFe + Manifestação (Ambiente Nacional) — A1 do destinatário/cliente
    |--------------------------------------------------------------------------
    |
    | Ciência da Operação (210210) e download por chave (consChNFe).
    |
    */
    'nfe_distdfe_url' => env(
        'NFE_DISTDFE_URL',
        'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx'
    ),
    'nfe_recepcao_evento_url' => env(
        'NFE_RECEPCAO_EVENTO_URL',
        'https://www.nfe.fazenda.gov.br/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx'
    ),
    'nfe_distdfe_tp_amb' => env('NFE_DISTDFE_TP_AMB', '1'),
    'nfe_distdfe_cuf_autor' => env('NFE_DISTDFE_CUF_AUTOR', '43'),
    'nfe_distdfe_timeout_s' => (int) env('NFE_DISTDFE_TIMEOUT_S', 60),
    'nfe_distdfe_retry_count' => (int) env('NFE_DISTDFE_RETRY_COUNT', 3),
    'nfe_distdfe_retry_delay_s' => (int) env('NFE_DISTDFE_RETRY_DELAY_S', 20),
];
