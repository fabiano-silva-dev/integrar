import type { Browser, BrowserContext, Page } from 'playwright';
import type { EnvConfig } from '../config/env.js';
import type { LocalArtifact } from '../artifacts/ArtifactStore.js';
import type { ClientCertificateMaterial } from '../certificates/CertificateProvider.js';
import type { ErrorCode } from '../errors/errorCodes.js';

export type AutomationMode = 'fake' | 'discovery' | 'certificate';

export type RunStatus = 'succeeded' | 'failed' | 'needs_intervention';

export type EventLevel = 'debug' | 'info' | 'warn' | 'error';

export type AutomationEventType =
  | 'RUN_STARTED'
  | 'BROWSER_STARTED'
  | 'CONTEXT_CREATED'
  | 'PAGE_OPENED'
  | 'NAVIGATION_STARTED'
  | 'NAVIGATION_FINISHED'
  | 'REDIRECT_OBSERVED'
  | 'POPUP_OPENED'
  | 'FRAME_ATTACHED'
  | 'CERTIFICATE_CONFIGURED'
  | 'CERTIFICATE_REQUEST_SUSPECTED'
  | 'AUTHENTICATION_CONFIRMED'
  | 'ROLE_SELECTION_DETECTED'
  | 'SCREENSHOT_SAVED'
  | 'TRACE_SAVED'
  | 'RUN_FINISHED'
  | 'RUN_FAILED'
  | 'MANUAL_CONFIRMATION_DETECTED'
  | 'LAYOUT_CHANGED'
  | 'FAKE_STEP';

export type AutomationEvent = {
  level: EventLevel;
  eventType: AutomationEventType;
  message: string;
  metadata?: Record<string, unknown>;
};

export type SuccessDetection = {
  confirmed: boolean;
  confidence: 'high' | 'medium' | 'low';
  evidence: string[];
};

export type AutomationResult = {
  status: RunStatus;
  finalUrl?: string;
  errorCode?: ErrorCode;
  errorMessage?: string;
  resultData?: Record<string, unknown>;
  durationMs: number;
};

export type AutomationContext = {
  runId: string;
  mode: AutomationMode;
  operation: string;
  params: Record<string, unknown>;
  config: EnvConfig;
  browser: Browser | null;
  context: BrowserContext | null;
  page: Page | null;
  /** Material A1 carregado para o contexto atual. */
  clientCertificates?: ClientCertificateMaterial[];
  emitEvent: (event: AutomationEvent) => Promise<void>;
  saveScreenshot: (
    filename: string,
    page?: Page,
    options?: { fullPage?: boolean; metadata?: Record<string, unknown> },
  ) => Promise<LocalArtifact | null>;
  saveDownload?: (
    filename: string,
    content: Buffer,
    mimeType?: string,
  ) => Promise<LocalArtifact | null>;
  signal: AbortSignal;
};

export interface PortalAdapter {
  validateAccess(context: AutomationContext): Promise<AutomationResult>;
  /** Operações além de validate-access. Se omitido, retorna FLOW_NOT_IMPLEMENTED. */
  execute?(context: AutomationContext): Promise<AutomationResult>;
}
