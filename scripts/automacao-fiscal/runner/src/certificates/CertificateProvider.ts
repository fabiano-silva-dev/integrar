export type ClientCertificateMaterial = {
  origin: string;
  /** Preferir cert+key quando o PFX usa algoritmos legados (RC2) incompatíveis com OpenSSL 3. */
  cert?: Buffer;
  key?: Buffer;
  pfx?: Buffer;
  passphrase?: string;
};

export type CertificateMetadata = {
  subjectName: string | null;
  issuerName: string | null;
  validFrom: Date | null;
  expiresAt: Date | null;
  fingerprintSha256: string | null;
};

export interface CertificateProvider {
  readonly name: string;
  loadClientCertificates(origins: string[]): Promise<ClientCertificateMaterial[]>;
  getMetadata(): Promise<CertificateMetadata>;
  dispose(): Promise<void>;
}
