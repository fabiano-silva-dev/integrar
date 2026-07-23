# Automação fiscal — runner

Runner Node.js + Playwright incorporado da PoC `automacao-portais`.

## Instalação em produção (nativa)

Use o instalador idempotente (pacotes de SO + Node 24 + Chromium + unit systemd). **Não** misturar com o `atualizar-producao.sh` diário:

```bash
sudo ./instalar-deps-automacao-fiscal.sh --yes
# dry-run:
sudo ./instalar-deps-automacao-fiscal.sh --dry-run --yes
```

Detalhes: `script-manutencao/instalar-deps-automacao-fiscal.sh` e `script-manutencao/README.md`.

## Instalação (desenvolvimento)

No host ou em container com Node 24+:

```bash
cd scripts/automacao-fiscal/runner
npm ci
npx playwright install chromium
npm run build
```

## CLI

```bash
cd scripts/automacao-fiscal/runner
# variáveis mínimas: ver app/Services/AutomacaoFiscal/Runners/NodeRunnerBridge.php
npm run cli -- --input /tmp/input.json
```

Ou via Laravel (`NodeRunnerBridge`) com `AUTOMACAO_FISCAL_FAKE_MODE=false` e certificado configurado.

## Portais

| Código IntegraExpert | Código runner |
|----------------------|---------------|
| `ecac_rs` | `ecac-rs` |
| `nfse_nacional` | `nfse-emissor` |
