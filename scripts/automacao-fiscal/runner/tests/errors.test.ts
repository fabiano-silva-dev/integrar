import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { AutomationError, toAutomationError } from '../src/errors/AutomationError.js';
import { ERROR_CODES, SAFE_MESSAGES } from '../src/errors/errorCodes.js';

describe('errors', () => {
  it('possui todos os códigos esperados', () => {
    assert.ok(ERROR_CODES.includes('RUNNER_BUSY'));
    assert.ok(ERROR_CODES.includes('CERTIFICATE_ORIGIN_NOT_CONFIGURED'));
    assert.equal(ERROR_CODES.length, 18);
  });

  it('serializa payload tipado sem segredos', () => {
    const error = new AutomationError('TIMEOUT', 'timeout after 120000 password=segredo', {
      metadata: { password: 'segredo', elapsedMs: 120000 },
    });
    const json = error.toJSON();
    assert.equal(json.code, 'TIMEOUT');
    assert.equal(json.safeMessage, SAFE_MESSAGES.TIMEOUT);
    assert.equal(json.retryable, true);
    assert.equal(json.metadata.password, '[REDACTED]');
    assert.doesNotMatch(json.technicalMessage, /segredo/);
  });

  it('mapeia TimeoutError para TIMEOUT', () => {
    const err = new Error('Navigation timeout');
    err.name = 'TimeoutError';
    const mapped = toAutomationError(err);
    assert.equal(mapped.code, 'TIMEOUT');
  });
});
