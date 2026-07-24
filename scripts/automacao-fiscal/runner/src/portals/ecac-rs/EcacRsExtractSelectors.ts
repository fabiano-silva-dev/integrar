import type { Frame, Locator, Page } from 'playwright';

export type FormScope = Page | Frame;

/**
 * IDs reais observados em nfe-ics-ext.aspx.
 * Evitar name*="IE": há #filtroIe / #filtroIeModal ocultos antes no DOM.
 */
export const EcacRsExtractSelectors = {
  painelModalDismiss: (page: Page) =>
    page
      .getByRole('button', { name: /fechar|ok|continuar|entendi|ciente|aceitar/i })
      .or(page.locator('.modal button.close, .modal [data-dismiss="modal"], button[aria-label="Close"]'))
      .first(),

  formMarker: (scope: FormScope) =>
    scope
      .getByText(
        /Extrato NF-e\/NFC-e\s*-\s*Filtros|Extrato do Contribuinte|Inscri[cç][aã]o\s*Estadual/i,
      )
      .first(),

  ieInput: (scope: FormScope): Locator => scope.locator('#IeRs'),

  cnpjInput: (scope: FormScope): Locator => scope.locator('#CNPJ'),

  periodoInicial: (scope: FormScope): Locator => scope.locator('#DtPeriodoInicio'),

  periodoFinal: (scope: FormScope): Locator => scope.locator('#DtPeriodoFim'),

  totalizadoPorMes: (scope: FormScope) =>
    scope
      .getByRole('checkbox', { name: /totalizado\s*por\s*m[eê]s/i })
      .or(scope.getByLabel(/totalizado\s*por\s*m[eê]s/i))
      .first(),

  excluirVendaFora: (scope: FormScope) =>
    scope
      .getByRole('checkbox', {
        name: /sem as nf-e|fora do estabelecimento|cfop:\s*5103/i,
      })
      .or(scope.getByLabel(/sem as nf-e|fora do estabelecimento|cfop:\s*5103/i))
      .first(),

  situacaoNormal: (scope: FormScope) =>
    scope
      .getByRole('checkbox', { name: /^\s*Normal\s*$/i })
      .or(scope.getByLabel(/^\s*Normal\s*$/i))
      .first(),

  situacaoCancelada: (scope: FormScope) =>
    scope
      .getByRole('checkbox', { name: /Cancelad/i })
      .or(scope.getByLabel(/Cancelad/i))
      .first(),

  /** Preferir submit/button visível "Consultar" (evita outro controle role=button no DOM). */
  consultarButton: (scope: FormScope) =>
    scope
      .locator(
        'input[type="submit"][value="Consultar"], input[type="button"][value="Consultar"], input[type="submit"][value*="Consultar" i], input[type="button"][value*="Consultar" i]',
      )
      .or(scope.getByRole('button', { name: /^consultar$/i }))
      .or(scope.getByRole('button', { name: /consultar|pesquisar|buscar/i }))
      .first(),

  /**
   * Exportação pós-consulta (textos observados no portal):
   * - "Exportar resultado completo da pesquisa para arquivo texto"
   * - "Gerar arquivo Texto(txt) das notas..."
   */
  exportarButton: (scope: FormScope) =>
    scope
      .getByRole('link', {
        name: /exportar\s*resultado|gerar\s*arquivo\s*texto|arquivo\s*texto\s*\(?\s*txt|download|baixar/i,
      })
      .or(
        scope.getByRole('button', {
          name: /exportar\s*resultado|gerar\s*arquivo\s*texto|arquivo\s*texto\s*\(?\s*txt|download|baixar/i,
        }),
      )
      .or(
        scope.locator(
          'input[type="submit"][value*="Exportar" i], input[type="button"][value*="Exportar" i], a:has-text("Exportar"), a:has-text("Gerar arquivo")',
        ),
      )
      .or(scope.getByText(/exportar\s*resultado\s*completo|gerar\s*arquivo\s*texto\s*\(?\s*txt/i))
      .first(),

  resultadosMarker: (scope: FormScope) =>
    scope
      .getByText(
        /Extrato NF-e(?:\/NFC-e)?\s*-\s*Resultados|Total de Linhas|Exportar resultado completo|Gerar arquivo Texto/i,
      )
      .first(),

  filtrosMarker: (scope: FormScope) =>
    scope.getByText(/Extrato NF-e\/NFC-e\s*-\s*Filtros/i).first(),

  altcha: (scope: FormScope) =>
    scope
      .locator('altcha-widget, [data-altcha], iframe[src*="altcha" i], .altcha')
      .or(scope.getByText(/\baltcha\b|verifica[cç][aã]o\s*humana|sou\s*humano|desafio/i))
      .first(),
} as const;

/** Encontra o frame/página que contém o formulário de extrato. */
export async function resolveExtractFormScope(page: Page): Promise<FormScope> {
  if (await page.locator('#IeRs').count().catch(() => 0)) {
    return page;
  }

  for (const frame of page.frames()) {
    if (frame === page.mainFrame()) {
      continue;
    }
    if ((await frame.locator('#IeRs').count().catch(() => 0)) > 0) {
      return frame;
    }
  }

  return page;
}

export async function inventoryTextInputs(scope: FormScope): Promise<
  Array<{ type: string; name: string; id: string; row: string }>
> {
  const js = `(() => {
    return [...document.querySelectorAll('input')]
      .filter((input) => {
        const type = (input.getAttribute('type') || 'text').toLowerCase();
        return !['hidden', 'checkbox', 'radio', 'submit', 'button', 'image'].includes(type);
      })
      .slice(0, 30)
      .map((input) => ({
        type: input.getAttribute('type') || 'text',
        name: input.getAttribute('name') || '',
        id: input.getAttribute('id') || '',
        row: (input.closest('tr')?.innerText || input.parentElement?.innerText || '')
          .replace(/\\s+/g, ' ')
          .trim()
          .slice(0, 120),
      }));
  })()`;
  return (await scope.evaluate(js).catch(() => [])) as Array<{
    type: string;
    name: string;
    id: string;
    row: string;
  }>;
}
