import type { Page } from 'playwright';

export const NfeFazendaSelectors = {
  chaveInput(page: Page) {
    return page
      .locator(
        [
          'input[name*="txtChaveAcesso" i]',
          'input[id*="txtChaveAcesso" i]',
          'input[name*="ChaveAcesso" i]',
          'input[id*="ChaveAcesso" i]',
          '#ctl00_ContentPlaceHolder1_txtChaveAcessoResumo',
          'input[maxlength="44"]',
          'input[maxlength="54"]',
        ].join(', '),
      )
      .first();
  },

  continuarButton(page: Page) {
    return page
      .getByRole('button', { name: /continuar/i })
      .or(page.locator('input[type="submit"][value*="Continuar" i], input[type="button"][value*="Continuar" i]'))
      .or(page.locator('a:has-text("Continuar"), button:has-text("Continuar")'))
      .first();
  },

  downloadButton(page: Page) {
    return page
      .getByRole('button', { name: /download do documento/i })
      .or(page.getByRole('link', { name: /download do documento/i }))
      .or(page.locator('input[type="submit"][value*="Download" i], input[type="button"][value*="Download" i]'))
      .or(page.locator('a:has-text("Download do documento"), button:has-text("Download do documento")'))
      .first();
  },

  resultadoMarker(page: Page) {
    return page
      .locator('text=/Dados Gerais|Dados da NF-e|Chave de Acesso/i')
      .first();
  },
};
