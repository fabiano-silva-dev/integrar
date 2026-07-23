import { chromium, type Browser, type BrowserContext, type Page } from 'playwright';
import type { ClientCertificateMaterial } from '../certificates/CertificateProvider.js';
import { structuredLog } from '../security/sanitize.js';

export type BrowserSessionOptions = {
  headless: boolean;
  clientCertificates?: ClientCertificateMaterial[];
  ignoreHTTPSErrors?: boolean;
};

export class BrowserManager {
  #browser: Browser | null = null;
  #context: BrowserContext | null = null;
  #page: Page | null = null;

  get browser(): Browser | null {
    return this.#browser;
  }

  get context(): BrowserContext | null {
    return this.#context;
  }

  get page(): Page | null {
    return this.#page;
  }

  async start(options: BrowserSessionOptions): Promise<{ browser: Browser; context: BrowserContext; page: Page }> {
    this.#browser = await chromium.launch({
      headless: options.headless,
      args: ['--disable-dev-shm-usage'],
    });
    structuredLog('info', 'BROWSER_STARTED', { headless: options.headless });

    const clientCertificates = options.clientCertificates?.map((item) => {
      if (item.cert && item.key) {
        return {
          origin: item.origin,
          cert: item.cert,
          key: item.key,
          ...(item.passphrase ? { passphrase: item.passphrase } : {}),
        };
      }
      if (item.pfx) {
        return {
          origin: item.origin,
          pfx: item.pfx,
          ...(item.passphrase ? { passphrase: item.passphrase } : {}),
        };
      }
      throw new Error('Material de certificado inválido: informe cert+key ou pfx');
    });

    this.#context = await this.#browser.newContext({
      ...(clientCertificates ? { clientCertificates } : {}),
      acceptDownloads: true,
      locale: 'pt-BR',
      timezoneId: 'America/Sao_Paulo',
      ignoreHTTPSErrors: options.ignoreHTTPSErrors === true,
    });

    await this.#context.tracing.start({
      screenshots: true,
      snapshots: true,
      sources: false,
    });

    this.#page = await this.#context.newPage();
    return {
      browser: this.#browser,
      context: this.#context,
      page: this.#page,
    };
  }

  async stopTrace(path: string): Promise<void> {
    if (this.#context) {
      await this.#context.tracing.stop({ path });
    }
  }

  async close(): Promise<void> {
    try {
      if (this.#page && !this.#page.isClosed()) {
        await this.#page.close().catch(() => undefined);
      }
    } finally {
      this.#page = null;
    }

    try {
      if (this.#context) {
        await this.#context.close().catch(() => undefined);
      }
    } finally {
      this.#context = null;
    }

    try {
      if (this.#browser) {
        await this.#browser.close().catch(() => undefined);
      }
    } finally {
      this.#browser = null;
    }
  }
}
