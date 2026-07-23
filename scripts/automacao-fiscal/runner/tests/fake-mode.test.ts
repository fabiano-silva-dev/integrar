import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { after, before, describe, it } from 'node:test';
import { loadEnv } from '../src/config/env.js';
import { AutomationRunner } from '../src/automation/AutomationRunner.js';
import { createRequestHandler } from '../src/server/app.js';

const TOKEN = 'test-token-16chars-xx';
const RUN_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

describe('fake mode', () => {
  let platformBaseUrl = '';
  let platformServer: ReturnType<typeof createServer> | null = null;
  let runnerServer: ReturnType<typeof createServer> | null = null;
  let runnerBaseUrl = '';

  before(async () => {
    platformServer = createServer((req, res) => {
      // Simula indisponibilidade da plataforma
      res.writeHead(503, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ error: 'unavailable' }));
    });

    await new Promise<void>((resolve) => {
      platformServer!.listen(0, '127.0.0.1', () => resolve());
    });
    const platformAddress = platformServer.address();
    if (!platformAddress || typeof platformAddress === 'string') {
      throw new Error('platform address inválido');
    }
    platformBaseUrl = `http://127.0.0.1:${platformAddress.port}`;

    const config = loadEnv({
      RUNNER_INTERNAL_TOKEN: TOKEN,
      PLATFORM_BASE_URL: platformBaseUrl,
      AUTOMATION_FAKE_MODE: 'true',
      AUTOMATION_HEADLESS: 'true',
      AUTOMATION_TIMEOUT_MS: '30000',
      ECAC_RS_MODE: 'fake',
      ECAC_RS_ENTRY_URL: 'https://atendimento.receita.rs.gov.br/pessoa-juridica-portal-e-cac',
      ECAC_RS_CERT_ORIGINS: '',
      ECAC_RS_ALLOWED_HOST_SUFFIXES: 'rs.gov.br',
      ECAC_A1_PFX_FILE: '/tmp/missing.pfx',
      ECAC_A1_PASSWORD_FILE: '/tmp/missing-pass.txt',
      PORT: '0',
    });

    const handler = createRequestHandler(config);
    runnerServer = createServer((req, res) => {
      void handler(req, res);
    });
    await new Promise<void>((resolve) => {
      runnerServer!.listen(0, '127.0.0.1', () => resolve());
    });
    const runnerAddress = runnerServer.address();
    if (!runnerAddress || typeof runnerAddress === 'string') {
      throw new Error('runner address inválido');
    }
    runnerBaseUrl = `http://127.0.0.1:${runnerAddress.port}`;
  });

  after(async () => {
    await new Promise<void>((resolve, reject) => {
      runnerServer?.close((error) => (error ? reject(error) : resolve()));
    });
    await new Promise<void>((resolve, reject) => {
      platformServer?.close((error) => (error ? reject(error) : resolve()));
    });
  });

  it('finaliza com sucesso mesmo se a plataforma estiver indisponível', async () => {
    const response = await fetch(`${runnerBaseUrl}/internal/v1/ecac-rs/validate`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${TOKEN}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({ runId: RUN_ID, mode: 'fake' }),
    });

    assert.equal(response.status, 200);
    const body = (await response.json()) as { status: string; resultData?: { mode?: string } };
    assert.equal(body.status, 'succeeded');
    assert.equal(body.resultData?.mode, 'fake');
  });

  it('health responde ok', async () => {
    const response = await fetch(`${runnerBaseUrl}/health`);
    assert.equal(response.status, 200);
    const body = (await response.json()) as { status: string };
    assert.equal(body.status, 'ok');
  });

  it('valida NFS-e emissor em modo fake', async () => {
    const response = await fetch(`${runnerBaseUrl}/internal/v1/nfse-emissor/validate`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${TOKEN}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({ runId: RUN_ID, mode: 'fake' }),
    });

    assert.equal(response.status, 200);
    const body = (await response.json()) as {
      status: string;
      portal?: string;
      finalUrl?: string;
    };
    assert.equal(body.status, 'succeeded');
    assert.equal(body.portal, 'nfse-emissor');
    assert.match(body.finalUrl ?? '', /nfse-emissor/);
  });

  it('AutomationRunner fake não depende de browser', async () => {
    const config = loadEnv({
      RUNNER_INTERNAL_TOKEN: TOKEN,
      PLATFORM_BASE_URL: platformBaseUrl,
      AUTOMATION_FAKE_MODE: 'true',
      AUTOMATION_HEADLESS: 'true',
      AUTOMATION_TIMEOUT_MS: '30000',
      ECAC_RS_MODE: 'fake',
      ECAC_RS_ENTRY_URL: 'https://atendimento.receita.rs.gov.br/pessoa-juridica-portal-e-cac',
      ECAC_RS_CERT_ORIGINS: '',
      ECAC_RS_ALLOWED_HOST_SUFFIXES: 'rs.gov.br',
      ECAC_A1_PFX_FILE: '/tmp/missing.pfx',
      ECAC_A1_PASSWORD_FILE: '/tmp/missing-pass.txt',
      PORT: '3000',
    });
    const runner = new AutomationRunner(config);
    const result = await runner.validate('01ARZ3NDEKTSV4RRFFQ69G5FB0', 'fake');
    assert.equal(result.status, 'succeeded');
  });
});
