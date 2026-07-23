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
import { classifyPageError, nfseError } from './NfseEmissorErrors.js';
import { NfseEmissorSelectors } from './NfseEmissorSelectors.js';
import { NfseEmissorSuccessDetector } from './NfseEmissorSuccessDetector.js';

export type NfseAuthSession = {
  page: Page;
  observedHosts: string[];
  redirects: string[];
  detection: SuccessDetection;
  durationMs: number;
};

export class NfseEmissorCertificateFlow {
  readonly detector = new NfseEmissorSuccessDetector();

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
   * Login A1 e devolve a página autenticada para fluxos seguintes (extract-nfse).
   * Em intervenção, lança AutomationError tipado.
   */
  async authenticate(context: AutomationContext): Promise<NfseAuthSession> {
    if (context.config.NFSE_EMISSOR_CERT_ORIGINS.length === 0) {
      throw nfseError(
        'CERTIFICATE_ORIGIN_NOT_CONFIGURED',
        'NFSE_EMISSOR_CERT_ORIGINS vazio no modo certificate',
      );
    }

    const page = requirePage(context);
    const started = Date.now();
    const observedUrls: string[] = [];
    const observedHosts: string[] = [];
    const redirects: string[] = [];

    attachNavigationObservers(page, context, observedUrls, observedHosts, redirects);

    const entryUrl = context.config.NFSE_EMISSOR_ENTRY_URL;
    assertUrlAllowed(entryUrl, context.config.NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES);

    await context.emitEvent({
      level: 'info',
      eventType: 'NAVIGATION_STARTED',
      message: 'Abrindo login NFS-e com certificado configurado',
      metadata: { url: sanitizeUrl(entryUrl) },
    });

    try {
      await page.goto(entryUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      if (/err_bad_ssl_client_auth_cert|ssl_client|client certificate/i.test(message)) {
        throw nfseError('TLS_CLIENT_AUTH_FAILED', message);
      }
      throw nfseError('PORTAL_UNAVAILABLE', message);
    }

    await page.waitForLoadState('load').catch(() => undefined);
    await context.saveScreenshot('01-entry.png');
    await dismissCookiesIfPresent(page, NfseEmissorSelectors.cookieAccept(page));

    const certificateOption = NfseEmissorSelectors.certificateOption(page);
    if (!(await certificateOption.isVisible({ timeout: 15_000 }).catch(() => false))) {
      throw nfseError('PORTAL_LAYOUT_CHANGED', 'Opção de certificado digital não encontrada');
    }
    await context.saveScreenshot('02-certificate-option.png');

    const navigationPromise = page
      .waitForURL(/certificado\.nfse\.gov\.br|EmissorNacional(?!\/Login)/i, { timeout: 60_000 })
      .catch(() => null);

    await certificateOption.click({ force: true }).catch(async () => {
      await certificateOption.click();
    });

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
          throw nfseError('TLS_CLIENT_AUTH_FAILED', message);
        }
        throw error;
      }
    }

    await activePage.waitForLoadState('domcontentloaded').catch(() => undefined);
    await activePage.waitForLoadState('load').catch(() => undefined);

    await activePage
      .waitForURL(/nfse\.gov\.br\/EmissorNacional/i, { timeout: 45_000 })
      .catch(() => undefined);
    await activePage.waitForLoadState('networkidle').catch(() => undefined);

    ensureHostAllowed(activePage.url(), context.config.NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES);

    const bodyText = (await activePage.locator('body').innerText().catch(() => '')).slice(0, 2_000);
    if (
      /403\.16|client certificate is either not trusted|certificado.*(inválido|invalido|não confiado)/i.test(
        bodyText,
      )
    ) {
      throw nfseError('CERTIFICATE_NOT_ACCEPTED', 'Servidor rejeitou o certificado de cliente');
    }

    await context.saveScreenshot('03-after-certificate.png', activePage);

    if (
      await NfseEmissorSelectors.captchaOrMfa(activePage)
        .first()
        .isVisible({ timeout: 1_500 })
        .catch(() => false)
    ) {
      await context.emitEvent({
        level: 'warn',
        eventType: 'MANUAL_CONFIRMATION_DETECTED',
        message: 'CAPTCHA/MFA/QR detectado',
      });
      throw nfseError('MANUAL_CONFIRMATION_REQUIRED', 'Intervenção manual necessária');
    }

    const certError = NfseEmissorSelectors.certificateError(activePage).first();
    if (await certError.isVisible({ timeout: 1_500 }).catch(() => false)) {
      const text = (await certError.innerText().catch(() => '')) || 'certificado rejeitado';
      throw classifyPageError(text) ?? nfseError('CERTIFICATE_NOT_ACCEPTED', text);
    }

    const detection = await this.detector.detect(activePage);

    if (
      !detection.confirmed &&
      (await NfseEmissorSelectors.roleSelection(activePage)
        .first()
        .isVisible({ timeout: 1_500 })
        .catch(() => false))
    ) {
      await context.emitEvent({
        level: 'warn',
        eventType: 'ROLE_SELECTION_DETECTED',
        message: 'Seleção de papel/perfil detectada',
      });
      throw new AutomationError(
        'NEEDS_ROLE_MAPPING',
        'Seleção de papel detectada; mapeamento manual necessário',
        {
          metadata: {
            detection,
            finalUrl: sanitizeUrl(activePage.url()),
            observedHosts: unique(observedHosts),
            redirects: redirects.map(sanitizeUrl),
          },
        },
      );
    }

    if (!detection.confirmed) {
      await context.emitEvent({
        level: 'warn',
        eventType: 'RUN_FINISHED',
        message: 'Login NFS-e não confirmado com confiança alta',
        metadata: {
          detection,
          url: sanitizeUrl(activePage.url()),
          errorCode: 'LOGIN_NOT_CONFIRMED',
        },
      });
      throw new AutomationError(
        'LOGIN_NOT_CONFIRMED',
        'Não foi possível confirmar o login NFS-e com segurança.',
        {
          metadata: {
            detection,
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
      message: 'Autenticação NFS-e confirmada com alta confiança',
      metadata: { evidence: detection.evidence, confidence: detection.confidence },
    });

    return {
      page: activePage,
      observedHosts,
      redirects,
      detection,
      durationMs: Date.now() - started,
    };
  }
}
