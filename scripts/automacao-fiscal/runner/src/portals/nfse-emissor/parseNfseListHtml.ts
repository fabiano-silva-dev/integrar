/**
 * Extrai linhas da listagem NFS-e (Emitidas / Recebidas) e gera o extrato TXT.
 *
 * Colunas HTML observadas:
 * - Geração, Emitida para/por, Competência, Município Emissor, Preço Serviço, Situação
 * - Chave no href Visualizar/Download/NFSe/{chave50}
 */

export type NfseListItem = {
  chave: string;
  dataGeracao?: string;
  competencia?: string;
  cnpjContraparte?: string;
  nomeContraparte?: string;
  municipioEmissor?: string;
  valorServico?: string;
  situacaoCodigo?: string;
  situacaoLabel?: string;
  href?: string;
  trecho?: string;
};

/** Chave de acesso NFS-e (nacional): 50 dígitos. */
const CHAVE_50 = /\b(\d{50})\b/g;

export function parseNfseListHtml(html: string): NfseListItem[] {
  const fromRows = parseTableRows(html);
  if (fromRows.length > 0) {
    return fromRows;
  }

  // Fallback: só chaves (layouts sem tabela reconhecida).
  const found = new Map<string, NfseListItem>();
  for (const match of html.matchAll(/href=["']([^"']*(?:Visualizar|Download\/NFSe)\/[^"']*)["']/gi)) {
    const href = decodeHtmlEntities(match[1] ?? '');
    const chave = extractChaveFromText(href);
    if (chave) {
      found.set(chave, { chave, href });
    }
  }
  CHAVE_50.lastIndex = 0;
  for (const match of html.matchAll(CHAVE_50)) {
    const chave = match[1];
    if (chave && !found.has(chave)) {
      found.set(chave, { chave });
    }
  }
  return [...found.values()];
}

function parseTableRows(html: string): NfseListItem[] {
  const items: NfseListItem[] = [];
  const rowRe = /<tr\b([^>]*)>([\s\S]*?)<\/tr>/gi;
  let rowMatch: RegExpExecArray | null;

  while ((rowMatch = rowRe.exec(html)) !== null) {
    const attrs = rowMatch[1] ?? '';
    const body = rowMatch[2] ?? '';
    if (!/data-chave=/i.test(attrs) && !/Visualizar\/Index\/\d{50}/i.test(body)) {
      continue;
    }

    const chave =
      extractChaveFromText(body.match(/Visualizar\/Index\/(\d{50})/i)?.[1] ?? '') ??
      extractChaveFromText(body.match(/Download\/NFSe\/(\d{50})/i)?.[1] ?? '') ??
      extractChaveFromText(body);

    if (!chave) {
      continue;
    }

    const situacaoCodigo = attr(attrs, 'data-situacao') || undefined;
    const valorAttr = attr(attrs, 'data-valor');
    const cells = [...body.matchAll(/<td\b[^>]*>([\s\S]*?)<\/td>/gi)].map((m) =>
      collapseText(m[1] ?? ''),
    );

    // Emitidas: Geração | Contraparte | Competência | Município | Valor | Situação
    // Recebidas: Geração | Contraparte | Competência | Valor | Situação  (sem município)
    const dataGeracao = normalizeDataGeracao(cells[0] || '') || undefined;
    const contraparteRaw = cells[1] || '';
    const { cnpj, nome } = parseContraparte(contraparteRaw);
    const competencia = cells[2] || undefined;
    const col3 = cells[3] || '';
    const col4 = cells[4] || '';
    const recebidasLayout = looksLikeMoney(col3) && !looksLikeMunicipio(col3);
    const municipioEmissor = recebidasLayout ? undefined : col3 || undefined;
    const valorCell = recebidasLayout ? col3 : col4;
    const situacaoLabel =
      body.match(/data-original-title=["']([^"']*NFS-e[^"']*)["']/i)?.[1] ||
      body.match(/title=["']([^"']*NFS-e[^"']*)["']/i)?.[1] ||
      undefined;

    items.push({
      chave,
      dataGeracao,
      competencia,
      cnpjContraparte: cnpj,
      nomeContraparte: nome,
      municipioEmissor,
      valorServico: valorAttr || valorCell || undefined,
      situacaoCodigo,
      situacaoLabel: situacaoLabel ? decodeHtmlEntities(situacaoLabel) : undefined,
      href: body.match(/href=["']([^"']*Visualizar\/Index\/\d{50})["']/i)?.[1],
    });
  }

  return dedupeByChave(items);
}

export function buildExtratoNfseTxt(
  items: NfseListItem[],
  options: { tipo: 'emitidas' | 'recebidas' },
): string {
  const header = [
    'dt_Geracao',
    'Competencia',
    'CNPJ_Contraparte',
    'Nome_Contraparte',
    'Municipio_Emissor',
    'Valor_Servico',
    'Sit',
    'Sit_Label',
    'Tipo',
    'Numero',
    'Chave_NFS-e',
  ].join(';');

  const lines = items.map((item) =>
    [
      item.dataGeracao ?? '',
      item.competencia ?? '',
      item.cnpjContraparte ?? '',
      sanitizeCsvField(item.nomeContraparte ?? ''),
      sanitizeCsvField(item.municipioEmissor ?? ''),
      formatMoneyBr(item.valorServico ?? ''),
      item.situacaoCodigo ?? '',
      sanitizeCsvField(item.situacaoLabel ?? ''),
      options.tipo,
      String(numeroFromChave(item.chave) ?? ''),
      item.chave,
    ].join(';'),
  );

  return [header, ...lines].join('\n') + '\n';
}

export function extractChaveFromText(text: string): string | null {
  const digits = text.replace(/\D+/g, '');
  if (digits.length >= 50) {
    return digits.match(/\d{50}/)?.[0] ?? null;
  }
  return null;
}

/**
 * Número da NFS-e embutido na chave nacional (50 dígitos):
 * mun(7) + prefixo(2) + CNPJ(14) + número (padding) + complemento(14).
 */
export function numeroFromChave(chave: string): number | null {
  const digits = chave.replace(/\D+/g, '');
  if (digits.length < 38) {
    return null;
  }
  const raw = digits.slice(23, -14);
  const n = Number.parseInt(raw, 10);
  return Number.isFinite(n) && n > 0 ? n : null;
}

function parseContraparte(raw: string): { cnpj?: string; nome?: string } {
  const text = collapseText(raw);
  const cnpjMatch = text.match(/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{14}|\d{3}\.\d{3}\.\d{3}-\d{2}/);
  const cnpj = cnpjMatch?.[0];
  let nome = text;
  if (cnpj) {
    nome = text
      .replace(cnpj, '')
      .replace(/^[\s\-–—:]+/, '')
      .trim();
  }
  return {
    cnpj: cnpj ? cnpj.replace(/\D+/g, '') : undefined,
    nome: nome || undefined,
  };
}

/** Ex.: `90,00` / `1.234,56` */
function looksLikeMoney(value: string): boolean {
  return /^\d{1,3}(\.\d{3})*,\d{2}$/.test(value.trim());
}

/** Ex.: `Faxinal do Soturno/RS` */
function looksLikeMunicipio(value: string): boolean {
  return /\/[A-Z]{2}\s*$/.test(value.trim()) || /[A-Za-zÀ-ÿ].*\//.test(value.trim());
}

/** Portal exibe `30/06/26 10:08` — normaliza para `30/06/2026`. */
function normalizeDataGeracao(value: string): string {
  const text = value.trim();
  if (!text) {
    return '';
  }
  const m = text.match(/^(\d{2})\/(\d{2})\/(\d{2,4})(?:\s+\d{1,2}:\d{2})?/);
  if (!m) {
    return text;
  }
  const day = m[1] ?? '';
  const month = m[2] ?? '';
  let year = m[3] ?? '';
  if (year.length === 2) {
    year = `20${year}`;
  }
  return `${day}/${month}/${year}`;
}

function attr(attrs: string, name: string): string {
  const re = new RegExp(`${name}=["']([^"']*)["']`, 'i');
  return decodeHtmlEntities(attrs.match(re)?.[1] ?? '');
}

function collapseText(html: string): string {
  return decodeHtmlEntities(
    html
      .replace(/<script[\s\S]*?<\/script>/gi, '')
      .replace(/<style[\s\S]*?<\/style>/gi, '')
      .replace(/<[^>]+>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim(),
  );
}

function decodeHtmlEntities(value: string): string {
  return value
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&nbsp;/g, ' ');
}

function sanitizeCsvField(value: string): string {
  return value.replace(/[;\r\n]+/g, ' ').trim();
}

function formatMoneyBr(value: string): string {
  const trimmed = value.trim();
  if (!trimmed) {
    return '';
  }
  // Já em formato BR (1.558,70) ou data-valor (1558,70)
  if (/^\d{1,3}(\.\d{3})*,\d{2}$/.test(trimmed) || /^\d+,\d{2}$/.test(trimmed)) {
    return trimmed;
  }
  const n = Number(trimmed.replace(/\./g, '').replace(',', '.'));
  if (!Number.isFinite(n)) {
    return trimmed;
  }
  return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function dedupeByChave(items: NfseListItem[]): NfseListItem[] {
  const map = new Map<string, NfseListItem>();
  for (const item of items) {
    map.set(item.chave, item);
  }
  return [...map.values()];
}

export function mergeNfseListItems(...pages: NfseListItem[][]): NfseListItem[] {
  return dedupeByChave(pages.flat());
}

export type NfsePaginationInfo = {
  totalRegistros: number | null;
  paginaAtual: number;
  ultimaPagina: number;
};

/**
 * Lê paginação do Emissor Nacional:
 * - "Total de 16 registros"
 * - links `?pg=2` / `Página 2`
 */
export function parseNfsePaginationInfo(html: string, paginaAtual = 1): NfsePaginationInfo {
  const totalMatch = html.match(/Total\s+de\s+(\d+)\s+registros/i);
  const totalRegistros = totalMatch ? Number.parseInt(totalMatch[1] ?? '', 10) : null;

  let ultimaPagina = paginaAtual;
  let viuLinkPg = false;
  for (const match of html.matchAll(/[?&]pg=(\d+)/gi)) {
    viuLinkPg = true;
    const n = Number.parseInt(match[1] ?? '', 10);
    if (Number.isFinite(n) && n > ultimaPagina) {
      ultimaPagina = n;
    }
  }
  for (const match of html.matchAll(/(?:data-original-title|title)=["']P[aá]gina\s+(\d+)["']/gi)) {
    const n = Number.parseInt(match[1] ?? '', 10);
    if (Number.isFinite(n) && n > ultimaPagina) {
      ultimaPagina = n;
    }
  }

  // Só estima pelo total quando não há links `pg=` (layout sem numeração).
  if (
    !viuLinkPg &&
    totalRegistros !== null &&
    Number.isFinite(totalRegistros) &&
    totalRegistros > 0
  ) {
    const rows = (html.match(/<tr\b[^>]*data-chave=/gi) ?? []).length;
    const porPagina = rows > 0 ? rows : 15;
    ultimaPagina = Math.max(1, Math.ceil(totalRegistros / porPagina));
  }

  return {
    totalRegistros: Number.isFinite(totalRegistros as number) ? totalRegistros : null,
    paginaAtual,
    ultimaPagina: Math.max(1, ultimaPagina),
  };
}
