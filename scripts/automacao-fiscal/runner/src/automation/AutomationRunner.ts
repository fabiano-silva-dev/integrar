import type { Page } from 'playwright';
import { join } from 'node:path';
import type { EnvConfig, PortalCode } from '../config/env.js';
import {
  portalAllowedHosts,
  portalCertOrigins,
  portalEntryUrl,
  resolvePortalMode,
} from '../config/env.js';
import { ArtifactStore, buildDiagnosticLog } from '../artifacts/ArtifactStore.js';
import { PfxFileCertificateProvider } from '../certificates/PfxFileCertificateProvider.js';
import { AutomationError, isAutomationError, toAutomationError } from '../errors/AutomationError.js';
import { sanitizeUrl, structuredLog } from '../security/sanitize.js';
import { assertUrlAllowed } from '../security/allowlist.js';
import { BrowserManager } from './BrowserManager.js';
import { globalExecutionLock } from './ExecutionLock.js';
import { runFakeMode } from './FakeModeRunner.js';
import { PlatformClient } from './PlatformClient.js';
import type {
  AutomationContext,
  AutomationEvent,
  AutomationMode,
  AutomationResult,
  PortalAdapter,
} from './types.js';
import { EcacRsAdapter } from '../portals/ecac-rs/EcacRsAdapter.js';
import { NfseEmissorAdapter } from '../portals/nfse-emissor/NfseEmissorAdapter.js';

export class AutomationRunner {
  readonly platform: PlatformClient;

  constructor(private readonly config: EnvConfig) {
    this.platform = new PlatformClient(config);
  }

  async validate(
    runId: string,
    mode?: AutomationMode,
    portal: PortalCode = 'ecac-rs',
  ): Promise<AutomationResult> {
    return this.run({
      runId,
      portal,
      operation: 'validate-access',
      params: {},
      ...(mode ? { mode } : {}),
    });
  }

  async run(options: {
    runId: string;
    portal?: PortalCode;
    operation?: string;
    mode?: AutomationMode;
    params?: Record<string, unknown>;
  }): Promise<AutomationResult> {
    const portal = options.portal ?? 'ecac-rs';
    const operation = options.operation ?? 'validate-access';
    const params = options.params ?? {};
    const effectiveMode: AutomationMode = options.mode ?? resolvePortalMode(this.config, portal);

    const started = Date.now();
    const controller = new AbortController();
    const timeout = setTimeout(() => {
      controller.abort();
    }, this.config.AUTOMATION_TIMEOUT_MS);

    try {
      globalExecutionLock.tryAcquire(options.runId);

      if (effectiveMode === 'fake') {
        return await runFakeMode({
          runId: options.runId,
          config: this.config,
          platform: this.platform,
          portal,
          operation,
          params,
        });
      }

      return await this.#runBrowserMode(
        options.runId,
        effectiveMode,
        portal,
        operation,
        params,
        controller.signal,
      );
    } catch (error) {
      if (controller.signal.aborted) {
        const timeoutError = new AutomationError('TIMEOUT', 'Timeout total da execução excedido', {
          metadata: { timeoutMs: this.config.AUTOMATION_TIMEOUT_MS },
        });
        await this.#emitBestEffort(options.runId, {
          level: 'error',
          eventType: 'RUN_FAILED',
          message: timeoutError.safeMessage,
          metadata: timeoutError.toJSON(),
        });
        return {
          status: 'failed',
          errorCode: timeoutError.code,
          errorMessage: timeoutError.safeMessage,
          durationMs: Date.now() - started,
        };
      }

      const automationError = toAutomationError(error);
      await this.#emitBestEffort(options.runId, {
        level: 'error',
        eventType: 'RUN_FAILED',
        message: automationError.safeMessage,
        metadata: automationError.toJSON(),
      });

      const status = isInterventionCode(automationError.code) ? 'needs_intervention' : 'failed';

      return {
        status,
        errorCode: automationError.code,
        errorMessage: automationError.safeMessage,
        durationMs: Date.now() - started,
        resultData: {
          technicalMessage: automationError.technicalMessage,
          metadata: automationError.metadata,
        },
      };
    } finally {
      clearTimeout(timeout);
      globalExecutionLock.release(options.runId);
    }
  }

  async #runBrowserMode(
    runId: string,
    mode: Exclude<AutomationMode, 'fake'>,
    portal: PortalCode,
    operation: string,
    params: Record<string, unknown>,
    signal: AbortSignal,
  ): Promise<AutomationResult> {
    const entryUrl = portalEntryUrl(this.config, portal);
    const allowedHosts = portalAllowedHosts(this.config, portal);
    const certOrigins = portalCertOrigins(this.config, portal);
    assertUrlAllowed(entryUrl, allowedHosts);

    const artifactBase = this.config.AUTOMATION_ARTIFACT_DIR?.trim()
      ? this.config.AUTOMATION_ARTIFACT_DIR.trim()
      : undefined;
    const durableArtifacts = Boolean(artifactBase);
    const store = new ArtifactStore(runId, artifactBase);
    await store.init();
    const events: AutomationEvent[] = [];
    const browserManager = new BrowserManager();
    const certProvider =
      mode === 'certificate'
        ? new PfxFileCertificateProvider({
            pfxFile: this.config.ECAC_A1_PFX_FILE,
            passwordFile: this.config.ECAC_A1_PASSWORD_FILE,
          })
        : null;

    const emitEvent = async (event: AutomationEvent): Promise<void> => {
      events.push(event);
      structuredLog(event.level, event.eventType, {
        runId,
        message: event.message,
        ...(event.metadata ? { metadata: event.metadata } : {}),
      });
      try {
        await this.platform.postEvent(runId, event);
      } catch (error) {
        structuredLog('warn', 'EVENT_UPLOAD_FAILED', {
          runId,
          eventType: event.eventType,
          reason: error instanceof Error ? error.message : String(error),
        });
      }
    };

    const saveScreenshot = async (
      filename: string,
      page?: Page,
      options?: { fullPage?: boolean; metadata?: Record<string, unknown> },
    ) => {
      const target = page ?? browserManager.page;
      if (!target || target.isClosed()) {
        return null;
      }
      const fullPage = options?.fullPage ?? true;
      const buffer = await target.screenshot({ fullPage, type: 'png' });
      const artifact = await store.writeBinary('screenshot', filename, buffer, 'image/png');
      await emitEvent({
        level: 'info',
        eventType: 'SCREENSHOT_SAVED',
        message: `Screenshot salvo: ${filename}`,
        metadata: { filename, sha256: artifact.sha256, ...(options?.metadata ?? {}) },
      });
      try {
        await this.platform.postArtifact(runId, artifact);
      } catch (error) {
        structuredLog('warn', 'SCREENSHOT_UPLOAD_FAILED', {
          runId,
          filename,
          reason: error instanceof Error ? error.message : String(error),
        });
      }
      return artifact;
    };

    const saveDownload = async (filename: string, content: Buffer, mimeType = 'text/plain') => {
      const safeName = filename.replace(/[^a-zA-Z0-9._-]+/g, '_').slice(0, 120) || 'download.bin';
      const artifact = await store.writeBinary('download', safeName, content, mimeType);
      await emitEvent({
        level: 'info',
        eventType: 'RUN_FINISHED',
        message: `Download salvo: ${safeName}`,
        metadata: { filename: safeName, sha256: artifact.sha256, bytes: artifact.size },
      });
      try {
        await this.platform.postArtifact(runId, artifact);
      } catch (error) {
        structuredLog('warn', 'DOWNLOAD_UPLOAD_FAILED', {
          runId,
          filename: safeName,
          reason: error instanceof Error ? error.message : String(error),
        });
      }
      return artifact;
    };

    try {
      await emitEvent({
        level: 'info',
        eventType: 'RUN_STARTED',
        message: `Execução ${mode} iniciada`,
        metadata: { mode, portal, operation, entryUrl: sanitizeUrl(entryUrl) },
      });

      let clientCertificates;
      if (certProvider) {
        clientCertificates = await certProvider.loadClientCertificates(certOrigins);
        await emitEvent({
          level: 'info',
          eventType: 'CERTIFICATE_CONFIGURED',
          message: 'Certificado A1 configurado para origens informadas',
          metadata: {
            origins: certOrigins,
            count: clientCertificates.length,
          },
        });
      }

      const session = await browserManager.start({
        headless: this.config.AUTOMATION_HEADLESS,
        ...(clientCertificates ? { clientCertificates } : {}),
        ignoreHTTPSErrors: false,
      });

      await emitEvent({
        level: 'info',
        eventType: 'BROWSER_STARTED',
        message: 'Navegador Chromium iniciado',
      });
      await emitEvent({
        level: 'info',
        eventType: 'CONTEXT_CREATED',
        message: 'Browser context exclusivo criado',
      });
      await emitEvent({
        level: 'info',
        eventType: 'PAGE_OPENED',
        message: 'Página inicial aberta',
      });

      const context: AutomationContext = {
        runId,
        mode,
        operation,
        params,
        config: this.config,
        browser: session.browser,
        context: session.context,
        page: session.page,
        ...(clientCertificates ? { clientCertificates } : {}),
        emitEvent,
        saveScreenshot,
        saveDownload,
        signal,
      };

      const adapter = createPortalAdapter(portal);
      const result = await Promise.race([
        dispatchAdapter(adapter, context),
        abortPromise(signal),
      ]);

      const diagnostic = await store.writeText(
        'diagnostic-log',
        'diagnostic.json',
        buildDiagnosticLog(this.config, events, { result, portal, operation }),
        'application/json',
      );
      try {
        await this.platform.postArtifact(runId, diagnostic);
      } catch {
        // diagnostic upload is best-effort
      }

      const tracePath = join(store.runDir, 'trace.zip');
      await browserManager.stopTrace(tracePath);
      const traceBuffer = await import('node:fs/promises').then((fs) => fs.readFile(tracePath));
      const traceArtifact = await store.writeBinary(
        'trace',
        'trace.zip',
        traceBuffer,
        'application/zip',
      );
      await emitEvent({
        level: 'info',
        eventType: 'TRACE_SAVED',
        message: 'Trace Playwright salvo',
        metadata: { filename: traceArtifact.filename, sha256: traceArtifact.sha256 },
      });
      try {
        await this.platform.postArtifact(runId, traceArtifact);
      } catch {
        // best-effort
      }

      await emitEvent({
        level: result.status === 'succeeded' ? 'info' : 'warn',
        eventType: result.status === 'failed' ? 'RUN_FAILED' : 'RUN_FINISHED',
        message: `Execução finalizada com status ${result.status}`,
        metadata: {
          status: result.status,
          errorCode: result.errorCode,
          finalUrl: result.finalUrl ? sanitizeUrl(result.finalUrl) : null,
          portal,
        },
      });

      return result;
    } catch (error) {
      await saveScreenshot('99-error.png').catch(() => null);
      throw error;
    } finally {
      await browserManager.close();
      if (certProvider) {
        await certProvider.dispose();
      }
      // Em modo CLI com diretório durável (Laravel), não apaga — o PHP lê os arquivos depois.
      if (!durableArtifacts) {
        await store.cleanup().catch(() => undefined);
      }
    }
  }

  async #emitBestEffort(runId: string, event: AutomationEvent): Promise<void> {
    try {
      await this.platform.postEvent(runId, event);
    } catch {
      // ignore
    }
  }
}

function createPortalAdapter(portal: PortalCode): PortalAdapter {
  if (portal === 'nfse-emissor') {
    return new NfseEmissorAdapter();
  }
  return new EcacRsAdapter();
}

async function dispatchAdapter(
  adapter: PortalAdapter,
  context: AutomationContext,
): Promise<AutomationResult> {
  if (context.operation === 'validate-access') {
    return adapter.validateAccess(context);
  }
  if (adapter.execute) {
    return adapter.execute(context);
  }
  throw new AutomationError(
    'FLOW_NOT_IMPLEMENTED',
    `Operação ${context.operation} ainda não implementada neste portal`,
  );
}

function isInterventionCode(code: string): boolean {
  return (
    code === 'NEEDS_ROLE_MAPPING' ||
    code === 'MANUAL_CONFIRMATION_REQUIRED' ||
    code === 'LOGIN_NOT_CONFIRMED' ||
    code === 'CERTIFICATE_ORIGIN_NOT_CONFIGURED' ||
    code === 'FLOW_NOT_IMPLEMENTED'
  );
}

function abortPromise(signal: AbortSignal): Promise<never> {
  return new Promise((_resolve, reject) => {
    if (signal.aborted) {
      reject(new AutomationError('TIMEOUT', 'AbortSignal já acionado'));
      return;
    }
    signal.addEventListener(
      'abort',
      () => reject(new AutomationError('TIMEOUT', 'AbortSignal acionado')),
      { once: true },
    );
  });
}

export function mapThrownToResult(error: unknown, durationMs: number): AutomationResult {
  const automationError = isAutomationError(error) ? error : toAutomationError(error);
  const status = isInterventionCode(automationError.code) ? 'needs_intervention' : 'failed';

  return {
    status,
    errorCode: automationError.code,
    errorMessage: automationError.safeMessage,
    durationMs,
    resultData: automationError.metadata,
  };
}
