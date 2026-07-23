const CPF_PATTERN = /\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/g;
const CNPJ_PATTERN = /\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/g;
const AUTHORIZATION_BEARER_PATTERN = /Authorization:\s*Bearer\s+[A-Za-z0-9\-._~+/]+=*/gi;
const BEARER_PATTERN = /Bearer\s+[A-Za-z0-9\-._~+/]+=*/gi;
const COOKIE_HEADER_PATTERN = /(cookie|set-cookie)\s*[:=]\s*[^;\s]+/gi;
const PASSWORD_PATTERN =
  /(password|senha|passphrase|secret)\s*[:=]\s*["']?[^"'\s,}]+["']?/gi;
const PRIVATE_KEY_PATTERN =
  /-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----[\s\S]*?-----END (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/gi;

const SENSITIVE_KEYS = new Set([
  'password',
  'senha',
  'passphrase',
  'secret',
  'token',
  'authorization',
  'cookie',
  'set-cookie',
  'pfx',
  'privatekey',
  'private_key',
  'clientcertificates',
]);

export function sanitizeUrl(rawUrl: string): string {
  try {
    const url = new URL(rawUrl);
    return `${url.protocol}//${url.host}${url.pathname}`;
  } catch {
    return '[invalid-url]';
  }
}

export function originFromUrl(rawUrl: string): string | null {
  try {
    const url = new URL(rawUrl);
    return url.origin;
  } catch {
    return null;
  }
}

export function sanitizeString(input: string): string {
  return input
    .replace(PRIVATE_KEY_PATTERN, '[REDACTED_PRIVATE_KEY]')
    .replace(AUTHORIZATION_BEARER_PATTERN, 'Authorization: Bearer [REDACTED]')
    .replace(BEARER_PATTERN, 'Bearer [REDACTED]')
    .replace(COOKIE_HEADER_PATTERN, '$1=[REDACTED]')
    .replace(PASSWORD_PATTERN, '$1=[REDACTED]')
    .replace(CPF_PATTERN, '[REDACTED_CPF]')
    .replace(CNPJ_PATTERN, '[REDACTED_CNPJ]');
}

export function sanitizeValue(value: unknown): unknown {
  if (typeof value === 'string') {
    return sanitizeString(value);
  }

  if (Array.isArray(value)) {
    return value.map((item) => sanitizeValue(item));
  }

  if (value && typeof value === 'object') {
    const result: Record<string, unknown> = {};
    for (const [key, nested] of Object.entries(value as Record<string, unknown>)) {
      const normalized = key.toLowerCase().replace(/[^a-z0-9_]/g, '');
      if (SENSITIVE_KEYS.has(normalized) || SENSITIVE_KEYS.has(key.toLowerCase())) {
        result[key] = '[REDACTED]';
        continue;
      }
      result[key] = sanitizeValue(nested);
    }
    return result;
  }

  return value;
}

export function structuredLog(
  level: 'info' | 'warn' | 'error' | 'debug',
  message: string,
  metadata: Record<string, unknown> = {},
): void {
  const payload = {
    level,
    message: sanitizeString(message),
    metadata: sanitizeValue(metadata),
    ts: new Date().toISOString(),
  };
  const line = JSON.stringify(payload);
  if (level === 'error') {
    console.error(line);
  } else if (level === 'warn') {
    console.warn(line);
  } else {
    console.log(line);
  }
}
