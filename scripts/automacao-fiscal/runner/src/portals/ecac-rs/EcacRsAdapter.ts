import type { AutomationContext, AutomationResult, PortalAdapter } from '../../automation/types.js';
import { AutomationError } from '../../errors/AutomationError.js';
import { EcacRsCertificateFlow } from './EcacRsCertificateFlow.js';
import { EcacRsDiscoveryFlow } from './EcacRsDiscoveryFlow.js';
import { EcacRsExtractNfeNfceFlow } from './EcacRsExtractNfeNfceFlow.js';

export class EcacRsAdapter implements PortalAdapter {
  readonly discovery = new EcacRsDiscoveryFlow();
  readonly certificate = new EcacRsCertificateFlow();
  readonly extractNfeNfce = new EcacRsExtractNfeNfceFlow(this.certificate);

  async validateAccess(context: AutomationContext): Promise<AutomationResult> {
    if (context.mode === 'discovery') {
      return this.discovery.run(context);
    }
    if (context.mode === 'certificate') {
      return this.certificate.run(context);
    }
    throw new AutomationError(
      'UNEXPECTED_ERROR',
      `EcacRsAdapter não suporta modo ${context.mode}`,
    );
  }

  async execute(context: AutomationContext): Promise<AutomationResult> {
    if (context.operation === 'extract-nfe-nfce') {
      return this.extractNfeNfce.run(context);
    }

    throw new AutomationError(
      'FLOW_NOT_IMPLEMENTED',
      `Operação ${context.operation} não suportada no e-CAC RS`,
    );
  }
}
