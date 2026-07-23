import type { Page } from 'playwright';
import type {
  AutomationContext,
  AutomationResult,
  SuccessDetection,
} from '../../automation/types.js';
import { AutomationError } from '../../errors/AutomationError.js';
import { assertUrlAllowed } from '../../security/allowlist.js';
import { sanitizeUrl } from '../../security/sanitize.js';
import {
  attachNavigationObservers,
  dismissCookiesIfPresent,
  ensureHostAllowed,
  requirePage,
  unique,
} from '../shared/navigation.js';
import { isAltchaPresent, solveAltchaInBrowser } from '../shared/solveAltcha.js';
import { classifyPageError, ecacError } from './EcacRsErrors.js';
import { EcacRsSelectors } from './EcacRsSelectors.js';
import { EcacRsSuccessDetector } from './EcacRsSuccessDetector.js';

export type EcacAuthSession = {
  page: Page;
  observedHosts: string[];
  redirects: string[];
  detection: SuccessDetection;
  durationMs: number;
};

export class EcacRsCertificateFlow {
  readonly detector = new EcacRsSuccessDetector();

  async run(context: AutomationContext): Promise<AutomationResult> {
    const session = await this.authenticate(context);
    return {
      status: 'succeeded',
      finalUrl: sanitizeUrl(session.page.url()),
      durationMs: session.durationMs,
      resultData: {
        detection: session.detection,
        observedHosts: unique(session.observedHosts),
        redirects: session.redirects.map(sanitizeUrl),
      },
    };
  }

  /**
   * Realiza login A1 e devolve a página autenticada para fluxos seguintes
   * (ex.: extract-nfe-nfce). Em intervenção, lança AutomationError tipado.
   */
  async authenticate(context: AutomationContext): Promise<EcacAuthSession> {
    if (context.config.ECAC_RS_CERT_ORIGINS.length === 0) {
      throw ecacError(
        'CERTIFICATE_ORIGIN_NOT_CONFIGURED',
        'ECAC_RS_CERT_ORIGINS vazio no modo certificate',
      );
    }

    const page = requirePage(context);
    const started = Date.now();
    const observedUrls: string[] = [];
    const observedHosts: string[] = [];
    const redirects: string[] = [];

    attachNavigationObservers(page, context, observedUrls, observedHosts, redirects);

    const entryUrl = context.config.ECAC_RS_ENTRY_URL;
    assertUrlAllowed(entryUrl, context.config.ECAC_RS_ALLOWED_HOST_SUFFIXES);

    await context.emitEvent({
      level: 'info',
      eventType: 'NAVIGATION_STARTED',
      message: 'Abrindo URL de entrada com certificado configurado',
      metadata: { url: sanitizeUrl(entryUrl) },
    });

    try {
      await page.goto(entryUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      if (/err_bad_ssl_client_auth_cert|ssl_client|client certificate/i.test(message)) {
        throw ecacError('TLS_CLIENT_AUTH_FAILED', message);
      }
      throw ecacError('PORTAL_UNAVAILABLE', message);
    }

    await page.waitForLoadState('load').catch(() => undefined);
    await context.saveScreenshot('01-entry.png');
    await dismissCookiesIfPresent(page, EcacRsSelectors.cookieAccept(page));

    const alreadyOnCertLogin = /LoginCertACRS\.aspx|Login\/LoginCert/i.test(page.url());

    if (!alreadyOnCertLogin) {
      const portalLink = EcacRsSelectors.portalEcacLink(page);
      if (await portalLink.isVisible({ timeout: 10_000 }).catch(() => false)) {
        await context.saveScreenshot('02-portal-link.png');
        await portalLink.click().catch(async () => portalLink.click({ force: true }));
        await page.waitForLoadState('load').catch(() => undefined);
        ensureHostAllowed(page.url(), context.config.ECAC_RS_ALLOWED_HOST_SUFFIXES);
      } else {
        const certLoginUrl = `${context.config.ECAC_RS_CERT_ORIGINS[0]}/Login/LoginCertACRS.aspx?codTpLogin=1`;
        await context.emitEvent({
          level: 'info',
          eventType: 'NAVIGATION_STARTED',
          message: 'Abrindo página de login por certificado digital',
          metadata: { url: sanitizeUrl(certLoginUrl) },
        });
        await page.goto(certLoginUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
        await page.waitForLoadState('load').catch(() => undefined);
        await context.saveScreenshot('02-portal-link.png');
      }
    } else {
      await context.saveScreenshot('02-portal-link.png');
    }

    const certificateOption = EcacRsSelectors.certificateOption(page);
    if (!(await certificateOption.isVisible({ timeout: 15_000 }).catch(() => false))) {
      throw ecacError('PORTAL_LAYOUT_CHANGED', 'Opção de certificado digital não encontrada');
    }
    await context.saveScreenshot('03-certificate-option.png');

    const navigationPromise = page
      .waitForURL(/LoginEcacCert\.aspx|PainelUsuario\.aspx|Receita\//i, { timeout: 45_000 })
      .catch(() => null);

    const viaBrowserLink = page.locator('a').filter({ hasText: /via navegador/i }).first();
    if (await viaBrowserLink.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await viaBrowserLink.click({ force: true });
    } else {
      await certificateOption.click({ force: true });
    }

    await navigationPromise;

    const popup = page.context().pages().find((candidate) => candidate !== page) ?? null;
    const activePage: Page = popup ?? page;
    if (popup) {
      await context.emitEvent({
        level: 'info',
        eventType: 'POPUP_OPENED',
        message: 'Popup de autenticação aberto',
        metadata: { url: sanitizeUrl(popup.url()) },
      });
      try {
        await popup.waitForLoadState('domcontentloaded');
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        if (/err_bad_ssl_client_auth_cert|client certificate/i.test(message)) {
          throw ecacError('TLS_CLIENT_AUTH_FAILED', message);
        }
        throw error;
      }
    }

    await activePage.waitForLoadState('domcontentloaded').catch(() => undefined);
    await activePage.waitForLoadState('load').catch(() => undefined);
    ensureHostAllowed(activePage.url(), context.config.ECAC_RS_ALLOWED_HOST_SUFFIXES);

    const bodyText = (await activePage.locator('body').innerText().catch(() => '')).slice(0, 2_000);
    if (/403\.16|client certificate is either not trusted|certificado.*(inválido|invalido|não confiado)/i.test(bodyText)) {
      throw ecacError('CERTIFICATE_NOT_ACCEPTED', 'Servidor rejeitou o certificado de cliente (403.16)');
    }

    await context.saveScreenshot('04-after-certificate.png', activePage);

    if (await isAltchaPresent(activePage)) {
      await solveAltchaInBrowser(activePage, context, { screenshotPrefix: '04-altcha-login' });
    } else if (
      await EcacRsSelectors.captchaOrMfa(activePage)
        .first()
        .isVisible({ timeout: 1_500 })
        .catch(() => false)
    ) {
      await context.emitEvent({
        level: 'warn',
        eventType: 'MANUAL_CONFIRMATION_DETECTED',
        message: 'CAPTCHA/MFA/QR detectado',
      });
      throw ecacError('MANUAL_CONFIRMATION_REQUIRED', 'Intervenção manual necessária');
    }

    const certError = EcacRsSelectors.certificateError(activePage).first();
    if (await certError.isVisible({ timeout: 1_500 }).catch(() => false)) {
      const text = (await certError.innerText().catch(() => '')) || 'certificado rejeitado';
      throw classifyPageError(text) ?? ecacError('CERTIFICATE_NOT_ACCEPTED', text);
    }

    const detection = await this.detector.detect(activePage);

    if (
      !detection.confirmed &&
      (await EcacRsSelectors.roleSelection(activePage)
        .first()
        .isVisible({ timeout: 1_500 })
        .catch(() => false))
    ) {
      await this.selectLoginRole(activePage, context);
      await activePage.waitForLoadState('domcontentloaded').catch(() => undefined);
      await activePage.waitForLoadState('load').catch(() => undefined);
      await context.saveScreenshot('05-after-role.png', activePage);
    }

    const detectionAfterRole = await this.detector.detect(activePage);

    if (
      !detectionAfterRole.confirmed &&
      (await EcacRsSelectors.roleSelection(activePage)
        .first()
        .isVisible({ timeout: 1_000 })
        .catch(() => false))
    ) {
      await context.emitEvent({
        level: 'warn',
        eventType: 'ROLE_SELECTION_DETECTED',
        message: 'Seleção de papel/perfil ainda presente após tentativa de escolha',
      });
      throw new AutomationError(
        'NEEDS_ROLE_MAPPING',
        'Seleção de papel detectada; não foi possível escolher a opção automaticamente',
        {
          metadata: {
            detection: detectionAfterRole,
            loginPapel: context.params.loginPapel ?? null,
            finalUrl: sanitizeUrl(activePage.url()),
            observedHosts: unique(observedHosts),
            redirects: redirects.map(sanitizeUrl),
          },
        },
      );
    }

    if (!detectionAfterRole.confirmed) {
      await context.emitEvent({
        level: 'warn',
        eventType: 'RUN_FINISHED',
        message: 'Login não confirmado com confiança alta',
        metadata: {
          detection: detectionAfterRole,
          url: sanitizeUrl(activePage.url()),
          errorCode: 'LOGIN_NOT_CONFIRMED',
        },
      });
      throw new AutomationError(
        'LOGIN_NOT_CONFIRMED',
        'Não foi possível confirmar o login com segurança.',
        {
          metadata: {
            detection: detectionAfterRole,
            finalUrl: sanitizeUrl(activePage.url()),
            observedHosts: unique(observedHosts),
            redirects: redirects.map(sanitizeUrl),
          },
        },
      );
    }

    await context.emitEvent({
      level: 'info',
      eventType: 'AUTHENTICATION_CONFIRMED',
      message: 'Autenticação confirmada com alta confiança',
      metadata: { evidence: detectionAfterRole.evidence, confidence: detectionAfterRole.confidence },
    });

    return {
      page: activePage,
      observedHosts,
      redirects,
      detection: detectionAfterRole,
      durationMs: Date.now() - started,
    };
  }

  /**
   * Tela "Escolha através de qual opção do seu e-CNPJ deseja logar-se no e-CAC".
   *
   * HTML real (Sefaz RS / LoginEcacCert):
   * - radios: input[name="opcLogin"] value 1=CPF Responsável, 2=Empresa Contábil, 3=Não inscrito RS
   * - OK: input[type="button"][name="Action"][value="OK"] onclick="direcionaECnpj();"
   *   (há outro OK oculto name=btnConsultaContrib — NÃO usar)
   */
  private async selectLoginRole(page: Page, context: AutomationContext): Promise<void> {
    const raw = typeof context.params.loginPapel === 'string' ? context.params.loginPapel : '';
    const papel =
      raw === 'empresa-contabil' || raw === 'cnpj-nao-inscrito-rs' || raw === 'responsavel-legal'
        ? raw
        : 'responsavel-legal';

    const opcValue =
      papel === 'empresa-contabil' ? '2' : papel === 'cnpj-nao-inscrito-rs' ? '3' : '1';

    await context.emitEvent({
      level: 'info',
      eventType: 'ROLE_SELECTION_STARTED',
      message: `Selecionando papel de login no e-CAC: ${papel} (opcLogin=${opcValue})`,
      metadata: { loginPapel: papel, opcLogin: opcValue },
    });
    await context.saveScreenshot('04b-role-selection.png', page);

    if (context.saveDownload) {
      const html = await page.content().catch(() => '');
      if (html) {
        await context.saveDownload('04b-role-selection.html', Buffer.from(html, 'utf8'), 'text/html');
      }
    }

    const radio = page.locator(`input[type="radio"][name="opcLogin"][value="${opcValue}"]`).first();
    if (!(await radio.count().catch(() => 0))) {
      throw new AutomationError(
        'NEEDS_ROLE_MAPPING',
        `Rádio opcLogin value=${opcValue} não encontrado`,
        { metadata: { loginPapel: papel, opcLogin: opcValue } },
      );
    }
    await radio.check({ force: true }).catch(async () => {
      await radio.click({ force: true });
    });

    await context.emitEvent({
      level: 'info',
      eventType: 'ROLE_OPTION_SELECTED',
      message: `opcLogin=${opcValue} marcado (${papel})`,
      metadata: { loginPapel: papel, opcLogin: opcValue },
    });

    // Preferir o botão Action (dispara direcionaECnpj); nunca o OK oculto btnConsultaContrib.
    const ok = page
      .locator('input[type="button"][name="Action"][value="OK"]')
      .or(page.locator('input.button[name="Action"][value="OK"]'))
      .first();

    if (!(await ok.isVisible({ timeout: 5_000 }).catch(() => false))) {
      throw new AutomationError(
        'PORTAL_LAYOUT_CHANGED',
        'Botão OK (name=Action) da escolha de papel não encontrado',
        { metadata: { loginPapel: papel, opcLogin: opcValue } },
      );
    }

    const urlBefore = page.url();
    await ok.click({ force: true });

    await Promise.race([
      page.waitForURL((url) => {
        const href = url.toString();
        return href !== urlBefore || !/LoginEcacCert\.aspx/i.test(href);
      }, { timeout: 30_000 }),
      page.waitForFunction(
        () => !/e-cnpj deseja logar/i.test(document.body?.innerText ?? ''),
        { timeout: 30_000 },
      ),
    ]).catch(() => null);

    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    await page.waitForLoadState('load').catch(() => undefined);

    const stillThere = await EcacRsSelectors.roleSelection(page)
      .first()
      .isVisible({ timeout: 1_500 })
      .catch(() => false);

    if (stillThere) {
      // Fallback: chama a mesma função do onclick do portal
      await context.emitEvent({
        level: 'warn',
        eventType: 'ROLE_SELECTION_STARTED',
        message: 'OK Action não avançou — tentando direcionaECnpj()',
        metadata: { loginPapel: papel, url: sanitizeUrl(page.url()) },
      });

      await page.evaluate(() => {
        const fn = (window as unknown as { direcionaECnpj?: () => void }).direcionaECnpj;
        if (typeof fn === 'function') {
          fn();
          return;
        }
        const btn = document.querySelector<HTMLInputElement>(
          'input[type="button"][name="Action"][value="OK"]',
        );
        btn?.click();
      });

      await Promise.race([
        page.waitForURL((url) => !/LoginEcacCert\.aspx/i.test(url.toString()), { timeout: 20_000 }),
        page.waitForFunction(
          () => !/e-cnpj deseja logar/i.test(document.body?.innerText ?? ''),
          { timeout: 20_000 },
        ),
      ]).catch(() => null);
      await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    }

    const stillBlocked = await EcacRsSelectors.roleSelection(page)
      .first()
      .isVisible({ timeout: 1_000 })
      .catch(() => false);

    if (stillBlocked) {
      throw new AutomationError(
        'NEEDS_ROLE_MAPPING',
        'Escolha de papel não avançou após OK (direcionaECnpj)',
        { metadata: { loginPapel: papel, opcLogin: opcValue, url: sanitizeUrl(page.url()) } },
      );
    }

    await context.emitEvent({
      level: 'info',
      eventType: 'ROLE_SELECTION_CONFIRMED',
      message: `Papel "${papel}" confirmado`,
      metadata: { loginPapel: papel, opcLogin: opcValue, url: sanitizeUrl(page.url()) },
    });
  }
}
