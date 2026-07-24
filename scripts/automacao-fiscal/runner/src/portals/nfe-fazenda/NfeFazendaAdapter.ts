import type { AutomationContext, AutomationResult, PortalAdapter } from '../../automation/types.js';
import { AutomationError } from '../../errors/AutomationError.js';
import { NfeFazendaDownloadFlow } from './NfeFazendaDownloadFlow.js';

export class NfeFazendaAdapter implements PortalAdapter {
  readonly download = new NfeFazendaDownloadFlow();

  async validateAccess(context: AutomationContext): Promise<AutomationResult> {
    throw new AutomationError(
      'FLOW_NOT_IMPLEMENTED',
      `validate-access não se aplica ao portal nfe-fazenda (modo=${context.mode})`,
    );
  }

  async execute(context: AutomationContext): Promise<AutomationResult> {
    if (context.operation === 'download-nfe-xml') {
      return this.download.run(context);
    }

    throw new AutomationError(
      'FLOW_NOT_IMPLEMENTED',
      `Operação ${context.operation} não suportada no portal nacional da NF-e`,
    );
  }
}
