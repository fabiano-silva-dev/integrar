import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  isoToBrDate,
  parseExtractNfeNfceParams,
} from '../src/portals/ecac-rs/extractNfeNfceParams.js';

describe('extractNfeNfceParams', () => {
  it('aceita payload válido com IE', () => {
    const parsed = parseExtractNfeNfceParams({
      ie: '1350028310',
      cnpj: null,
      modelo: 'nfe',
      totalizadoPorMes: false,
      periodoInicial: '2026-06-01',
      periodoFinal: '2026-06-30',
      operacao: 'saida-consulente',
      excluirVendaForaEstabelecimento: true,
      situacaoNormal: true,
      situacaoCancelada: false,
    });

    assert.equal(parsed.ie, '1350028310');
    assert.equal(parsed.modelo, 'nfe');
    assert.equal(isoToBrDate(parsed.periodoInicial), '01/06/2026');
    assert.equal(isoToBrDate(parsed.periodoFinal), '30/06/2026');
  });

  it('rejeita período acima de 31 dias', () => {
    assert.throws(
      () =>
        parseExtractNfeNfceParams({
          ie: '1350028310',
          modelo: 'nfe',
          periodoInicial: '2026-06-01',
          periodoFinal: '2026-07-10',
          operacao: 'saida-consulente',
          situacaoNormal: true,
        }),
      /31 dias|Período/i,
    );
  });

  it('exige IE ou CNPJ', () => {
    assert.throws(
      () =>
        parseExtractNfeNfceParams({
          ie: null,
          cnpj: null,
          modelo: 'nfce',
          periodoInicial: '2026-06-01',
          periodoFinal: '2026-06-10',
          operacao: 'entrada-terceiros',
          situacaoNormal: true,
        }),
      /IE ou CNPJ/i,
    );
  });
});
