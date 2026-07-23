import type { Page } from 'playwright';
import type { SuccessDetection } from '../../automation/types.js';
import { sanitizeString } from '../../security/sanitize.js';
import { NfseEmissorSelectors } from './NfseEmissorSelectors.js';

export class NfseEmissorSuccessDetector {
  async detect(page: Page): Promise<SuccessDetection> {
    const evidence: string[] = [];
    let score = 0;

    const url = page.url();
    evidence.push(`url-path:${safePath(url)}`);

    if (/\/EmissorNacional\/Login/i.test(url)) {
      score -= 3;
      evidence.push('still-on-login');
    }
    if (/certificado\.nfse\.gov\.br/i.test(url)) {
      score -= 1;
      evidence.push('still-on-certificate-host');
    }
    if (
      /nfse\.gov\.br/i.test(url) &&
      /\/EmissorNacional\//i.test(url) &&
      !/\/Login/i.test(url) &&
      !/\/Certificado/i.test(url)
    ) {
      score += 4;
      evidence.push('authenticated-emissor-path');
    }

    const hasLogout = await NfseEmissorSelectors.logout(page)
      .isVisible({ timeout: 1_000 })
      .catch(() => false);
    if (hasLogout) {
      score += 3;
      evidence.push('logout-control-present');
    }

    const hasHints = await NfseEmissorSelectors.authenticatedHints(page)
      .first()
      .isVisible({ timeout: 1_000 })
      .catch(() => false);
    if (hasHints) {
      score += 3;
      evidence.push('authenticated-hints-present');
    }

    const hasAuthenticatedMenu = await NfseEmissorSelectors.authenticatedMenu(page)
      .first()
      .isVisible({ timeout: 1_000 })
      .catch(() => false);
    if (hasAuthenticatedMenu) {
      score += 2;
      evidence.push('authenticated-menu-present');
    }

    const hasLoginForm = await NfseEmissorSelectors.loginForm(page)
      .first()
      .isVisible({ timeout: 500 })
      .catch(() => false);
    if (hasLoginForm) {
      score -= 3;
      evidence.push('login-form-still-present');
    }

    const hasCertError = await NfseEmissorSelectors.certificateError(page)
      .first()
      .isVisible({ timeout: 500 })
      .catch(() => false);
    if (hasCertError) {
      score -= 4;
      evidence.push('certificate-error-message');
    }

    const hasRoleSelection = await NfseEmissorSelectors.roleSelection(page)
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
