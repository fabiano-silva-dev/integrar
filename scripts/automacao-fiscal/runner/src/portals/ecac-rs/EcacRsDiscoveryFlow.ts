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
import { EcacRsSelectors } from './EcacRsSelectors.js';
import { classifyPageError, ecacError } from './EcacRsErrors.js';
import { EcacRsSuccessDetector } from './EcacRsSuccessDetector.js';

export class EcacRsDiscoveryFlow {
  readonly detector = new EcacRsSuccessDetector();

  async run(context: AutomationContext): Promise<AutomationResult> {
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
      message: 'Abrindo URL de entrada do e-CAC RS',
      metadata: { url: sanitizeUrl(entryUrl) },
    });

    try {
      await page.goto(entryUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    } catch (error) {
      throw ecacError(
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

    await dismissCookiesIfPresent(page, EcacRsSelectors.cookieAccept(page));

    const portalLink = EcacRsSelectors.portalEcacLink(page);
    const portalVisible = await portalLink.isVisible({ timeout: 10_000 }).catch(() => false);
    if (!portalVisible) {
      throw ecacError(
        'PORTAL_LAYOUT_CHANGED',
        'Link do Portal e-CAC não encontrado na página de entrada',
      );
    }

    await context.saveScreenshot('02-portal-link.png');
    await Promise.all([
      page.waitForLoadState('domcontentloaded').catch(() => undefined),
      portalLink.click(),
    ]).catch(async () => {
      await portalLink.click({ force: true });
    });

    await page.waitForLoadState('load').catch(() => undefined);
    ensureHostAllowed(page.url(), context.config.ECAC_RS_ALLOWED_HOST_SUFFIXES);

    const certificateOption = EcacRsSelectors.certificateOption(page);
    const certVisible = await certificateOption.isVisible({ timeout: 15_000 }).catch(() => false);
    if (!certVisible) {
      throw ecacError(
        'PORTAL_LAYOUT_CHANGED',
        'Opção de certificado digital não encontrada',
      );
    }

    await context.saveScreenshot('03-certificate-option.png');

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
    ensureHostAllowed(activePage.url(), context.config.ECAC_RS_ALLOWED_HOST_SUFFIXES);
    await context.saveScreenshot('04-after-certificate.png', activePage);

    const candidateOrigins = collectOrigins([
      ...observedUrls,
      activePage.url(),
      page.url(),
      ...redirects,
    ]).filter((origin) => {
      try {
        return isHostAllowed(new URL(origin).hostname, context.config.ECAC_RS_ALLOWED_HOST_SUFFIXES);
      } catch {
        return false;
      }
    });

    const manual = await EcacRsSelectors.captchaOrMfa(activePage)
      .first()
      .isVisible({ timeout: 1_000 })
      .catch(() => false);
    if (manual) {
      throw ecacError('MANUAL_CONFIRMATION_REQUIRED', 'CAPTCHA/MFA detectado no fluxo de discovery');
    }

    const certErrorVisible = await EcacRsSelectors.certificateError(activePage)
      .first()
      .isVisible({ timeout: 1_000 })
      .catch(() => false);
    if (certErrorVisible) {
      const text = (await EcacRsSelectors.certificateError(activePage).first().innerText().catch(() => '')) ||
        'erro de certificado';
      const classified = classifyPageError(text);
      if (classified) {
        throw classified;
      }
    }

    // Sem certificado configurado, chegar a origem autenticada indica necessidade de configuração.
    const likelyNeedsCert =
      /certificado|login|auth|autentic/i.test(activePage.url()) ||
      candidateOrigins.length > 0;

    if (likelyNeedsCert) {
      await context.emitEvent({
        level: 'warn',
        eventType: 'CERTIFICATE_REQUEST_SUSPECTED',
        message: 'Fluxo atingiu ponto que provavelmente exige certificado',
        metadata: { candidateOrigins, observedHosts: unique(observedHosts) },
      });

      return {
        status: 'needs_intervention',
        finalUrl: sanitizeUrl(activePage.url()),
        errorCode: 'CERTIFICATE_ORIGIN_NOT_CONFIGURED',
        errorMessage:
          'Origens candidatas identificadas; configure ECAC_RS_CERT_ORIGINS e use o modo certificate',
        durationMs: Date.now() - started,
        resultData: {
          candidateOrigins,
          observedHosts: unique(observedHosts),
          redirects: redirects.map(sanitizeUrl),
        },
      };
    }

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
      errorMessage: 'Discovery concluído sem autenticação; configure origens de certificado',
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

// Re-export para compatibilidade com imports existentes do e-CAC.
export {
  attachNavigationObservers,
  dismissCookiesIfPresent,
  ensureHostAllowed,
  requirePage,
} from '../shared/navigation.js';
