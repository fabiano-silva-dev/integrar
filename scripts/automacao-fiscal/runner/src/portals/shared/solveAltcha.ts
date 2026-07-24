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

  while (Date.now() < deadline) {
    verified = await isAltchaVerified(page);
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

/**
 * Exige payload/token real do ALTCHA.
 * Não confiar só em "Verificado" na UI — o rótulo pode ficar sem token após postback parcial.
 */
export async function isAltchaVerified(page: Page): Promise<boolean> {
  const checkTokenJs = `(() => {
    const tokenOk = (value) => Boolean(value && String(value).length > 20);

    const named = document.querySelectorAll('input[name="altcha"], input[name*="altcha" i], input[type="hidden"][name*="altcha" i]');
    for (const input of named) {
      if (tokenOk(input.value)) return true;
    }

    const widget = document.querySelector('altcha-widget, [data-altcha]');
    if (!widget) return false;

    const roots = [widget];
    if (widget.shadowRoot) roots.push(widget.shadowRoot);
    for (const root of roots) {
      const hidden = root.querySelector?.('input[type="hidden"][name*="altcha" i], input[type="hidden"]');
      if (hidden && tokenOk(hidden.value)) return true;
    }

    // Alguns builds expõem o payload só via getState()/state.
    try {
      const state = typeof widget.getState === 'function' ? widget.getState() : null;
      if (state && typeof state === 'object') {
        const payload = state.payload || state.token || state.value;
        if (tokenOk(payload)) return true;
      }
    } catch (_) {}

    return false;
  })()`;
  return Boolean(await page.evaluate(checkTokenJs).catch(() => false));
}

/** Resolve ALTCHA só se o widget existir e ainda não tiver token válido. */
export async function ensureAltchaSolved(
  page: Page,
  context: AutomationContext,
  options: { timeoutMs?: number; screenshotPrefix?: string; force?: boolean } = {},
): Promise<void> {
  if (!(await isAltchaPresent(page))) {
    return;
  }
  if (!options.force && (await isAltchaVerified(page))) {
    return;
  }
  if (options.force) {
    await page
      .evaluate(`(() => {
        const el = document.querySelector('altcha-widget, [data-altcha]');
        if (!el) return;
        try { el.reset?.(); } catch (_) {}
        try { el.setAttribute?.('data-state', ''); } catch (_) {}
        for (const input of document.querySelectorAll('input[name="altcha"], input[name*="altcha" i]')) {
          input.value = '';
        }
      })()`)
      .catch(() => undefined);
  }
  await solveAltchaInBrowser(page, context, options);
}
