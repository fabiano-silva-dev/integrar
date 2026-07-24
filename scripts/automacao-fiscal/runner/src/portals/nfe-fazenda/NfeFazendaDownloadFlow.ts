import { readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import type { Page } from 'playwright';
import { ZodError } from 'zod';
import type { AutomationContext, AutomationResult } from '../../automation/types.js';
import { AutomationError } from '../../errors/AutomationError.js';
import { assertUrlAllowed } from '../../security/allowlist.js';
import { sanitizeUrl } from '../../security/sanitize.js';
import { ensureHostAllowed } from '../shared/navigation.js';
import { ensureHCaptchaSolved } from '../shared/solveHCaptcha.js';
import { parseDownloadNfeXmlParams } from './downloadNfeXmlParams.js';
import { NfeFazendaSelectors } from './NfeFazendaSelectors.js';

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Fluxo avulso: consulta a NF-e no portal nacional e baixa o XML
 * com certificado A1 (mTLS no botão "Download do documento").
 *
 * URL: consultaRecaptcha.aspx (hCaptcha → CapSolver).
 */
export class NfeFazendaDownloadFlow {
  async run(context: AutomationContext): Promise<AutomationResult> {
    const started = Date.now();

    let params;
    try {
      params = parseDownloadNfeXmlParams(context.params);
    } catch (error) {
      const message =
        error instanceof ZodError
          ? error.issues.map((i) => i.message).join('; ')
          : error instanceof Error
            ? error.message
            : String(error);
      throw new AutomationError('UNEXPECTED_ERROR', `Params download-nfe-xml inválidos: ${message}`);
    }

    if (context.mode !== 'certificate') {
      throw new AutomationError(
        'UNEXPECTED_ERROR',
        `download-nfe-xml requer modo certificate (recebido: ${context.mode})`,
      );
    }

    const page = context.page;
    if (!page) {
      throw new AutomationError('UNEXPECTED_ERROR', 'Browser page indisponível no contexto.');
    }

    const entryUrl = context.config.NFE_FAZENDA_ENTRY_URL;
    assertUrlAllowed(entryUrl, context.config.NFE_FAZENDA_ALLOWED_HOST_SUFFIXES);

    await context.emitEvent({
      level: 'info',
      eventType: 'NAVIGATION_STARTED',
      message: 'Abrindo portal nacional da NF-e (consulta por chave)',
      metadata: { url: sanitizeUrl(entryUrl), chave: params.chaveAcesso },
    });

    await page.goto(entryUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    await page.waitForLoadState('load').catch(() => undefined);
    ensureHostAllowed(page.url(), context.config.NFE_FAZENDA_ALLOWED_HOST_SUFFIXES);
    await context.saveScreenshot('01-consulta-form.png', page);

    await this.preencherChave(page, params.chaveAcesso);
    await context.saveScreenshot('02-chave-preenchida.png', page);

    await ensureHCaptchaSolved(page, context, {
      screenshotPrefix: '03-hcaptcha',
      websiteUrl: entryUrl,
    });

    await this.clicarContinuar(page, context);
    await context.saveScreenshot('04-apos-continuar.png', page);

    await this.aguardarResultado(page);
    ensureHostAllowed(page.url(), context.config.NFE_FAZENDA_ALLOWED_HOST_SUFFIXES);
    await context.saveScreenshot('05-resultado.png', page);

    const xmlBuffer = await this.baixarXml(page, context, params.chaveAcesso);
    const filename = `${params.chaveAcesso}-nfe.xml`;

    if (context.saveDownload) {
      await context.saveDownload(filename, xmlBuffer, 'application/xml');
    }

    await context.emitEvent({
      level: 'info',
      eventType: 'RUN_FINISHED',
      message: 'XML da NF-e baixado via portal nacional',
      metadata: { filename, bytes: xmlBuffer.length },
    });

    return {
      status: 'succeeded',
      durationMs: Date.now() - started,
      resultData: {
        chaveAcesso: params.chaveAcesso,
        downloadFilename: filename,
        downloadBytes: xmlBuffer.length,
        source: 'nfe-fazenda-portal',
      },
    };
  }

  private async preencherChave(page: Page, chave: string): Promise<void> {
    const input = NfeFazendaSelectors.chaveInput(page);
    await input.waitFor({ state: 'visible', timeout: 20_000 });
    await input.click({ force: true }).catch(() => undefined);
    await input.fill('');
    await input.fill(chave);

    const value = (await input.inputValue().catch(() => '')).replace(/\D+/g, '');
    if (value !== chave) {
      await input.evaluate((el, v) => {
        const inputEl = el as unknown as { value: string; dispatchEvent: (e: Event) => void };
        inputEl.value = v;
        inputEl.dispatchEvent(new Event('input', { bubbles: true }));
        inputEl.dispatchEvent(new Event('change', { bubbles: true }));
      }, chave);
    }
  }

  private async clicarContinuar(page: Page, context: AutomationContext): Promise<void> {
    const btn = NfeFazendaSelectors.continuarButton(page);
    await btn.waitFor({ state: 'visible', timeout: 15_000 });

    await context.emitEvent({
      level: 'info',
      eventType: 'NAVIGATION_STARTED',
      message: 'Enviando consulta (Continuar)',
    });

    await Promise.all([
      page.waitForLoadState('domcontentloaded', { timeout: 60_000 }).catch(() => undefined),
      btn.click({ force: true }),
    ]);

    await sleep(1_500);

    const bodyText = (await page.locator('body').innerText().catch(() => '')).toLowerCase();
    if (
      bodyText.includes('código da imagem') ||
      (bodyText.includes('captcha') && bodyText.includes('incorret')) ||
      bodyText.includes('digite os caracteres')
    ) {
      throw new AutomationError(
        'MANUAL_CONFIRMATION_REQUIRED',
        'Portal rejeitou o captcha. Verifique a chave CapSolver ou tente novamente.',
      );
    }

    if (bodyText.includes('não existe') || bodyText.includes('nao existe') || bodyText.includes('inválida')) {
      throw new AutomationError('UNEXPECTED_ERROR', 'Chave de acesso não encontrada ou inválida no portal da NF-e.');
    }
  }

  private async aguardarResultado(page: Page): Promise<void> {
    const marker = NfeFazendaSelectors.resultadoMarker(page);
    const download = NfeFazendaSelectors.downloadButton(page);

    const ok = await Promise.race([
      marker.waitFor({ state: 'visible', timeout: 45_000 }).then(() => true),
      download.waitFor({ state: 'visible', timeout: 45_000 }).then(() => true),
    ]).catch(() => false);

    if (!ok) {
      throw new AutomationError(
        'UNEXPECTED_ERROR',
        'Página de resultado da NF-e não carregou após a consulta.',
      );
    }
  }

  private async baixarXml(
    page: Page,
    context: AutomationContext,
    chave: string,
  ): Promise<Buffer> {
    const btn = NfeFazendaSelectors.downloadButton(page);
    await btn.waitFor({ state: 'visible', timeout: 20_000 });
    await btn.scrollIntoViewIfNeeded().catch(() => undefined);

    await context.emitEvent({
      level: 'info',
      eventType: 'NAVIGATION_STARTED',
      message: 'Clicando em Download do documento (requer certificado A1)',
    });

    const downloadPromise = page.waitForEvent('download', { timeout: 90_000 }).catch(() => null);
    const popupPromise = page.context().waitForEvent('page', { timeout: 15_000 }).catch(() => null);

    await btn.click({ force: true });

    const download = await downloadPromise;
    if (download) {
      return this.lerDownload(download, chave);
    }

    const popup = await popupPromise;
    if (popup) {
      await popup.waitForLoadState('domcontentloaded').catch(() => undefined);
      const popupDownloadPromise = popup.waitForEvent('download', { timeout: 60_000 }).catch(() => null);
      const popupDownloadBtn = NfeFazendaSelectors.downloadButton(popup);
      if (await popupDownloadBtn.isVisible().catch(() => false)) {
        await popupDownloadBtn.click({ force: true }).catch(() => undefined);
      }
      const popupDownload = await popupDownloadPromise;
      if (popupDownload) {
        return this.lerDownload(popupDownload, chave);
      }

      const content = await popup.content().catch(() => '');
      if (content.includes('<?xml') || content.includes('<nfeProc') || content.includes('<NFe')) {
        return Buffer.from(content, 'utf8');
      }
    }

    // Alguns fluxos entregam o XML na própria navegação (content-type xml).
    await sleep(2_000);
    const current = await page.content().catch(() => '');
    if (current.includes('<nfeProc') || current.includes('<NFe') || current.includes('<?xml')) {
      const match = current.match(/<\?xml[\s\S]+/i);
      return Buffer.from(match?.[0] ?? current, 'utf8');
    }

    await context.saveScreenshot('06-download-falhou.png', page);
    throw new AutomationError(
      'UNEXPECTED_ERROR',
      'Não foi possível capturar o download do XML. Confira se o certificado A1 do emitente está válido e autorizado neste portal.',
    );
  }

  private async lerDownload(
    download: { suggestedFilename: () => string; saveAs: (path: string) => Promise<void> },
    chave: string,
  ): Promise<Buffer> {
    const suggested = download.suggestedFilename() || `${chave}-nfe.xml`;
    const tempPath = join(tmpdir(), `nfe-fazenda-${chave}-${Date.now()}.bin`);
    await download.saveAs(tempPath);
    const buffer = await readFile(tempPath);
    await rm(tempPath, { force: true }).catch(() => undefined);

    const text = buffer.toString('utf8');
    if (!text.includes('<') || (!text.includes('nfe') && !text.includes('NFe') && !text.includes('xml'))) {
      // Pode ser zip — ainda devolve o binário; o PHP valida.
      if (buffer.length < 50) {
        throw new AutomationError(
          'UNEXPECTED_ERROR',
          `Arquivo baixado inválido (${suggested}, ${buffer.length} bytes).`,
        );
      }
    }

    return buffer;
  }
}
