import type { Frame, Page } from 'playwright';
import type { AutomationContext } from '../../automation/types.js';
import { AutomationError } from '../../errors/AutomationError.js';

const CAPSOLVER_CREATE = 'https://api.capsolver.com/createTask';
const CAPSOLVER_RESULT = 'https://api.capsolver.com/getTaskResult';

/**
 * Resolve hCaptcha do portal da NF-e.
 *
 * 1) Tenta só clicar no checkbox "Sou humano" (muitas vezes basta).
 * 2) Se abrir desafio de imagens / não gerar token, usa CapSolver se houver API key.
 */
export async function ensureHCaptchaSolved(
  page: Page,
  context: AutomationContext,
  options: { screenshotPrefix?: string; websiteUrl?: string } = {},
): Promise<void> {
  const prefix = options.screenshotPrefix ?? 'hcaptcha';
  const apiKey = (context.config.CAPSOLVER_API_KEY ?? '').trim();

  if (await hasHCaptchaToken(page)) {
    return;
  }

  const hasWidget = await page
    .locator('iframe[src*="hcaptcha"], [data-sitekey], .h-captcha, #hcaptcha')
    .first()
    .isVisible({ timeout: 4_000 })
    .catch(() => false);

  if (!hasWidget) {
    if (await hasHCaptchaToken(page)) {
      return;
    }
    // Sem widget visível — segue; o Continuar vai falhar se o portal exigir.
    return;
  }

  await context.emitEvent({
    level: 'info',
    eventType: 'MANUAL_CONFIRMATION_DETECTED',
    message: 'hCaptcha detectado — tentando clicar no checkbox "Sou humano"',
  });
  await context.saveScreenshot(`${prefix}-01-before.png`, page);

  const clicked = await tryClickCheckbox(page);
  if (clicked) {
    const ok = await waitForTokenOrChecked(page, 12_000);
    await context.saveScreenshot(`${prefix}-02-after-click.png`, page);
    if (ok) {
      await context.emitEvent({
        level: 'info',
        eventType: 'AUTHENTICATION_CONFIRMED',
        message: 'hCaptcha aceito com clique no checkbox',
      });
      return;
    }
  }

  // Desafio de imagens / clique insuficiente.
  if (apiKey === '') {
    throw new AutomationError(
      'MANUAL_CONFIRMATION_REQUIRED',
      'O hCaptcha pediu confirmação extra (não bastou clicar no quadradinho). ' +
        'Opcional: configure CAPSOLVER_API_KEY no .env para resolver o desafio de imagens automaticamente.',
    );
  }

  await context.emitEvent({
    level: 'info',
    eventType: 'MANUAL_CONFIRMATION_DETECTED',
    message: 'Clique no checkbox insuficiente — resolvendo desafio via CapSolver',
  });

  const sitekey = await extractSitekey(page);
  if (!sitekey) {
    throw new AutomationError(
      'MANUAL_CONFIRMATION_REQUIRED',
      'Não foi possível localizar o sitekey do hCaptcha na página da NF-e.',
    );
  }

  const websiteURL = options.websiteUrl ?? page.url();
  const token = await solveWithCapSolver(apiKey, websiteURL, sitekey);
  await injectHCaptchaToken(page, token);
  await context.saveScreenshot(`${prefix}-03-injected.png`, page);

  await context.emitEvent({
    level: 'info',
    eventType: 'AUTHENTICATION_CONFIRMED',
    message: 'hCaptcha resolvido via CapSolver',
    metadata: { sitekeyPrefix: sitekey.slice(0, 8) },
  });
}

async function tryClickCheckbox(page: Page): Promise<boolean> {
  // Checkbox fica dentro do iframe do hCaptcha.
  const frames = page.frames();
  for (const frame of frames) {
    if (!/hcaptcha/i.test(frame.url())) {
      continue;
    }
    if (await clickCheckboxInFrame(frame)) {
      return true;
    }
  }

  // Fallback: clique no container / texto "Sou humano" na página.
  const candidates = [
    page.frameLocator('iframe[src*="hcaptcha.com/captcha"]').first().locator('#checkbox'),
    page.frameLocator('iframe[src*="hcaptcha"]').first().locator('[role="checkbox"]'),
    page.getByText(/^Sou humano$/i).first(),
    page.locator('.h-captcha, #hcaptcha, [data-sitekey]').first(),
  ];

  for (const loc of candidates) {
    const visible = await loc.isVisible({ timeout: 1_500 }).catch(() => false);
    if (!visible) {
      continue;
    }
    await loc.click({ force: true, timeout: 5_000 }).catch(() => undefined);
    return true;
  }

  return false;
}

async function clickCheckboxInFrame(frame: Frame): Promise<boolean> {
  const checkbox = frame
    .locator('#checkbox, [role="checkbox"], .check, #anchor-state')
    .first();
  const visible = await checkbox.isVisible({ timeout: 2_000 }).catch(() => false);
  if (!visible) {
    return false;
  }
  await checkbox.click({ force: true, timeout: 5_000 }).catch(() => undefined);
  return true;
}

async function waitForTokenOrChecked(page: Page, timeoutMs: number): Promise<boolean> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (await hasHCaptchaToken(page)) {
      return true;
    }
    // Checkbox marcado (aria-checked) sem desafio aberto.
    for (const frame of page.frames()) {
      if (!/hcaptcha/i.test(frame.url())) {
        continue;
      }
      const checked = await frame
        .locator('[aria-checked="true"], #checkbox[aria-checked="true"], .check[aria-checked="true"]')
        .first()
        .isVisible()
        .catch(() => false);
      if (checked) {
        // Aguarda token aparecer após o check.
        await sleep(1_500);
        if (await hasHCaptchaToken(page)) {
          return true;
        }
      }
      // Desafio de imagens aberto → clique sozinho não resolve.
      const challenge = await frame
        .locator('.challenge-container, .task-image, [class*="challenge"]')
        .first()
        .isVisible()
        .catch(() => false);
      if (challenge) {
        return false;
      }
    }
    await sleep(400);
  }
  return await hasHCaptchaToken(page);
}

async function hasHCaptchaToken(page: Page): Promise<boolean> {
  const value = await page
    .locator('textarea[name="h-captcha-response"], textarea[name="g-recaptcha-response"]')
    .first()
    .inputValue()
    .catch(() => '');
  return typeof value === 'string' && value.length > 20;
}

async function extractSitekey(page: Page): Promise<string | null> {
  const fromAttr = await page
    .locator('[data-sitekey]')
    .first()
    .getAttribute('data-sitekey')
    .catch(() => null);
  if (fromAttr && fromAttr.length > 10) {
    return fromAttr;
  }

  const fromDom = await page.evaluate(() => {
    type DocLike = {
      querySelector: (sel: string) => { dataset?: { sitekey?: string }; src?: string } | null;
    };
    const doc = (globalThis as unknown as { document: DocLike }).document;
    const el = doc.querySelector('[data-sitekey]');
    if (el?.dataset?.sitekey) {
      return el.dataset.sitekey;
    }
    const iframe = doc.querySelector('iframe[src*="hcaptcha"]');
    if (iframe?.src) {
      try {
        const url = new URL(iframe.src);
        return url.searchParams.get('sitekey');
      } catch {
        return null;
      }
    }
    return null;
  });

  return fromDom && fromDom.length > 10 ? fromDom : null;
}

async function solveWithCapSolver(apiKey: string, websiteURL: string, websiteKey: string): Promise<string> {
  const createRes = await fetch(CAPSOLVER_CREATE, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      clientKey: apiKey,
      task: {
        type: 'HCaptchaTaskProxyLess',
        websiteURL,
        websiteKey,
      },
    }),
  });

  const createJson = (await createRes.json()) as {
    errorId?: number;
    errorDescription?: string;
    taskId?: string;
  };

  if (createJson.errorId && createJson.errorId !== 0) {
    throw new AutomationError(
      'MANUAL_CONFIRMATION_REQUIRED',
      `CapSolver recusou a tarefa: ${createJson.errorDescription ?? 'erro desconhecido'}`,
    );
  }

  const taskId = createJson.taskId;
  if (!taskId) {
    throw new AutomationError('MANUAL_CONFIRMATION_REQUIRED', 'CapSolver não retornou taskId.');
  }

  const deadline = Date.now() + 120_000;
  while (Date.now() < deadline) {
    await sleep(3_000);
    const resultRes = await fetch(CAPSOLVER_RESULT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ clientKey: apiKey, taskId }),
    });
    const resultJson = (await resultRes.json()) as {
      errorId?: number;
      errorDescription?: string;
      status?: string;
      solution?: { gRecaptchaResponse?: string; token?: string };
    };

    if (resultJson.errorId && resultJson.errorId !== 0) {
      throw new AutomationError(
        'MANUAL_CONFIRMATION_REQUIRED',
        `CapSolver falhou: ${resultJson.errorDescription ?? 'erro desconhecido'}`,
      );
    }

    if (resultJson.status === 'ready') {
      const token = resultJson.solution?.gRecaptchaResponse || resultJson.solution?.token;
      if (token && token.length > 20) {
        return token;
      }
      throw new AutomationError('MANUAL_CONFIRMATION_REQUIRED', 'CapSolver retornou solução vazia.');
    }
  }

  throw new AutomationError('TIMEOUT', 'Timeout aguardando resolução do hCaptcha no CapSolver.');
}

async function injectHCaptchaToken(page: Page, token: string): Promise<void> {
  await page.evaluate((value) => {
    type El = {
      name?: string;
      value?: string;
      style?: { display?: string };
      dispatchEvent?: (e: Event) => void;
      getAttribute?: (n: string) => string | null;
    };
    type Doc = {
      querySelector: (sel: string) => El | null;
      querySelectorAll: (sel: string) => ArrayLike<El>;
      createElement: (tag: string) => El;
      body: { appendChild: (el: El) => void };
    };
    const doc = (globalThis as unknown as { document: Doc }).document;
    const win = globalThis as unknown as {
      hcaptcha?: { setResponse?: (t: string) => void };
      [key: string]: unknown;
    };

    const ensureTextarea = (name: string) => {
      let el = doc.querySelector(`textarea[name="${name}"]`);
      if (!el) {
        el = doc.createElement('textarea');
        el.name = name;
        if (el.style) el.style.display = 'none';
        doc.body.appendChild(el);
      }
      el.value = value;
      el.dispatchEvent?.(new Event('input', { bubbles: true }));
      el.dispatchEvent?.(new Event('change', { bubbles: true }));
    };

    ensureTextarea('h-captcha-response');
    ensureTextarea('g-recaptcha-response');

    try {
      win.hcaptcha?.setResponse?.(value);
    } catch {
      // ignore
    }

    const nodes = Array.from(doc.querySelectorAll('[data-callback]'));
    for (const node of nodes) {
      const cbName = node.getAttribute?.('data-callback');
      if (!cbName) continue;
      const fn = win[cbName];
      if (typeof fn === 'function') {
        try {
          (fn as (t: string) => void)(value);
        } catch {
          // ignore
        }
      }
    }
  }, token);
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
