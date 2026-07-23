import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { sanitizeString, sanitizeUrl, sanitizeValue } from '../src/security/sanitize.js';

describe('sanitize', () => {
  it('redige CPF e CNPJ', () => {
    const text = sanitizeString('CPF 123.456.789-09 e CNPJ 12.345.678/0001-99');
    assert.match(text, /\[REDACTED_CPF\]/);
    assert.match(text, /\[REDACTED_CNPJ\]/);
    assert.doesNotMatch(text, /123\.456\.789-09/);
  });

  it('redige bearer tokens e senhas', () => {
    const text = sanitizeString('Authorization: Bearer abcdef.secret.token password=super-secret');
    assert.match(text, /Bearer \[REDACTED\]/);
    assert.match(text, /password=\[REDACTED\]/i);
    assert.doesNotMatch(text, /super-secret/);
  });

  it('remove query string de URLs', () => {
    assert.equal(
      sanitizeUrl('https://portal.rs.gov.br/path?token=abc&cpf=123'),
      'https://portal.rs.gov.br/path',
    );
  });

  it('redige chaves sensíveis em objetos', () => {
    const result = sanitizeValue({
      cookie: 'session=abc',
      passphrase: 'segredo',
      nested: { authorization: 'Bearer x', safe: 'ok' },
    }) as Record<string, unknown>;

    assert.equal(result.cookie, '[REDACTED]');
    assert.equal(result.passphrase, '[REDACTED]');
    const nested = result.nested as Record<string, unknown>;
    assert.equal(nested.authorization, '[REDACTED]');
    assert.equal(nested.safe, 'ok');
  });
});
