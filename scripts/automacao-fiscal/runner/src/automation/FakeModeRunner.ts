import { ArtifactStore, buildDiagnosticLog } from '../artifacts/ArtifactStore.js';
import type { EnvConfig, PortalCode } from '../config/env.js';
import type { AutomationEvent, AutomationMode, AutomationResult } from './types.js';
import type { PlatformClient } from './PlatformClient.js';
import { structuredLog } from '../security/sanitize.js';
import { isAutomationError } from '../errors/AutomationError.js';

export async function runFakeMode(options: {
  runId: string;
  config: EnvConfig;
  platform: PlatformClient;
  portal?: PortalCode;
  operation?: string;
  params?: Record<string, unknown>;
}): Promise<AutomationResult> {
  const portal = options.portal ?? 'ecac-rs';
  const operation = options.operation ?? 'validate-access';
  const params = options.params ?? {};
  const started = Date.now();
  const artifactBase = options.config.AUTOMATION_ARTIFACT_DIR?.trim() || undefined;
  const durableArtifacts = Boolean(artifactBase);
  const store = new ArtifactStore(options.runId, artifactBase);
  await store.init();
  const events: AutomationEvent[] = [];
  let platformAvailable = true;

  const emit = async (event: AutomationEvent): Promise<void> => {
    events.push(event);
    structuredLog(event.level, event.eventType, {
      runId: options.runId,
      message: event.message,
      ...(event.metadata ? { metadata: event.metadata } : {}),
    });
    if (!platformAvailable) {
      return;
    }
    try {
      await options.platform.postEvent(options.runId, event);
    } catch (error) {
      platformAvailable = false;
      structuredLog('warn', 'PLATFORM_UNAVAILABLE_FAKE_MODE', {
        runId: options.runId,
        reason: error instanceof Error ? error.message : String(error),
      });
    }
  };

  try {
    await emit({
      level: 'info',
      eventType: 'RUN_STARTED',
      message: 'Execução fake iniciada',
      metadata: { mode: 'fake' satisfies AutomationMode, portal, operation },
    });

    await emit({
      level: 'info',
      eventType: 'FAKE_STEP',
      message: 'Simulando abertura do navegador',
    });

    await emit({
      level: 'info',
      eventType: 'FAKE_STEP',
      message: 'Simulando navegação até área autenticada',
    });

    const png = minimalPng();
    const screenshot = await store.writeBinary(
      'screenshot',
      '01-entry.png',
      png,
      'image/png',
    );
    await emit({
      level: 'info',
      eventType: 'SCREENSHOT_SAVED',
      message: 'Screenshot de teste salvo',
      metadata: { filename: screenshot.filename },
    });

    if (platformAvailable) {
      try {
        await options.platform.postArtifact(options.runId, screenshot);
      } catch (error) {
        platformAvailable = false;
        structuredLog('warn', 'PLATFORM_ARTIFACT_SKIPPED_FAKE_MODE', {
          runId: options.runId,
          reason: error instanceof Error ? error.message : String(error),
        });
      }
    }

    const diagnostic = await store.writeText(
      'diagnostic-log',
      'diagnostic.json',
      buildDiagnosticLog(options.config, events, {
        fake: true,
        platformAvailable,
        portal,
        operation,
        params,
      }),
      'application/json',
    );

    if (platformAvailable) {
      try {
        await options.platform.postArtifact(options.runId, diagnostic);
      } catch {
        platformAvailable = false;
      }
    }

    const knownOps = new Set(['validate-access', 'extract-nfe-nfce', 'extract-nfse']);
    if (!knownOps.has(operation)) {
      await emit({
        level: 'warn',
        eventType: 'RUN_FINISHED',
        message: `Fluxo fake ${operation} não implementado`,
      });
      return {
        status: 'needs_intervention',
        finalUrl: `https://fake.local/${portal}/${operation}`,
        errorCode: 'FLOW_NOT_IMPLEMENTED',
        errorMessage: 'Fluxo ainda não implementado no runner.',
        durationMs: Date.now() - started,
        resultData: { mode: 'fake', portal, operation, params },
      };
    }

    const extractMessage =
      operation === 'extract-nfe-nfce'
        ? 'Extrato NF-e/NFC-e simulado (fake)'
        : operation === 'extract-nfse'
          ? 'Extrato NFS-e simulado (fake)'
          : 'Login simulado confirmado';

    await emit({
      level: 'info',
      eventType: 'AUTHENTICATION_CONFIRMED',
      message: extractMessage,
      metadata: { confidence: 'high', portal, operation },
    });

    if (operation === 'extract-nfse') {
      const header =
        'dt_Geracao;Competencia;CNPJ_Contraparte;Nome_Contraparte;Municipio_Emissor;Valor_Servico;Sit;Sit_Label;Tipo;Numero;Chave_NFS-e\n';
      const extrato = await store.writeText(
        'download',
        'extratonfse.txt',
        header,
        'text/plain',
      );
      await emit({
        level: 'info',
        eventType: 'RUN_FINISHED',
        message: 'extratonfse.txt simulado (fake, sem notas)',
        metadata: { filename: extrato.filename },
      });
      if (platformAvailable) {
        try {
          await options.platform.postArtifact(options.runId, extrato);
        } catch {
          platformAvailable = false;
        }
      }
    }

    await emit({
      level: 'info',
      eventType: 'RUN_FINISHED',
      message: 'Execução fake finalizada com sucesso',
    });

    const finalUrl =
      operation === 'extract-nfe-nfce'
        ? `https://fake.local/${portal}/nfe-ics-ext`
        : operation === 'extract-nfse'
          ? `https://fake.local/${portal}/Notas/${String(params.tipo ?? 'emitidas')}`
          : `https://fake.local/${portal}/dashboard`;

    const evidence =
      operation === 'extract-nfe-nfce'
        ? ['fake-extract-nfe-nfce']
        : operation === 'extract-nfse'
          ? ['fake-extract-nfse']
          : ['fake-authentication-confirmed'];

    return {
      status: 'succeeded',
      finalUrl,
      durationMs: Date.now() - started,
      resultData: {
        mode: 'fake',
        portal,
        operation,
        params,
        platformAvailable,
        quantidade: operation === 'extract-nfse' ? 0 : undefined,
        chaves: operation === 'extract-nfse' ? [] : undefined,
        extrato: operation === 'extract-nfse' ? 'extratonfse.txt' : undefined,
        evidence,
      },
    };
  } catch (error) {
    if (isAutomationError(error) && error.code !== 'ARTIFACT_UPLOAD_FAILED') {
      throw error;
    }
    // Em fake mode, indisponibilidade da plataforma não falha a execução.
    return {
      status: 'succeeded',
      finalUrl: `https://fake.local/${portal}/dashboard`,
      durationMs: Date.now() - started,
      resultData: {
        mode: 'fake',
        portal,
        operation,
        platformAvailable: false,
        evidence: ['fake-authentication-confirmed', 'platform-unavailable-tolerated'],
      },
    };
  } finally {
    // Diretório durável (Laravel CLI): PHP lê os arquivos depois — não apagar.
    if (!durableArtifacts) {
      await store.cleanup().catch(() => undefined);
    }
  }
}

function minimalPng(): Buffer {
  // 1x1 PNG transparente
  return Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
  );
}
