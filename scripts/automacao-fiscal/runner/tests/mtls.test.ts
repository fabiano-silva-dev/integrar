import assert from 'node:assert/strict';
import { createServer as createHttpsServer, type Server as HttpsServer } from 'node:https';
import type { TLSSocket } from 'node:tls';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawn } from 'node:child_process';
import { after, before, describe, it } from 'node:test';
import { chromium } from 'playwright';

async function run(command: string, args: string[], cwd: string): Promise<void> {
  await new Promise<void>((resolve, reject) => {
    const child = spawn(command, args, { cwd, stdio: ['ignore', 'pipe', 'pipe'] });
    let stderr = '';
    child.stderr.on('data', (chunk: Buffer) => {
      stderr += chunk.toString('utf8');
    });
    child.on('error', reject);
    child.on('close', (code) => {
      if (code === 0) {
        resolve();
      } else {
        reject(new Error(`${command} ${args.join(' ')} failed: ${stderr}`));
      }
    });
  });
}

describe('mTLS local proof', () => {
  let workDir = '';
  let server: HttpsServer | null = null;
  let origin = '';
  let pfxPath = '';
  const passphrase = 'test-passphrase-not-real';

  before(async () => {
    workDir = await mkdtemp(join(tmpdir(), 'runner-mtls-'));

    // CA
    await run(
      'openssl',
      [
        'req',
        '-x509',
        '-newkey',
        'rsa:2048',
        '-nodes',
        '-keyout',
        'ca.key',
        '-out',
        'ca.crt',
        '-days',
        '1',
        '-subj',
        '/CN=PortalAutomationTestCA',
      ],
      workDir,
    );

    // Server cert
    await run(
      'openssl',
      [
        'req',
        '-newkey',
        'rsa:2048',
        '-nodes',
        '-keyout',
        'server.key',
        '-out',
        'server.csr',
        '-subj',
        '/CN=localhost',
      ],
      workDir,
    );
    await writeFile(
      join(workDir, 'server.ext'),
      'subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n',
    );
    await run(
      'openssl',
      [
        'x509',
        '-req',
        '-in',
        'server.csr',
        '-CA',
        'ca.crt',
        '-CAkey',
        'ca.key',
        '-CAcreateserial',
        '-out',
        'server.crt',
        '-days',
        '1',
        '-extfile',
        'server.ext',
      ],
      workDir,
    );

    // Client cert
    await run(
      'openssl',
      [
        'req',
        '-newkey',
        'rsa:2048',
        '-nodes',
        '-keyout',
        'client.key',
        '-out',
        'client.csr',
        '-subj',
        '/CN=PortalAutomationTestClient',
      ],
      workDir,
    );
    await writeFile(join(workDir, 'client.ext'), 'extendedKeyUsage=clientAuth\n');
    await run(
      'openssl',
      [
        'x509',
        '-req',
        '-in',
        'client.csr',
        '-CA',
        'ca.crt',
        '-CAkey',
        'ca.key',
        '-CAcreateserial',
        '-out',
        'client.crt',
        '-days',
        '1',
        '-extfile',
        'client.ext',
      ],
      workDir,
    );

    pfxPath = join(workDir, 'client.pfx');
    await run(
      'openssl',
      [
        'pkcs12',
        '-export',
        '-out',
        'client.pfx',
        '-inkey',
        'client.key',
        '-in',
        'client.crt',
        '-certfile',
        'ca.crt',
        '-password',
        `pass:${passphrase}`,
      ],
      workDir,
    );

    const [caCert, serverCert, serverKey] = await Promise.all([
      readFile(join(workDir, 'ca.crt')),
      readFile(join(workDir, 'server.crt')),
      readFile(join(workDir, 'server.key')),
    ]);

    server = createHttpsServer(
      {
        key: serverKey,
        cert: serverCert,
        ca: caCert,
        requestCert: true,
        rejectUnauthorized: true,
      },
      (req, res) => {
        const socket = req.socket as TLSSocket;
        const peer = socket.getPeerCertificate();
        if (!socket.authorized || !peer || !peer.subject) {
          res.writeHead(401, { 'content-type': 'text/plain' });
          res.end('unauthorized');
          return;
        }
        res.writeHead(200, { 'content-type': 'text/plain' });
        res.end('mtls-ok');
      },
    );

    await new Promise<void>((resolve) => {
      server!.listen(0, '127.0.0.1', () => resolve());
    });
    const address = server.address();
    if (!address || typeof address === 'string') {
      throw new Error('https address inválido');
    }
    origin = `https://127.0.0.1:${address.port}`;
  });

  after(async () => {
    await new Promise<void>((resolve, reject) => {
      server?.close((error) => (error ? reject(error) : resolve()));
    });
    if (workDir) {
      await rm(workDir, { recursive: true, force: true });
    }
  });

  it('falha sem certificado de cliente', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
      const context = await browser.newContext({ ignoreHTTPSErrors: true });
      const page = await context.newPage();
      let failed = false;
      try {
        await page.goto(origin, { waitUntil: 'domcontentloaded', timeout: 10_000 });
        const body = await page.textContent('body');
        failed = body !== 'mtls-ok';
      } catch {
        failed = true;
      }
      assert.equal(failed, true);
      await context.close();
    } finally {
      await browser.close();
    }
  });

  it('autentica com PFX via Playwright clientCertificates', async () => {
    const pfx = await readFile(pfxPath);
    const browser = await chromium.launch({ headless: true });
    try {
      const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        clientCertificates: [
          {
            origin,
            pfx,
            passphrase,
          },
        ],
      });
      const page = await context.newPage();
      await page.goto(origin, { waitUntil: 'domcontentloaded', timeout: 15_000 });
      const body = await page.textContent('body');
      assert.equal(body, 'mtls-ok');
      await context.close();
    } finally {
      await browser.close();
    }
  });
});
