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

    const consultar = EcacRsExtractSelectors.consultarButton(scope);
    if (!(await consultar.isVisible({ timeout: 5_000 }).catch(() => false))) {
      throw ecacError(
        'PORTAL_LAYOUT_CHANGED',
        'Botão Consultar não encontrado no formulário de extrato',
        { params, url: sanitizeUrl(page.url()) },
      );
    }

    // Consultar NÃO inicia download — só monta a grade. Evitar waitForEvent longo aqui.
    await consultar.click({ force: true });
    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    await sleep(1_500);
    await context.saveScreenshot('12-after-consultar.png', page);

    await ensureAltchaSolved(page, context, { screenshotPrefix: '12-altcha-post' });

    // Se a consulta não avançou (ALTCHA resetou), reenvia uma vez.
    const resultados = EcacRsExtractSelectors.resultadosMarker(scope);
    if (!(await resultados.isVisible({ timeout: 8_000 }).catch(() => false))) {
      if (await this.isCaptchaVisible(page)) {
        const challenge = EcacRsSelectors.captchaOrMfa(page).first();
        await challenge.click({ force: true }).catch(() => undefined);
        await sleep(1_000);
      }
      await ensureAltchaSolved(page, context, { screenshotPrefix: '12b-altcha-retry' });
      if (await consultar.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await consultar.click({ force: true });
        await page.waitForLoadState('domcontentloaded').catch(() => undefined);
        await sleep(1_500);
      }
    }

    await resultados.waitFor({ state: 'visible', timeout: 45_000 }).catch(() => undefined);
    await context.saveScreenshot('13-resultados.png', page);

    const exportar = EcacRsExtractSelectors.exportarButton(scope);
    if (!(await exportar.isVisible({ timeout: 15_000 }).catch(() => false))) {
      throw ecacError(
        'PORTAL_LAYOUT_CHANGED',
        'Resultados visíveis, mas link "Gerar arquivo Texto(txt)" não encontrado.',
        { params, url: sanitizeUrl(page.url()) },
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
      await this.fillText(context, scope, EcacRsExtractSelectors.cnpjInput(scope), params.cnpj, 'CNPJ');
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
        const input = el as {
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

    const current = await target.inputValue().catch(() => '');
    const normalizedCurrent = current.replace(/\D/g, '');
    const normalizedValue = value.replace(/\D/g, '');
    if (normalizedCurrent !== normalizedValue && current !== value) {
      await target.evaluate((el, v) => {
        const input = el as {
          value: string;
          dispatchEvent: (e: Event) => boolean;
        };
        input.value = v;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
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
