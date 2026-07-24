import { z } from 'zod';

const operacaoEnum = z.enum([
  'saida-consulente',
  'saida-terceiros',
  'entrada-consulente',
  'entrada-terceiros',
]);

export const extractNfeNfceParamsSchema = z
  .object({
    ie: z.string().regex(/^\d+$/).nullable().optional(),
    cnpj: z.string().regex(/^\d{14}$/).nullable().optional(),
    modelo: z.enum(['nfe', 'nfce', 'ambos']),
    totalizadoPorMes: z.boolean().default(false),
    periodoInicial: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    periodoFinal: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    operacao: operacaoEnum,
    excluirVendaForaEstabelecimento: z.boolean().default(false),
    situacaoNormal: z.boolean().default(true),
    situacaoCancelada: z.boolean().default(false),
  })
  .superRefine((value, ctx) => {
    const hasIe = Boolean(value.ie);
    const hasCnpj = Boolean(value.cnpj);
    if (!hasIe && !hasCnpj) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: 'Informe IE ou CNPJ',
        path: ['ie'],
      });
    }
    if (!value.situacaoNormal && !value.situacaoCancelada) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: 'Selecione ao menos uma situação',
        path: ['situacaoNormal'],
      });
    }
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
    if (days > 30) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: 'Período máximo de 31 dias',
        path: ['periodoFinal'],
      });
    }
  });

export type ExtractNfeNfceParams = z.infer<typeof extractNfeNfceParamsSchema>;

export function parseExtractNfeNfceParams(raw: Record<string, unknown>): ExtractNfeNfceParams {
  return extractNfeNfceParamsSchema.parse(raw);
}

/** Converte YYYY-MM-DD → DD/MM/AAAA (formato do portal). */
export function isoToBrDate(iso: string): string {
  const [year, month, day] = iso.split('-');
  if (!year || !month || !day) {
    throw new Error(`Data ISO inválida: ${iso}`);
  }
  return `${day}/${month}/${year}`;
}

/** Máscara CNPJ do formulário nfe-ics-ext (sem máscara o Consultar pode não postar). */
export function formatCnpjBr(digitsOrMasked: string): string {
  const digits = digitsOrMasked.replace(/\D/g, '');
  if (digits.length !== 14) {
    return digitsOrMasked;
  }
  return digits.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
}

/** Textos reais do formulário nfe-ics-ext.aspx (Sefaz RS). */
export const OPERACAO_LABELS: Record<ExtractNfeNfceParams['operacao'], RegExp> = {
  'saida-consulente': /sa[ií]da\s+emitidas\s+pelo\s+consulente/i,
  'saida-terceiros': /sa[ií]da\s+emitidas\s+por\s+terceiros/i,
  'entrada-consulente': /entrada\s+emitidas\s+pelo\s+consulente/i,
  'entrada-terceiros': /entrada\s+emitidas\s+por\s+terceiros/i,
};

export const MODELO_LABELS: Record<'nfe' | 'nfce', RegExp> = {
  nfe: /\bnf-?e\b(?!c)|modelo\s*55/i,
  nfce: /\bnfc-?e\b|modelo\s*65/i,
};
