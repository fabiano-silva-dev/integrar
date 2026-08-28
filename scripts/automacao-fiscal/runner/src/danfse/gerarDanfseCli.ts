#!/usr/bin/env node
/**
 * Gera DANFSe (PDF) local a partir do XML nacional da NFS-e.
 * Uso: node dist/danfse/gerarDanfseCli.js --input nota.xml --output nota.pdf
 */
import { readFileSync, writeFileSync } from 'node:fs';

type Campos = {
  chave: string;
  numero: string;
  competencia: string;
  prestadorNome: string;
  prestadorCnpj: string;
  tomadorNome: string;
  tomadorCnpj: string;
  municipio: string;
  descricao: string;
  valor: string;
  consultaUrl: string;
};

const CONSULTA = 'https://www.nfse.gov.br/ConsultaPublica';

function arg(name: string): string | undefined {
  const idx = process.argv.indexOf(name);
  return idx >= 0 ? process.argv[idx + 1] : undefined;
}

function tag(xml: string, name: string): string {
  const re = new RegExp(`<${name}\\b[^>]*>([\\s\\S]*?)</${name}>`, 'i');
  const m = xml.match(re);
  return m?.[1]?.replace(/<[^>]+>/g, '').trim() ?? '';
}

function bloco(xml: string, name: string): string {
  const re = new RegExp(`<${name}\\b[\\s\\S]*?</${name}>`, 'i');
  return xml.match(re)?.[0] ?? '';
}

function cnpjNoBloco(xml: string, name: string): string {
  const inner = bloco(xml, name);
  const m = inner.match(/<(CNPJ|CPF)\b[^>]*>([\s\S]*?)<\/\1>/i);
  return (m?.[2] ?? '').replace(/\D+/g, '');
}

function nomeNoBloco(xml: string, name: string): string {
  return tag(bloco(xml, name), 'xNome');
}

function extrair(xml: string): Campos {
  let chave = (tag(xml, 'cChaveAcesso') || tag(xml, 'chNFSe')).replace(/\D+/g, '');
  if (chave.length !== 50) {
    const m = xml.match(/\b(\d{50})\b/);
    chave = m?.[1] ?? chave;
  }
  return {
    chave,
    numero: tag(xml, 'nNFSe') || tag(xml, 'nDPS'),
    competencia: tag(xml, 'dCompet') || tag(xml, 'dhEmi'),
    prestadorNome: nomeNoBloco(xml, 'prest'),
    prestadorCnpj: cnpjNoBloco(xml, 'prest'),
    tomadorNome: nomeNoBloco(xml, 'toma'),
    tomadorCnpj: cnpjNoBloco(xml, 'toma'),
    municipio: tag(xml, 'xLocEmi') || tag(xml, 'xLocPrestacao'),
    descricao: tag(xml, 'xDescServ') || tag(xml, 'xInfComp'),
    valor: tag(xml, 'vServ') || tag(xml, 'vLiq'),
    consultaUrl: chave ? `${CONSULTA}?chaveAcesso=${chave}` : CONSULTA,
  };
}

function pdfEscape(text: string): string {
  return text.replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)');
}

function latin1Bytes(text: string): string {
  const buf = Buffer.from(text.normalize('NFKC'), 'latin1');
  let out = '';
  for (const b of buf) {
    if (b < 32 || b === 40 || b === 41 || b === 92) {
      out += `\\${b.toString(8).padStart(3, '0')}`;
    } else if (b < 128) {
      out += String.fromCharCode(b);
    } else {
      out += `\\${b.toString(8).padStart(3, '0')}`;
    }
  }
  return out;
}

function linha(y: number, titulo: string, valor: string, size = 10): string {
  return `BT /F1 8 Tf 36 ${y + 12} Td (${pdfEscape(titulo)}) Tj ET\nBT /F2 ${size} Tf 36 ${y} Td (${latin1Bytes(valor || '—')}) Tj ET\n`;
}

function montarPdf(c: Campos): Buffer {
  const lines = [
    linha(760, 'DANFSe — Documento Auxiliar da NFS-e', 'Sistema Nacional da NFS-e', 11),
    linha(720, 'Chave de acesso', c.chave, 9),
    linha(690, 'Numero', c.numero),
    linha(660, 'Competencia / emissao', c.competencia),
    linha(630, 'Valor do servico', c.valor),
    linha(600, 'Prestador', `${c.prestadorNome}  ${c.prestadorCnpj}`.trim()),
    linha(560, 'Tomador', `${c.tomadorNome}  ${c.tomadorCnpj}`.trim()),
    linha(520, 'Municipio', c.municipio),
    linha(480, 'Discriminacao do servico', c.descricao.slice(0, 180)),
    linha(430, 'Consulta publica', c.consultaUrl, 8),
    linha(390, 'Observacao', 'PDF gerado localmente a partir do XML autorizado (NT 008/2026).', 8),
  ].join('');

  const stream = `0.2 w 36 790 523 20 re S\n${lines}`;
  const streamBuf = Buffer.from(stream, 'latin1');
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>',
    `<< /Length ${streamBuf.length} >>\nstream\n${stream}endstream`,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
  ];

  let body = '%PDF-1.4\n';
  const offsets = [0];
  for (let i = 0; i < objects.length; i++) {
    offsets.push(Buffer.byteLength(body, 'latin1'));
    body += `${i + 1} 0 obj\n${objects[i]}\nendobj\n`;
  }
  const xrefPos = Buffer.byteLength(body, 'latin1');
  body += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
  for (let i = 1; i <= objects.length; i++) {
    body += `${String(offsets[i]).padStart(10, '0')} 00000 n \n`;
  }
  body += `trailer << /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefPos}\n%%EOF\n`;
  return Buffer.from(body, 'latin1');
}

function main(): void {
  const input = arg('--input');
  const output = arg('--output');
  if (!input || !output) {
    process.stderr.write('Uso: gerarDanfseCli --input arquivo.xml --output arquivo.pdf\n');
    process.exit(2);
  }
  const xml = readFileSync(input, 'utf8');
  if (!xml.includes('<') || (!/NFSe/i.test(xml) && !/nfse/i.test(xml))) {
    process.stderr.write('Arquivo de entrada não é um XML de NFS-e.\n');
    process.exit(1);
  }
  const pdf = montarPdf(extrair(xml));
  writeFileSync(output, pdf);
}

main();
