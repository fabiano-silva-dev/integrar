# Amostras de extratos bancários

Arquivos de referência para desenvolvimento e teste dos conversores PDF/OFX/CSV.

Organização: `docs/extratos/<banco>/<modelo>[-periodo].ext`

Binários ficam só na máquina local (`.gitignore`); este README é o catálogo versionado.

## Caixa — três layouts distintos

| Arquivo | Layout no sistema | Conversor | Como reconhecer |
|---------|-------------------|-----------|-----------------|
| `caixa/internet-banking-janeiro-2026.pdf` | `caixa` — Internet Banking (modelo novo) | `conversor_extrato_caixa_pdf_csv.py` | Título `Inte-r_neT::::BanK:ing....CAIXA`, “Extrato por período”, colunas Data Mov. / Nr. Doc. |
| `caixa/internet-banking-modelo.jpeg` | (miniatura do IB) | — | Mesmo layout do PDF acima |
| `caixa/historico-conta-jan-a-maio-2025.pdf` | `caixa_federal` — Extrato Histórico (modelo antigo) | `conversor_extrato_caixa_federal_pdf_csv.py` | JasperReports/iText, título “Extrato Histórico da Conta”, colunas Data Mov. / Data e Hora / Nr.Doc. |
| `caixa/data-efetiva-paisagem-janeiro-2025.pdf` | `caixa_data_efetiva` — Data efetiva (paisagem) | `conversor_extrato_caixa_data_efetiva_pdf_csv.py` → OFX via `conversor_extrato_pdf_ofx.py` | Paisagem A4, “Saldo anterior ao período solicitado”, colunas Data / Data Efetiva, valores `- R$` |

PDF→OFX (família Caixa): escolher **Caixa (PDF) - Data efetiva (paisagem)**.

```bash
docker compose exec app python3 /var/www/html/scripts/conversor_extrato_pdf_ofx.py \
  caixa_data_efetiva \
  /var/www/html/docs/extratos/caixa/data-efetiva-paisagem-janeiro-2025.pdf \
  /tmp/caixa-data-efetiva.ofx
```

## Demais bancos

| Pasta | Arquivos / modelo |
|-------|-------------------|
| `banco-do-brasil/` | `conta-corrente.pdf` |
| `banrisul/` | `conta-corrente-*.pdf`, `relatorio-pix-*.pdf`, `relatorio-pagamentos-titulos-*.pdf` |
| `bradesco/` | `extrato-mensal-01-a-04-2026.pdf` |
| `cora/` | `extrato-periodo.pdf` |
| `cresol/` | `consolidado.pdf` / `conta-corrente.pdf` (+ variantes) — layouts `cresol` e `cresol_modelo2` |
| `grafeno/` | `extrato-claudio-abr.ofx` |
| `infinitepay/` | `relatorio-movimentacoes.pdf` |
| `itau/` | `lancamentos-01-a-04-2026.pdf` |
| `nubank/` | `extrato-periodo.pdf` |
| `santander/` | `internet-banking-empresarial.pdf` |
| `sicoob/` | `conta-corrente.pdf` + amostras `.ofx` |
| `sicredi/` | `extrato-periodo.pdf` |

Miniaturas da UI (Cresol): `public/images/extratos/cresol/`.

Documentação do produto e plano de contas permanecem em `docs/` (fora desta pasta).
