import { readFile } from 'node:fs/promises';
import type { EnvConfig } from '../config/env.js';
import type { LocalArtifact } from '../artifacts/ArtifactStore.js';
import type { AutomationEvent } from './types.js';
import { AutomationError } from '../errors/AutomationError.js';
import { sanitizeValue, structuredLog } from '../security/sanitize.js';

const MAX_ARTIFACT_BYTES = 20 * 1024 * 1024;

export class PlatformClient {
  constructor(private readonly config: EnvConfig) {}

  async postEvent(runId: string, event: AutomationEvent): Promise<void> {
    const body = {
      level: event.level,
      event_type: event.eventType,
      message: event.message,
      metadata: sanitizeValue(event.metadata ?? {}),
    };

    await this.#request(`/internal/v1/automation-runs/${runId}/events`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        authorization: `Bearer ${this.config.RUNNER_INTERNAL_TOKEN}`,
      },
      body: JSON.stringify(body),
    });
  }

  async postArtifact(runId: string, artifact: LocalArtifact): Promise<void> {
    if (artifact.size > MAX_ARTIFACT_BYTES) {
      throw new AutomationError(
        'ARTIFACT_UPLOAD_FAILED',
        `Artefato excede limite de ${MAX_ARTIFACT_BYTES} bytes`,
        { metadata: { filename: artifact.filename, size: artifact.size } },
      );
    }

    const content = await readFile(artifact.absolutePath);
    const form = new FormData();
    form.set('type', artifact.type);
    form.set('filename', artifact.filename);
    form.set('mime_type', artifact.mimeType);
    form.set('sha256', artifact.sha256);
    form.set(
      'file',
      new Blob([new Uint8Array(content)], { type: artifact.mimeType }),
      artifact.filename,
    );

    await this.#request(`/internal/v1/automation-runs/${runId}/artifacts`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${this.config.RUNNER_INTERNAL_TOKEN}`,
      },
      body: form,
    });
  }

  async #request(path: string, init: RequestInit): Promise<void> {
    const url = new URL(path, this.config.PLATFORM_BASE_URL).toString();
    let response: Response;
    try {
      response = await fetch(url, {
        ...init,
        signal: AbortSignal.timeout(30_000),
      });
    } catch (error) {
      throw new AutomationError(
        'ARTIFACT_UPLOAD_FAILED',
        `Falha de rede ao contatar plataforma: ${error instanceof Error ? error.message : String(error)}`,
        { cause: error, metadata: { path } },
      );
    }

    if (!response.ok) {
      const text = await response.text().catch(() => '');
      structuredLog('error', 'PLATFORM_REQUEST_FAILED', {
        path,
        status: response.status,
        bodyPreview: text.slice(0, 200),
      });
      throw new AutomationError(
        'ARTIFACT_UPLOAD_FAILED',
        `Plataforma respondeu HTTP ${response.status}`,
        { metadata: { path, status: response.status } },
      );
    }
  }
}
