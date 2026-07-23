import { AutomationError } from '../../errors/AutomationError.js';
import type { ErrorCode } from '../../errors/errorCodes.js';

export function ecacError(
  code: ErrorCode,
  technicalMessage: string,
  metadata: Record<string, unknown> = {},
): AutomationError {
  return new AutomationError(code, technicalMessage, { metadata });
}

export function classifyPageError(message: string): AutomationError | null {
  const lower = message.toLowerCase();

  if (lower.includes('expirado') || lower.includes('expired')) {
    return ecacError('CERTIFICATE_EXPIRED', message);
  }
  if (
    lower.includes('não autorizado') ||
    lower.includes('nao autorizado') ||
    lower.includes('não aceito') ||
    lower.includes('nao aceito') ||
    lower.includes('inválido') ||
    lower.includes('invalido')
  ) {
    return ecacError('CERTIFICATE_NOT_ACCEPTED', message);
  }
  if (
    lower.includes('captcha') ||
    lower.includes('altcha') ||
    lower.includes('mfa') ||
    lower.includes('qr')
  ) {
    return ecacError('MANUAL_CONFIRMATION_REQUIRED', message);
  }
  return null;
}
