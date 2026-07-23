import type { Page } from 'playwright';

export const NfseEmissorSelectors = {
  cookieAccept: (page: Page) =>
    page.getByRole('button', { name: /aceitar|concordo|ok|continuar|aceito/i }).first(),

  certificateOption: (page: Page) =>
    page
      .getByRole('link', { name: /acesso via certificado digital/i })
      .or(page.locator('a.img-certificado'))
      .or(page.getByRole('link', { name: /certificado digital/i }))
      .first(),

  logout: (page: Page) =>
    page
      .getByRole('link', { name: /sair|logout|encerrar sessão|encerrar sessao/i })
      .or(page.getByRole('button', { name: /sair|logout|encerrar/i }))
      .first(),

  authenticatedHints: (page: Page) =>
    page
      .getByRole('link', { name: /emitir|consultar|dashboard|painel|notas|nfs-e/i })
      .or(page.getByText(/bem-vindo|bem vindo|área do contribuinte|area do contribuinte/i)),

  roleSelection: (page: Page) =>
    page
      .getByText(/selecione.*(perfil|papel|empresa|contribuinte|município|municipio)/i)
      .or(page.getByRole('heading', { name: /selecione.*(perfil|papel|empresa)/i })),

  captchaOrMfa: (page: Page) =>
    page
      .getByText(/captcha|recaptcha|autenticação em dois fatores|verificação em duas etapas|qr\s*code|token/i)
      .or(page.getByRole('img', { name: /captcha|qr/i })),

  loginForm: (page: Page) =>
    page
      .getByPlaceholder(/cpf\/cnpj/i)
      .or(page.getByRole('textbox', { name: /cpf\/cnpj/i }))
      .or(page.getByPlaceholder(/^senha$/i)),

  certificateError: (page: Page) =>
    page.getByText(
      /certificado.*(inválido|invalido|expirado|não autorizado|nao autorizado|não aceito|nao aceito)|falha.*(certificado|autenticação|autenticacao)/i,
    ),

  authenticatedMenu: (page: Page) =>
    page
      .getByRole('navigation')
      .or(page.getByRole('menubar'))
      .or(page.getByText(/portal de gestão nfs-e|emissor nacional/i)),
} as const;
