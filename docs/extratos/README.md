# Amostras de extratos bancários

Arquivos de referência para desenvolvimento e teste dos conversores PDF/OFX/CSV.

Organização: `docs/extratos/<banco>/<modelo>[-periodo].ext`

Binários ficam só na máquina local (`.gitignore`); este README é o catálogo versionado.

## Caixa — layouts

| Arquivo | Layout na tela | Conversor | Como reconhecer |
|---------|----------------|-----------|-----------------|
| `caixa/internet-banking-janeiro-2026.pdf` | `caixa` — Internet Banking | `conversor_extrato_caixa_pdf_csv.py` | Retrato, “Extrato por período”, Data Mov. / Nr. Doc. |
| `caixa/internet-banking-modelo.jpeg` | (miniatura do IB) | — | Mesmo layout do PDF acima |
| `caixa/data-efetiva-paisagem-janeiro-2025.pdf` | `caixa` (detecção automática) | mesmo script → parser paisagem | Paisagem A4, “Data Efetiva”, “Saldo anterior ao período solicitado” |
| `caixa/historico-conta-jan-a-maio-2025.pdf` | `caixa_federal` — Extrato Histórico | `conversor_extrato_caixa_federal_pdf_csv.py` | JasperReports, “Extrato Histórico da Conta” |

O layout paisagem **não** aparece como opção separada: ao escolher **Caixa Internet Banking**, o conversor detecta paisagem/marcas de texto e usa o parser adequado.

```bash
docker compose exec app python3 /var/www/html/scripts/conversor_extrato_pdf_ofx.py \
  caixa \
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
