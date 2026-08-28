import { readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import type { Locator, Page } from 'playwright';
import { ZodError } from 'zod';
import type { AutomationContext, AutomationResult } from '../../automation/types.js';
import { AutomationError } from '../../errors/AutomationError.js';
import { assertUrlAllowed } from '../../security/allowlist.js';
import { sanitizeUrl } from '../../security/sanitize.js';
import { ensureHostAllowed, unique } from '../shared/navigation.js';
import { ensureAltchaSolved } from '../shared/solveAltcha.js';
import { EcacRsCertificateFlow } from './EcacRsCertificateFlow.js';
import {
  EcacRsExtractSelectors,
  inventoryTextInputs,
  resolveExtractFormScope,
  type FormScope,
} from './EcacRsExtractSelectors.js';
import { EcacRsSelectors } from './EcacRsSelectors.js';
import { ecacError } from './EcacRsErrors.js';
import {
  MODELO_LABELS,
  OPERACAO_LABELS,
  isoToBrDate,
  parseExtractNfeNfceParams,
  type ExtractNfeNfceParams,
} from './extractNfeNfceParams.js';

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

const EXTRACT_PATH = '/nfe/nfe-ics-ext.aspx';

export class EcacRsExtractNfeNfceFlow {
  constructor(private readonly certificate = new EcacRsCertificateFlow()) {}

  async run(context: AutomationContext): Promise<AutomationResult> {
    const started = Date.now();
    let params: ExtractNfeNfceParams;
    try {
      params = parseExtractNfeNfceParams(context.params);
    } catch (error) {
      const message =
        error instanceof ZodError
          ? error.issues.map((issue) => issue.message).join('; ')
          : error instanceof Error
            ? error.message
            : String(error);
      throw new AutomationError('UNEXPECTED_ERROR', `Params extract-nfe-nfce inválidos: ${message}`, {
        metadata: { params: context.params },
      });
    }

    if (context.mode !== 'certificate') {
      throw new AutomationError(
        'UNEXPECTED_ERROR',
        `extract-nfe-nfce requer modo certificate (recebido: ${context.mode})`,
      );
    }

    const auth = await this.certificate.authenticate(context);
    const page = auth.page;

    await this.dismissPainelModal(context, page);

    const extractUrl = this.resolveExtractUrl(context);
    assertUrlAllowed(extractUrl, context.config.ECAC_RS_ALLOWED_HOST_SUFFIXES);

    await context.emitEvent({
      level: 'info',
      eventType: 'NAVIGATION_STARTED',
      message: 'Abrindo formulário de extrato NF-e / NFC-e',
      metadata: { url: sanitizeUrl(extractUrl) },
    });

    await page.goto(extractUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    await page.waitForLoadState('load').catch(() => undefined);
    ensureHostAllowed(page.url(), context.config.ECAC_RS_ALLOWED_HOST_SUFFIXES);
    await context.saveScreenshot('10-extract-form.png', page);

    const scope = await resolveExtractFormScope(page);
    await EcacRsExtractSelectors.formMarker(scope)
      .waitFor({ state: 'visible', timeout: 15_000 })
      .catch(() => undefined);
    // Garante que o input IE já entrou no DOM (ASP.NET pós-load).
    await EcacRsExtractSelectors.ieInput(scope)
      .waitFor({ state: 'visible', timeout: 10_000 })
      .catch(() => undefined);

    await ensureAltchaSolved(page, context, { screenshotPrefix: '10-altcha-prefill' });

    await this.fillForm(context, page, scope, params);
    await context.saveScreenshot('11-extract-filled.png', page);

    await ensureAltchaSolved(page, context, { screenshotPrefix: '11-altcha-filled' });

    let consultaDialog = await this.submitConsulta(context, page, scope);
    await context.saveScreenshot('12-after-consultar.png', page);

    if (this.isEmptyResultDialog(consultaDialog)) {
      return this.emptyExtractSuccess(context, auth, params, started, consultaDialog);
    }

    const resultados = EcacRsExtractSelectors.resultadosMarker(scope);
    if (!(await resultados.isVisible({ timeout: 12_000 }).catch(() => false))) {
      // ALTCHA pode invalidar o post — resolve e reenvia.
      if (await this.isCaptchaVisible(page)) {
        const challenge = EcacRsSelectors.captchaOrMfa(page).first();
        await challenge.click({ force: true }).catch(() => undefined);
        await sleep(1_000);
      }
      await ensureAltchaSolved(page, context, { screenshotPrefix: '12b-altcha-retry' });
      consultaDialog = await this.submitConsulta(context, page, scope, { viaEvaluate: true });
      await context.saveScreenshot('12c-after-consultar-retry.png', page);

      if (this.isEmptyResultDialog(consultaDialog)) {
        return this.emptyExtractSuccess(context, auth, params, started, consultaDialog);
      }
    }

    await resultados.waitFor({ state: 'visible', timeout: 45_000 }).catch(() => undefined);
    await context.saveScreenshot('13-resultados.png', page);

    const aindaNosFiltros = await EcacRsExtractSelectors.filtrosMarker(scope)
      .isVisible({ timeout: 1_000 })
      .catch(() => false);
    const temResultados = await resultados.isVisible({ timeout: 1_000 }).catch(() => false);
    const portalHint = await this.readPortalHints(scope);

    if (!temResultados) {
      throw ecacError(
        'PORTAL_LAYOUT_CHANGED',
        aindaNosFiltros
          ? `Consulta não avançou dos Filtros (Consultar não gerou Resultados).${portalHint}`
          : `Tela de Resultados do extrato não apareceu após Consultar.${portalHint}`,
        { params, url: sanitizeUrl(page.url()), portalHint, aindaNosFiltros },
      );
    }

    const exportar = EcacRsExtractSelectors.exportarButton(scope);
    if (!(await exportar.isVisible({ timeout: 15_000 }).catch(() => false))) {
      const vazio = await this.hasEmptyResultMessage(scope);
      throw ecacError(
        'PORTAL_LAYOUT_CHANGED',
        vazio
          ? `Consulta sem notas no período — botão de exportação não disponível.${portalHint}`
          : `Resultados visíveis, mas botão/link de exportar extrato (.txt) não encontrado.${portalHint}`,
        { params, url: sanitizeUrl(page.url()), portalHint, vazio },
      );
    }

    const downloadPromise = page.waitForEvent('download', { timeout: 90_000 }).catch(() => null);
    await exportar.scrollIntoViewIfNeeded().catch(() => undefined);
    await exportar.click({ force: true });
    let download = await downloadPromise;

    if (!download) {
      await ensureAltchaSolved(page, context, { screenshotPrefix: '14-altcha-export' });
      const retry = page.waitForEvent('download', { timeout: 90_000 }).catch(() => null);
      if (await exportar.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await exportar.click({ force: true });
      }
      download = await retry;
    }

    if (!download) {
      throw ecacError(
        'PORTAL_LAYOUT_CHANGED',
        'Extrato consultado, mas download .txt não iniciou ao clicar em Gerar arquivo Texto.',
        {
          params,
          url: sanitizeUrl(page.url()),
          filled: true,
          durationMs: Date.now() - started,
        },
      );
    }

    const suggested = download.suggestedFilename() || 'extrato-nfe-nfce.txt';
    const tempPath = join(tmpdir(), `ecac-extract-${context.runId}-${Date.now()}.bin`);
    await download.saveAs(tempPath);
    const buffer = await readFile(tempPath);
    await rm(tempPath, { force: true }).catch(() => undefined);

    if (context.saveDownload) {
      await context.saveDownload(suggested, buffer, 'text/plain');
    }

    await context.emitEvent({
      level: 'info',
      eventType: 'RUN_FINISHED',
      message: `Download do extrato concluído: ${suggested}`,
      metadata: { filename: suggested, bytes: buffer.byteLength },
    });

    return {
      status: 'succeeded',
      finalUrl: sanitizeUrl(page.url()),
      durationMs: Date.now() - started,
      resultData: {
        params,
        downloadFilename: suggested,
        downloadBytes: buffer.byteLength,
        detection: auth.detection,
        observedHosts: unique(auth.observedHosts),
        redirects: auth.redirects.map(sanitizeUrl),
      },
    };
  }

  private resolveExtractUrl(context: AutomationContext): string {
    const origin = context.config.ECAC_RS_CERT_ORIGINS[0] ?? 'https://www.sefaz.rs.gov.br';
    return new URL(EXTRACT_PATH, origin.endsWith('/') ? origin : `${origin}/`).toString();
  }

  private async dismissPainelModal(context: AutomationContext, page: Page): Promise<void> {
    const dismiss = EcacRsExtractSelectors.painelModalDismiss(page);
    if (await dismiss.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await dismiss.click({ force: true }).catch(() => undefined);
      await context.saveScreenshot('05-painel-modal-dismissed.png', page);
    }
  }

  private async isCaptchaVisible(page: Page): Promise<boolean> {
    return EcacRsSelectors.captchaOrMfa(page)
      .first()
      .isVisible({ timeout: 1_500 })
      .catch(() => false);
  }

  /** Clica Consultar de forma a disparar postback ASP.NET. Retorna texto de dialog, se houver. */
  private async submitConsulta(
    context: AutomationContext,
    page: Page,
    scope: FormScope,
    options: { viaEvaluate?: boolean } = {},
  ): Promise<string | null> {
    const consultar = EcacRsExtractSelectors.consultarButton(scope);
    if (!(await consultar.isVisible({ timeout: 5_000 }).catch(() => false))) {
      throw ecacError(
        'PORTAL_LAYOUT_CHANGED',
        'Botão Consultar não encontrado no formulário de extrato',
        { url: sanitizeUrl(page.url()) },
      );
    }

    // Garante token real; só força reset se ainda não houver payload válido.
    const hadToken = await page
      .evaluate(`(() => {
        const inputs = document.querySelectorAll('input[name="altcha"], input[name*="altcha" i]');
        for (const input of inputs) {
          if (input.value && input.value.length > 20) return true;
        }
        return false;
      })()`)
      .catch(() => false);

    await ensureAltchaSolved(page, context, {
      screenshotPrefix: options.viaEvaluate ? '12b-altcha-before-click' : '12-altcha-before-click',
      force: !hadToken,
    });

    const buttonMeta = await consultar
      .evaluate((el) => {
        const input = el as {
          outerHTML?: string;
          type?: string;
          name?: string;
          disabled?: boolean;
          getAttribute?: (n: string) => string | null;
          form?: { id?: string; name?: string; getAttribute?: (n: string) => string | null } | null;
        };
        return {
          type: input.type || null,
          name: input.name || null,
          disabled: Boolean(input.disabled),
          onclick: input.getAttribute?.('onclick') || null,
          formaction: input.getAttribute?.('formaction') || null,
          formOnsubmit: input.form?.getAttribute?.('onsubmit') || null,
          formId: input.form?.id || input.form?.name || null,
          html: (input.outerHTML || '').slice(0, 400),
        };
      })
      .catch(() => null);

    const hasToken = await page
      .evaluate(`(() => {
        const inputs = document.querySelectorAll('input[name="altcha"], input[name*="altcha" i]');
        for (const input of inputs) {
          if (input.value && input.value.length > 20) return true;
        }
        return false;
      })()`)
      .catch(() => false);

    await context.emitEvent({
      level: 'info',
      eventType: 'NAVIGATION_STARTED',
      message: 'Enviando Consultar do extrato',
      metadata: {
        viaEvaluate: Boolean(options.viaEvaluate),
        altchaToken: Boolean(hasToken),
        button: buttonMeta,
      },
    });

    await consultar.scrollIntoViewIfNeeded().catch(() => undefined);

    const seenRequests: string[] = [];
    const onRequest = (request: { method: () => string; url: () => string }): void => {
      const url = request.url();
      if (/sefaz\.rs\.gov\.br/i.test(url)) {
        seenRequests.push(`${request.method()} ${url}`.slice(0, 180));
      }
    };
    page.on('request', onRequest);

    let dialogMessage: string | null = null;
    page.once('dialog', (dialog) => {
      dialogMessage = dialog.message();
      void context.emitEvent({
        level: 'warn',
        eventType: 'LAYOUT_CHANGED',
        message: `Dialog do portal ao Consultar: ${dialog.message()}`,
      });
      void dialog.accept().catch(() => undefined);
    });

    const postPromise = page
      .waitForResponse(
        (response) =>
          /nfe-ics-ext/i.test(response.url()) &&
          ['POST', 'GET'].includes(response.request().method()) &&
          response.status() < 500,
        { timeout: 20_000 },
      )
      .catch(() => null);

    if (options.viaEvaluate) {
      await consultar
        .evaluate((el) => {
          const input = el as {
            click: () => void;
            form?: { requestSubmit?: (submitter?: unknown) => void };
            type?: string;
          };
          input.click();
          if (input.type === 'submit' && input.form?.requestSubmit) {
            input.form.requestSubmit(el);
          }
        })
        .catch(async () => {
          await consultar.click({ force: true });
        });
    } else {
      await consultar.click({ timeout: 5_000 }).catch(async () => {
        await consultar.click({ force: true });
      });
    }

    const post = await postPromise;
    // Dialog pode chegar logo após o POST — dá um respiro.
    await sleep(400);
    page.off('request', onRequest);

    if (dialogMessage && !this.isEmptyResultDialog(dialogMessage)) {
      throw ecacError(
        'PORTAL_LAYOUT_CHANGED',
        `Portal rejeitou a consulta: ${dialogMessage}`,
        { url: sanitizeUrl(page.url()), button: buttonMeta, dialogMessage },
      );
    }

    if (dialogMessage) {
      return dialogMessage;
    }

    if (!post) {
      await context.emitEvent({
        level: 'warn',
        eventType: 'LAYOUT_CHANGED',
        message: 'Consultar não gerou POST — tentando submit nativo / __doPostBack',
        metadata: { seenRequests: seenRequests.slice(-8), button: buttonMeta },
      });

      const postbackName =
        buttonMeta && typeof buttonMeta === 'object' && 'name' in buttonMeta
          ? String((buttonMeta as { name?: string | null }).name || '')
          : '';

      await scope
        .evaluate(
          `((name) => {
            const w = window;
            if (name && typeof w.__doPostBack === 'function') {
              w.__doPostBack(name, '');
              return 'doPostBack';
            }
            const btn = document.querySelector(
              'input[type="submit"][value="Consultar"], input[type="button"][value="Consultar"]'
            );
            if (btn && btn.name && typeof w.__doPostBack === 'function') {
              w.__doPostBack(btn.name, '');
              return 'doPostBack-btn';
            }
            if (btn && btn.form) {
              if (typeof btn.form.requestSubmit === 'function') {
                btn.form.requestSubmit(btn);
                return 'requestSubmit';
              }
              btn.click();
              return 'click';
            }
            const form = document.querySelector('form');
            form?.submit?.();
            return form ? 'form.submit' : 'none';
          })(${JSON.stringify(postbackName)})`,
        )
        .catch(() => 'error');

      await page
        .waitForResponse(
          (response) => /nfe-ics-ext/i.test(response.url()),
          { timeout: 15_000 },
        )
        .catch(() => null);
    } else {
      await context.emitEvent({
        level: 'info',
        eventType: 'NAVIGATION_FINISHED',
        message: `Consultar gerou navegação ${post.request().method()} ${post.status()}`,
      });
    }

    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    await sleep(2_000);
    return dialogMessage;
  }

  private isEmptyResultDialog(message: string | null | undefined): boolean {
    if (!message) {
      return false;
    }
    return /n[aã]o\s+foram\s+localizad|nenhuma\s+nfe|sem\s+notas|n[aã]o\s+foram\s+encontrad/i.test(
      message,
    );
  }

  private async emptyExtractSuccess(
    context: AutomationContext,
    auth: Awaited<ReturnType<EcacRsCertificateFlow['authenticate']>>,
    params: ExtractNfeNfceParams,
    started: number,
    dialogMessage: string | null,
  ): Promise<AutomationResult> {
    // Não gravar .txt placeholder — evita falso erro na importação PHP.
    await context.emitEvent({
      level: 'info',
      eventType: 'RUN_FINISHED',
      message: 'Nenhuma NF-e/NFC-e encontrada com o filtro informado.',
      metadata: { empty: true, dialogMessage, params },
    });

    return {
      status: 'succeeded',
      finalUrl: sanitizeUrl(auth.page.url()),
      durationMs: Date.now() - started,
      resultData: {
        params,
        empty: true,
        dialogMessage,
        quantidade: 0,
        message: 'Nenhuma NF-e/NFC-e encontrada com o filtro informado.',
        detection: auth.detection,
        observedHosts: unique(auth.observedHosts),
        redirects: auth.redirects.map(sanitizeUrl),
      },
    };
  }

  private async readPortalHints(scope: FormScope): Promise<string> {
    const js = `(() => {
      const texts = [];
      const nodes = document.querySelectorAll(
        '.erro, .error, .alert, .validation-summary-errors, font[color="red"], font[color="#ff0000"], span[style*="color:red"], span[style*="color: red"]'
      );
      for (const n of nodes) {
        const t = (n.textContent || '').replace(/\\s+/g, ' ').trim();
        // Ignora rótulos fixos do formulário (ex.: "Máx. 31 dias").
        if (/m[aá]x\\.?\\s*31\\s*dias/i.test(t)) continue;
        if (/banco\\s+de\\s+dados\\s+atualizado/i.test(t)) continue;
        if (t.length > 3 && t.length < 240) texts.push(t);
      }
      const body = (document.body?.innerText || '').replace(/\\s+/g, ' ');
      const m = body.match(/(per[ií]odo\\s*inv[aá]lido[^.]{0,80}|informe\\s+(a\\s+)?(ie|cnpj|per[ií]odo)[^.]{0,60}|nenhuma\\s+nota[^.]{0,60}|n[aã]o\\s+foram\\s+encontrad[^.]{0,60})/i);
      if (m && !/m[aá]x\\.?\\s*31\\s*dias/i.test(m[0])) texts.push(m[0].trim());
      return [...new Set(texts)].slice(0, 5).join(' | ');
    })()`;
    const hint = String((await scope.evaluate(js).catch(() => '')) || '').trim();
    return hint ? ` Detalhe do portal: ${hint}` : '';
  }

  private async hasEmptyResultMessage(scope: FormScope): Promise<boolean> {
    return scope
      .getByText(/nenhuma\s+nota|n[aã]o\s+foram\s+encontrad|sem\s+resultados|total\s+de\s+linhas:\s*0/i)
      .first()
      .isVisible({ timeout: 1_500 })
      .catch(() => false);
  }

  private async fillForm(
    context: AutomationContext,
    page: Page,
    scope: FormScope,
    params: ExtractNfeNfceParams,
  ): Promise<void> {
    await context.emitEvent({
      level: 'info',
      eventType: 'NAVIGATION_FINISHED',
      message: 'Preenchendo parâmetros do extrato',
      metadata: { params },
    });

    if (params.ie) {
      await this.fillText(context, scope, EcacRsExtractSelectors.ieInput(scope), params.ie, 'IE');
    }
    if (params.cnpj) {
      // Portal valida com alert "CNPJ deve ser numérico" — enviar só dígitos.
      // A máscara visual (se houver) é aplicada pelo próprio ASP.NET no blur.
      await this.fillText(
        context,
        scope,
        EcacRsExtractSelectors.cnpjInput(scope),
        params.cnpj.replace(/\D/g, ''),
        'CNPJ',
      );
    }

    await this.fillText(
      context,
      scope,
      EcacRsExtractSelectors.periodoInicial(scope),
      isoToBrDate(params.periodoInicial),
      'período inicial',
    );
    await this.fillText(
      context,
      scope,
      EcacRsExtractSelectors.periodoFinal(scope),
      isoToBrDate(params.periodoFinal),
      'período final',
    );

    await this.setCheckbox(EcacRsExtractSelectors.totalizadoPorMes(scope), params.totalizadoPorMes);
    await this.setCheckbox(EcacRsExtractSelectors.situacaoNormal(scope), params.situacaoNormal);
    await this.setCheckbox(EcacRsExtractSelectors.situacaoCancelada(scope), params.situacaoCancelada);

    await this.selectOperacao(scope, params.operacao);
    await this.setCheckbox(
      EcacRsExtractSelectors.excluirVendaFora(scope),
      params.excluirVendaForaEstabelecimento,
    );
    await this.selectModelo(scope, params.modelo);

    // Garante foco fora dos campos (evita máscara ASP.NET).
    await page.keyboard.press('Tab').catch(() => undefined);
  }

  private async selectOperacao(
    scope: FormScope,
    operacao: ExtractNfeNfceParams['operacao'],
  ): Promise<void> {
    const pattern = OPERACAO_LABELS[operacao];

    const radio = scope.getByRole('radio', { name: pattern }).first();
    if (await radio.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await radio.check({ force: true }).catch(async () => radio.click({ force: true }));
      if (await radio.isChecked().catch(() => false)) {
        return;
      }
    }

    const labelText = scope.getByText(pattern).first();
    if (await labelText.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await labelText.click({ force: true });
      const nearbyRadio = labelText
        .locator('xpath=ancestor::*[.//input[@type="radio"]][1]//input[@type="radio"]')
        .first();
      if ((await nearbyRadio.count().catch(() => 0)) > 0) {
        await nearbyRadio.check({ force: true }).catch(async () => nearbyRadio.click({ force: true }));
      }
      if (await scope.getByRole('radio', { name: pattern }).isChecked().catch(() => false)) {
        return;
      }
    }

    throw ecacError(
      'PORTAL_LAYOUT_CHANGED',
      `Opção de operação não encontrada/marcada: ${operacao}`,
    );
  }

  private async fillText(
    context: AutomationContext,
    scope: FormScope,
    locator: Locator,
    value: string,
    label: string,
  ): Promise<void> {
    await locator.first().waitFor({ state: 'attached', timeout: 10_000 }).catch(() => undefined);
    const count = await locator.count().catch(() => 0);
    if (count === 0) {
      const inventory = await inventoryTextInputs(scope);
      await context.emitEvent({
        level: 'warn',
        eventType: 'LAYOUT_CHANGED',
        message: `Campo ${label} não localizado — inventário de inputs`,
        metadata: { inventory, count },
      });
      throw ecacError('PORTAL_LAYOUT_CHANGED', `Campo ${label} não encontrado no formulário`, {
        inventory,
        count,
      });
    }

    const target = locator.first();
    await target.scrollIntoViewIfNeeded().catch(() => undefined);

    // ASP.NET: alguns inputs passam no inventário mas falham isVisible() do Playwright.
    let filled = false;
    try {
      await target.click({ force: true, timeout: 3_000 });
      await target.fill(value, { force: true, timeout: 5_000 });
      filled = true;
    } catch {
      filled = false;
    }

    if (!filled) {
      await target.evaluate((el, v) => {
        const input = el as unknown as {
          removeAttribute: (n: string) => void;
          focus: () => void;
          value: string;
          dispatchEvent: (e: Event) => boolean;
        };
        input.removeAttribute('readonly');
        input.focus();
        input.value = v;
        for (const type of ['input', 'change', 'blur', 'keyup']) {
          input.dispatchEvent(new Event(type, { bubbles: true }));
        }
      }, value);
    }

    // Dispara máscara ASP.NET (CNPJ/datas) após preencher.
    await target.evaluate((el) => {
      const input = el as { dispatchEvent: (e: Event) => boolean; blur?: () => void };
      input.dispatchEvent(new Event('keyup', { bubbles: true }));
      input.dispatchEvent(new Event('blur', { bubbles: true }));
      input.blur?.();
    }).catch(() => undefined);

    const current = await target.inputValue().catch(() => '');
    const normalizedCurrent = current.replace(/\D/g, '');
    const normalizedValue = value.replace(/\D/g, '');
    if (normalizedCurrent !== normalizedValue && current !== value) {
      await target.evaluate((el, v) => {
        const input = el as unknown as {
          value: string;
          dispatchEvent: (e: Event) => boolean;
        };
        input.value = v;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.dispatchEvent(new Event('blur', { bubbles: true }));
      }, value);
      const again = await target.inputValue().catch(() => '');
      if (again.replace(/\D/g, '') !== normalizedValue && again !== value) {
        throw ecacError('PORTAL_LAYOUT_CHANGED', `Não foi possível preencher o campo ${label}`, {
          current: again,
          expected: value,
        });
      }
    }
  }

  private async setCheckbox(locator: Locator, checked: boolean): Promise<void> {
    if (!(await locator.isVisible({ timeout: 2_000 }).catch(() => false))) {
      return;
    }
    const isChecked = await locator.isChecked().catch(() => false);
    if (isChecked !== checked) {
      await locator.setChecked(checked, { force: true }).catch(async () => {
        await locator.click({ force: true });
      });
    }
  }

  private async selectModelo(
    scope: FormScope,
    modelo: ExtractNfeNfceParams['modelo'],
  ): Promise<void> {
    const wantNfe = modelo === 'nfe' || modelo === 'ambos';
    const wantNfce = modelo === 'nfce' || modelo === 'ambos';

    const nfeBox = scope.getByRole('checkbox', { name: MODELO_LABELS.nfe }).first();
    const nfceBox = scope.getByRole('checkbox', { name: MODELO_LABELS.nfce }).first();
    const nfeVisible = await nfeBox.isVisible({ timeout: 3_000 }).catch(() => false);
    const nfceVisible = await nfceBox.isVisible({ timeout: 1_000 }).catch(() => false);

    // No portal atual Modelo é checkbox (NF-e / NFC-e) — ambos podem ficar marcados.
    if (nfeVisible || nfceVisible) {
      if (nfeVisible) {
        if (wantNfe) {
          await nfeBox.check({ force: true }).catch(async () => nfeBox.click({ force: true }));
        } else {
          await nfeBox.uncheck({ force: true }).catch(() => undefined);
        }
      }
      if (nfceVisible) {
        if (wantNfce) {
          await nfceBox.check({ force: true }).catch(async () => nfceBox.click({ force: true }));
        } else {
          await nfceBox.uncheck({ force: true }).catch(() => undefined);
        }
      }
      return;
    }

    // Fallback legado: radio (só um modelo).
    const pattern = MODELO_LABELS[modelo === 'ambos' ? 'nfe' : modelo];
    const radio = scope.getByRole('radio', { name: pattern }).first();
    if (await radio.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await radio.check({ force: true }).catch(async () => radio.click({ force: true }));
      return;
    }

    const text = scope.getByText(pattern).first();
    if (await text.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await text.click({ force: true });
      return;
    }

    throw ecacError('PORTAL_LAYOUT_CHANGED', 'Campo modelo NF-e/NFC-e não encontrado');
  }
}
