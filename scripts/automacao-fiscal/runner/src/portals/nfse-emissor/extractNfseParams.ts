import { z } from 'zod';

/** Emitidas = prestadas; Recebidas = tomadas. */
export const nfseTipoEnum = z.enum(['emitidas', 'recebidas']);

/**
 * Querystring real do Emissor Nacional:
 *   /EmissorNacional/Notas/{Emitidas|Recebidas}
 *     ?executar=1&busca=&datainicio=DD/MM/AAAA&datafim=DD/MM/AAAA
 *
 * O portal limita o filtro a 30 dias (mensagem na UI).
 */
export const extractNfseParamsSchema = z
  .object({
    tipo: nfseTipoEnum,
    periodoInicial: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    periodoFinal: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    /** Campo "Pesquisar pessoa física ou jurídica" (opcional). */
    busca: z.string().max(120).optional().default(''),
  })
  .superRefine((value, ctx) => {
    const start = new Date(`${value.periodoInicial}T00:00:00Z`);
    const end = new Date(`${value.periodoFinal}T00:00:00Z`);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end < start) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: 'Período final deve ser >= inicial',
        path: ['periodoFinal'],
      });
      return;
    }
    const days = Math.round((end.getTime() - start.getTime()) / 86_400_000);
    // Portal: "períodos superiores a 30 dias" — a UI padrão usa janela de 30 dias
    // de diferença (ex.: 23/06 → 23/07).
    if (days > 30) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: 'Período máximo de 30 dias',
        path: ['periodoFinal'],
      });
    }
  });

export type ExtractNfseParams = z.infer<typeof extractNfseParamsSchema>;

export function parseExtractNfseParams(raw: Record<string, unknown>): ExtractNfseParams {
  return extractNfseParamsSchema.parse(raw);
}

/** Converte YYYY-MM-DD → DD/MM/AAAA (formato do portal). */
export function isoToBrDate(iso: string): string {
  const [year, month, day] = iso.split('-');
  if (!year || !month || !day) {
    throw new Error(`Data ISO inválida: ${iso}`);
  }
  return `${day}/${month}/${year}`;
}

const NOTAS_PATH: Record<ExtractNfseParams['tipo'], string> = {
  emitidas: 'Emitidas',
  recebidas: 'Recebidas',
};

/**
 * Monta a URL de listagem já com filtro aplicado (executar=1),
 * igual ao navegador ao clicar em "Filtrar".
 * Paginação do portal: `pg=2`, `pg=3`, …
 */
export function buildNfseNotasListUrl(
  params: ExtractNfseParams,
  baseOrigin = 'https://www.nfse.gov.br',
  pagina = 1,
): string {
  const origin = baseOrigin.replace(/\/+$/, '');
  const path = `/EmissorNacional/Notas/${NOTAS_PATH[params.tipo]}`;
  const query = new URLSearchParams({
    executar: '1',
    busca: params.busca ?? '',
    datainicio: isoToBrDate(params.periodoInicial),
    datafim: isoToBrDate(params.periodoFinal),
  });
  if (pagina > 1) {
    query.set('pg', String(pagina));
  }
  return `${origin}${path}?${query.toString()}`;
}
