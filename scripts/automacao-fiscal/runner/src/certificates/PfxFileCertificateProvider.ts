import { X509Certificate } from 'node:crypto';
import { access, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { constants as fsConstants } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawn } from 'node:child_process';
import type {
  CertificateMetadata,
  CertificateProvider,
  ClientCertificateMaterial,
} from './CertificateProvider.js';
import { AutomationError } from '../errors/AutomationError.js';

const MAX_PFX_BYTES = 5 * 1024 * 1024;

export type PfxFileCertificateProviderOptions = {
  pfxFile: string;
  passwordFile: string;
};

type LoadedMaterial = {
  certPem: Buffer;
  keyPem: Buffer;
  metadata: CertificateMetadata;
};

export class PfxFileCertificateProvider implements CertificateProvider {
  readonly name = 'pfx-file';

  #material: LoadedMaterial | null = null;

  constructor(private readonly options: PfxFileCertificateProviderOptions) {}

  async loadClientCertificates(origins: string[]): Promise<ClientCertificateMaterial[]> {
    await this.#ensureLoaded();
    if (!this.#material) {
      throw new AutomationError('UNEXPECTED_ERROR', 'Material de certificado não carregado');
    }

    if (origins.length === 0) {
      throw new AutomationError(
        'CERTIFICATE_ORIGIN_NOT_CONFIGURED',
        'Nenhuma origem de certificado configurada em ECAC_RS_CERT_ORIGINS',
      );
    }

    return origins.map((origin) => ({
      origin,
      cert: Buffer.from(this.#material!.certPem),
      key: Buffer.from(this.#material!.keyPem),
    }));
  }

  async getMetadata(): Promise<CertificateMetadata> {
    await this.#ensureLoaded();
    return (
      this.#material?.metadata ?? {
        subjectName: null,
        issuerName: null,
        validFrom: null,
        expiresAt: null,
        fingerprintSha256: null,
      }
    );
  }

  async dispose(): Promise<void> {
    if (this.#material) {
      this.#material.certPem.fill(0);
      this.#material.keyPem.fill(0);
    }
    this.#material = null;
  }

  async #ensureLoaded(): Promise<void> {
    if (this.#material) {
      return;
    }

    try {
      await access(this.options.pfxFile, fsConstants.R_OK);
    } catch {
      throw new AutomationError(
        'CERTIFICATE_FILE_MISSING',
        `Arquivo PFX ausente em caminho configurado`,
      );
    }

    try {
      await access(this.options.passwordFile, fsConstants.R_OK);
    } catch {
      throw new AutomationError(
        'CERTIFICATE_PASSWORD_FILE_MISSING',
        `Arquivo de senha ausente em caminho configurado`,
      );
    }

    const pfx = await readFile(this.options.pfxFile);
    if (pfx.byteLength === 0) {
      throw new AutomationError('CERTIFICATE_FILE_MISSING', 'Arquivo PFX vazio');
    }
    if (pfx.byteLength > MAX_PFX_BYTES) {
      throw new AutomationError(
        'UNEXPECTED_ERROR',
        `Arquivo PFX excede o tamanho máximo de ${MAX_PFX_BYTES} bytes`,
      );
    }

    const passphrase = (await readFile(this.options.passwordFile, 'utf8')).trim();
    if (!passphrase) {
      throw new AutomationError(
        'CERTIFICATE_PASSWORD_FILE_MISSING',
        'Arquivo de senha do certificado está vazio',
      );
    }

    const material = await extractModernMaterial(pfx, passphrase);

    if (material.metadata.expiresAt && material.metadata.expiresAt.getTime() < Date.now()) {
      throw new AutomationError('CERTIFICATE_EXPIRED', 'Certificado A1 expirado', {
        metadata: {
          expiresAt: material.metadata.expiresAt.toISOString(),
        },
      });
    }

    this.#material = material;
  }
}

async function extractModernMaterial(pfx: Buffer, passphrase: string): Promise<LoadedMaterial> {
  const workDir = await mkdtemp(join(tmpdir(), 'runner-pfx-'));
  const pfxPath = join(workDir, 'cert.pfx');
  const passPath = join(workDir, 'pass.txt');
  const certPath = join(workDir, 'cert.pem');
  const keyPath = join(workDir, 'key.pem');

  try {
    await writeFile(pfxPath, pfx, { mode: 0o600 });
    await writeFile(passPath, passphrase, { mode: 0o600 });

    // Extrai cadeia completa + chave com -legacy (PFX ICP-Brasil / RC2).
    // Sem -clcerts: IIS/Sefaz exige intermediárias (403.16 se faltar).
    const certResult = await runOpenSslWithLegacyFallback([
      'pkcs12',
      '-in',
      pfxPath,
      '-passin',
      `file:${passPath}`,
      '-nokeys',
      '-out',
      certPath,
    ]);
    assertOpenSslOk(certResult, 'certificado');

    const keyResult = await runOpenSslWithLegacyFallback([
      'pkcs12',
      '-in',
      pfxPath,
      '-passin',
      `file:${passPath}`,
      '-nocerts',
      '-nodes',
      '-out',
      keyPath,
    ]);
    assertOpenSslOk(keyResult, 'chave privada');

    const certPemText = await readFile(certPath, 'utf8');
    const keyPemText = await readFile(keyPath, 'utf8');
    const certBlocks =
      certPemText.match(/-----BEGIN CERTIFICATE-----[\s\S]+?-----END CERTIFICATE-----/g) ?? [];
    const keyMatch =
      /-----BEGIN (?:RSA |EC )?PRIVATE KEY-----[\s\S]+?-----END (?:RSA |EC )?PRIVATE KEY-----/.exec(
        keyPemText,
      );

    if (certBlocks.length === 0 || !keyMatch) {
      throw new AutomationError(
        'UNEXPECTED_ERROR',
        'Não foi possível extrair certificado/chave do PFX',
      );
    }

    const leaf = new X509Certificate(certBlocks[0]!);
    const metadata: CertificateMetadata = {
      subjectName: leaf.subject,
      issuerName: leaf.issuer,
      validFrom: new Date(leaf.validFrom),
      expiresAt: new Date(leaf.validTo),
      fingerprintSha256: leaf.fingerprint256.replace(/:/g, '').toLowerCase(),
    };

    return {
      certPem: Buffer.from(`${certBlocks.join('\n')}\n`, 'utf8'),
      keyPem: Buffer.from(keyMatch[0], 'utf8'),
      metadata,
    };
  } finally {
    await rm(workDir, { recursive: true, force: true });
    pfx.fill(0);
  }
}

function assertOpenSslOk(
  result: { code: number; stderr: string },
  label: string,
): void {
  if (result.code === 0) {
    return;
  }
  const stderr = result.stderr.toLowerCase();
  if (
    stderr.includes('mac verify failure') ||
    stderr.includes('invalid password') ||
    stderr.includes('password required') ||
    stderr.includes('bad decrypt')
  ) {
    throw new AutomationError(
      'CERTIFICATE_PASSWORD_INVALID',
      'Falha ao abrir PFX com a senha informada',
    );
  }
  throw new AutomationError(
    'UNEXPECTED_ERROR',
    `Falha ao extrair ${label} do PFX (openssl exit ${result.code})`,
  );
}

async function runOpenSslWithLegacyFallback(
  args: string[],
): Promise<{ code: number; stdout: string; stderr: string }> {
  let result = await runOpenSsl(args);
  if (result.code !== 0) {
    result = await runOpenSsl([...args, '-legacy']);
  }
  return result;
}

function runOpenSsl(
  args: string[],
): Promise<{ code: number; stdout: string; stderr: string }> {
  return new Promise((resolve, reject) => {
    const child = spawn('openssl', args, { stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (chunk: Buffer) => {
      stdout += chunk.toString('utf8');
    });
    child.stderr.on('data', (chunk: Buffer) => {
      stderr += chunk.toString('utf8');
    });
    child.on('error', reject);
    child.on('close', (code) => {
      resolve({ code: code ?? 1, stdout, stderr });
    });
  });
}
