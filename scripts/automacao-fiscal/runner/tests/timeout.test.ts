import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { AutomationError } from '../src/errors/AutomationError.js';
import { globalExecutionLock } from '../src/automation/ExecutionLock.js';
import { loadEnv } from '../src/config/env.js';
import { AutomationRunner } from '../src/automation/AutomationRunner.js';

describe('timeout e concorrência no runner', () => {
  it('classifica timeout tipado', () => {
    const error = new AutomationError('TIMEOUT', 'Timeout total da execução excedido', {
      metadata: { timeoutMs: 1 },
    });
    assert.equal(error.code, 'TIMEOUT');
    assert.equal(error.retryable, true);
  });

  it('segunda execução recebe RUNNER_BUSY enquanto lock está ativo', async () => {
    globalExecutionLock.tryAcquire('external-holder');
    try {
      const config = loadEnv({
        RUNNER_INTERNAL_TOKEN: 'test-token-16chars-xx',
        PLATFORM_BASE_URL: 'http://127.0.0.1:9',
        AUTOMATION_FAKE_MODE: 'true',
        AUTOMATION_HEADLESS: 'true',
        AUTOMATION_TIMEOUT_MS: '5000',
        ECAC_RS_MODE: 'fake',
        ECAC_RS_ENTRY_URL: 'https://atendimento.receita.rs.gov.br/pessoa-juridica-portal-e-cac',
        ECAC_RS_CERT_ORIGINS: '',
        ECAC_RS_ALLOWED_HOST_SUFFIXES: 'rs.gov.br',
        ECAC_A1_PFX_FILE: '/tmp/missing.pfx',
        ECAC_A1_PASSWORD_FILE: '/tmp/missing-pass.txt',
        PORT: '3000',
      });

      const runner = new AutomationRunner(config);
      const result = await runner.validate('01ARZ3NDEKTSV4RRFFQ69G5FC1', 'fake');
      assert.equal(result.status, 'failed');
      assert.equal(result.errorCode, 'RUNNER_BUSY');
    } finally {
      globalExecutionLock.release('external-holder');
    }
  });
});
