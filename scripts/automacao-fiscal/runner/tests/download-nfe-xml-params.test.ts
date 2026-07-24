import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { parseDownloadNfeXmlParams } from '../src/portals/nfe-fazenda/downloadNfeXmlParams.js';

describe('downloadNfeXmlParams', () => {
  it('aceita chave com 44 dígitos', () => {
    const parsed = parseDownloadNfeXmlParams({
      chaveAcesso: '43260711222333000181550010000003511000000015',
    });
    assert.equal(parsed.chaveAcesso.length, 44);
  });

  it('remove máscara da chave', () => {
    const parsed = parseDownloadNfeXmlParams({
      chaveAcesso: '43 2607 11222333000181 55 001 000000351 1 00000001 5',
    });
    assert.equal(parsed.chaveAcesso, '43260711222333000181550010000003511000000015');
  });

  it('rejeita chave curta', () => {
    assert.throws(() => parseDownloadNfeXmlParams({ chaveAcesso: '123' }));
  });
});
