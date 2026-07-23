import type { Page } from 'playwright';

export const EcacRsSelectors = {
  cookieAccept: (page: Page) =>
    page.getByRole('button', { name: /aceitar|concordo|ok|continuar|aceito/i }).first(),

  portalEcacLink: (page: Page) =>
    page
      .getByRole('link', { name: /e-?\s*cac|portal e-cac|acessar.*e-cac/i })
      .or(page.getByText(/portal e-cac|acessar o e-cac/i))
      .first(),

  // Na Sefaz RS a opção correta para Playwright é "via navegador"
  // (a primeira usa plugin/desktop e não envia o certificado do Chromium).
  certificateOption: (page: Page) =>
    page
      .getByRole('link', { name: /via navegador/i })
      .or(page.getByText(/certificado digital \(e-cpf\s*\/\s*e-cnpj\) via navegador/i))
      .or(page.getByRole('link', { name: /certificado digital/i }))
      .or(page.getByRole('button', { name: /certificado digital/i }))
      .first(),

  logout: (page: Page) =>
    page
      .getByRole('link', { name: /sair|logout|encerrar/i })
      .or(page.getByRole('button', { name: /sair|logout|encerrar/i }))
      .first(),

  meusServicos: (page: Page) =>
    page
      .getByRole('link', { name: /meus serviços|meus servicos|meus vínculos|meus vinculos/i })
      .or(page.getByText(/meus serviços|meus servicos|meu perfil|portal e-cac/i)),

  roleSelection: (page: Page) =>
    page
      .getByText(
        /escolha através de qual opção|escolha atraves de qual opcao|e-cnpj deseja logar|selecione.*(perfil|papel|empresa|representação|representacao|inscrição|inscricao)/i,
      )
      .or(page.getByRole('heading', { name: /escolha|selecione.*(perfil|papel|empresa)/i }))
      .or(page.getByText(/cpf do responsável|cnpj\s*\(empresa contábil\)|cnpj\s*\(empresa contabil\)/i)),

  /** Container da caixa de escolha e-CNPJ (evita botões de outras áreas). */
  roleSelectionContainer: (page: Page) =>
    page
      .locator('form, table, div, fieldset')
      .filter({
        hasText: /e-cnpj deseja logar|cpf do responsável|empresa contábil|empresa contabil/i,
      })
      .first(),

  /** Rádio: CPF do Responsável (certificado da empresa cliente). */
  roleResponsavelLegal: (page: Page) =>
    page
      .getByRole('radio', { name: /cpf do responsável|cpf do responsavel/i })
      .or(page.locator('label').filter({ hasText: /cpf do responsável|cpf do responsavel/i }))
      .or(page.getByText(/cpf do responsável|cpf do responsavel/i)),

  /** Rádio: CNPJ (Empresa Contábil) — certificado do escritório. */
  roleEmpresaContabil: (page: Page) =>
    page
      .getByRole('radio', { name: /cnpj\s*\(\s*empresa contábil\s*\)|cnpj\s*\(\s*empresa contabil\s*\)/i })
      .or(page.locator('label').filter({ hasText: /empresa contábil|empresa contabil/i }))
      .or(page.getByText(/cnpj\s*\(\s*empresa contábil\s*\)|cnpj\s*\(\s*empresa contabil\s*\)/i)),

  /** Rádio: CNPJ não inscrito no RS / MEI. */
  roleCnpjNaoInscritoRs: (page: Page) =>
    page
      .getByRole('radio', { name: /não inscrito no rs|nao inscrito no rs|contribuinte mei/i })
      .or(page.locator('label').filter({ hasText: /não inscrito no rs|nao inscrito no rs|contribuinte mei/i }))
      .or(page.getByText(/não inscrito no rs|nao inscrito no rs/i)),

  /** OK da escolha e-CNPJ — name=Action (onclick direcionaECnpj). Não usar btnConsultaContrib. */
  roleConfirmButton: (page: Page) =>
    page.locator('input[type="button"][name="Action"][value="OK"], input.button[name="Action"][value="OK"]'),

  captchaOrMfa: (page: Page) =>
    page
      .getByText(
        /altcha|captcha|recaptcha|autenticação em dois fatores|verificação em duas etapas|qr\s*code|token|sou\s*humano/i,
      )
      .or(page.locator('altcha-widget, [data-altcha]'))
      .or(page.getByRole('img', { name: /captcha|qr/i })),

  loginForm: (page: Page) =>
    page
      .getByLabel(/usuário|usuario|cpf|login/i)
      .or(page.getByRole('textbox', { name: /usuário|usuario|cpf|login/i })),

  certificateError: (page: Page) =>
    page.getByText(
      /certificado.*(inválido|invalido|expirado|não autorizado|nao autorizado|não aceito|nao aceito)|falha.*(certificado|autenticação|autenticacao)/i,
    ),

  authenticatedMenu: (page: Page) =>
    page
      .getByRole('navigation')
      .or(page.getByRole('menubar'))
      .or(page.getByText(/área restrita|area restrita|painel/i)),
} as const;
