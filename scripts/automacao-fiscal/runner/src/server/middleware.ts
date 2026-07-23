import type { IncomingMessage, ServerResponse } from 'node:http';
import { isAuthorized } from '../security/auth.js';
import type { EnvConfig } from '../config/env.js';

export function readJsonBody(req: IncomingMessage, maxBytes = 64 * 1024): Promise<unknown> {
  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = [];
    let total = 0;

    req.on('data', (chunk: Buffer) => {
      total += chunk.byteLength;
      if (total > maxBytes) {
        reject(new Error('Payload too large'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });

    req.on('end', () => {
      if (chunks.length === 0) {
        resolve({});
        return;
      }
      try {
        resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
      } catch {
        reject(new Error('JSON inválido'));
      }
    });

    req.on('error', reject);
  });
}

export function sendJson(
  res: ServerResponse,
  status: number,
  body: Record<string, unknown>,
): void {
  const payload = JSON.stringify(body);
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'content-length': Buffer.byteLength(payload),
    'cache-control': 'no-store',
  });
  res.end(payload);
}

export function requireInternalAuth(
  req: IncomingMessage,
  res: ServerResponse,
  config: EnvConfig,
): boolean {
  const header = req.headers.authorization;
  if (!isAuthorized(typeof header === 'string' ? header : undefined, config.RUNNER_INTERNAL_TOKEN)) {
    sendJson(res, 401, {
      error: 'unauthorized',
      message: 'Token interno inválido ou ausente',
    });
    return false;
  }
  return true;
}
