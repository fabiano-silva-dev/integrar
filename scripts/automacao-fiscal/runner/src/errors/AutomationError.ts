import type { ErrorCode } from './errorCodes.js';
import { SAFE_MESSAGES } from './errorCodes.js';
import { sanitizeValue } from '../security/sanitize.js';

export type AutomationErrorPayload = {
  code: ErrorCode;
  safeMessage: string;
  technicalMessage: string;
  retryable: boolean;
  metadata: Record<string, unknown>;
};

const RETRYABLE_CODES: ReadonlySet<ErrorCode> = new Set([
  'PORTAL_UNAVAILABLE',
  'TIMEOUT',
  'ARTIFACT_UPLOAD_FAILED',
  'TLS_CLIENT_AUTH_FAILED',
]);

export class AutomationError extends Error {
  readonly code: ErrorCode;
  readonly safeMessage: string;
  readonly technicalMessage: string;
  readonly retryable: boolean;
  readonly metadata: Record<string, unknown>;

  constructor(
    code: ErrorCode,
    technicalMessage: string,
    options?: {
      safeMessage?: string;
      retryable?: boolean;
      metadata?: Record<string, unknown>;
      cause?: unknown;
    },
  ) {
    const safeMessage = options?.safeMessage ?? SAFE_MESSAGES[code];
    super(safeMessage, options?.cause !== undefined ? { cause: options.cause } : undefined);
    this.name = 'AutomationError';
    this.code = code;
    this.safeMessage = safeMessage;
    this.technicalMessage = String(sanitizeValue(technicalMessage));
    this.retryable = options?.retryable ?? RETRYABLE_CODES.has(code);
    this.metadata = (sanitizeValue(options?.metadata ?? {}) as Record<string, unknown>) ?? {};
  }

  toJSON(): AutomationErrorPayload {
    return {
      code: this.code,
      safeMessage: this.safeMessage,
      technicalMessage: this.technicalMessage,
      retryable: this.retryable,
      metadata: this.metadata,
    };
  }
}

export function isAutomationError(error: unknown): error is AutomationError {
  return error instanceof AutomationError;
}

export function toAutomationError(error: unknown): AutomationError {
  if (isAutomationError(error)) {
    return error;
  }

  if (error instanceof Error) {
    const message = error.message.toLowerCase();

    if (message.includes('timeout') || error.name === 'TimeoutError') {
      return new AutomationError('TIMEOUT', error.message, { cause: error });
    }

    if (
      message.includes('net::err_bad_ssl_client_auth_cert') ||
      message.includes('ssl_client') ||
      message.includes('client certificate')
    ) {
      return new AutomationError('TLS_CLIENT_AUTH_FAILED', error.message, { cause: error });
    }

    return new AutomationError('UNEXPECTED_ERROR', error.message, { cause: error });
  }

  return new AutomationError('UNEXPECTED_ERROR', String(error));
}
