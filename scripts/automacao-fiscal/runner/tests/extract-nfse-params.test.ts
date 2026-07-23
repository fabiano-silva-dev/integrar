import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  buildNfseNotasListUrl,
  isoToBrDate,
  parseExtractNfseParams,
} from '../src/portals/nfse-emissor/extractNfseParams.js';
import {
  buildExtratoNfseTxt,
  mergeNfseListItems,
  numeroFromChave,
  parseNfseListHtml,
  parseNfsePaginationInfo,
} from '../src/portals/nfse-emissor/parseNfseListHtml.js';

describe('extractNfseParams', () => {
  it('aceita payload válido e monta URL Recebidas', () => {
    const parsed = parseExtractNfseParams({
      tipo: 'recebidas',
      periodoInicial: '2026-06-01',
      periodoFinal: '2026-06-30',
      busca: '',
    });

    assert.equal(parsed.tipo, 'recebidas');
    assert.equal(isoToBrDate(parsed.periodoInicial), '01/06/2026');
    assert.equal(isoToBrDate(parsed.periodoFinal), '30/06/2026');

    const url = buildNfseNotasListUrl(parsed);
    assert.match(url, /\/EmissorNacional\/Notas\/Recebidas\?/);
    assert.match(url, /executar=1/);
    assert.match(url, /datainicio=01%2F06%2F2026/);
    assert.match(url, /datafim=30%2F06%2F2026/);
  });

  it('monta URL Emitidas', () => {
    const url = buildNfseNotasListUrl({
      tipo: 'emitidas',
      periodoInicial: '2026-06-23',
      periodoFinal: '2026-07-23',
      busca: 'empresa teste',
    });
    assert.match(url, /\/Notas\/Emitidas\?/);
    assert.match(url, /busca=empresa\+teste/);
    assert.doesNotMatch(url, /[?&]pg=/);
  });

  it('monta URL com página 2', () => {
    const url = buildNfseNotasListUrl(
      {
        tipo: 'emitidas',
        periodoInicial: '2026-06-01',
        periodoFinal: '2026-06-30',
      },
      'https://www.nfse.gov.br',
      2,
    );
    assert.match(url, /[?&]pg=2/);
    assert.match(url, /executar=1/);
  });

  it('aceita janela padrão de 30 dias (diff)', () => {
    const parsed = parseExtractNfseParams({
      tipo: 'emitidas',
      periodoInicial: '2026-06-23',
      periodoFinal: '2026-07-23',
    });
    assert.equal(parsed.periodoInicial, '2026-06-23');
  });

  it('rejeita período acima de 30 dias', () => {
    assert.throws(
      () =>
        parseExtractNfseParams({
          tipo: 'emitidas',
          periodoInicial: '2026-06-01',
          periodoFinal: '2026-07-02',
        }),
      /30 dias|Período/i,
    );
  });
});

describe('parseNfseListHtml', () => {
  const chave = '43080031216679526000180000000000001726066074736113';

  const sampleHtml = `
<table>
<thead><tr><th>Geração</th><th>Emitida para</th><th>Competência</th><th>Município</th><th>Valor</th><th>Situação</th><th></th></tr></thead>
<tbody>
<tr data-chave="abc" data-situacao="P100_GERADA" data-valor="1558,70">
  <td class="td-data">25/06/2026</td>
  <td class="td-texto-grande"><span class="cnpj">95.591.764/0001-05</span> - UFSM</td>
  <td class="td-competencia">06/2026</td>
  <td class="td-center">Faxinal do Soturno/RS</td>
  <td class="td-valor">1.558,70</td>
  <td class="td-situacao"><img data-original-title="NFS-e emitida"></td>
  <td><a href="/EmissorNacional/Notas/Visualizar/Index/${chave}">Visualizar</a></td>
</tr>
</tbody>
</table>`;

  it('extrai linha completa da tabela', () => {
    const items = parseNfseListHtml(sampleHtml);
    assert.equal(items.length, 1);
    assert.equal(items[0]?.chave, chave);
    assert.equal(items[0]?.dataGeracao, '25/06/2026');
    assert.equal(items[0]?.cnpjContraparte, '95591764000105');
    assert.equal(items[0]?.valorServico, '1558,70');
    assert.equal(numeroFromChave(chave), 17);

    // CNPJ 95.070.777/0001-39 — offset do número diferente do layout antigo (22).
    assert.equal(
      numeroFromChave('43080031295070777000139000000000008626066548202908'),
      86,
    );
  });

  it('gera extratonfse.txt com cabeçalho e chave', () => {
    const items = parseNfseListHtml(sampleHtml);
    const txt = buildExtratoNfseTxt(items, { tipo: 'emitidas' });
    assert.match(txt, /^dt_Geracao;Competencia;/);
    assert.match(txt, new RegExp(chave));
    assert.match(txt, /emitidas;/);
    assert.match(txt, /;17;/);
  });

  it('extrai layout recebidas (sem coluna município)', () => {
    const chaveRec = '43080031293568210000161000000000002626063093014389';
    const html = `
<table>
<thead><tr><th>Geração</th><th>Emitida por</th><th>Competência</th><th>Preço Serviço (R$)</th><th>Situação</th></tr></thead>
<tbody>
<tr data-chave="x" data-situacao="P100_GERADA">
  <td>30/06/26 10:08</td>
  <td>93.568.210/0001-61 - SERGIO ROGGIA &amp; CIA LTDA ME</td>
  <td>06/2026</td>
  <td>90,00</td>
  <td><img data-original-title="NFS-e Gerada"></td>
  <td><a href="/EmissorNacional/Notas/Visualizar/Index/${chaveRec}">Visualizar</a></td>
</tr>
</tbody>
</table>`;
    const items = parseNfseListHtml(html);
    assert.equal(items.length, 1);
    assert.equal(items[0]?.dataGeracao, '30/06/2026');
    assert.equal(items[0]?.cnpjContraparte, '93568210000161');
    assert.equal(items[0]?.municipioEmissor, undefined);
    assert.equal(items[0]?.valorServico, '90,00');
    const txt = buildExtratoNfseTxt(items, { tipo: 'recebidas' });
    assert.match(txt, /;90,00;/);
    assert.match(txt, /;recebidas;/);
    assert.doesNotMatch(txt, /;90,00;;;NFS-e Gerada/);
  });

  it('lê paginação (total e última página)', () => {
    const html = `
      <div class="pagination">
        <li class="active"><a title="Página 1">1</a></li>
        <li><a href="/EmissorNacional/Notas/Emitidas?pg=2&amp;executar=1" data-original-title="Página 2">2</a></li>
      </div>
      <span>Total de 16 registros</span>
      <tr data-chave="x"></tr><tr data-chave="y"></tr>
    `;
    const info = parseNfsePaginationInfo(html, 1);
    assert.equal(info.totalRegistros, 16);
    assert.equal(info.ultimaPagina, 2);
  });

  it('mescla páginas sem duplicar chave', () => {
    const merged = mergeNfseListItems(
      [{ chave: 'a' }, { chave: 'b' }],
      [{ chave: 'b' }, { chave: 'c' }],
    );
    assert.deepEqual(
      merged.map((i) => i.chave).sort(),
      ['a', 'b', 'c'],
    );
  });
});
