import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { assertUrlAllowed, isHostAllowed, parseHostSuffixes } from '../src/security/allowlist.js';

describe('allowlist', () => {
  it('parseia suffixes', () => {
    assert.deepEqual(parseHostSuffixes('rs.gov.br, .gov.br'), ['rs.gov.br', 'gov.br']);
  });

  it('permite hosts da allowlist', () => {
    const suffixes = ['rs.gov.br'];
    assert.equal(isHostAllowed('atendimento.receita.rs.gov.br', suffixes), true);
    assert.equal(isHostAllowed('rs.gov.br', suffixes), true);
    assert.equal(isHostAllowed('evil.com', suffixes), false);
  });

  it('assertUrlAllowed rejeita host externo', () => {
    assert.throws(() => assertUrlAllowed('https://example.com', ['rs.gov.br']));
  });

  it('assertUrlAllowed aceita host autorizado', () => {
    assert.doesNotThrow(() =>
      assertUrlAllowed('https://atendimento.receita.rs.gov.br/path', ['rs.gov.br']),
    );
  });
});
