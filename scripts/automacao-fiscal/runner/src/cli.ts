#!/usr/bin/env node
/**
 * Entrada CLI para o IntegraExpert.
 * Lê JSON de um arquivo (--input) ou stdin e imprime o resultado final em stdout.
 * Eventos intermediários vão para stderr como linhas NDJSON (type=event).
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { loadEnv } from './config/env.js';
import { AutomationRunner } from './automation/AutomationRunner.js';
import { z } from 'zod';

const inputSchema = z.object({
  runId: z.string().min(1),
  portal: z.enum(['ecac-rs', 'nfse-emissor', 'nfe-fazenda']).default('ecac-rs'),
  operation: z.string().min(1).default('validate-access'),
  mode: z.enum(['fake', 'discovery', 'certificate']).optional(),
  params: z.record(z.unknown()).default({}),
});

async function readInput(): Promise<unknown> {
  const args = process.argv.slice(2);
  const inputIdx = args.indexOf('--input');
  const inputPath = inputIdx >= 0 ? args[inputIdx + 1] : undefined;
  if (inputPath) {
    return JSON.parse(readFileSync(inputPath, 'utf8'));
  }

  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) {
    chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
  }
  const raw = Buffer.concat(chunks).toString('utf8').trim();
  if (!raw) {
    throw new Error('Informe JSON via --input arquivo.json ou stdin');
  }
  return JSON.parse(raw);
}

async function main(): Promise<void> {
  const parsed = inputSchema.parse(await readInput());
  const config = loadEnv();
  const runner = new AutomationRunner(config);

  // Modo CLI: não depende da API HTTP da platform (eventos vão para stderr).
  runner.platform.postEvent = async (runId, event) => {
    process.stderr.write(`${JSON.stringify({ type: 'event', runId, ...event })}\n`);
  };
  runner.platform.postArtifact = async (runId, artifact) => {
    // Evita NDJSON gigante no stderr (pipe do PHP Process trunca linhas grandes e
    // corrompe o restante do stream — exatamente o que fez o ExtratoNFe se perder).
    const maxBase64Chars = 80_000; // ~60KB binário
    let contentBase64: string | undefined;
    try {
      const raw = readFileSync(artifact.absolutePath);
      const encoded = raw.toString('base64');
      if (encoded.length <= maxBase64Chars) {
        contentBase64 = encoded;
      }
    } catch {
      contentBase64 = undefined;
    }
    process.stderr.write(
      `${JSON.stringify({
        type: 'artifact',
        runId,
        artifactType: artifact.type,
        filename: artifact.filename,
        absolutePath: artifact.absolutePath,
        mimeType: artifact.mimeType,
        sha256: artifact.sha256,
        size: artifact.size,
        ...(contentBase64 ? { contentBase64 } : {}),
      })}\n`,
    );
  };

  const result = await runner.run({
    runId: parsed.runId,
    portal: parsed.portal,
    operation: parsed.operation,
    ...(parsed.mode ? { mode: parsed.mode } : {}),
    params: parsed.params,
  });

  const payload = {
    type: 'result' as const,
    runId: parsed.runId,
    portal: parsed.portal,
    operation: parsed.operation,
    ...result,
  };

  // Arquivo durável: o pipe do PHP Process corta linhas stdout ~4KB.
  // AUTOMATION_ARTIFACT_DIR = {workDir}/artifacts → grava em {workDir}/result.json
  const artifactDir = config.AUTOMATION_ARTIFACT_DIR?.trim();
  if (artifactDir) {
    try {
      const resultPath = join(dirname(artifactDir), 'result.json');
      mkdirSync(dirname(resultPath), { recursive: true });
      writeFileSync(resultPath, `${JSON.stringify(payload)}\n`, { encoding: 'utf8', mode: 0o600 });
    } catch {
      // best-effort
    }
  }

  process.stdout.write(`${JSON.stringify(payload)}\n`);

  process.exit(result.status === 'succeeded' ? 0 : 1);
}

main().catch((error) => {
  process.stderr.write(
    `${JSON.stringify({
      type: 'error',
      message: error instanceof Error ? error.message : 'Erro desconhecido',
    })}\n`,
  );
  process.exit(2);
});
