import type { Page } from 'playwright';
import type { AutomationContext } from '../../automation/types.js';
import { AutomationError } from '../../errors/AutomationError.js';

const DEFAULT_TIMEOUT_MS = 180_000;

/**
 * Resolve ALTCHA no próprio browser: dispara verify()/checkbox e espera estado verified.
 * O PoW roda no Chromium — não usa serviço externo de “quebra”.
 */
export async function solveAltchaInBrowser(
  page: Page,
  context: AutomationContext,
  options: { timeoutMs?: number; screenshotPrefix?: string } = {},
): Promise<void> {
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
  const prefix = options.screenshotPrefix ?? 'altcha';

  await context.emitEvent({
    level: 'info',
    eventType: 'MANUAL_CONFIRMATION_DETECTED',
    message: 'ALTCHA detectado — resolvendo automaticamente no browser',
    metadata: { timeoutMs },
  });
  await context.saveScreenshot(`${prefix}-01-before.png`, page);

  const widget = page.locator('altcha-widget, [data-altcha]').first();
  const visible = await widget.isVisible({ timeout: 3_000 }).catch(() => false);

  if (visible) {
    await widget.scrollIntoViewIfNeeded().catch(() => undefined);
    await widget.click({ force: true }).catch(() => undefined);

    await widget
      .evaluate((el) => {
        const host = el as {
          verify?: () => void | Promise<void>;
          shadowRoot?: { querySelector: (sel: string) => { click: () => void } | null } | null;
          querySelector?: (sel: string) => { click: () => void } | null;
        };
        try {
          void host.verify?.();
        } catch {
          // ignore
        }
        const root = host.shadowRoot ?? host;
        const checkbox = root.querySelector?.(
          'input[type="checkbox"], #checkbox, .checkbox, [role="checkbox"], label',
        );
        checkbox?.click();
      })
      .catch(() => undefined);
  } else {
    const fallback = page
      .getByRole('checkbox', { name: /altcha|humano|robot|desafio|verifica/i })
      .or(page.getByText(/\baltcha\b|sou\s*humano|não\s*sou\s*um\s*robô/i))
      .first();
    if (await fallback.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await fallback.click({ force: true }).catch(() => undefined);
    }
  }

  const deadline = Date.now() + timeoutMs;
  let verified = false;
  // String evita tipagem DOM no tsc do runner (lib sem "dom").
  const checkVerifiedJs = `(() => {
    const el = document.querySelector('altcha-widget, [data-altcha]');
    if (el) {
      const state =
        (typeof el.getState === 'function' ? el.getState() : null) ||
        el.getAttribute('data-state') ||
        el.getAttribute('state');
      if (String(state).toLowerCase() === 'verified') return true;
      const hidden = el.querySelector('input[type="hidden"][name*="altcha" i]');
      if (hidden && hidden.value && hidden.value.length > 20) return true;
    }
    const named = document.querySelector('input[name="altcha"], input[name*="altcha" i]');
    return Boolean(named && named.value && named.value.length > 20);
  })()`;

  while (Date.now() < deadline) {
    verified = Boolean(await page.evaluate(checkVerifiedJs).catch(() => false));
    if (verified) {
      break;
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }

  await context.saveScreenshot(`${prefix}-02-after.png`, page);

  if (!verified) {
    throw new AutomationError(
      'MANUAL_CONFIRMATION_REQUIRED',
      `ALTCHA não atingiu estado verified em ${timeoutMs}ms`,
      { metadata: { timeoutMs } },
    );
  }

  await context.emitEvent({
    level: 'info',
    eventType: 'NAVIGATION_FINISHED',
    message: 'ALTCHA verificado com sucesso no browser',
  });
}

export async function isAltchaPresent(page: Page): Promise<boolean> {
  return page
    .locator('altcha-widget, [data-altcha]')
    .or(page.getByText(/\baltcha\b/i))
    .first()
    .isVisible({ timeout: 1_500 })
    .catch(() => false);
}

export async function isAltchaVerified(page: Page): Promise<boolean> {
  const checkVerifiedJs = `(() => {
    const el = document.querySelector('altcha-widget, [data-altcha]');
    if (el) {
      const state =
        (typeof el.getState === 'function' ? el.getState() : null) ||
        el.getAttribute('data-state') ||
        el.getAttribute('state');
      if (String(state).toLowerCase() === 'verified') return true;
      const hidden = el.querySelector('input[type="hidden"][name*="altcha" i]');
      if (hidden && hidden.value && hidden.value.length > 20) return true;
    }
    if (document.body?.innerText && /\\bVerificado\\b/i.test(document.body.innerText)
      && /\\bALTCHA\\b/i.test(document.body.innerText)) {
      return true;
    }
    const named = document.querySelector('input[name="altcha"], input[name*="altcha" i]');
    return Boolean(named && named.value && named.value.length > 20);
  })()`;
  return Boolean(await page.evaluate(checkVerifiedJs).catch(() => false));
}

/** Resolve ALTCHA só se o widget existir e ainda não estiver verified. */
export async function ensureAltchaSolved(
  page: Page,
  context: AutomationContext,
  options: { timeoutMs?: number; screenshotPrefix?: string } = {},
): Promise<void> {
  if (!(await isAltchaPresent(page))) {
    return;
  }
  if (await isAltchaVerified(page)) {
    return;
  }
  await solveAltchaInBrowser(page, context, options);
}
