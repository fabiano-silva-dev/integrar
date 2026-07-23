export const ERROR_CODES = [
  'CERTIFICATE_FILE_MISSING',
  'CERTIFICATE_PASSWORD_FILE_MISSING',
  'CERTIFICATE_PASSWORD_INVALID',
  'CERTIFICATE_EXPIRED',
  'CERTIFICATE_ORIGIN_NOT_CONFIGURED',
  'CERTIFICATE_NOT_ACCEPTED',
  'TLS_CLIENT_AUTH_FAILED',
  'UNAPPROVED_REDIRECT_HOST',
  'PORTAL_UNAVAILABLE',
  'PORTAL_LAYOUT_CHANGED',
  'LOGIN_NOT_CONFIRMED',
  'NEEDS_ROLE_MAPPING',
  'MANUAL_CONFIRMATION_REQUIRED',
  'TIMEOUT',
  'RUNNER_BUSY',
  'ARTIFACT_UPLOAD_FAILED',
  'FLOW_NOT_IMPLEMENTED',
  'UNEXPECTED_ERROR',
] as const;

export type ErrorCode = (typeof ERROR_CODES)[number];

export const SAFE_MESSAGES: Record<ErrorCode, string> = {
  CERTIFICATE_FILE_MISSING: 'Arquivo do certificado digital não encontrado.',
  CERTIFICATE_PASSWORD_FILE_MISSING: 'Arquivo da senha do certificado não encontrado.',
  CERTIFICATE_PASSWORD_INVALID: 'Senha do certificado digital inválida.',
  CERTIFICATE_EXPIRED: 'Certificado digital expirado.',
  CERTIFICATE_ORIGIN_NOT_CONFIGURED:
    'Origem que exige certificado ainda não configurada. Execute o modo discovery.',
  CERTIFICATE_NOT_ACCEPTED: 'O portal não aceitou o certificado digital apresentado.',
  TLS_CLIENT_AUTH_FAILED: 'Falha na autenticação TLS com certificado de cliente.',
  UNAPPROVED_REDIRECT_HOST: 'Redirecionamento para host não autorizado.',
  PORTAL_UNAVAILABLE: 'Portal indisponível no momento.',
  PORTAL_LAYOUT_CHANGED: 'Layout do portal alterado; seletores precisam de revisão.',
  LOGIN_NOT_CONFIRMED: 'Não foi possível confirmar o login com segurança.',
  NEEDS_ROLE_MAPPING: 'Seleção de papel/perfil detectada; intervenção necessária.',
  MANUAL_CONFIRMATION_REQUIRED: 'CAPTCHA, MFA ou confirmação manual requerida.',
  TIMEOUT: 'Tempo limite da execução excedido.',
  RUNNER_BUSY: 'Runner ocupado com outra execução.',
  ARTIFACT_UPLOAD_FAILED: 'Falha ao enviar artefato para a plataforma.',
  FLOW_NOT_IMPLEMENTED: 'Fluxo ainda não implementado no runner.',
  UNEXPECTED_ERROR: 'Erro inesperado durante a automação.',
};
