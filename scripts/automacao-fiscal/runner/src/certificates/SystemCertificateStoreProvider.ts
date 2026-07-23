import type {
  CertificateMetadata,
  CertificateProvider,
  ClientCertificateMaterial,
} from './CertificateProvider.js';

/**
 * Stub para futuro agente local (Windows / A3 / Certificate Store).
 * Não implementado nesta fase.
 */
export class SystemCertificateStoreProvider implements CertificateProvider {
  readonly name = 'system-certificate-store';

  async loadClientCertificates(_origins: string[]): Promise<ClientCertificateMaterial[]> {
    throw new Error(
      'SystemCertificateStoreProvider não implementado nesta fase. Use PfxFileCertificateProvider.',
    );
  }

  async getMetadata(): Promise<CertificateMetadata> {
    throw new Error(
      'SystemCertificateStoreProvider não implementado nesta fase. Use PfxFileCertificateProvider.',
    );
  }

  async dispose(): Promise<void> {
    // no-op
  }
}
