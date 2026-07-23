import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { loadEnvSafe, validateRequestSchema } from '../src/config/env.js';

function baseEnv(overrides: Record<string, string> = {}): NodeJS.ProcessEnv {
  return {
    RUNNER_INTERNAL_TOKEN: 'test-token-16chars',
    PLATFORM_BASE_URL: 'http://localhost:8080',
    AUTOMATION_FAKE_MODE: 'true',
    AUTOMATION_HEADLESS: 'true',
    AUTOMATION_TIMEOUT_MS: '120000',
    ECAC_RS_MODE: 'discovery',
    ECAC_RS_ENTRY_URL: 'https://atendimento.receita.rs.gov.br/pessoa-juridica-portal-e-cac',
    ECAC_RS_CERT_ORIGINS: '',
    ECAC_RS_ALLOWED_HOST_SUFFIXES: 'rs.gov.br',
    ECAC_A1_PFX_FILE: '/tmp/missing.pfx',
    ECAC_A1_PASSWORD_FILE: '/tmp/missing-pass.txt',
    PORT: '3000',
    ...overrides,
  };
}

describe('env validation', () => {
  it('aceita configuração válida', () => {
    const result = loadEnvSafe(baseEnv());
    assert.equal(result.success, true);
    if (result.success) {
      assert.equal(result.data.AUTOMATION_FAKE_MODE, true);
      assert.deepEqual(result.data.ECAC_RS_ALLOWED_HOST_SUFFIXES, ['rs.gov.br']);
    }
  });

  it('rejeita token curto', () => {
    const result = loadEnvSafe(baseEnv({ RUNNER_INTERNAL_TOKEN: 'short' }));
    assert.equal(result.success, false);
  });

  it('rejeita entry URL fora da allowlist', () => {
    const result = loadEnvSafe(
      baseEnv({
        ECAC_RS_ENTRY_URL: 'https://evil.example.com/login',
      }),
    );
    assert.equal(result.success, false);
  });

  it('valida ULID no request', () => {
    const ok = validateRequestSchema.safeParse({
      runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
      mode: 'fake',
    });
    assert.equal(ok.success, true);

    const bad = validateRequestSchema.safeParse({
      runId: 'not-a-ulid',
      mode: 'fake',
    });
    assert.equal(bad.success, false);
  });

  it('parseia origens HTTPS de certificado', () => {
    const result = loadEnvSafe(
      baseEnv({
        ECAC_RS_CERT_ORIGINS: 'https://auth.rs.gov.br,https://login.receita.rs.gov.br',
      }),
    );
    assert.equal(result.success, true);
    if (result.success) {
      assert.deepEqual(result.data.ECAC_RS_CERT_ORIGINS, [
        'https://auth.rs.gov.br',
        'https://login.receita.rs.gov.br',
      ]);
    }
  });
});
