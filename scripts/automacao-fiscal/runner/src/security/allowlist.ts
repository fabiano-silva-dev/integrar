export function parseHostSuffixes(raw: string): string[] {
  return raw
    .split(',')
    .map((item) => item.trim().toLowerCase().replace(/^\./, ''))
    .filter((item) => item.length > 0);
}

export function isHostAllowed(hostname: string, allowedSuffixes: string[]): boolean {
  const host = hostname.toLowerCase();
  return allowedSuffixes.some((suffix) => {
    const normalized = suffix.toLowerCase().replace(/^\./, '');
    return host === normalized || host.endsWith(`.${normalized}`);
  });
}

export function assertUrlAllowed(rawUrl: string, allowedSuffixes: string[]): void {
  let url: URL;
  try {
    url = new URL(rawUrl);
  } catch {
    throw new Error(`URL inválida: ${rawUrl}`);
  }

  if (url.protocol !== 'https:' && url.protocol !== 'http:') {
    throw new Error(`Protocolo não permitido: ${url.protocol}`);
  }

  if (!isHostAllowed(url.hostname, allowedSuffixes)) {
    throw new Error(`Host não autorizado: ${url.hostname}`);
  }
}

export function collectOrigins(urls: string[]): string[] {
  const origins = new Set<string>();
  for (const raw of urls) {
    try {
      origins.add(new URL(raw).origin);
    } catch {
      // ignore invalid
    }
  }
  return [...origins].sort();
}
