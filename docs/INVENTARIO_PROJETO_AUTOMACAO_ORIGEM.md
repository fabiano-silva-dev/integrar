# Inventário — Projeto de Automação de Origem

**Documento:** Fase 0 do plano de Automação Fiscal no IntegraExpert  
**Data:** 22/07/2026  
**Projeto de origem (somente leitura):** `/ico/fabiano/ft/automacao-portais`  
**IntegraExpert (HEAD inventariado):** `a32da960d0e720378a95e27603e304a1eba60260` (`master`)  
**Plano de referência:** `docs/PLANO_IMPLEMENTACAO_AUTOMACAO_FISCAL_INTEGRAEXPERT.md`

---

## 1. Resumo executivo

O projeto `automacao-portais` é uma PoC monorepo com:

| Camada | Stack |
|--------|--------|
| Plataforma web | Laravel 13 + Inertia/Vue 3 + Horizon + Redis |
| Runner | Node.js ≥24 + TypeScript + Playwright **1.61.1** (Chromium) |
| Dados | MySQL 8.4 |
| Infra local | Docker Compose (Apache → PHP-FPM → app; runner isolado) |

**e-CAC RS:** maduro — login A1 (mTLS) + fluxo `extract-nfe-nfce` (download `.txt`), com resolução de ALTCHA no browser.  
**Portal Nacional NFS-e (Emissor):** parcial — adapter de `validate-access` implementado; prova real até área autenticada e fluxos de consulta/emissão **ainda abertos**.

Para o IntegraExpert: **reaproveitar o runner e os contratos**; **não** incorporar a plataforma Laravel/Inertia paralela; orquestração, UI e multi-tenant ficam no IntegraExpert (Livewire + fila database/systemd).

---

## 2. Estrutura (5.1)

### 2.1. Linguagens e frameworks

- **PHP** `^8.3` (imagem Docker `php:8.4-fpm-bookworm`) + **Laravel v13.21.1**
- **Inertia Vue 3** + Vite + Tailwind (Breeze) — **não** usa Livewire
- **Node.js ≥24** + TypeScript no runner
- **Playwright 1.61.1** + Chromium (`mcr.microsoft.com/playwright:v1.61.1-noble`)
- **Zod** para validação de params/env no runner
- **Horizon** + Redis para filas na PoC
- OpenSSL CLI para extração PKCS#12 (PFX → PEM para `clientCertificates` do Playwright)

### 2.2. Como iniciar

```bash
cd /ico/fabiano/ft/automacao-portais
make setup    # .env, composer, npm, build, migrate, seed
make up       # stack (fake mode por padrão)
make start    # scripts/dev-start.sh
make status
make ecac-discovery / make ecac-validate / make ecac-up
make runner-rebuild
make tools-up # phpMyAdmin :8094, Mailpit :8026
```

Portas host: **8093** (Apache), **5181** (Vite), **8094** (phpMyAdmin), **8026/1026** (Mailpit). Runner interno `:3000` (não publicado).

### 2.3. Arquivos de entrada

| Papel | Path |
|-------|------|
| README | `automacao-portais/README.md` |
| Plano interno | `automacao-portais/PLAN.md` |
| Compose | `automacao-portais/compose.yaml` (+ `compose.ecac.yaml`, `compose.tools.yaml`) |
| Makefile | `automacao-portais/Makefile` |
| Env exemplo | `automacao-portais/.env.example` |
| Platform | `automacao-portais/apps/platform/` |
| Runner | `automacao-portais/services/runner/` |
| Docker | `automacao-portais/docker/{apache,php,playwright}/` |
| Scripts | `automacao-portais/scripts/` |
| Secrets (gitignored) | `automacao-portais/secrets/dev/` |

### 2.4. e-CAC RS — arquivos responsáveis

**Runner**

- `services/runner/src/portals/ecac-rs/EcacRsAdapter.ts`
- `EcacRsCertificateFlow.ts`, `EcacRsDiscoveryFlow.ts`
- `EcacRsExtractNfeNfceFlow.ts`, `extractNfeNfceParams.ts`
- `EcacRsSelectors.ts`, `EcacRsExtractSelectors.ts`
- `EcacRsSuccessDetector.ts`, `EcacRsErrors.ts`
- Shared: `portals/shared/navigation.ts`, `solveAltcha.ts`
- Orquestração: `automation/AutomationRunner.ts`, `BrowserManager.ts`, `FakeModeRunner.ts`
- Cert: `certificates/PfxFileCertificateProvider.ts`
- API: `server/app.ts`

**Platform**

- `apps/platform/app/Http/Controllers/EcacRsController.php`
- Jobs: `ExecutePortalAutomation.php`, `ExecuteEcacRsValidation.php`
- Catálogo: `app/Automation/FlowCatalog.php`
- UI: `resources/js/Pages/EcacRs/Index.vue`
- Rotas: `/portals/ecac-rs`, validate/run

### 2.5. Portal Nacional NFS-e — arquivos responsáveis

**Runner**

- `services/runner/src/portals/nfse-emissor/NfseEmissorAdapter.ts`
- `NfseEmissorCertificateFlow.ts`, `NfseEmissorDiscoveryFlow.ts`
- `NfseEmissorSelectors.ts`, `NfseEmissorSuccessDetector.ts`, `NfseEmissorErrors.ts`

**Platform**

- `NfseEmissorController.php`, job `ExecuteNfseEmissorValidation.php`
- UI: `resources/js/Pages/NfseEmissor/Index.vue`
- Rotas: `/portals/nfse-emissor`

### 2.6. Telas existentes

Inertia/Vue (não Livewire):

| Página | Rota |
|--------|------|
| Dashboard | `/dashboard` |
| Certificado A1 | `/certificates` |
| e-CAC RS | `/portals/ecac-rs` |
| NFS-e Emissor | `/portals/nfse-emissor` |
| Histórico de runs | `/automation-runs` |
| Detalhe da run | `/automation-runs/{id}` |
| Progresso JSON | `/automation-runs/{id}/progress` |
| Artefatos | `.../artifacts/{id}/download\|preview` |
| Horizon | path configurável |

### 2.7. Certificados, parâmetros e resultados

| Dado | Onde |
|------|------|
| PFX + senha | Arquivos em `secrets/dev/` (nomes fixos `ecac-a1.pfx` / `ecac-a1-password.txt`), montados no runner em `/certs` |
| Metadados | Tabela `certificate_profiles` (fingerprint, subject, issuer, validade) — **sem** binário/senha no MySQL |
| Parâmetros | `automation_runs.input_params` (JSON) |
| Resultado | `automation_runs.result_data`, status, error_code, final_url |
| Eventos | `automation_run_events` |
| Artefatos | `automation_artifacts` + `storage/app/private/automation-runs/<ulid>/` |

### 2.8. Banco de dados

- MySQL **8.4**, database `portal_automation`
- Tabelas: `organizations`, `users`, `portals`, `certificate_profiles`, `automation_runs` (+ `input_params`), `automation_run_events`, `automation_artifacts`
- Redis: cache, sessão, fila (Horizon)

### 2.9. Variáveis de ambiente (somente nomes)

Raiz `.env.example`:  
`APP_*`, `WWWUSER`, `WWWGROUP`, `DB_*`, `REDIS_*`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `RUNNER_BASE_URL`, `RUNNER_INTERNAL_TOKEN`, `PLATFORM_BASE_URL`, `AUTOMATION_FAKE_MODE`, `AUTOMATION_HEADLESS`, `AUTOMATION_TIMEOUT_MS`, `AUTOMATION_ARTIFACT_RETENTION_DAYS`, `ECAC_RS_MODE`, `ECAC_RS_ENTRY_URL`, `ECAC_RS_CERT_ORIGINS`, `ECAC_RS_ALLOWED_HOST_SUFFIXES`, `ECAC_A1_PFX_FILE`, `ECAC_A1_PASSWORD_FILE`, `NFSE_EMISSOR_MODE`, `NFSE_EMISSOR_ENTRY_URL`, `NFSE_EMISSOR_CERT_ORIGINS`, `NFSE_EMISSOR_ALLOWED_HOST_SUFFIXES`, `VITE_*`.

Extras na platform: `CERTIFICATE_SECRETS_PATH`, `CERTIFICATE_PFX_FILENAME`, `CERTIFICATE_PASSWORD_FILENAME`, `AUTOMATION_MAX_ARTIFACT_BYTES`, `HORIZON_PATH`, etc.

### 2.10. Diretórios temporários e formatos baixados

| Onde | Formato |
|------|--------|
| Runner temp | `/tmp/automation-artifacts/<runId>/` (mode `0o600`) |
| Download extrato | `os.tmpdir()/ecac-extract-<runId>-<ts>.bin` → lido e apagado |
| Extração PFX | `tmpdir()/runner-pfx-*` (apagados após uso) |
| Persistência | `storage/app/private/automation-runs/<runUlid>/` |
| Tipos | screenshot (png), trace (zip), diagnostic-log (json/txt), html, download (txt/bin/pdf) |
| Extrato NF-e/NFC-e | arquivo **texto (`.txt`)** gerado pelo portal SEFAZ |

---

## 3. Dependências (5.2)

| Componente | Versão / observação |
|------------|---------------------|
| Node.js | `>=24` / imagem `node:24-bookworm` |
| Playwright | **1.61.1** |
| Browser | Chromium (`npx playwright install chromium`) |
| PHP | 8.4 (Docker); composer `^8.3` |
| Laravel (PoC) | **v13.21.1** — **não** migrar o framework para o IntegraExpert (Laravel 12) |
| Horizon | v5.48.1 (PoC; IntegraExpert usa fila `database` + systemd) |
| Zod | `^3.25.76` |
| PKCS#12 | OpenSSL CLI (`pkcs12`, fallback `-legacy` para ICP-Brasil) + `openssl_pkcs12_read` no PHP |
| MySQL / Redis | `mysql:8.4`, `redis:7-alpine` (Redis **não** obrigatório na Fase 1 do IntegraExpert) |
| Python | **não usado** neste projeto de origem |

### 3.1. Pacotes para Docker de desenvolvimento (IntegraExpert)

Quando a Fase 5+ incorporar o runner:

- serviço Node 24 + Playwright 1.61.1 + Chromium;
- OpenSSL com suporte `-legacy`;
- rede interna + token entre app Laravel e runner;
- **não** exigir Redis/Horizon no primeiro momento (plano do IntegraExpert: fila `database`).

### 3.2. Pacotes para produção nativa

- Node.js 24+;
- dependências de SO do Chromium/Playwright;
- OpenSSL com `-legacy`;
- worker systemd da fila Laravel (já previsto) + processo/serviço do runner;
- permissões restritas em diretório de certificados por operadora.

---

## 4. Fluxo por portal (5.3)

### 4.1. e-CAC RS

**Operações:** `validate-access` | `extract-nfe-nfce`  
**Modos:** `fake` | `discovery` | `certificate`

#### validate-access — discovery

1. Entrada: `ECAC_RS_ENTRY_URL` (default atendimento Receita RS / portal e-CAC PJ)
2. Allowlist: hosts `*.rs.gov.br`
3. Aceita cookies → clica Portal e-CAC → localiza opção certificado (“via navegador”)
4. Coleta `candidateOrigins` HTTPS → tipicamente `needs_intervention` / `CERTIFICATE_ORIGIN_NOT_CONFIGURED`
5. Saída: eventos + screenshots + origins para preencher `ECAC_RS_CERT_ORIGINS`

#### validate-access — certificate

1. Exige `ECAC_RS_CERT_ORIGINS` (ex. origem Sefaz/auth)
2. Carrega PFX via OpenSSL → PEM cert+key → Playwright `clientCertificates`
3. Navega entry URL → login certificado / possível popup mTLS
4. Detecta erros de certificado; resolve **ALTCHA** no Chromium
5. Seleção de papel sem mapeamento → `NEEDS_ROLE_MAPPING`
6. Sucesso se detector confirma painel autenticado (score/confiança)

#### extract-nfe-nfce (certificate)

1. Login via fluxo de certificado
2. Fecha modal do PainelUsuario se houver
3. Abre `{certOrigin}/nfe/nfe-ics-ext.aspx`
4. Preenche IE/CNPJ, datas (ISO→DD/MM/AAAA), modelo, operação, situações; resolve ALTCHA
5. Consultar → grade → “Gerar arquivo Texto(txt)” → `waitForEvent('download')`
6. Upload artefato `download` (text/plain) para a platform

**Params típicos** (`input_params`): `ie` ou `cnpj`, `modelo` (`nfe`/`nfce`), `periodoInicial`/`periodoFinal` (≤ 31 dias), `operacao`, flags de situação, etc.

### 4.2. Portal Nacional NFS-e (Emissor)

1. Entrada: `https://www.nfse.gov.br/EmissorNacional/Login`
2. Allowlist: `nfse.gov.br`
3. Clique “Acesso via certificado digital”
4. mTLS em origem tipicamente `https://certificado.nfse.gov.br`
5. Aguarda retorno a `/EmissorNacional/` (fora de Login)
6. Detector de área autenticada / papel → `NEEDS_ROLE_MAPPING`
7. **Somente** `validate-access` no adapter — sem extratos/emissão

**Maturidade:** código de login existe; aceite real e fluxos fiscais **WIP**.

---

## 5. Segurança (5.4)

| Tema | Achado | Ação na incorporação |
|------|--------|----------------------|
| Senha do PFX | Texto puro em arquivo (`chmod` permissivo para leitura no container) | Criptografar com Laravel; storage privado por operadora; permissões restritas |
| Certificado em dir web | Não em `public/`; montagem `secrets/dev` | Manter privado; `OperadoraStorage`; nome físico aleatório |
| Cookies/tokens em logs | Runner sanitiza (`sanitize.ts`) | Portar sanitizador; obrigatório também no PHP |
| Nomes previsíveis | PFX/senha com nomes fixos na PoC | ULID/UUID nos paths do IntegraExpert |
| Temp files | Limpeza após PFX/download na maioria dos fluxos | Garantir cleanup no runner incorporado |
| Shell injection | OpenSSL via args array (sem shell) | Manter `Symfony Process` / spawn com args |
| URL arbitrária | API rejeita body com URL livre; allowlist | Preservar allowlist no runner |
| Stack traces | `APP_DEBUG` em dev | Mensagens seguras ao usuário; log técnico sanitizado |
| Auth interna | Bearer `RUNNER_INTERNAL_TOKEN` | Adaptar ao contrato Laravel ↔ runner do IntegraExpert |
| Concorrência | Lock global no runner (1 execução) | Documentar; escalar só depois do piloto |
| A3 / store Windows | Stub não implementado | Fora do escopo inicial |

---

## 6. Entrega da Fase 0 (5.5)

### 6.1. Copiar (núcleo de valor)

- `services/runner/src/` (portais e-CAC + NFS-e, ALTCHA, PFX, allowlist, sanitize, AutomationRunner, ArtifactStore)
- Receita Docker Playwright `v1.61.1-noble` / Dockerfile do runner
- Contratos de erro e schemas Zod (`extractNfeNfceParams`, etc.)
- Ideia do `FlowCatalog` (espelhar em PHP do IntegraExpert)

### 6.2. Adaptar

- Jobs Horizon → fila/systemd do IntegraExpert (`integrar-queue`)
- `organization_id` → `empresa_operadora_id` / `BelongsToOperadora` + `OperadoraStorage`
- UI Inertia/Vue → **Livewire** no IntegraExpert
- Certificados: um PFX por escritório/cliente, criptografado
- Comunicação Laravel ↔ runner (token + rede; ou CLI JSON conforme plano seção 6.3)
- Timeouts alinhados a jobs longos (5–15 min)

### 6.3. Descartar

- App Laravel paralelo completo (`apps/platform` como produto)
- Horizon/Redis obrigatórios na primeira entrega
- Fake mode como produto (manter só em testes)
- phpMyAdmin/Mailpit da PoC
- Stub A3 até requisito real
- Modelo `Organization`/`Portal` da PoC em favor do modelo do plano IntegraExpert (`portais_integracao`, `empresa_integracoes`, etc.)

### 6.4. Dependências novas no IntegraExpert

- Serviço/processo Node 24 + Playwright 1.61.1 + Chromium
- OpenSSL com `-legacy`
- (Fases posteriores) units systemd do runner, se separado do worker PHP
- Redis/Horizon: **não** na fundação — plano prevê fila `database`

### 6.5. Riscos técnicos

1. Layout SEFAZ/NFS-e muda → seletores quebram (`PORTAL_LAYOUT_CHANGED`)
2. Seleção de papel/empresa sem mapeamento → bloqueio
3. ALTCHA timeout / MFA não automatizável
4. Cadeia intermediária mTLS (já mitigada com cadeia completa no OpenSSL da PoC)
5. Um runner, uma execução por vez (escala)
6. Certificado compartilhado na PoC vs multi-tenant no IntegraExpert
7. NFS-e ainda sem prova real fechada
8. Não copiar Laravel 13 da PoC — só runner/contratos

### 6.6. Plano de teste por portal

**e-CAC RS**

1. Fake mode end-to-end (eventos/artefatos)
2. Discovery → origins em allowlist
3. Certificate → login até painel autenticado
4. `extract-nfe-nfce` com dados de homologação, período ≤ 31 dias, download `.txt`
5. Negativos: senha errada, origem vazia, papel múltiplo, ALTCHA falho

**NFS-e**

1. Fake + discovery
2. Certificate real até área autenticada (aceite ainda aberto na origem)
3. Papel múltiplo / login não confirmado
4. Só depois: fluxos de consulta (ainda inexistentes na origem)

---

## 7. Decisões de migração para as próximas fases

| Decisão | Escolha |
|---------|---------|
| Ordem | Fase 1 (fundação de dados) após este inventário; runner real nas Fases 5–6 |
| UI da PoC | Não portar Inertia/Vue; recriar em Livewire |
| Código a preservar | Runner TypeScript/Playwright |
| Multi-tenant | Modelo do plano IntegraExpert (`BelongsToOperadora`), não `organizations` da PoC |
| Fila inicial | Driver `database` já existente; sem Redis obrigatório |
| Credenciais | Nunca colunas de portal em `empresas`; tabelas relacionais do plano |

---

## 8. Critérios de aceite da Fase 0

- [x] Origem localizada e analisada em modo somente leitura
- [x] Dependências e versões documentadas
- [x] Fluxos e-CAC RS e NFS-e documentados
- [x] Riscos de segurança listados com mitigação na incorporação
- [x] Estratégia copiar / adaptar / descartar definida
- [ ] Integrações reproduzíveis no projeto de origem (validação operacional no host do usuário — fora deste documento)

**Próximo passo:** Fase 1 — migrations, models, criptografia, storage, seeders de portais/recursos e testes de isolamento tenant.
