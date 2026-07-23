import { timingSafeEqual } from 'node:crypto';

export function extractBearerToken(authorizationHeader: string | undefined): string | null {
  if (!authorizationHeader) {
    return null;
  }
  const match = /^Bearer\s+(.+)$/i.exec(authorizationHeader.trim());
  return match?.[1]?.trim() ?? null;
}

export function secureCompare(a: string, b: string): boolean {
  const left = Buffer.from(a);
  const right = Buffer.from(b);
  if (left.length !== right.length) {
    return false;
  }
  return timingSafeEqual(left, right);
}

export function isAuthorized(authorizationHeader: string | undefined, expectedToken: string): boolean {
  const token = extractBearerToken(authorizationHeader);
  if (!token || expectedToken.length === 0) {
    return false;
  }
  return secureCompare(token, expectedToken);
}
