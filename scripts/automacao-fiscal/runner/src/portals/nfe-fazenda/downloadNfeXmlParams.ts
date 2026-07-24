import { z } from 'zod';

export const downloadNfeXmlParamsSchema = z.object({
  chaveAcesso: z
    .string()
    .trim()
    .transform((v) => v.replace(/\D+/g, ''))
    .refine((v) => v.length === 44, 'chaveAcesso deve ter 44 dígitos'),
});

export type DownloadNfeXmlParams = z.infer<typeof downloadNfeXmlParamsSchema>;

export function parseDownloadNfeXmlParams(input: unknown): DownloadNfeXmlParams {
  return downloadNfeXmlParamsSchema.parse(input);
}
