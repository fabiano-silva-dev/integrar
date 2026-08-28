# Amostras de extratos bancários

Arquivos de referência para desenvolvimento e teste dos conversores PDF/OFX/CSV.

Organização: `docs/extratos/<banco>/<modelo>[-periodo].ext`

Binários ficam só na máquina local (`.gitignore`); este README é o catálogo versionado.

## Caixa — layouts

Fluxo: `caixa_extrato_layout.py` **identifica o padrão** do PDF e **chama o motor** correspondente.

| Arquivo | Padrão detectado | Motor |
|---------|------------------|-------|
| `caixa/data-efetiva-paisagem-janeiro-2025.pdf` | data efetiva (paisagem) | `conversor_extrato_caixa_data_efetiva_pdf_csv.py` |
| `caixa/internet-banking-janeiro-2026.pdf` | Internet Banking (retrato) | `conversor_extrato_caixa_pdf_csv.py` (`converter_internet_banking`) |
| `caixa/historico-conta-jan-a-maio-2025.pdf` | Extrato Histórico | `conversor_extrato_caixa_federal_pdf_csv.py` (`converter_historico`) |

Na tela: uma única opção **Caixa (PDF)**. O identificador escolhe o motor (histórico, Internet Banking ou data efetiva).

```bash
docker compose exec app python3 /var/www/html/scripts/caixa_extrato_layout.py \
  /var/www/html/docs/extratos/caixa/data-efetiva-paisagem-janeiro-2025.pdf \
  /tmp/caixa.csv

docker compose exec app python3 /var/www/html/scripts/conversor_extrato_pdf_ofx.py \
  caixa \
  /var/www/html/docs/extratos/caixa/data-efetiva-paisagem-janeiro-2025.pdf \
  /tmp/caixa.ofx
```

## Demais bancos

| Pasta | Arquivos / modelo |
|-------|-------------------|
| `banco-do-brasil/` | `conta-corrente.pdf` |
| `banrisul/` | `conta-corrente-*.pdf`, `relatorio-pix-*.pdf`, `relatorio-pagamentos-titulos-*.pdf` |
| `bradesco/` | `extrato-mensal-01-a-04-2026.pdf` |
| `cora/` | `extrato-periodo.pdf`, `extrato-01-a-08-2026.pdf` |
| `cresol/` | `consolidado.pdf` / `conta-corrente.pdf` (+ variantes) — layouts `cresol` e `cresol_modelo2` |
| `dominio/` | `extrato-2026-08-13.pdf` — layout `dominio_conta_digital` |
| `grafeno/` | `extrato-claudio-abr.ofx` |
| `infinitepay/` | `relatorio-movimentacoes.pdf` |
| `itau/` | `lancamentos-01-a-04-2026.pdf` |
| `nubank/` | `extrato-periodo.pdf` |
| `santander/` | `internet-banking-empresarial.pdf` |
| `sicoob/` | `conta-corrente.pdf` + amostras `.ofx` |
| `sicredi/` | `extrato-periodo.pdf` |

Miniaturas da UI (Cresol): `public/images/extratos/cresol/`.

Documentação do produto e plano de contas permanecem em `docs/` (fora desta pasta).
