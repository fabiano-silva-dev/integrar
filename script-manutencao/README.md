# Scripts de manutenção — IntegraExpert

Todos os scripts operacionais ficam nesta pasta. Na raiz do projeto há apenas atalhos e `wait-for-it.sh` (usado pelo Docker).

## Atualização após `git pull` (use sempre)

Padrão: modo **nativo**. `composer`/`npm` rodam como **dono do projeto** (fabiano após `git pull`);
`artisan` roda como **www-data** (PHP-FPM). O `sudo` é pedido só quando necessário.
Use `--docker` apenas em desenvolvimento com containers.

```bash
# Na raiz do projeto, após git pull:
./atualizar-producao.sh
```

Executa:

- `composer install` (inclui `package:discover` do Livewire)
- `npm ci && npm run build` (se `package.json` existir; requer Node.js 20+ no PATH do sistema)
- `php artisan migrate --force`
- `php artisan optimize:clear` (+ `optimize` em produção nativa)
- reload PHP-FPM / filas systemd (nativo)

Opções: `--docker` (dev), `--skip-npm`, `--skip-migrate`, `--skip-composer`, `--skip-cache`, `--app-user`

## Instalação inicial (migração Docker → nativo)

```bash
sudo ./instalar-nativo-producao.sh          # atalho na raiz
sudo ./script-manutencao/instalar-nativo-producao.sh --dry-run --yes
```

Pacotes de SO incluem `poppler-utils` (`pdftotext`), necessário para importar plano de contas em PDF Domínio.
Em servidor já migrado sem esse pacote:

```bash
sudo apt-get install -y poppler-utils
```

## Dependências da Automação Fiscal (produção nativa)

Instalador **idempotente e separado** do deploy diário (não roda Chromium/`apt` em todo `git pull`).

```bash
sudo ./instalar-deps-automacao-fiscal.sh --yes
# ou
sudo ./script-manutencao/instalar-deps-automacao-fiscal.sh --dry-run --yes
```

Instala Node.js 24+, OpenSSL, deps de SO do Playwright/Chromium, `npm ci` + build do runner em `scripts/automacao-fiscal/runner`, cache em `/var/cache/integrar-playwright` e unit `integrar-queue-automacoes` (timeout 900s).

O `./atualizar-producao.sh` **não** recompila o runner TypeScript. Depois de mudanças em `scripts/automacao-fiscal/runner/`, rode o instalador acima (ou `npm ci && npm run build` nesse diretório) e reinicie a fila de automações.

Variáveis extras no `.env` (ver `.env.example`): `NFE_FAZENDA_ENTRY_URL`, `NFE_FAZENDA_CERT_ORIGINS`, `NFE_XML_TIMEOUT_MS` e, se o portal nacional exigir desafio de imagens, `CAPSOLVER_API_KEY`.

Teste do script: `tests/scripts/test_instalar_deps_automacao_fiscal.sh`

## Backup e restauração

| Script | Descrição |
|--------|-----------|
| `backup-automatico.sh` | Dump MySQL (cron diário; padrão nativo, `--docker` no dev) |
| `importar-backup.sh` | Restaura SQL no container Docker |
| `rollback-deploy.sh` | Restaura banco + limpa cache (emergência) |

## Docker (desenvolvimento / legado)

| Script | Descrição |
|--------|-----------|
| `atualizar-producao.sh` | **Padrão** — pós-pull leve |
| `deploy-docker-completo.sh` | Rebuild completo da imagem (raro) |
| `recriar_containers_seguro.sh` | Recria containers preservando volumes |

## Migração e diagnóstico nativo

| Script | Descrição |
|--------|-----------|
| `diagnostico_migracao_nativa.sh` | Relatório somente leitura pré-migração |
| `verificar_migracao_nativa.sh` | Checagens pós-instalação nativa |
| `corrigir-apache-ssl-nativo.sh` | Ajustes SSL Apache |

## Banco de dados

| Script | Descrição |
|--------|-----------|
| `diagnosticar_banco.sh` | Diagnóstico MySQL |
| `verificar_restaurar_banco.sh` | Verifica/restaura banco Docker |
| `otimizar_mysql_*.sh` | Tuning de performance |
| `monitorar_mysql_performance.sh` | Monitoramento |

## Segurança

| Script | Descrição |
|--------|-----------|
| `hardening_seguranca_servidor.sh` | Hardening do servidor |
| `testar_seguranca_externo.sh` | Testes de portas expostas |
| `auditar_seguranca.sh` | Auditoria |
| `seguranca_bloquear_ataque.sh` | Bloqueio emergencial |

## Removidos / substituídos

| Antigo (raiz) | Substituído por |
|---------------|-----------------|
| `limpa_caches.sh` | `atualizar-producao.sh` |
| `deploy-producao.sh` | `deploy-docker-completo.sh` (rebuild) + `atualizar-producao.sh` (rotina) |
| `importar_backup.sh` | `importar-backup.sh` |

## Raiz do projeto

| Arquivo | Motivo |
|---------|--------|
| `atualizar-producao.sh` | Atalho para uso diário |
| `instalar-nativo-producao.sh` | Atalho para instalação |
| `instalar-deps-automacao-fiscal.sh` | Atalho — deps do runner (Node/Playwright) |
| `wait-for-it.sh` | Montado no `docker-compose.yml` |
