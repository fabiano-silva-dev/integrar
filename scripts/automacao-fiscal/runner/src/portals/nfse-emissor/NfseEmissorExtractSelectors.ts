import type { Page } from 'playwright';

/**
 * Seletores da listagem Notas Emitidas / Recebidas do Emissor Nacional.
 * Baseados na UI pública (filtros + grade). Ajustar se o portal mudar o DOM.
 */
export const NfseEmissorExtractSelectors = {
  pageTitle: (page: Page) =>
    page.getByRole('heading', { name: /notas\s+(emitidas|recebidas)/i }).first(),

  buscaInput: (page: Page) =>
    page
      .locator(
        'input[placeholder*="Pesquisar pessoa" i], input[name="busca"], input#busca, input[name="Busca"]',
      )
      .first(),

  dataInicioInput: (page: Page) =>
    page
      .locator(
        'input[name="datainicio"], input#datainicio, input[name="DataInicio"], label:has-text("Data Inicial") + * input, label:has-text("Data Inicial") ~ input',
      )
      .first(),

  dataFimInput: (page: Page) =>
    page
      .locator(
        'input[name="datafim"], input#datafim, input[name="DataFim"], label:has-text("Data Final") + * input, label:has-text("Data Final") ~ input',
      )
      .first(),

  filtrarButton: (page: Page) =>
    page.getByRole('button', { name: /filtrar/i }).or(page.locator('button:has-text("Filtrar")')).first(),

  emptyState: (page: Page) => page.getByText(/nenhum\s+registro\s+encontrado/i).first(),

  /** Linhas/cards da grade de resultados (heurística). */
  resultRows: (page: Page) =>
    page.locator(
      'table tbody tr, .lista-notas .item, .notas-emitidas .item, [class*="nota"] [class*="item"]',
    ),

  chaveLinks: (page: Page) =>
    page.locator('a[href*="chave"], a[href*="Chave"], [data-chave], [data-chave-acesso]'),

  totalRegistros: (page: Page) => page.getByText(/Total\s+de\s+\d+\s+registros/i).first(),

  /** Links de página (`?pg=2`). */
  paginationLinks: (page: Page) => page.locator('.pagination a[href*="pg="]'),

  nextPageLink: (page: Page) =>
    page
      .locator(
        '.pagination a[data-original-title="Próxima"], .pagination a[title="Próxima"], .pagination a[href*="pg="]',
      )
      .filter({ hasNot: page.locator('.disabled') })
      .last(),
};
