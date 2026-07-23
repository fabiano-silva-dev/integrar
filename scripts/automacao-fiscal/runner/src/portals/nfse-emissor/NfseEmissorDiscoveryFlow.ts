import type { AutomationContext, AutomationResult } from '../../automation/types.js';
import {
  assertUrlAllowed,
  collectOrigins,
  isHostAllowed,
} from '../../security/allowlist.js';
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

export class NfseEmissorDiscoveryFlow {
  readonly detector = new NfseEmissorSuccessDetector();

  async run(context: AutomationContext): Promise<AutomationResult> {
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
      message: 'Abrindo URL de entrada do NFS-e Emissor Nacional',
      metadata: { url: sanitizeUrl(entryUrl) },
    });

    try {
      await page.goto(entryUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    } catch (error) {
      throw nfseError(
        'PORTAL_UNAVAILABLE',
        `Falha ao abrir entrada: ${error instanceof Error ? error.message : String(error)}`,
      );
    }

    await page.waitForLoadState('load').catch(() => undefined);
    await context.saveScreenshot('01-entry.png');
    await context.emitEvent({
      level: 'info',
      eventType: 'NAVIGATION_FINISHED',
      message: 'Página de entrada carregada',
      metadata: { url: sanitizeUrl(page.url()) },
    });

    await dismissCookiesIfPresent(page, NfseEmissorSelectors.cookieAccept(page));

    const certificateOption = NfseEmissorSelectors.certificateOption(page);
    const certVisible = await certificateOption.isVisible({ timeout: 15_000 }).catch(() => false);
    if (!certVisible) {
      throw nfseError(
        'PORTAL_LAYOUT_CHANGED',
        'Opção de certificado digital não encontrada na página de login',
      );
    }

    await context.saveScreenshot('02-certificate-option.png');

    const popupPromise = page.context().waitForEvent('page', { timeout: 5_000 }).catch(() => null);
    await certificateOption.click().catch(async () => {
      await certificateOption.click({ force: true });
    });
    const popup = await popupPromise;
    const activePage = popup ?? page;
    if (popup) {
      await context.emitEvent({
        level: 'info',
        eventType: 'POPUP_OPENED',
        message: 'Popup aberto após opção de certificado',
        metadata: { url: sanitizeUrl(popup.url()) },
      });
      await popup.waitForLoadState('domcontentloaded').catch(() => undefined);
    }

    await activePage.waitForLoadState('load').catch(() => undefined);
    ensureHostAllowed(activePage.url(), context.config.NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES);
    await context.saveScreenshot('03-after-certificate.png', activePage);

    const candidateOrigins = collectOrigins([
      ...observedUrls,
      activePage.url(),
      page.url(),
      ...redirects,
      'https://certificado.nfse.gov.br',
      'https://www.nfse.gov.br',
    ]).filter((origin) => {
      try {
        return isHostAllowed(
          new URL(origin).hostname,
          context.config.NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES,
        );
      } catch {
        return false;
      }
    });

    const manual = await NfseEmissorSelectors.captchaOrMfa(activePage)
      .first()
      .isVisible({ timeout: 1_000 })
      .catch(() => false);
    if (manual) {
      throw nfseError('MANUAL_CONFIRMATION_REQUIRED', 'CAPTCHA/MFA detectado no fluxo de discovery');
    }

    const certErrorVisible = await NfseEmissorSelectors.certificateError(activePage)
      .first()
      .isVisible({ timeout: 1_000 })
      .catch(() => false);
    if (certErrorVisible) {
      const text =
        (await NfseEmissorSelectors.certificateError(activePage).first().innerText().catch(() => '')) ||
        'erro de certificado';
      const classified = classifyPageError(text);
      if (classified) {
        throw classified;
      }
    }

    await context.emitEvent({
      level: 'warn',
      eventType: 'CERTIFICATE_REQUEST_SUSPECTED',
      message: 'Fluxo atingiu ponto que provavelmente exige certificado',
      metadata: { candidateOrigins, observedHosts: unique(observedHosts) },
    });

    const detection = await this.detector.detect(activePage);
    if (detection.confirmed) {
      return {
        status: 'succeeded',
        finalUrl: sanitizeUrl(activePage.url()),
        durationMs: Date.now() - started,
        resultData: {
          candidateOrigins,
          observedHosts: unique(observedHosts),
          detection,
        },
      };
    }

    return {
      status: 'needs_intervention',
      finalUrl: sanitizeUrl(activePage.url()),
      errorCode: 'CERTIFICATE_ORIGIN_NOT_CONFIGURED',
      errorMessage:
        'Origens candidatas identificadas; configure NFSE_EMISSOR_CERT_ORIGINS e use o modo certificate',
      durationMs: Date.now() - started,
      resultData: {
        candidateOrigins,
        observedHosts: unique(observedHosts),
        redirects: redirects.map(sanitizeUrl),
        detection,
      },
    };
  }
}
