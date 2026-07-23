import type { Page } from 'playwright';
import type { SuccessDetection } from '../../automation/types.js';
import { EcacRsSelectors } from './EcacRsSelectors.js';
import { sanitizeString } from '../../security/sanitize.js';

export class EcacRsSuccessDetector {
  async detect(page: Page): Promise<SuccessDetection> {
    const evidence: string[] = [];
    let score = 0;

    const url = page.url();
    evidence.push(`url-path:${safePath(url)}`);

    // Área autenticada conhecida da Sefaz RS / e-CAC
    if (/\/Receita\/PainelUsuario\.aspx|\/PainelUsuario\.aspx/i.test(url)) {
      score += 4;
      evidence.push('painel-usuario-url');
    }
    if (/LoginCertACRS\.aspx|LoginEcacCert\.aspx/i.test(url)) {
      score -= 2;
      evidence.push('still-on-certificate-login');
    }

    const hasLogout = await EcacRsSelectors.logout(page)
      .isVisible({ timeout: 1_000 })
      .catch(() => false);
    if (hasLogout) {
      score += 3;
      evidence.push('logout-control-present');
    }

    const hasMeusServicos = await EcacRsSelectors.meusServicos(page)
      .first()
      .isVisible({ timeout: 1_000 })
      .catch(() => false);
    if (hasMeusServicos) {
      score += 3;
      evidence.push('meus-servicos-present');
    }

    const hasAuthenticatedMenu = await EcacRsSelectors.authenticatedMenu(page)
      .first()
      .isVisible({ timeout: 1_000 })
      .catch(() => false);
    if (hasAuthenticatedMenu) {
      score += 2;
      evidence.push('authenticated-menu-present');
    }

    const hasLoginForm = await EcacRsSelectors.loginForm(page)
      .first()
      .isVisible({ timeout: 500 })
      .catch(() => false);
    if (hasLoginForm) {
      score -= 3;
      evidence.push('login-form-still-present');
    }

    const hasCertError = await EcacRsSelectors.certificateError(page)
      .first()
      .isVisible({ timeout: 500 })
      .catch(() => false);
    if (hasCertError) {
      score -= 4;
      evidence.push('certificate-error-message');
    }

    const hasRoleSelection = await EcacRsSelectors.roleSelection(page)
      .first()
      .isVisible({ timeout: 500 })
      .catch(() => false);
    if (hasRoleSelection) {
      evidence.push('role-selection-present');
    }

    let confidence: SuccessDetection['confidence'] = 'low';
    if (score >= 6) {
      confidence = 'high';
    } else if (score >= 3) {
      confidence = 'medium';
    }

    const confirmed = confidence === 'high' && !hasLoginForm && !hasCertError && !hasRoleSelection;

    return {
      confirmed,
      confidence,
      evidence: evidence.map((item) => sanitizeString(item)),
    };
  }
}

function safePath(rawUrl: string): string {
  try {
    const url = new URL(rawUrl);
    return `${url.hostname}${url.pathname}`;
  } catch {
    return '[invalid-url]';
  }
}
