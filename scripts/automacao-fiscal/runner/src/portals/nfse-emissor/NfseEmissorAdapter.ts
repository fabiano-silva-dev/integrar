import type { AutomationContext, AutomationResult, PortalAdapter } from '../../automation/types.js';
import { AutomationError } from '../../errors/AutomationError.js';
import { NfseEmissorCertificateFlow } from './NfseEmissorCertificateFlow.js';
import { NfseEmissorDiscoveryFlow } from './NfseEmissorDiscoveryFlow.js';
import { NfseEmissorExtractFlow } from './NfseEmissorExtractFlow.js';

export class NfseEmissorAdapter implements PortalAdapter {
  readonly discovery = new NfseEmissorDiscoveryFlow();
  readonly certificate = new NfseEmissorCertificateFlow();
  readonly extractNfse = new NfseEmissorExtractFlow(this.certificate);

  async validateAccess(context: AutomationContext): Promise<AutomationResult> {
    if (context.mode === 'discovery') {
      return this.discovery.run(context);
    }
    if (context.mode === 'certificate') {
      return this.certificate.run(context);
    }
    throw new AutomationError(
      'UNEXPECTED_ERROR',
      `NfseEmissorAdapter não suporta modo ${context.mode}`,
    );
  }

  async execute(context: AutomationContext): Promise<AutomationResult> {
    if (context.operation === 'extract-nfse') {
      return this.extractNfse.run(context);
    }

    throw new AutomationError(
      'FLOW_NOT_IMPLEMENTED',
      `Operação ${context.operation} não suportada no Portal Nacional da NFS-e`,
    );
  }
}
