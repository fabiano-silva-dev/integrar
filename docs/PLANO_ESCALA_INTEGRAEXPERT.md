# IntegraExpert — Plano Consolidado de Escala SaaS

**Documento oficial de referência**  
**Versão:** 2.0 (consolidado)  
**Data:** junho/2026  
**Origem:** comparativo crítico entre `ESCALA_MULTI_ESCRITORIO.md` e `ROADMAP_ESCALA_SAAS-codex.md`, alinhado ao codebase atual

**Documentos analisados:**

| Documento | Foco | Extensão |
|-----------|------|----------|
| `ESCALA_MULTI_ESCRITORIO.md` | Diagnóstico do código + plano pragmático | ~580 linhas |
| `ROADMAP_ESCALA_SAAS-codex.md` | Roadmap enterprise SaaS completo | ~1.660 linhas |

---

## Parte I — Análise comparativa crítica

### 1.1 Visão geral

Os dois documentos convergem na **mesma conclusão central**: o IntegraExpert não precisa ser reescito; precisa evoluir de sistema **multi-empresa** (vários CNPJs de clientes) para **multi-escritório** (vários tenants SaaS), com isolamento rigoroso de dados.

A divergência está no **nível de ambição**, na **terminologia**, na **ordem das fases** e no **grau de aderência ao código existente**.

| Critério | ESCALA_MULTI_ESCRITORIO | ROADMAP_ESCALA_SAAS-codex | Avaliação |
|----------|-------------------------|---------------------------|-----------|
| **Coerência com o codebase** | Alta — cita models, Livewire, rotas reais | Média — propõe modelos genéricos (`tenants`) ignorando `empresas_operadoras` já implementada | Preferir abordagem ESCALA |
| **Profundidade técnica** | Média — suficiente para iniciar | Alta — segurança, LGPD, infra, KPIs, equipe | Preferir rigor CODEX onde aplicável |
| **Realismo de prazo (Fase 1)** | 2–3 semanas | 4–8 semanas | ESCALA otimista; CODEX mais realista se incluir policies + testes |
| **Ordem de prioridades** | Jobs na Fase 3; segurança na Fase 5 | Jobs e segurança no P0, antes de escala comercial | CODEX mais correto para SaaS |
| **Escopo comercial** | Billing na Fase 4, sem validação prévia | Fase 0 dedicada a entrevistas e pilotos | CODEX mais maduro comercialmente |
| **Risco de over-engineering** | Baixo | Alto — microsserviços, marketplace, IA, API v1 cedo | ESCALA mais enxuto; CODEX exige poda |
| **Cobertura de testes** | Menciona testes de isolamento | Suíte completa, CI, carga, golden files | Adotar exigências CODEX |
| **Infraestrutura produção** | Menciona Redis/S3 genericamente | Critica Docker atual (Artisan serve, root) — correto | Adotar diagnóstico CODEX |
| **Modelo de usuário** | 1 usuário → 1 escritório | Pivot `tenant_user` — usuário em N escritórios | Decisão pendente (ver 2.3) |

---

### 1.2 Coerência

#### Pontos em que os documentos concordam (base sólida)

1. **Monólito Laravel modular** — não iniciar com microsserviços.
2. **Banco compartilhado + coluna de tenant** — adequado à fase inicial.
3. **Sessão de empresa não é autorização** — validar no backend em toda operação.
4. **Processamento síncrono é gargalo** — importações Python na requisição HTTP não escalam.
5. **Armazenamento local impede horizontalização** — object storage com prefixo por tenant.
6. **Fluxo de negócio preservado** — Importar → Amarrar → Conferir → Exportar.
7. **Isolamento antes de escala comercial** — não vender para muitos escritórios sem prova técnica.

#### Incoerências e contradições identificadas

| Tema | Documento A | Documento B | Problema | Resolução adotada neste plano |
|------|-------------|-------------|----------|-------------------------------|
| Nome do tenant | `empresas_operadoras` / `empresa_operadora_id` | Nova tabela `tenants` + `tenant_user` | Duplicar conceito já existente no código | **Estender `empresas_operadoras`** — renomear conceitualmente para "tenant" na documentação, sem nova tabela |
| Nome do produto | IntegraExpert | Integrar | Inconsistência de branding | **IntegraExpert** |
| Jobs assíncronos | Fase 3 (pode esperar) | P0 — obrigatório antes de vender em escala | Ordem conflitante | **Fase 2 técnica** — após isolamento (Fase 1), antes de pilotos pagos |
| Segurança / LGPD | Fase 5 (tarde) | Fase 2 paralela à Fase 1 | ESCALA subestima risco | **Paralelo à Fase 1** — mínimo: policies, IDOR, upload seguro |
| Prazo Fase 1 | 1–2 semanas | 4–8 semanas | Gap de 3–6 semanas | **3–5 semanas** — meio-termo realista para 1–2 devs |
| Papéis | `super_admin` + roles atuais | `owner`, `billing`, `viewer`… | Granularidade diferente | **Estender enum atual** + `super_admin`; adicionar `owner` e `viewer` só se necessário na Fase comercial |
| Históricos padrão | Aberto — global vs. por tenant | Não detalha | Lacuna em ambos | **Modelo híbrido** — catálogo global de layouts (Sicoob, Sicredi…) + override opcional por escritório |
| Terceiros | Escopo por operadora | Escopo por tenant | Concordam | **Por operadora**, compartilhados entre empresas clientes do mesmo escritório |

#### Lacuna comum aos dois documentos

- Nenhum detalha **API existente** (`ExportadorContabilController`, rotas `/api/*`) com matriz de vulnerabilidades.
- Nenhum quantifica **capacidade atual** (teste de carga baseline) antes de projetar limites.
- Nenhum define **critério objetivo** para "pronto para o 2º escritório" vs. "pronto para 50 escritórios".

---

### 1.3 Implementações — análise crítica

#### Abordagem ESCALA: Global Scope + Trait

```php
// BelongsToOperadora + OperadoraContext
static::addGlobalScope('operadora', fn ($q) => ...);
```

| Prós | Contras |
|------|---------|
| Implementação rápida | Um `withoutGlobalScope` esquecido = vazamento |
| Poucas linhas alteradas por query | Super admin precisa exceções em todo lugar |
| Alinhado ao Laravel idiomático | Jobs/console commands podem rodar sem contexto |

**Veredicto:** útil como **camada auxiliar**, insuficiente como **única** barreira. Deve ser complementado por **Policies** e validação explícita em Livewire (exigência CODEX).

#### Abordagem CODEX: TenantContext + Policies + testes CI

| Prós | Contras |
|------|---------|
| Defesa em profundidade | Mais código e tempo inicial |
| Testável automaticamente | Risco de duplicar lógica (scope + policy) |
| Padrão recomendado para SaaS B2B | Proposta de tabelas novas ignora código existente |

**Veredicto:** adotar **Policies + testes de isolamento no CI**; usar Global Scope como reforço, não substituto.

#### Reutilizar `empresas_operadoras` vs. criar `tenants`

| Critério | Reutilizar | Criar nova tabela |
|----------|------------|-------------------|
| Esforço de migration | Menor | Maior — duplicar CRUD existente |
| Campos atuais | razão social, CNPJ, logo, configuracoes JSON | CODEX propõe uuid, slug, trial_ends_at… |
| Código existente | `EmpresasOperadorasForm` já funciona | Descartar ou migrar |
| Clareza conceitual | "Operadora" = tenant (documentar) | Termo `tenant` mais universal |

**Decisão adotada:** **reutilizar e enriquecer** `empresas_operadoras` com campos comerciais (`status`, `plano`, `trial_ends_at`, `uuid`, `slug`) em migrations incrementais. Evitar tabela `tenants` paralela.

#### Associação usuário ↔ escritório

| Modelo | Quando usar |
|--------|-------------|
| `users.empresa_operadora_id` (FK direta) | Usuário pertence a **um** escritório — suficiente para 90% dos casos iniciais |
| Tabela pivot `operadora_user` | Contador consultor em **vários** escritórios — necessário só se for requisito de negócio |

**Decisão adotada:** iniciar com **FK direta**; projetar pivot como evolução (Fase 6+) se pilotos exigirem.

---

### 1.4 Eficiência — o que fazer agora vs. depois

#### Alta eficiência (ROI imediato, baixo risco)

- `empresa_operadora_id` + migration de dados legados
- Middleware `OperadoraContext` + Policies nos recursos críticos
- Corrigir `SeletorEmpresaGlobal`, `GerenciadorUsuarios`, `/trocar-empresa/{id}`
- Testes automatizados de isolamento (10 cenários CODEX)
- Primeiro job assíncrono: `ProcessarImportacaoExtrato`

#### Média eficiência (necessário antes de escala comercial)

- Redis + Horizon + filas separadas
- Object storage (S3/MinIO)
- Auditoria estruturada (`audit_logs`)
- Produção: PHP-FPM + Nginx (substituir `artisan serve`)
- MFA para admins
- Limites por plano (entitlements service)

#### Baixa eficiência prematura (adiar)

- Microsserviços de conversão
- API pública v1 completa
- Marketplace de layouts
- Inteligência assistida / sugestão de regras
- Banco dedicado por tenant Enterprise
- SSO / SAML

---

### 1.5 Síntese da análise crítica

| Dimensão | Nota ESCALA | Nota CODEX | Plano consolidado |
|----------|-------------|------------|-------------------|
| Aderência ao produto real | ★★★★★ | ★★★☆☆ | Herda mapeamento de arquivos do ESCALA |
| Rigor de segurança | ★★☆☆☆ | ★★★★★ | Herda exigências CODEX (Fase 1B) |
| Pragmatismo de prazo | ★★★★☆ (otimista) | ★★★☆☆ (longo) | Prazos intermediários |
| Visão comercial | ★★☆☆☆ | ★★★★★ | Fase 0 comercial do CODEX |
| Completude operacional | ★★☆☆☆ | ★★★★★ | KPIs e gates CODEX, podados |
| Risco de scope creep | ★★★★★ (baixo) | ★★☆☆☆ (alto) | Escopo explícito P0/P1/P2 |

**Conclusão da análise:** o documento ESCALA é o **mapa do terreno atual**; o CODEX é o **manual de maturidade SaaS**. O plano consolidado usa o primeiro como guia de implementação imediata e o segundo como **checklist de qualidade** — sem absorver todo o escopo de 12 meses de uma vez.

---

## Parte II — Decisões arquiteturais registradas

Estas decisões fecham pontos abertos entre os dois documentos. Devem ser revisadas externamente antes do commit de grande escala.

### ADR-001 — Estratégia multi-tenant

**Decisão:** banco MySQL compartilhado, coluna `empresa_operadora_id` (tenant) nas entidades de negócio, reutilizando tabela `empresas_operadoras`.

**Alternativas rejeitadas na fase atual:** schema por tenant, banco por tenant.

**Revisão:** quando houver cliente Enterprise exigindo isolamento físico ou >500 tenants ativos.

---

### ADR-002 — Resolução do tenant

**Decisão:** tenant derivado primariamente de `auth()->user()->empresa_operadora_id`. Super admin seleciona operadora via sessão `operadora_context_id`.

**Fase posterior:** subdomínio (`slug.integraexpert.com.br`) mapeando para `empresas_operadoras.slug`.

---

### ADR-003 — Isolamento de dados

**Decisão:** três camadas — (1) Policy por recurso, (2) Global Scope como reforço, (3) validação explícita em Livewire/API/downloads/jobs.

**Regra:** nunca confiar apenas em `empresa_id` da sessão.

---

### ADR-004 — Modelo de papéis

**Decisão:** estender enum atual:

| Role | Escopo |
|------|--------|
| `super_admin` | Plataforma — todos os escritórios |
| `admin` | Administrador do escritório |
| `gerente` | Gestão operacional + usuários |
| `operador` | Operação diária |

**Adiar:** `owner`, `billing`, `viewer` até Fase comercial.

---

### ADR-005 — Processamento de importações

**Decisão:** manter síncrono **somente** durante desenvolvimento da Fase 1 com 1 operadora; migrar para job **antes** do 2º escritório piloto em produção.

**Justificativa:** CODEX correto ao classificar jobs como P0; ESCALA correto ao permitir sync temporário para reduzir risco de refactor duplo.

---

### ADR-006 — Históricos padrão por layout

**Decisão:** modelo **híbrido** — catálogo global mantido por `super_admin` (layouts de banco são iguais entre escritórios); personalização por `empresa_id` continua; futuro override por operadora se necessário.

---

### ADR-007 — Terceiros

**Decisão:** escopo por **operadora** (compartilhados entre empresas clientes do mesmo escritório). Adicionar `empresa_operadora_id`; `empresa_id` opcional se no futuro houver terceiros exclusivos por cliente.

---

### ADR-008 — Unicidade de e-mail

**Decisão pendente de validação externa:**

- **Opção A (recomendada inicial):** e-mail único global — login simples, um usuário = um escritório.
- **Opção B:** e-mail único por operadora — mesmo e-mail em escritórios diferentes.

---

## Parte III — Estado atual (referência)

### Hierarquia hoje

```
Usuários (globais)
  └── Empresas clientes (globais — seletor na sessão)
        └── Importações → Lançamentos → Regras → Terceiros → Layouts
```

### Hierarquia alvo

```
Super Admin
  └── Empresa Operadora / Tenant
        ├── Usuários (admin, gerente, operador)
        ├── Plano e limites
        └── Empresas clientes
              ├── Importações / Lançamentos
              ├── Regras de amarração
              ├── Terceiros
              └── Exportações / Conversões
```

### Evidências críticas no codebase

| Arquivo | Problema |
|---------|----------|
| `SeletorEmpresaGlobal.php` | `Empresa::orderBy('nome')->get()` — sem filtro |
| `GerenciadorUsuarios.php` | `User::all()` |
| `GerenciadorEmpresas.php` | Create sem `empresa_operadora_id` |
| `/trocar-empresa/{id}` | Sem validação de tenant |
| `ImportadorAvancado.php` | Python síncrono, 5 min timeout |
| `EmpresasOperadora` model | Sem relações com outros models |
| Docker Compose | `artisan serve`, app como root |

---

## Parte IV — Roadmap consolidado

### Visão das fases

```
Fase 0   Validação comercial (2–4 sem)
Fase 1A  Fundação multi-tenant (3–5 sem)
Fase 1B  Segurança mínima (paralelo, 2–3 sem)
Fase 2   Jobs + storage (3–5 sem)
Fase 3   Produção escalável (4–6 sem)
Fase 4   SaaS comercial (6–10 sem)
Fase 5   Observabilidade + escala (contínuo)
Fase 6+  API, integrações, Enterprise (sob demanda)
```

---

### Fase 0 — Validação comercial

**Objetivo:** não construir escala sem demanda validada.

**Ações:**

- [ ] Entrevistar 5–10 escritórios contábeis
- [ ] Definir persona e proposta de valor
- [ ] Medir volume: empresas/escritório, importações/mês, tamanho de arquivos
- [ ] Esboçar planos e precificação preliminar
- [ ] Selecionar 3–5 escritórios para piloto alpha

**Critério de saída:** proposta de valor validada + pilotos comprometidos.

*Origem: CODEX Fase 0 — ausente no ESCALA; adotado integralmente.*

---

### Fase 1A — Fundação multi-tenant

**Objetivo:** isolamento comprovado entre escritórios.

**Prazo sugerido:** 3–5 semanas (1–2 desenvolvedores).

#### 1A.1 Banco de dados

**Enriquecer `empresas_operadoras`:**

```sql
ALTER TABLE empresas_operadoras ADD COLUMN uuid CHAR(36) UNIQUE;
ALTER TABLE empresas_operadoras ADD COLUMN slug VARCHAR(63) UNIQUE;
ALTER TABLE empresas_operadoras ADD COLUMN status ENUM('ativo','suspenso','trial') DEFAULT 'ativo';
ALTER TABLE empresas_operadoras ADD COLUMN trial_ends_at TIMESTAMP NULL;
ALTER TABLE empresas_operadoras ADD COLUMN plano VARCHAR(50) NULL;
ALTER TABLE empresas_operadoras ADD COLUMN limite_empresas INT NULL;
ALTER TABLE empresas_operadoras ADD COLUMN limite_usuarios INT NULL;
```

**Adicionar `empresa_operadora_id` NOT NULL (após backfill):**

| Tabela | Índice sugerido |
|--------|-----------------|
| `users` | `(empresa_operadora_id)` — nullable para super_admin |
| `empresas` | `(empresa_operadora_id, cnpj)` UNIQUE |
| `terceiros` | `(empresa_operadora_id, nome)` |
| `importacoes` | `(empresa_operadora_id, created_at)` |
| `lancamentos` | `(empresa_operadora_id, empresa_id, data)` |
| `regras_amarracoes_descricoes` | `(empresa_operadora_id, empresa_id)` |
| `layouts_importacao` | `(empresa_operadora_id, empresa_id)` |
| `historicos_padrao_layout` | `(empresa_operadora_id, layout_avancado)` |
| `conversoes_extrato` | `(empresa_operadora_id, created_at)` |

**Migration legado:**

1. Criar operadora padrão a partir dos dados atuais.
2. Backfill em todas as tabelas.
3. Recriar constraints de unicidade.
4. Comando `php artisan operadoras:auditar-orfaos`.

#### 1A.2 Código — arquivos novos

```
app/Models/Concerns/BelongsToOperadora.php
app/Services/OperadoraContext.php
app/Http/Middleware/SetOperadoraContext.php
app/Policies/EmpresaPolicy.php
app/Policies/ImportacaoPolicy.php
app/Policies/LancamentoPolicy.php
tests/Feature/TenantIsolationTest.php
```

#### 1A.3 Código — arquivos alterados (prioridade)

| Prioridade | Arquivo | Alteração |
|------------|---------|-----------|
| P0 | `User.php` | FK operadora, role `super_admin` |
| P0 | `Empresa.php` | BelongsToOperadora |
| P0 | `EmpresasOperadora.php` | hasMany empresas, users |
| P0 | `SeletorEmpresaGlobal.php` | Filtrar por operadora |
| P0 | `GerenciadorEmpresas.php` | Atribuir operadora no create |
| P0 | `GerenciadorUsuarios.php` | Escopo por operadora |
| P0 | `routes/web.php` | Validar tenant em `/trocar-empresa/{id}` |
| P0 | `bootstrap/app.php` | Registrar middleware |
| P1 | `ImportadorAvancado.php` | Policy + operadora_id na importação |
| P1 | `TabelaLancamentos.php` | Validar empresa ∈ operadora |
| P1 | `GerenciadorRegrasAmarracao.php` | Escopo operadora |
| P1 | `GerenciadorTerceiros.php` | Escopo operadora |
| P1 | `ImportadorPersonalizado.php` | Escopo operadora |
| P1 | `ExportadorContabil.php` | Escopo operadora |
| P2 | Demais Livewire | Revisão sistemática |

#### 1A.4 Testes obrigatórios (CI)

- [ ] Operadora A não lista empresas da Operadora B
- [ ] Operadora A não abre importação da Operadora B
- [ ] Operadora A não altera lançamento da Operadora B
- [ ] Operadora A não baixa arquivo da Operadora B
- [ ] `/trocar-empresa/{id}` rejeita empresa de outra operadora
- [ ] Admin de operadora A não gerencia usuários da operadora B
- [ ] Super admin acessa todas as operadoras com scope desabilitado

**Critério de saída:** suíte de isolamento verde no CI + 2 operadoras de teste com dados independentes.

---

### Fase 1B — Segurança mínima (paralelo à 1A)

**Objetivo:** fechar vulnerabilidades antes do 2º tenant em produção.

*Origem: CODEX Fase 2 — antecipada em relação ao ESCALA.*

**Ações P0:**

- [ ] Laravel Policies em todos os recursos tenant-aware
- [ ] Revisão de rotas API (`routes/api.php`, controllers)
- [ ] Testes IDOR/BOLA
- [ ] Upload: validar MIME real, tamanho, nome aleatório
- [ ] Processos Python: lista allowlist de scripts, timeout, sem concatenação insegura
- [ ] Confirmar e-mail no cadastro de usuários
- [ ] Rate limit em login e upload

**Ações P1 (antes de beta paga):**

- [ ] MFA/TOTP para admin e super_admin
- [ ] Tabela `audit_logs` (tenant, user, action, ip, old/new values)
- [ ] Política de retenção de arquivos temporários

**Critério de saída:** nenhum recurso crítico acessível sem Policy; auditoria de API concluída.

---

### Fase 2 — Jobs assíncronos e armazenamento

**Objetivo:** nenhuma operação pesada depende da duração da requisição HTTP.

**Prazo sugerido:** 3–5 semanas.

#### Jobs prioritários

```
ProcessarImportacaoExtrato      ← primeiro (maior impacto)
ConverterPdfOfx
ConverterExcelPersonalizado
GerarExportacaoContabil
ExcluirArquivosExpirados
CalcularConsumoOperadora
```

#### Fluxo alvo

```
Upload → Object storage → Registro "aguardando" → Job na fila
  → Worker Python → Progresso persistido → Notificação Livewire/polling
```

#### Infra mínima

- [ ] Redis (filas + cache + sessão)
- [ ] Laravel Horizon
- [ ] Worker container separado
- [ ] Filas: `imports`, `conversions`, `exports`
- [ ] Limites por operadora: jobs simultâneos, tamanho de arquivo

#### Storage

```
tenants/{operadora_uuid}/uploads/
tenants/{operadora_uuid}/processed/
tenants/{operadora_uuid}/exports/
tenants/{operadora_uuid}/temp/
```

- [ ] URLs assinadas para download
- [ ] Lifecycle / expiração de temp
- [ ] Hash do arquivo (`file_hash`) para idempotência

**Critério de saída:** importação típica processada via job; app web stateless em relação a arquivos.

---

### Fase 3 — Infraestrutura de produção

**Objetivo:** múltiplas réplicas, deploy seguro, recuperação comprovada.

**Prazo sugerido:** 4–6 semanas.

**Substituir stack de desenvolvimento:**

| Hoje | Produção |
|------|----------|
| `artisan serve` | Nginx + PHP-FPM |
| Container como root | Usuário sem privilégios |
| MySQL no Compose | Banco gerenciado (MySQL 8+) |
| Disco local | Object storage |
| Sem health check | Health checks + autoscaling |

**Metas operacionais (iniciais):**

```
Disponibilidade: 99,9%/mês
RPO: 15 minutos
RTO: 4 horas
p95 páginas normais: < 800 ms
95% jobs iniciados em < 60 s
```

**Critério de saída:** deploy sem downtime; restore de backup testado mensalmente.

---

### Fase 4 — Produto SaaS e comercialização

**Objetivo:** escritório se cadastra, paga e opera sem intervenção manual.

**Prazo sugerido:** 6–10 semanas.

**Onboarding self-service:**

1. Cadastro → verificação e-mail
2. Criação da operadora (trial)
3. Primeiro usuário admin
4. Convite de equipe
5. Cadastro de empresas clientes
6. Tutorial primeira importação

**Billing (estruturas):**

```
plans, subscriptions, usage_records, invoices
```

Gateway sugerido para BR: Asaas ou Stripe.

**Planos preliminares (validar com Fase 0):**

| Plano | Empresas | Usuários | Lançamentos/mês |
|-------|----------|----------|-----------------|
| Inicial | 20 | 3 | 20.000 |
| Profissional | 100 | 10 | 150.000 |
| Enterprise | Custom | Custom | Custom |

**Entitlements:** serviço centralizado `OperadoraEntitlements` — não espalhar `if ($plano)` no código.

**Critério de saída:** novo escritório completa primeiro ciclo importar→exportar sem suporte manual.

---

### Fase 5 — Observabilidade e operação contínua

**Contexto obrigatório em logs:**

```
operadora_id, user_id, request_id, job_id, importacao_id
```

**Dashboards:** técnico + por operadora (consumo, erros, fila).

**SLOs e alertas:** fila crescendo, taxa de erro de conversor, disco/storage.

---

### Fase 6+ — Sob demanda (não iniciar sem métricas)

- API pública `/api/v1/*`
- Webhooks
- SSO Enterprise
- Pivot multi-operadora por usuário
- Banco dedicado Enterprise
- Marketplace de layouts
- Motor de regras versionado
- Microsserviço de conversão

---

## Parte V — Priorização executiva

### P0 — Antes do 2º escritório em produção

1. `empresa_operadora_id` + backfill
2. OperadoraContext + middleware
3. Policies + testes de isolamento no CI
4. Corrigir Livewire P0 (seletor, usuários, empresas, trocar-empresa)
5. Role `super_admin`
6. Revisão de APIs
7. Segurança básica de upload e Python

### P1 — Antes de beta paga (10–20 escritórios)

1. Jobs de importação e conversão
2. Redis + Horizon
3. Object storage
4. Produção PHP-FPM + banco gerenciado
5. MFA admins
6. Audit logs
7. Backup com restore testado

### P2 — Antes de lançamento público (50+ escritórios)

1. Billing + onboarding self-service
2. Limites por plano
3. Observabilidade completa
4. Teste de carga documentado
5. CI/CD completo
6. Documentação LGPD mínima

### P3 — Após product-market fit

API, webhooks, SSO, Enterprise DB, IA assistida.

---

## Parte VI — Estratégia de lançamento por etapas

| Etapa | Escritórios | Gate técnico obrigatório |
|-------|-------------|--------------------------|
| **Alpha** | 3–5 | Fase 1A + 1B concluídas |
| **Piloto** | 10–20 | Fase 2 concluída (jobs) |
| **Beta paga** | 20–50 | Fase 3 + 4 mínimo |
| **Lançamento** | 50–200 | P2 completo + teste de carga |
| **Escala** | 200+ | Fase 5 madura + custo/tenant conhecido |

**Não avançar etapa sem:**

- [ ] Suíte de isolamento verde
- [ ] Backup restaurado em teste
- [ ] Capacidade documentada (jobs/min, uploads simultâneos)
- [ ] Taxa de erro de conversão aceitável
- [ ] Suporte dimensionado

---

## Parte VII — Riscos consolidados

| Risco | Impacto | Prob. | Mitigação |
|-------|---------|-------|-----------|
| Vazamento entre operadoras | Crítico | Alta sem Fase 1 | Policies + testes CI |
| Global Scope como única barreira | Crítico | Média | ADR-003 — defesa em profundidade |
| Lançamentos duplicados | Alto | Média | file_hash + idempotency (Fase 2) |
| Python travando servidor | Alto | Alta hoje | Jobs (Fase 2) |
| Exportação contábil incorreta | Crítico | Média | Golden files + testes integração |
| Regressão escritório atual | Alto | Média | Operadora padrão + E2E antes deploy |
| Over-engineering | Médio | Média | P0/P1/P2 explícito; Fase 6+ sob demanda |
| Prazo Fase 1 subestimado | Médio | Alta | 3–5 sem, não 1–2 |
| Docker dev em produção | Alto | Alta hoje | Fase 3 |
| Incidente LGPD | Crítico | Média | Fase 1B + 4 |

---

## Parte VIII — Indicadores de sucesso

### Técnicos (Fase 1–3)

- 100% testes de isolamento passando
- 0 importações pesadas síncronas em produção
- Restore de backup < RTO definido
- p95 latência web < 800 ms

### Produto (Fase 0–4)

- Tempo até primeiro valor (primeira exportação) < 1 hora após cadastro
- Taxa de amarração automática ≥ meta definida no piloto
- Retenção pilotos ≥ 80% em 90 dias

### Financeiros (Fase 4+)

- MRR, churn, custo infra/tenant, margem bruta

---

## Parte IX — Perguntas para validação externa

Consolidado dos dois documentos, priorizado:

### Negócio (validar primeiro)

1. Precificação: por empresa, usuário, lançamento ou híbrido?
2. Escritórios aceitam infra compartilhada ou exigem DB dedicado?
3. Self-service ou provisionamento manual no início?
4. White-label é requisito ou diferencial futuro?

### Técnico

5. Banco compartilhado + `empresa_operadora_id` escala até quantos tenants?
6. Global Scope + Policies é suficiente ou usar `stancl/tenancy`?
7. Migrar MySQL 5.7 → 8 antes ou durante Fase 3?
8. FK direta user→operadora vs. pivot multi-operadora?
9. E-mail único global ou por operadora?

### Segurança / LGPD

10. Retenção de extratos — prazo padrão e configurável?
11. MFA obrigatório para todos ou só admins?
12. DPA/contrato de tratamento — template adequado?

### Operação

13. SLO 99,9% é realista para a equipe atual?
14. RPO 15 min / RTO 4 h — aceitável para escritórios contábeis?

---

## Parte X — Entregável do primeiro mês (prova técnica)

Checklist unificado — meta concreta após ~4 semanas de Fase 1A+1B:

- [ ] Duas operadoras no banco com dados isolados
- [ ] Usuários independentes por operadora
- [ ] Suíte de 7+ testes de acesso cruzado no CI
- [ ] Super admin gerencia operadoras
- [ ] Seletor de empresa filtrado por operadora
- [ ] `/trocar-empresa/{id}` protegido
- [ ] Protótipo de 1 job de importação (mesmo que feature flag)
- [ ] Logs com `operadora_id` e `request_id`
- [ ] ADRs 001–008 registrados em `docs/adr/`

---

## Parte XI — Glossário

| Termo | Significado |
|-------|-------------|
| **Empresa Operadora** | Escritório de contabilidade — tenant SaaS |
| **Empresa (cliente)** | CNPJ atendido pelo escritório |
| **Tenant** | Sinônimo de Empresa Operadora neste plano |
| **Super Admin** | Administrador da plataforma IntegraExpert |
| **OperadoraContext** | Serviço que resolve o tenant da requisição/job |
| **Multi-empresa** | Vários CNPJs no mesmo escritório *(já existe)* |
| **Multi-escritório** | Vários tenants na plataforma *(a implementar)* |

---

## Parte XII — Histórico e documentos relacionados

| Versão | Data | Descrição |
|--------|------|-----------|
| 1.0 | jun/2026 | `ESCALA_MULTI_ESCRITORIO.md` — análise pragmática |
| 1.0 | jun/2026 | `ROADMAP_ESCALA_SAAS-codex.md` — roadmap enterprise |
| **2.0** | **jun/2026** | **Este documento — plano consolidado** |

**Próxima revisão:** após conclusão da Fase 1A ou mudança de decisão arquitetural (registrar em `docs/adr/`).

---

*Este plano não substitui auditoria de segurança, parecer jurídico/LGPD ou teste de carga formal. Use as Partes IX e X como roteiro para consultas externas.*
