import { z } from 'zod';
import { parseHostSuffixes } from '../security/allowlist.js';

const booleanFromEnv = z
  .union([z.boolean(), z.string()])
  .transform((value) => {
    if (typeof value === 'boolean') {
      return value;
    }
    const normalized = value.trim().toLowerCase();
    return normalized === '1' || normalized === 'true' || normalized === 'yes';
  });

const csvOrigins = z.string().transform((value) => {
  if (!value || value.trim() === '') {
    return [] as string[];
  }
  return value
    .split(',')
    .map((item) => item.trim())
    .filter((item) => item.length > 0)
    .map((origin) => {
      const url = new URL(origin);
      if (url.protocol !== 'https:') {
        throw new Error(`Origem de certificado deve ser HTTPS: ${origin}`);
      }
      return url.origin;
    });
});

const ulidLike = z.string().min(1);

function assertEntryInAllowlist(
  entryUrl: string,
  suffixes: string[],
  path: string,
  ctx: z.RefinementCtx,
): void {
  try {
    const entry = new URL(entryUrl);
    const allowed = suffixes.some((suffix) => {
      const host = entry.hostname.toLowerCase();
      const normalized = suffix.toLowerCase().replace(/^\./, '');
      return host === normalized || host.endsWith(`.${normalized}`);
    });
    if (!allowed) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: [path],
        message: `${path} fora da allowlist de hosts`,
      });
    }
  } catch {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      path: [path],
      message: `${path} inválida`,
    });
  }
}

export const portalCodeSchema = z.enum(['ecac-rs', 'nfse-emissor']);
export type PortalCode = z.infer<typeof portalCodeSchema>;

export const envSchema = z
  .object({
    RUNNER_INTERNAL_TOKEN: z.string().min(16, 'RUNNER_INTERNAL_TOKEN deve ter ao menos 16 caracteres'),
    PLATFORM_BASE_URL: z.string().url(),
    AUTOMATION_FAKE_MODE: booleanFromEnv.default(true),
    AUTOMATION_HEADLESS: booleanFromEnv.default(true),
    AUTOMATION_TIMEOUT_MS: z.coerce.number().int().positive().default(120_000),
    ECAC_RS_MODE: z.enum(['fake', 'discovery', 'certificate']).default('discovery'),
    ECAC_RS_ENTRY_URL: z.string().url(),
    ECAC_RS_CERT_ORIGINS: csvOrigins.default(''),
    ECAC_RS_ALLOWED_HOST_SUFFIXES: z
      .string()
      .min(1)
      .transform((value) => parseHostSuffixes(value)),
    NFSE_EMISSOR_MODE: z.enum(['fake', 'discovery', 'certificate']).default('discovery'),
    NFSE_EMISSOR_ENTRY_URL: z
      .string()
      .url()
      .default('https://www.nfse.gov.br/EmissorNacional/Login'),
    NFSE_EMISSOR_CERT_ORIGINS: csvOrigins.default(''),
    NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES: z
      .string()
      .min(1)
      .default('nfse.gov.br')
      .transform((value) => parseHostSuffixes(value)),
    ECAC_A1_PFX_FILE: z.string().min(1).default('/run/secrets/ecac_a1_pfx'),
    ECAC_A1_PASSWORD_FILE: z.string().min(1).default('/run/secrets/ecac_a1_password'),
    PORT: z.coerce.number().int().positive().default(3000),
    /** Diretório durável (ex.: storage do Laravel). Se vazio, usa /tmp e limpa ao final. */
    AUTOMATION_ARTIFACT_DIR: z.string().optional().default(''),
  })
  .superRefine((data, ctx) => {
    assertEntryInAllowlist(
      data.ECAC_RS_ENTRY_URL,
      data.ECAC_RS_ALLOWED_HOST_SUFFIXES,
      'ECAC_RS_ENTRY_URL',
      ctx,
    );
    assertEntryInAllowlist(
      data.NFSE_EMISSOR_ENTRY_URL,
      data.NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES,
      'NFSE_EMISSOR_ENTRY_URL',
      ctx,
    );
  });

export type EnvConfig = z.infer<typeof envSchema>;

export const validateRequestSchema = z.object({
  runId: ulidLike.regex(/^[0-9A-HJKMNP-TV-Z]{26}$/i, 'runId deve ser um ULID válido'),
  mode: z.enum(['fake', 'discovery', 'certificate']).optional(),
});

export type ValidateRequest = z.infer<typeof validateRequestSchema>;

export const runRequestSchema = z.object({
  runId: ulidLike.regex(/^[0-9A-HJKMNP-TV-Z]{26}$/i, 'runId deve ser um ULID válido'),
  operation: z.string().min(1).default('validate-access'),
  mode: z.enum(['fake', 'discovery', 'certificate']).optional(),
  params: z.record(z.unknown()).optional().default({}),
});

export type RunRequest = z.infer<typeof runRequestSchema>;

export function loadEnv(env: NodeJS.ProcessEnv = process.env): EnvConfig {
  return envSchema.parse(env);
}

export function loadEnvSafe(env: NodeJS.ProcessEnv = process.env):
  | { success: true; data: EnvConfig }
  | { success: false; error: z.ZodError } {
  const result = envSchema.safeParse(env);
  if (result.success) {
    return { success: true, data: result.data };
  }
  return { success: false, error: result.error };
}

export function resolvePortalMode(config: EnvConfig, portal: PortalCode): AutomationModeLike {
  if (config.AUTOMATION_FAKE_MODE) {
    return 'fake';
  }
  if (portal === 'nfse-emissor') {
    return config.NFSE_EMISSOR_MODE;
  }
  return config.ECAC_RS_MODE;
}

export function portalEntryUrl(config: EnvConfig, portal: PortalCode): string {
  if (portal === 'nfse-emissor') {
    return config.NFSE_EMISSOR_ENTRY_URL;
  }
  return config.ECAC_RS_ENTRY_URL;
}

export function portalAllowedHosts(config: EnvConfig, portal: PortalCode): string[] {
  if (portal === 'nfse-emissor') {
    return config.NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES;
  }
  return config.ECAC_RS_ALLOWED_HOST_SUFFIXES;
}

export function portalCertOrigins(config: EnvConfig, portal: PortalCode): string[] {
  if (portal === 'nfse-emissor') {
    return config.NFSE_EMISSOR_CERT_ORIGINS;
  }
  return config.ECAC_RS_CERT_ORIGINS;
}

type AutomationModeLike = 'fake' | 'discovery' | 'certificate';
