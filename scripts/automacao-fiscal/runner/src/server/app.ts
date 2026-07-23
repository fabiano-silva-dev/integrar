import type { IncomingMessage, ServerResponse } from 'node:http';
import {
  portalCodeSchema,
  runRequestSchema,
  validateRequestSchema,
  type EnvConfig,
  type PortalCode,
} from '../config/env.js';
import { AutomationRunner } from '../automation/AutomationRunner.js';
import { globalExecutionLock } from '../automation/ExecutionLock.js';
import { readJsonBody, requireInternalAuth, sendJson } from './middleware.js';
import { structuredLog } from '../security/sanitize.js';

const PORTAL_ROUTES: Record<string, { portal: PortalCode; kind: 'validate' | 'run' }> = {
  '/internal/v1/ecac-rs/validate': { portal: 'ecac-rs', kind: 'validate' },
  '/internal/v1/nfse-emissor/validate': { portal: 'nfse-emissor', kind: 'validate' },
  '/internal/v1/ecac-rs/run': { portal: 'ecac-rs', kind: 'run' },
  '/internal/v1/nfse-emissor/run': { portal: 'nfse-emissor', kind: 'run' },
};

export function createRequestHandler(config: EnvConfig) {
  const runner = new AutomationRunner(config);

  return async (req: IncomingMessage, res: ServerResponse): Promise<void> => {
    const url = new URL(req.url ?? '/', `http://${req.headers.host ?? 'localhost'}`);
    const method = req.method ?? 'GET';

    if (method === 'GET' && url.pathname === '/health') {
      sendJson(res, 200, {
        status: 'ok',
        busy: globalExecutionLock.isBusy,
        fakeMode: config.AUTOMATION_FAKE_MODE,
        portals: {
          'ecac-rs': config.ECAC_RS_MODE,
          'nfse-emissor': config.NFSE_EMISSOR_MODE,
        },
      });
      return;
    }

    const route = PORTAL_ROUTES[url.pathname];
    if (method === 'POST' && route) {
      if (!requireInternalAuth(req, res, config)) {
        return;
      }

      let body: unknown;
      try {
        body = await readJsonBody(req);
      } catch (error) {
        sendJson(res, 400, {
          error: 'invalid_body',
          message: error instanceof Error ? error.message : 'Corpo inválido',
        });
        return;
      }

      if (
        body &&
        typeof body === 'object' &&
        ('url' in body || 'entryUrl' in body || 'targetUrl' in body)
      ) {
        sendJson(res, 400, {
          error: 'arbitrary_url_rejected',
          message: 'URL arbitrária não é aceita pela API do runner',
        });
        return;
      }

      const resolvedPortal = portalCodeSchema.parse(route.portal);

      if (route.kind === 'validate') {
        const parsed = validateRequestSchema.safeParse(body);
        if (!parsed.success) {
          sendJson(res, 400, {
            error: 'validation_error',
            message: 'Payload inválido',
            details: parsed.error.flatten(),
          });
          return;
        }

        structuredLog('info', 'VALIDATE_REQUEST_RECEIVED', {
          runId: parsed.data.runId,
          mode: parsed.data.mode ?? null,
          portal: resolvedPortal,
        });

        const result = await runner.validate(parsed.data.runId, parsed.data.mode, resolvedPortal);
        const httpStatus =
          result.status === 'failed' && result.errorCode === 'RUNNER_BUSY' ? 409 : 200;

        sendJson(res, httpStatus, {
          runId: parsed.data.runId,
          portal: resolvedPortal,
          operation: 'validate-access',
          ...result,
        });
        return;
      }

      const parsed = runRequestSchema.safeParse(body);
      if (!parsed.success) {
        sendJson(res, 400, {
          error: 'validation_error',
          message: 'Payload inválido',
          details: parsed.error.flatten(),
        });
        return;
      }

      structuredLog('info', 'RUN_REQUEST_RECEIVED', {
        runId: parsed.data.runId,
        mode: parsed.data.mode ?? null,
        portal: resolvedPortal,
        operation: parsed.data.operation,
      });

      const result = await runner.run({
        runId: parsed.data.runId,
        portal: resolvedPortal,
        operation: parsed.data.operation,
        params: parsed.data.params,
        ...(parsed.data.mode ? { mode: parsed.data.mode } : {}),
      });
      const httpStatus =
        result.status === 'failed' && result.errorCode === 'RUNNER_BUSY' ? 409 : 200;

      sendJson(res, httpStatus, {
        runId: parsed.data.runId,
        portal: resolvedPortal,
        operation: parsed.data.operation,
        ...result,
      });
      return;
    }

    sendJson(res, 404, { error: 'not_found' });
  };
}
