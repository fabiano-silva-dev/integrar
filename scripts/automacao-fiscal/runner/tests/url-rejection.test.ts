import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { after, before, describe, it } from 'node:test';
import { loadEnv } from '../src/config/env.js';
import { createRequestHandler } from '../src/server/app.js';
import { assertUrlAllowed } from '../src/security/allowlist.js';

const TOKEN = 'test-token-16chars-xx';

describe('rejeição de URL arbitrária', () => {
  let baseUrl = '';
  let server: ReturnType<typeof createServer> | null = null;

  before(async () => {
    const config = loadEnv({
      RUNNER_INTERNAL_TOKEN: TOKEN,
      PLATFORM_BASE_URL: 'http://127.0.0.1:9',
      AUTOMATION_FAKE_MODE: 'true',
      AUTOMATION_HEADLESS: 'true',
      AUTOMATION_TIMEOUT_MS: '10000',
      ECAC_RS_MODE: 'fake',
      ECAC_RS_ENTRY_URL: 'https://atendimento.receita.rs.gov.br/pessoa-juridica-portal-e-cac',
      ECAC_RS_CERT_ORIGINS: '',
      ECAC_RS_ALLOWED_HOST_SUFFIXES: 'rs.gov.br',
      ECAC_A1_PFX_FILE: '/tmp/missing.pfx',
      ECAC_A1_PASSWORD_FILE: '/tmp/missing-pass.txt',
      PORT: '3099',
    });

    const handler = createRequestHandler(config);
    server = createServer((req, res) => {
      void handler(req, res);
    });
    await new Promise<void>((resolve) => {
      server!.listen(0, '127.0.0.1', () => resolve());
    });
    const address = server.address();
    if (!address || typeof address === 'string') {
      throw new Error('address inválido');
    }
    baseUrl = `http://127.0.0.1:${address.port}`;
  });

  after(async () => {
    if (!server) {
      return;
    }
    server.closeAllConnections();
    await new Promise<void>((resolve, reject) => {
      server!.close((error) => (error ? reject(error) : resolve()));
    });
    server = null;
  });

  it('rejeita payload com url arbitrária', async () => {
    const response = await fetch(`${baseUrl}/internal/v1/ecac-rs/validate`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${TOKEN}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        mode: 'fake',
        url: 'https://evil.example.com',
      }),
    });
    assert.equal(response.status, 400);
    const body = (await response.json()) as { error: string };
    assert.equal(body.error, 'arbitrary_url_rejected');
  });

  it('rejeita entryUrl no payload', async () => {
    const response = await fetch(`${baseUrl}/internal/v1/ecac-rs/validate`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${TOKEN}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        mode: 'fake',
        entryUrl: 'https://evil.example.com',
      }),
    });
    assert.equal(response.status, 400);
  });

  it('allowlist bloqueia host externo usado internamente', () => {
    assert.throws(() => assertUrlAllowed('https://evil.example.com/x', ['rs.gov.br']));
  });

  it('exige autenticação bearer', async () => {
    const response = await fetch(`${baseUrl}/internal/v1/ecac-rs/validate`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        runId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        mode: 'fake',
      }),
    });
    assert.equal(response.status, 401);
  });
});
