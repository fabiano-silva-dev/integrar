import type { Page } from 'playwright';
import { ZodError } from 'zod';
import type { AutomationContext, AutomationResult } from '../../automation/types.js';
import { AutomationError } from '../../errors/AutomationError.js';
import { assertUrlAllowed } from '../../security/allowlist.js';
import { sanitizeUrl } from '../../security/sanitize.js';
import { ensureHostAllowed, unique } from '../shared/navigation.js';
import { NfseEmissorCertificateFlow } from './NfseEmissorCertificateFlow.js';
import { NfseEmissorExtractSelectors } from './NfseEmissorExtractSelectors.js';
import { nfseError } from './NfseEmissorErrors.js';
import {
  buildNfseNotasListUrl,
  parseExtractNfseParams,
  type ExtractNfseParams,
} from './extractNfseParams.js';
import {
  buildExtratoNfseTxt,
  mergeNfseListItems,
  parseNfseListHtml,
  parseNfsePaginationInfo,
  type NfseListItem,
} from './parseNfseListHtml.js';

/** Limite de segurança — o portal pagina ~15 notas; 50 páginas = 750 notas. */
const MAX_PAGES = 50;

export class NfseEmissorExtractFlow {
  constructor(private readonly certificate = new NfseEmissorCertificateFlow()) {}

  async run(context: AutomationContext): Promise<AutomationResult> {
    const started = Date.now();
    let params: ExtractNfseParams;
    try {
      params = parseExtractNfseParams(context.params);
    } catch (error) {
      const message =
        error instanceof ZodError
          ? error.issues.map((issue) => issue.message).join('; ')
          : error instanceof Error
            ? error.message
            : String(error);
      throw new AutomationError('UNEXPECTED_ERROR', `Params extract-nfse inválidos: ${message}`, {
        metadata: { params: context.params },
      });
    }

    if (context.mode !== 'certificate') {
      throw new AutomationError(
        'UNEXPECTED_ERROR',
        `extract-nfse requer modo certificate (recebido: ${context.mode})`,
      );
    }

    const auth = await this.certificate.authenticate(context);
    const page = auth.page;
    const origin = new URL(context.config.NFSE_EMISSOR_ENTRY_URL).origin;

    const { items, paginasLidas, totalRegistros, listUrl } = await this.collectAllPages(
      context,
      page,
      params,
      origin,
    );

    const chaves = items.map((item) => item.chave);
    const extratoTxt = buildExtratoNfseTxt(items, { tipo: params.tipo });

    if (context.saveDownload) {
      await context.saveDownload('extratonfse.txt', Buffer.from(extratoTxt, 'utf8'), 'text/plain');
    }

    const empty =
      chaves.length === 0 &&
      (await NfseEmissorExtractSelectors.emptyState(page)
        .isVisible({ timeout: 1_000 })
        .catch(() => false));

    if (empty || chaves.length === 0) {
      if (chaves.length === 0 && !empty) {
        await context.saveScreenshot('11-nfse-no-chave.png', page);
        throw nfseError(
          'PORTAL_LAYOUT_CHANGED',
          'Listagem NFS-e carregou, mas nenhuma chave de acesso foi encontrada no HTML',
        );
      }

      await context.emitEvent({
        level: 'info',
        eventType: 'RUN_FINISHED',
        message: 'Nenhuma NFS-e no período informado',
        metadata: { tipo: params.tipo, quantidade: 0 },
      });

      return {
        status: 'succeeded',
        finalUrl: sanitizeUrl(page.url()),
        durationMs: Date.now() - started,
        resultData: {
          tipo: params.tipo,
          quantidade: 0,
          empty: true,
          extrato: 'extratonfse.txt',
          paginas: paginasLidas,
          totalRegistros,
          listUrl: sanitizeUrl(listUrl),
          observedHosts: unique(auth.observedHosts),
        },
      };
    }

    if (context.saveDownload) {
      const payload = JSON.stringify(
        {
          tipo: params.tipo,
          periodoInicial: params.periodoInicial,
          periodoFinal: params.periodoFinal,
          busca: params.busca ?? '',
          quantidade: chaves.length,
          paginas: paginasLidas,
          totalRegistros,
          chaves,
          items,
          coletadoEm: new Date().toISOString(),
        },
        null,
        2,
      );
      await context.saveDownload(
        `nfse-${params.tipo}-chaves.json`,
        Buffer.from(payload, 'utf8'),
        'application/json',
      );
    }

    const avisoTotal =
      totalRegistros !== null && totalRegistros > chaves.length
        ? ` (portal indica ${totalRegistros})`
        : '';

    await context.emitEvent({
      level: 'info',
      eventType: 'RUN_FINISHED',
      message: `${chaves.length} NFS-e em ${paginasLidas} página(s)${avisoTotal}; extratonfse.txt gerado`,
      metadata: {
        tipo: params.tipo,
        quantidade: chaves.length,
        paginas: paginasLidas,
        totalRegistros,
        extrato: 'extratonfse.txt',
      },
    });

    return {
      status: 'succeeded',
      finalUrl: sanitizeUrl(page.url()),
      durationMs: Date.now() - started,
      resultData: {
        tipo: params.tipo,
        quantidade: chaves.length,
        empty: false,
        extrato: 'extratonfse.txt',
        paginas: paginasLidas,
        totalRegistros,
        listUrl: sanitizeUrl(listUrl),
        observedHosts: unique(auth.observedHosts),
      },
    };
  }

  private async collectAllPages(
    context: AutomationContext,
    page: Page,
    params: ExtractNfseParams,
    origin: string,
  ): Promise<{
    items: NfseListItem[];
    paginasLidas: number;
    totalRegistros: number | null;
    listUrl: string;
  }> {
    const collected: NfseListItem[][] = [];
    let totalRegistros: number | null = null;
    let ultimaPagina = 1;
    let listUrl = buildNfseNotasListUrl(params, origin, 1);

    for (let pagina = 1; pagina <= MAX_PAGES; pagina++) {
      listUrl = buildNfseNotasListUrl(params, origin, pagina);
      assertUrlAllowed(listUrl, context.config.NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES);

      await context.emitEvent({
        level: 'info',
        eventType: 'NAVIGATION_STARTED',
        message:
          pagina === 1
            ? `Abrindo listagem NFS-e ${params.tipo} com filtro de período`
            : `Abrindo página ${pagina} da listagem NFS-e ${params.tipo}`,
        metadata: {
          url: sanitizeUrl(listUrl),
          tipo: params.tipo,
          pagina,
          periodoInicial: params.periodoInicial,
          periodoFinal: params.periodoFinal,
        },
      });

      await page.goto(listUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
      await page.waitForLoadState('load').catch(() => undefined);
      await page.waitForLoadState('networkidle').catch(() => undefined);
      ensureHostAllowed(page.url(), context.config.NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES);
      await context.saveScreenshot(
        pagina === 1 ? '10-nfse-list.png' : `10-nfse-list-p${pagina}.png`,
        page,
      );

      const html = await page.content();
      if (context.saveDownload) {
        const name =
          pagina === 1
            ? `nfse-${params.tipo}-list.html`
            : `nfse-${params.tipo}-list-p${pagina}.html`;
        await context.saveDownload(name, Buffer.from(html, 'utf8'), 'text/html');
      }

      const pageItems = parseNfseListHtml(html);
      if (pageItems.length === 0) {
        if (pagina === 1) {
          break;
        }
        // Página além do fim — encerra.
        break;
      }

      collected.push(pageItems);

      const pagination = parseNfsePaginationInfo(html, pagina);
      if (pagination.totalRegistros !== null) {
        totalRegistros = pagination.totalRegistros;
      }
      ultimaPagina = Math.max(ultimaPagina, pagination.ultimaPagina);

      await context.emitEvent({
        level: 'info',
        eventType: 'RUN_FINISHED',
        message: `Página ${pagina}: ${pageItems.length} nota(s)`,
        metadata: {
          pagina,
          quantidadePagina: pageItems.length,
          quantidadeAcumulada: mergeNfseListItems(...collected).length,
          totalRegistros,
          ultimaPagina,
        },
      });

      if (pagina >= ultimaPagina) {
        break;
      }
    }

    return {
      items: mergeNfseListItems(...collected),
      paginasLidas: collected.length,
      totalRegistros,
      listUrl,
    };
  }
}
