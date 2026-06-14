# IntegraExpert — Análise de Escala Multi-Escritório

**Documento para avaliação interna e consulta a outras fontes**  
**Data:** junho/2026  
**Versão:** 1.0  
**Escopo:** evolução do IntegraExpert de instância única (um escritório) para plataforma SaaS (vários escritórios de contabilidade)

---

## 1. Resumo executivo

O IntegraExpert hoje atende bem **um escritório contábil** que gerencia **várias empresas clientes** (multi-empresa via seletor global na barra superior). Para atender **vários escritórios** na mesma plataforma, é necessário evoluir para arquitetura **multi-tenant** (SaaS).

O conceito de tenant já foi **esboçado** na tabela `empresas_operadoras` e no CRUD em `/empresas-operadoras`, mas **não está conectado** ao restante do sistema: usuários, empresas clientes, importações, lançamentos e regras não possuem isolamento por escritório.

**Conclusão principal:** não é necessário reescrever o produto. O fluxo central (Importar → Amarrar → Conferir → Exportar) permanece. A mudança estrutural é inserir a **Empresa Operadora (escritório)** como camada de isolamento acima de todos os dados e usuários.

**Pré-requisito absoluto:** completar o multi-tenant antes de colocar um segundo escritório em produção no mesmo ambiente.

---

## 2. Contexto do produto atual

### 2.1 O que o sistema faz

Plataforma web para escritórios contábeis:

- Importar extratos bancários (PDF, OFX, CSV, TXT)
- Classificar lançamentos automaticamente por regras de amarração
- Conferir e revisar na tabela de lançamentos
- Exportar para o ERP **Domínio** (layout TXT)

### 2.2 Stack técnica

| Camada        | Tecnologia                          |
|---------------|-------------------------------------|
| Backend       | Laravel 12, PHP 8.2                   |
| Frontend      | Livewire 3, Tailwind CSS            |
| Banco         | MySQL 5.7                           |
| Conversores   | Python (PDF, OFX, Excel)            |
| Deploy        | Docker Compose                      |
| Produção      | integraexpert.com.br                |

### 2.3 Hierarquia de dados hoje

```
Usuários (globais, sem vínculo com escritório)
  └── Empresas clientes (globais — seletor na sessão)
        └── Importações → Lançamentos → Regras → Terceiros → Layouts...
```

### 2.4 Perfis de acesso hoje

| Perfil    | Escopo atual                                      |
|-----------|---------------------------------------------------|
| Operador  | Cadastros, importação, lançamentos, exportação    |
| Gerente   | Tudo do operador + gestão de usuários             |
| Admin     | Tudo acima + históricos padrão, auditoria PDF→OFX |

Todos os perfis enxergam **o mesmo universo de dados** — não há barreira entre escritórios.

---

## 3. Diagnóstico: o que existe vs. o que falta

| Camada                    | Situação atual                                      | Limitação para escala                    |
|---------------------------|-----------------------------------------------------|------------------------------------------|
| Empresas clientes         | Seletor global, `empresa_id` em importações/regras  | Funciona *dentro* de um escritório       |
| Empresas Operadoras       | CRUD admin em `/empresas-operadoras`                | **Não ligada** a users, empresas ou dados |
| Usuários                  | admin / gerente / operador                          | `User::all()` — todos veem todos         |
| Isolamento de dados       | Filtro por `empresa_id` na sessão                   | Sem barreira entre escritórios           |
| Terceiros                 | Tabela global, sem `empresa_id`                     | Compartilhados entre todos               |
| Processamento             | Python síncrono na requisição HTTP (até 5 min)      | Não escala com uploads simultâneos       |
| Infraestrutura            | Docker Compose, MySQL único, arquivos locais        | Single-instance                          |
| Billing / planos          | Inexistente                                         | —                                        |
| Onboarding de escritórios | Inexistente                                         | —                                        |

### 3.1 Evidências no código

- `EmpresasOperadora` — model e migration existem, mas sem relações com outros models.
- `SeletorEmpresaGlobal` — `Empresa::orderBy('nome')->get()` sem filtro de tenant.
- `GerenciadorUsuarios` — `User::all()` sem escopo.
- `GerenciadorEmpresas` — `Empresa::create([...])` sem `empresa_operadora_id`.
- Rota `/trocar-empresa/{id}` — aceita qualquer ID de empresa sem validar tenant.
- `ImportadorAvancado` — processamento Python inline com `set_time_limit(300)`.

---

## 4. Hierarquia alvo (multi-tenant)

```
Super Admin (plataforma IntegraExpert)
  └── Escritório / Empresa Operadora (tenant)
        ├── Usuários do escritório (admin, gerente, operador)
        └── Empresas clientes (CNPJ, código Domínio, conta banco)
              ├── Importações / Lançamentos
              ├── Regras de amarração
              ├── Terceiros
              ├── Layouts personalizados
              └── Conversões PDF→OFX
```

O fluxo operacional **Importar → Amarrar → Conferir → Exportar** permanece idêntico para o usuário final de cada escritório.

---

## 5. Plano de ações por fase

### Fase 1 — Multi-tenancy (obrigatório)

**Objetivo:** isolamento completo de dados entre escritórios.

| # | Ação | Detalhe |
|---|------|---------|
| 1.1 | Adicionar `empresa_operadora_id` nas tabelas | Ver seção 6.1 |
| 1.2 | Global Scope no Eloquent | Trait `BelongsToOperadora` em todos os models tenant-aware |
| 1.3 | Middleware de contexto | `SetOperadoraContext` — define tenant da sessão/usuário |
| 1.4 | Nova hierarquia de papéis | `super_admin` (plataforma) vs. `admin`/`gerente`/`operador` (escritório) |
| 1.5 | Filtrar seletor de empresa | Só empresas do escritório do usuário logado |
| 1.6 | Validar rotas com ID | `/trocar-empresa/{id}` e demais rotas — anti-IDOR |
| 1.7 | Migration de dados legados | Associar tudo existente a uma operadora padrão |
| 1.8 | Testes de isolamento | Usuário do escritório A nunca acessa dados do B |

**Esforço estimado:** 1–2 semanas (desenvolvimento) + testes.

---

### Fase 2 — Onboarding e gestão de escritórios

**Objetivo:** operacionalizar múltiplos clientes (escritórios).

| # | Ação | Detalhe |
|---|------|---------|
| 2.1 | Fluxo de cadastro de escritório | Super admin cria tenant ou self-service com aprovação |
| 2.2 | Primeiro usuário = admin do escritório | Convite por e-mail |
| 2.3 | Painel Super Admin | Lista escritórios, status, uso (importações/mês, usuários) |
| 2.4 | Impersonate | Login como admin do escritório para suporte |
| 2.5 | White-label leve | Logo e nome fantasia no header (campo `logo` já existe) |
| 2.6 | Subdomínio (opcional) | `escritorio-x.integraexpert.com.br` |

**Esforço estimado:** ~1 semana.

---

### Fase 3 — Infraestrutura e performance

**Objetivo:** suportar carga de vários escritórios simultâneos.

| # | Ação | Detalhe |
|---|------|---------|
| 3.1 | Filas assíncronas | Upload → Job Redis → Worker Python → notificação |
| 3.2 | Armazenamento por tenant | S3/MinIO com prefixo `{operadora_id}/` |
| 3.3 | Observabilidade | Logs com `operadora_id`, métricas, alertas |
| 3.4 | Rate limiting | Throttle de upload por tenant |
| 3.5 | Backup | Por tenant ou restore seletivo |

**Componentes novos:**

```
app/Jobs/ProcessarImportacaoExtrato.php
app/Events/ImportacaoConcluida.php
docker-compose: serviço redis + queue worker
```

**Esforço estimado:** ~1–2 semanas.

---

### Fase 4 — Comercialização (SaaS)

**Objetivo:** monetizar e controlar uso.

| # | Ação | Detalhe |
|---|------|---------|
| 4.1 | Planos e limites | Empresas, usuários, importações/mês, tamanho de arquivo |
| 4.2 | Billing | Stripe / Asaas / PagSeguro |
| 4.3 | Trial e suspensão | Inadimplência → tenant suspenso |
| 4.4 | LGPD | Exportação/exclusão de dados por tenant, política de retenção |

**Esforço estimado:** 2–3 semanas.

---

### Fase 5 — Segurança e confiabilidade

| # | Ação | Detalhe |
|---|------|---------|
| 5.1 | Auditoria reforçada | `operadora_id` + `user_id` em logs de alteração |
| 5.2 | Testes de penetração básicos | Rotas com ID, escopo de queries |
| 5.3 | DR / RTO-RPO | Definir para dados contábeis |

---

## 6. Alterações na estrutura atual (detalhamento)

### 6.1 Banco de dados — colunas novas

#### Tabelas que recebem `empresa_operadora_id`

| Tabela                         | FK hoje        | Observação                                      |
|--------------------------------|----------------|-------------------------------------------------|
| `users`                        | —              | Nullable apenas para `super_admin`              |
| `empresas`                     | —              | CNPJ passa a ser único por escritório           |
| `importacoes`                  | `empresa_id`   | Direta ou herdada via empresa                   |
| `lancamentos`                  | `empresa_id`   | Idem                                            |
| `regras_amarracoes_descricoes` | `empresa_id`   | Idem                                            |
| `layouts_importacao`           | `empresa_id`   | Idem                                            |
| `historicos_padrao_layout`     | `empresa_id`   | Escopo por escritório                           |
| `terceiros`                    | **nenhuma**    | Hoje global — precisa de escopo                 |
| `conversoes_extrato`           | `empresa_id`   | Adicionar escopo de escritório                  |
| `amarracoes`                   | —              | Se ainda em uso                                 |

#### Tabela `empresas_operadoras` — campos adicionais sugeridos

| Campo              | Tipo        | Propósito                    |
|--------------------|-------------|------------------------------|
| `ativo`            | boolean     | Ativar/suspender tenant      |
| `plano`            | string/enum | Plano comercial              |
| `limite_empresas`  | integer     | Quota de empresas clientes   |
| `limite_usuarios`  | integer     | Quota de usuários            |
| `subdominio`       | string      | White-label por URL          |

#### Constraints que mudam

**Antes (`empresas`):**
```sql
UNIQUE (cnpj)  -- único global
```

**Depois:**
```sql
UNIQUE (empresa_operadora_id, cnpj)  -- único por escritório
```

**Usuários:** decidir se `email` permanece único global ou passa a `UNIQUE (empresa_operadora_id, email)`.

#### Script de migração de dados legados

1. Criar registro em `empresas_operadoras` (escritório atual / padrão).
2. `UPDATE` em todas as tabelas com o ID dessa operadora.
3. Tornar colunas `NOT NULL` (exceto super admin em `users`).
4. Recriar índices e constraints de unicidade.

---

### 6.2 Models — alterações

#### Arquivos novos

```
app/Models/Concerns/BelongsToOperadora.php
app/Services/OperadoraContext.php
app/Http/Middleware/SetOperadoraContext.php
```

#### Models existentes (15) — impacto

| Model                    | Alteração principal                              |
|--------------------------|--------------------------------------------------|
| `User`                   | `belongsTo(EmpresasOperadora)`                   |
| `Empresa`                | `belongsTo(EmpresasOperadora)`                   |
| `EmpresasOperadora`      | `hasMany` users, empresas — deixa de ser isolado |
| `Importacao`             | Trait + relação                                  |
| `Lancamento`             | Trait + relação                                  |
| `RegraAmarracaoDescricao`| Trait + relação                                  |
| `Terceiro`               | Trait + relação (hoje sem escopo)                |
| `LayoutImportacao`       | Trait + relação                                  |
| `HistoricoPadraoLayout`  | Trait + relação                                  |
| `ConversaoExtrato`       | Trait + relação                                  |
| Demais                   | Avaliar caso a caso                              |

#### Padrão Global Scope

```php
static::addGlobalScope('operadora', function ($query) {
    if ($id = OperadoraContext::id()) {
        $query->where('empresa_operadora_id', $id);
    }
});
```

**Atenção:** super admin precisa de mecanismo para **desabilitar** o scope ao gerenciar a plataforma (`withoutGlobalScope('operadora')`).

---

### 6.3 Autenticação e papéis

#### Proposta de roles

| Role          | Quem                    | Escopo de dados              |
|---------------|-------------------------|------------------------------|
| `super_admin` | Equipe IntegraExpert    | Todos os escritórios         |
| `admin`       | Responsável do escritório | Seu tenant                 |
| `gerente`     | Gerente do escritório   | Seu tenant                   |
| `operador`    | Operador contábil       | Seu tenant                   |

#### Arquivos afetados

| Arquivo                                      | Mudança                                      |
|----------------------------------------------|----------------------------------------------|
| Migration `add_role_to_users_table`          | Novo valor `super_admin` no enum             |
| `RoleMiddleware.php`                         | Lógica por contexto de tenant                |
| `GerenciadorUsuarios.php`                    | Listar só usuários do escritório             |
| `EmpresasOperadorasForm.php`                 | Restrito a `super_admin`                     |
| `MenuTrait.php`                              | Menus distintos plataforma vs. escritório    |

---

### 6.4 Componentes Livewire — matriz de impacto

| Componente                         | Impacto | Problema atual                                      |
|------------------------------------|---------|-----------------------------------------------------|
| `SeletorEmpresaGlobal`             | **Alto**| Lista todas as empresas                             |
| `GerenciadorEmpresas`              | **Alto**| Create sem `empresa_operadora_id`                   |
| `GerenciadorUsuarios`              | **Alto**| `User::all()`                                       |
| `GerenciadorTerceiros`             | **Alto**| Terceiros globais                                   |
| `ImportadorAvancado`               | **Alto**| Valida empresa sem checar tenant                    |
| `ImportadorPersonalizado`          | **Alto**| Layouts sem barreira entre escritórios              |
| `TabelaLancamentos`                | **Alto**| Empresa da sessão pode ser de outro tenant          |
| `GerenciadorRegrasAmarracao`       | **Alto**| Regras sem tenant                                   |
| `ListaImportacoes`                 | **Médio**| Filtros incompletos                                |
| `ExportadorContabil`               | **Médio**| Idem                                               |
| `ExtratorBancario`                 | **Médio**| Idem                                               |
| `GerenciadorHistoricosPadraoLayout`| **Médio**| Admin global vê tudo                               |
| `ListaConversoesExtrato`           | **Médio**| Idem                                               |
| `ConversorPdfOfx`                  | **Médio**| Conversões sem escopo                              |
| `EmpresasOperadorasForm`           | **Médio**| Passa a ser painel super admin                     |
| `Home`                             | **Baixo**| Links/menus                                        |

---

### 6.5 Rotas e middleware

#### Registro em `bootstrap/app.php`

```php
$middleware->web(append: [
    \App\Http\Middleware\SetOperadoraContext::class,
]);
```

#### Grupos de rotas

| Grupo            | Middleware                    | Exemplos                          |
|------------------|-------------------------------|-----------------------------------|
| Plataforma       | `auth`, `role:super_admin`    | `/super-admin/escritorios`, métricas |
| Escritório       | `auth`, tenant context        | Todas as rotas atuais             |

#### Rota crítica a corrigir

`/trocar-empresa/{id}` — validar que a empresa pertence ao tenant do usuário antes de gravar na sessão.

---

### 6.6 Interface (views / layout)

| Elemento              | Hoje                         | Depois                                      |
|-----------------------|------------------------------|---------------------------------------------|
| Header                | Seletor de empresa cliente   | Super admin: seletor de escritório + empresa |
| Logo / nome           | IntegraExpert fixo           | `nome_fantasia` + `logo` da operadora       |
| Menu Administração    | Históricos, conversões       | Super admin: gestão de escritórios          |
| `/empresas-operadoras`| Fora do menu, só admin       | Painel de plataforma (super admin)          |

---

### 6.7 Armazenamento de arquivos

| Tipo        | Caminho hoje              | Caminho proposto                        |
|-------------|---------------------------|-----------------------------------------|
| Upload temp | `storage/app/temp/`       | `storage/app/{operadora_id}/temp/`      |
| Exports     | `storage/app/exports/`    | `storage/app/{operadora_id}/exports/`   |
| Logos       | `storage/app/public/logos/` | `.../logos/{operadora_id}/`           |

Afeta: `ImportadorAvancado`, `ExportadorContabil`, `EmpresasOperadorasForm`, `ConversaoPdfOfxService`, rotas de download.

---

### 6.8 O que NÃO muda (ou muda muito pouco)

| Camada                         | Motivo                                           |
|--------------------------------|--------------------------------------------------|
| Scripts Python (`scripts/`)    | Parsing agnóstico ao tenant                      |
| Fluxo Importar→Exportar        | Mesmo workflow de negócio                        |
| Motor de regras de amarração   | Continua por `empresa_id` + layout               |
| Formato de exportação Domínio  | Layout TXT ISO-8859-1 inalterado                 |
| Stack Laravel + Livewire       | Permanece                                        |
| Docker Compose (base)          | Só adiciona Redis/worker na Fase 3                |

---

## 7. Decisões arquiteturais para validar externamente

Estas decisões devem ser discutidas com arquitetos, consultores ou comunidade antes de implementar:

### 7.1 Modelo de multi-tenancy

| Opção | Descrição | Prós | Contras |
|-------|-----------|------|---------|
| **A — Banco compartilhado + `tenant_id`** | Uma DB, coluna em todas as tabelas | Simples, alinhado ao código atual | Risco de vazamento se scope falhar |
| **B — Schema por tenant** | Um schema PostgreSQL por escritório | Isolamento maior | MySQL 5.7 não suporta bem; migração de stack |
| **C — Banco por tenant** | DB separado por escritório | Isolamento máximo | Complexidade operacional alta |

**Recomendação interna:** Opção **A** — banco compartilhado com `empresa_operadora_id`. Compatível com MySQL atual, `EmpresasOperadora` já existe, escala até centenas de tenants com índices corretos.

### 7.2 Identificação do tenant

| Opção | Exemplo | Prós | Contras |
|-------|---------|------|---------|
| Por usuário logado | `user.empresa_operadora_id` | Simples | Super admin precisa de seletor |
| Por subdomínio | `abc.integraexpert.com.br` | White-label natural | DNS, SSL wildcard |
| Por path | `/tenant/abc/...` | Sem DNS | URLs feias |

**Recomendação interna:** começar por **usuário logado**; subdomínio na Fase 2 se houver demanda de white-label.

### 7.3 Unicidade de e-mail de usuário

| Opção | Cenário |
|-------|---------|
| Global | Mesmo e-mail não pode existir em dois escritórios |
| Por tenant | `joao@contabil.com.br` pode existir no escritório A e B |

**Pergunta para validação:** qual comportamento o mercado contábil espera?

### 7.4 Processamento síncrono vs. assíncrono

| Fase | Abordagem |
|------|-----------|
| Fase 1 | Manter síncrono (menor risco, 1–2 escritórios) |
| Fase 3 | Migrar para filas (obrigatório com carga) |

### 7.5 Históricos padrão por layout

Hoje são **globais da plataforma** (admin configura para todos). Com multi-tenant:

| Opção | Descrição |
|-------|-----------|
| Globais | Super admin mantém catálogo único de layouts/bancos |
| Por escritório | Cada tenant customiza seus históricos |
| Híbrido | Globais + override por tenant |

**Pergunta para validação:** layouts de banco (Sicoob, Sicredi…) são iguais para todos os escritórios?

---

## 8. Roadmap consolidado

| Ordem | Entrega                                      | Fase | Esforço est. |
|-------|----------------------------------------------|------|--------------|
| 1     | Migrations + `empresa_operadora_id` + dados legados | 1 | 3–5 dias |
| 2     | Trait, Global Scope, middleware              | 1    | 2–3 dias     |
| 3     | Roles `super_admin` + ajuste de permissões   | 1    | 2–3 dias     |
| 4     | Livewire alto impacto (seletor, CRUDs, importador) | 1 | 5–7 dias |
| 5     | Rotas, storage paths, testes de isolamento   | 1    | 3–5 dias     |
| 6     | Painel super admin + onboarding              | 2    | 5–7 dias     |
| 7     | White-label (logo, nome)                     | 2    | 2–3 dias     |
| 8     | Filas Redis + job de importação              | 3    | 5–7 dias     |
| 9     | S3 / observabilidade                         | 3    | contínuo     |
| 10    | Planos, limites, billing                     | 4    | 2–3 semanas  |

**Total Fase 1 (mínimo viável multi-tenant):** ~2–3 semanas de desenvolvimento focado.

---

## 9. Riscos e mitigações

| Risco | Impacto | Mitigação |
|-------|---------|-----------|
| Vazamento de dados entre tenants | Crítico | Global Scope + testes automatizados + code review |
| Regressão no escritório atual | Alto | Operadora padrão na migration; testes E2E antes do deploy |
| Performance com Global Scope | Médio | Índices em `empresa_operadora_id`; evitar N+1 |
| Complexidade de super admin | Médio | Implementar por último na Fase 1; começar com 1 operadora |
| LGPD / dados contábeis | Alto | Política de retenção; export/delete por tenant (Fase 4) |
| Processamento síncrono sob carga | Médio | Fase 3 — filas; limitar concorrência por tenant |

---

## 10. Diagrama de arquitetura alvo

```
┌─────────────────────────────────────────────────────────────┐
│                  IntegraExpert (SaaS)                        │
├─────────────────────────────────────────────────────────────┤
│  Super Admin                                                 │
│    └── Gerencia Escritórios (Empresas Operadoras)            │
│    └── Métricas, planos, suporte (impersonate)               │
├──────────────────────────┬──────────────────────────────────┤
│  Escritório A (tenant)   │  Escritório B (tenant)           │
│    ├── Usuários A        │    ├── Usuários B                │
│    ├── Empresa Cliente 1 │    ├── Empresa Cliente X         │
│    ├── Empresa Cliente 2 │    └── ...                       │
│    ├── Importações       │                                  │
│    ├── Lançamentos       │                                  │
│    └── Regras            │                                  │
└──────────────────────────┴──────────────────────────────────┘
              │                         │
              ▼                         ▼
         [MySQL compartilhado]    [Redis Queue*]
              │                         │
              ▼                         ▼
         tenant_id em todas       [Python Workers*]
         as tabelas
              
         [Storage: S3 ou local/{tenant_id}/]

* Fase 3
```

---

## 11. Perguntas para consulta externa

Use esta lista ao buscar segunda opinião (consultores, fóruns, arquitetos Laravel, contadores parceiros):

### Negócio

1. Qual modelo de precificação faz sentido? (por empresa cliente, por usuário, por importação/mês?)
2. Escritórios contábeis aceitam dados na mesma infraestrutura ou exigem isolamento físico?
3. Self-service de cadastro ou apenas provisionamento manual inicialmente?
4. White-label (logo/domínio próprio) é requisito do mercado ou diferencial futuro?

### Técnico

5. Banco compartilhado + `tenant_id` é suficiente para dezenas/centenas de escritórios contábeis?
6. Global Scope no Eloquent é abordagem segura ou preferir package (ex.: `stancl/tenancy`)?
7. Manter MySQL 5.7 ou migrar versão antes de escalar?
8. Processamento síncrono aguenta quantos escritórios simultâneos na infra atual?
9. S3 vs. disco local com prefixo por tenant — requisitos de LGPD para extratos bancários?

### Produto

10. Históricos padrão de layout devem ser globais (plataforma) ou por escritório?
11. Terceiros devem ser compartilhados entre empresas clientes do mesmo escritório ou por empresa?
12. Conversão PDF→OFX entra no plano base ou é add-on?

### Segurança / Compliance

13. Quais evidências de auditoria o mercado contábil exige?
14. Prazo de retenção de extratos e lançamentos — por tenant configurável?
15. Necessidade de certificações ou contratos específicos (DPA, SOC2)?

---

## 12. Referências úteis para pesquisa

| Tema | Onde buscar |
|------|-------------|
| Multi-tenancy Laravel | [Laravel docs — multi-tenancy patterns](https://laravel.com/docs), package `stancl/tenancy` |
| SaaS B2B contábil BR | Concorrentes: ContaAzul, Omie, módulos de escritórios no Domínio/Alterdata |
| LGPD SaaS | ANPD — guias sobre controlador/operador de dados |
| Filas Laravel | Laravel Horizon + Redis |
| Isolamento de dados | OWASP — Broken Access Control (IDOR) |

---

## 13. Glossário

| Termo | Significado no IntegraExpert |
|-------|------------------------------|
| **Empresa Operadora** | Escritório de contabilidade — o **tenant** |
| **Empresa (cliente)** | CNPJ atendido pelo escritório — cliente final |
| **Tenant** | Instância lógica isolada (= Empresa Operadora) |
| **Super Admin** | Administrador da plataforma IntegraExpert |
| **Multi-empresa** | Vários clientes CNPJ no mesmo escritório (já existe) |
| **Multi-escritório** | Vários escritórios na mesma plataforma (a implementar) |
| **Global Scope** | Filtro automático em queries Eloquent por tenant |
| **IDOR** | Acesso indevido via manipulação de ID na URL |

---

## 14. Histórico do documento

| Versão | Data     | Autor / origem | Alterações |
|--------|----------|----------------|------------|
| 1.0    | jun/2026 | Análise técnica IntegraExpert | Documento inicial consolidando diagnóstico e plano de ações |

---

*Este documento reflete o estado do codebase e da documentação em junho/2026. Revisar após implementação de cada fase ou mudanças arquiteturais significativas.*
