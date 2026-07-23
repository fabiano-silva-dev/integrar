import { createHash } from 'node:crypto';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import type { EnvConfig } from '../config/env.js';
import { sanitizeValue, structuredLog } from '../security/sanitize.js';

export type ArtifactType = 'screenshot' | 'trace' | 'diagnostic-log' | 'html' | 'download';

export type LocalArtifact = {
  type: ArtifactType;
  filename: string;
  mimeType: string;
  absolutePath: string;
  size: number;
  sha256: string;
};

export class ArtifactStore {
  readonly runDir: string;

  constructor(
    readonly runId: string,
    baseDir = join(tmpdir(), 'automation-artifacts'),
  ) {
    this.runDir = join(baseDir, runId);
  }

  async init(): Promise<void> {
    await mkdir(this.runDir, { recursive: true });
  }

  async writeBinary(
    type: ArtifactType,
    filename: string,
    content: Buffer,
    mimeType: string,
  ): Promise<LocalArtifact> {
    const absolutePath = join(this.runDir, filename);
    await writeFile(absolutePath, content, { mode: 0o600 });
    const sha256 = createHash('sha256').update(content).digest('hex');
    const artifact: LocalArtifact = {
      type,
      filename,
      mimeType,
      absolutePath,
      size: content.byteLength,
      sha256,
    };
    structuredLog('info', 'ARTIFACT_WRITTEN', {
      runId: this.runId,
      type,
      filename,
      size: artifact.size,
      sha256,
    });
    return artifact;
  }

  async writeText(
    type: ArtifactType,
    filename: string,
    content: string,
    mimeType: string,
  ): Promise<LocalArtifact> {
    return this.writeBinary(type, filename, Buffer.from(content, 'utf8'), mimeType);
  }

  async cleanup(): Promise<void> {
    await rm(this.runDir, { recursive: true, force: true });
  }
}

export function buildDiagnosticLog(
  config: EnvConfig,
  events: unknown[],
  extra: Record<string, unknown> = {},
): string {
  const portal = typeof extra.portal === 'string' ? extra.portal : 'ecac-rs';
  const isNfse = portal === 'nfse-emissor';
  const payload = sanitizeValue({
    generatedAt: new Date().toISOString(),
    portal,
    mode: isNfse ? config.NFSE_EMISSOR_MODE : config.ECAC_RS_MODE,
    entryUrl: isNfse ? config.NFSE_EMISSOR_ENTRY_URL : config.ECAC_RS_ENTRY_URL,
    allowedHostSuffixes: isNfse
      ? config.NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES
      : config.ECAC_RS_ALLOWED_HOST_SUFFIXES,
    certOriginsConfigured: isNfse
      ? config.NFSE_EMISSOR_CERT_ORIGINS.length
      : config.ECAC_RS_CERT_ORIGINS.length,
    events,
    ...extra,
  });
  return `${JSON.stringify(payload, null, 2)}\n`;
}
