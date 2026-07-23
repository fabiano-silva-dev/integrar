import type { Locator, Page } from 'playwright';
import type { AutomationContext } from '../../automation/types.js';
import { AutomationError } from '../../errors/AutomationError.js';
import { isHostAllowed } from '../../security/allowlist.js';
import { originFromUrl, sanitizeUrl } from '../../security/sanitize.js';

export function attachNavigationObservers(
  page: Page,
  context: AutomationContext,
  observedUrls: string[],
  observedHosts: string[],
  redirects: string[],
): void {
  page.on('framenavigated', (frame) => {
    if (frame !== page.mainFrame()) {
      return;
    }
    const url = frame.url();
    if (!url || url === 'about:blank') {
      return;
    }
    observedUrls.push(url);
    try {
      observedHosts.push(new URL(url).hostname);
    } catch {
      // ignore
    }
    void context.emitEvent({
      level: 'info',
      eventType: 'REDIRECT_OBSERVED',
      message: 'Navegação observada',
      metadata: { url: sanitizeUrl(url) },
    });
    redirects.push(url);
  });

  page.on('frameattached', (frame) => {
    void context.emitEvent({
      level: 'debug',
      eventType: 'FRAME_ATTACHED',
      message: 'Frame anexado',
      metadata: { url: sanitizeUrl(frame.url() || 'about:blank') },
    });
  });
}

export async function dismissCookiesIfPresent(
  _page: Page,
  cookieButton: Locator,
): Promise<void> {
  const visible = await cookieButton.isVisible({ timeout: 2_000 }).catch(() => false);
  if (visible) {
    await cookieButton.click().catch(() => undefined);
  }
}

export function ensureHostAllowed(rawUrl: string, suffixes: string[]): void {
  try {
    const url = new URL(rawUrl);
    if (!isHostAllowed(url.hostname, suffixes)) {
      throw new AutomationError('UNAPPROVED_REDIRECT_HOST', `Host não autorizado: ${url.hostname}`, {
        metadata: { host: url.hostname, origin: originFromUrl(rawUrl) },
      });
    }
  } catch (error) {
    if (error instanceof AutomationError) {
      throw error;
    }
  }
}

export function requirePage(context: AutomationContext): Page {
  if (!context.page) {
    throw new AutomationError('UNEXPECTED_ERROR', 'Página Playwright não disponível no contexto');
  }
  return context.page;
}

export function unique(values: string[]): string[] {
  return [...new Set(values)].sort();
}
