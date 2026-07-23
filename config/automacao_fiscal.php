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
];
