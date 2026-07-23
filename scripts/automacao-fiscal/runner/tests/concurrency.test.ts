import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { ExecutionLock } from '../src/automation/ExecutionLock.js';
import { AutomationError } from '../src/errors/AutomationError.js';

describe('concurrency', () => {
  it('permite apenas uma execução por vez', () => {
    const lock = new ExecutionLock();
    lock.tryAcquire('run-1');
    assert.equal(lock.isBusy, true);
    assert.throws(
      () => lock.tryAcquire('run-2'),
      (error: unknown) => error instanceof AutomationError && error.code === 'RUNNER_BUSY',
    );
    lock.release('run-1');
    assert.equal(lock.isBusy, false);
    assert.doesNotThrow(() => lock.tryAcquire('run-2'));
    lock.release('run-2');
  });

  it('release de outro owner não libera o lock', () => {
    const lock = new ExecutionLock();
    lock.tryAcquire('run-1');
    lock.release('run-other');
    assert.equal(lock.isBusy, true);
    lock.release('run-1');
    assert.equal(lock.isBusy, false);
  });
});
