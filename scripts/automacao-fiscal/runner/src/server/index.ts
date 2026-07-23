import { createServer } from 'node:http';
import { pathToFileURL } from 'node:url';
import { loadEnv } from '../config/env.js';
import { structuredLog } from '../security/sanitize.js';
import { createRequestHandler } from './app.js';

export function startServer(env: NodeJS.ProcessEnv = process.env): ReturnType<typeof createServer> {
  const config = loadEnv(env);
  const handler = createRequestHandler(config);
  const server = createServer((req, res) => {
    void handler(req, res).catch((error: unknown) => {
      structuredLog('error', 'UNHANDLED_REQUEST_ERROR', {
        message: error instanceof Error ? error.message : String(error),
      });
      if (!res.headersSent) {
        res.writeHead(500, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ error: 'internal_error' }));
      }
    });
  });

  server.listen(config.PORT, '0.0.0.0', () => {
    structuredLog('info', 'RUNNER_LISTENING', {
      port: config.PORT,
      fakeMode: config.AUTOMATION_FAKE_MODE,
      mode: config.ECAC_RS_MODE,
    });
  });

  return server;
}

const isMain =
  process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href;

if (isMain) {
  startServer();
}
