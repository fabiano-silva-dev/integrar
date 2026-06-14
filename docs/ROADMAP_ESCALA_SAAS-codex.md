# Roadmap de Evolução do Integrar para uma Plataforma SaaS Escalável

## 1. Objetivo deste documento

Este documento consolida uma avaliação inicial do projeto **Integrar** e propõe um plano de evolução para transformá-lo em uma plataforma SaaS capaz de atender milhares de escritórios de contabilidade.

Ele foi preparado para servir como:

- base de discussão técnica;
- roteiro de implementação;
- material para estimativas de prazo e orçamento;
- checklist para auditorias de arquitetura, segurança e LGPD;
- insumo para avaliações de consultorias, fornecedores de nuvem e potenciais investidores;
- registro das decisões que ainda precisam ser tomadas.

Este material não substitui auditorias especializadas de segurança, privacidade, infraestrutura, contabilidade ou legislação. Antes do lançamento comercial em escala, as recomendações devem ser revisadas por profissionais dessas áreas.

---

## 2. Resumo executivo

O Integrar já possui um núcleo funcional relevante para escritórios de contabilidade:

- importação de extratos e arquivos contábeis;
- importação personalizada de CSV e Excel;
- conversão de PDF para OFX;
- regras de amarração;
- edição e conferência de lançamentos;
- cadastro de empresas, usuários e terceiros;
- exportação para sistemas contábeis;
- histórico de importações e conversões;
- geração e conferência de extratos.

O fluxo principal do produto pode ser resumido como:

> Importar dados → aplicar regras → revisar lançamentos → exportar para o sistema contábil.

O principal desafio para comercialização em escala não é apenas aumentar a capacidade do servidor. Antes disso, a aplicação precisa evoluir de um sistema contábil compartilhado para um **produto SaaS multi-tenant**, no qual cada escritório seja um cliente isolado da plataforma.

As prioridades estruturais são:

1. criar o conceito explícito de escritório/tenant;
2. garantir isolamento de dados entre clientes;
3. executar importações e conversões em filas assíncronas;
4. implantar infraestrutura de produção segura e escalável;
5. elevar a cobertura de testes;
6. implementar observabilidade, auditoria e recuperação de desastres;
7. atender aos requisitos de segurança e LGPD;
8. desenvolver planos, cobrança, limites e onboarding self-service.

Não é recomendável iniciar com uma arquitetura de microsserviços. Um monólito Laravel modular, executado de forma stateless, com banco gerenciado, Redis, object storage e workers independentes, deve atender a uma quantidade significativa de clientes antes que a extração de serviços seja necessária.

---

## 3. Escopo da avaliação

A análise considera principalmente:

- estrutura atual dos modelos;
- rotas web e de API;
- componentes Livewire;
- execução de importações e conversões;
- armazenamento de arquivos;
- configuração de sessões, cache e filas;
- infraestrutura Docker atual;
- testes automatizados existentes;
- requisitos esperados de uma plataforma SaaS B2B contábil.

Não foram executados nesta etapa:

- pentest;
- análise estática completa de segurança;
- teste de carga;
- benchmark dos conversores;
- inspeção de infraestrutura real de produção;
- validação jurídica;
- levantamento detalhado de custos de nuvem;
- entrevistas com usuários e clientes;
- auditoria contábil dos arquivos exportados.

Essas atividades aparecem como ações recomendadas nas fases seguintes.

---

## 4. Diagnóstico do estado atual

### 4.1 Pontos fortes

#### Domínio de negócio já implementado

O sistema já cobre um fluxo que possui valor comercial direto para escritórios de contabilidade. Isso reduz o risco de começar uma plataforma SaaS sem um núcleo funcional validável.

#### Associação de dados com empresas

Importações, lançamentos, layouts e regras já utilizam `empresa_id` em diferentes pontos. Essa estrutura ajuda na futura implementação multi-tenant, embora `empresa_id` não seja suficiente para representar o cliente SaaS.

#### Controle básico de papéis

Já existem papéis como administrador, gerente e operador. Eles podem servir de base para um sistema de permissões contextualizado por escritório.

#### Histórico operacional

Importações, conversões e alterações de lançamentos já possuem algum nível de rastreabilidade. Isso pode ser expandido para uma auditoria SaaS completa.

#### Suporte a filas no framework

O Laravel já está configurado para aceitar uma conexão de filas. O desafio é mover os processamentos pesados atuais para jobs reais.

---

### 4.2 Lacunas críticas

#### Ausência do conceito explícito de tenant

Atualmente, a principal unidade organizacional é a empresa contábil. Em um SaaS, é necessário separar:

- **tenant/escritório:** cliente que assina e utiliza a plataforma;
- **empresa:** empresa atendida pelo escritório;
- **usuário:** profissional que trabalha em um ou mais escritórios;
- **plano/assinatura:** contrato comercial e limites do tenant.

Estrutura conceitual esperada:

```text
Tenant / Escritório contábil
├── Usuários e permissões
├── Plano e assinatura
├── Configurações
├── Empresas atendidas
│   ├── Importações
│   ├── Lançamentos
│   ├── Terceiros
│   ├── Regras
│   └── Exportações
├── Arquivos
├── Auditoria
└── Consumo
```

Sem essa separação, o sistema não possui uma fronteira estrutural segura entre os dados de diferentes escritórios.

#### Empresa selecionada em sessão

A empresa ativa é armazenada em sessão. Isso é útil como preferência de interface, mas não pode ser utilizado como mecanismo de autorização.

Cada acesso deve validar no backend:

```text
usuário autenticado
+ associação com o tenant
+ empresa pertencente ao tenant
+ permissão para a operação
```

Alterar um identificador na URL, no payload Livewire, em uma chamada de API ou na sessão não pode permitir acesso a outro escritório.

#### Processamentos pesados síncronos

Importações, conversões de PDF e conversões de Excel ainda executam processos externos durante o fluxo da requisição.

Em escala, isso pode provocar:

- timeouts;
- consumo excessivo de memória;
- indisponibilidade causada por arquivos pesados;
- concorrência descontrolada de processos Python;
- dificuldade de repetir operações;
- perda de processamento após reinício;
- experiência ruim para o usuário;
- falta de prioridade e limites por plano.

#### Armazenamento local

Arquivos temporários e exportações são gravados no filesystem local. Em uma aplicação com múltiplas instâncias, um arquivo criado no servidor A pode não existir no servidor B.

O armazenamento local também dificulta:

- escalabilidade horizontal;
- retenção controlada;
- backup;
- criptografia centralizada;
- expiração;
- rastreamento de consumo;
- download por URL temporária.

#### Infraestrutura orientada a desenvolvimento

O ambiente Docker atual inicia a aplicação com o servidor embutido do Artisan, instala dependências no início e executa a aplicação como usuário privilegiado. O banco é executado no mesmo ambiente com credenciais fixas.

Essa abordagem deve ser substituída em produção por:

- imagem imutável;
- Nginx e PHP-FPM;
- usuário sem privilégios;
- banco gerenciado;
- Redis gerenciado;
- secret manager;
- object storage;
- health checks;
- múltiplas réplicas;
- deploy sem downtime.

#### Cobertura limitada de testes

Os testes existentes estão concentrados principalmente no fluxo padrão de autenticação e perfil.

Faltam testes para:

- isolamento entre tenants;
- importadores;
- conversores;
- regras de amarração;
- criação e edição de lançamentos;
- exportações;
- permissões;
- arquivos inválidos;
- idempotência;
- concorrência;
- filas;
- recuperação após falhas.

#### APIs precisam de revisão

As rotas de exportação e consulta devem passar por revisão de autenticação, autorização, rate limiting, isolamento de tenant e versionamento.

Todas as APIs comerciais devem aplicar explicitamente:

- autenticação;
- autorização;
- tenant derivado da credencial;
- limites;
- paginação;
- idempotência;
- auditoria.

---

## 5. Princípios de arquitetura

As próximas implementações devem seguir os princípios abaixo.

### 5.1 Isolamento antes de escala

Não aumentar significativamente a base de clientes antes de provar, por testes automatizados, que um tenant não acessa dados de outro.

### 5.2 Operações pesadas fora da requisição

Importações, conversões, aplicação massiva de regras e exportações devem ser processadas por jobs.

### 5.3 Aplicação web stateless

Qualquer instância da aplicação deve conseguir atender qualquer requisição. Sessões, cache, filas e arquivos não devem depender do disco local de uma instância.

### 5.4 Idempotência

Repetir uma requisição ou job não pode duplicar lançamentos, cobranças ou exportações.

### 5.5 Segurança por padrão

Toda operação deve ser negada quando a permissão não for explicitamente comprovada.

### 5.6 Observabilidade por tenant

Erros, latência, consumo e custos devem poder ser analisados por escritório.

### 5.7 Evolução incremental

Manter o Laravel como monólito modular até que métricas indiquem a necessidade real de extrair serviços.

### 5.8 Rastreabilidade contábil

Cada lançamento e exportação deve permitir identificar:

- arquivo de origem;
- linha de origem;
- versão do conversor;
- versão das regras;
- usuário;
- data;
- alterações;
- motivo da classificação;
- arquivo final em que foi incluído.

---

## 6. Arquitetura-alvo inicial

```text
Internet
   │
CDN / WAF
   │
Load Balancer
   │
Aplicação Laravel stateless
   ├── Redis: sessão, cache, locks, rate limit e filas
   ├── Banco de dados gerenciado
   ├── Object storage para uploads e exportações
   └── Eventos e notificações

Workers
   ├── importações
   ├── conversões
   ├── exportações
   ├── notificações
   └── manutenção

Serviço/worker isolado de conversão
   └── processos Python com CPU, memória e timeout controlados

Observabilidade
   ├── logs centralizados
   ├── métricas
   ├── tracing
   ├── erros
   └── alertas
```

Essa arquitetura pode ser implantada em diferentes provedores. A escolha do provedor deve considerar:

- região dos dados;
- serviços gerenciados disponíveis;
- custo de saída de dados;
- facilidade de backup e recuperação;
- suporte;
- conformidade;
- experiência da equipe.

---

## 7. Roadmap por fases

## Fase 0 — Validação comercial e definição do produto

**Prazo sugerido:** 2 a 4 semanas.

### Objetivos

- definir o cliente ideal;
- validar o fluxo com escritórios reais;
- estimar volume e sazonalidade;
- escolher o modelo de cobrança;
- identificar integrações prioritárias;
- definir suporte e onboarding.

### Ações

- [ ] Entrevistar pelo menos 10 escritórios.
- [ ] Selecionar um segmento inicial.
- [ ] Documentar o fluxo atual de trabalho.
- [ ] Medir tempo gasto antes e depois do Integrar.
- [ ] Levantar bancos e layouts mais utilizados.
- [ ] Identificar o sistema contábil prioritário.
- [ ] Definir limites preliminares por plano.
- [ ] Definir trial e política de cancelamento.
- [ ] Levantar requisitos de suporte.
- [ ] Criar métricas de ativação e retenção.

### Perguntas para validação

- Quantas empresas cada escritório atende?
- Quantos usuários utilizarão a plataforma?
- Quantos arquivos são importados por mês?
- Qual o tamanho médio e máximo dos arquivos?
- Em quais dias há pico de processamento?
- Qual percentual dos lançamentos pode ser amarrado automaticamente?
- Quanto o escritório economiza por mês?
- Qual valor estaria disposto a pagar?
- Quais erros seriam considerados inaceitáveis?
- Quais integrações são obrigatórias?

### Critério de saída

- 5 a 10 escritórios pilotos;
- persona definida;
- proposta de valor validada;
- planos preliminares;
- volume estimado;
- fluxo principal aprovado por usuários.

---

## Fase 1 — Fundação multi-tenant

**Prazo sugerido:** 4 a 8 semanas.

### Objetivo

Criar isolamento seguro entre escritórios.

### Modelo inicial sugerido

```text
tenants
- id
- uuid
- nome
- slug
- documento
- email_financeiro
- timezone
- status
- trial_ends_at
- suspended_at
- created_at
- updated_at

tenant_user
- tenant_id
- user_id
- role
- status
- invited_at
- joined_at
- created_at
- updated_at
```

### Papéis sugeridos

- `owner`: proprietário da assinatura;
- `admin`: administra usuários e configurações;
- `manager`: gerencia processos;
- `operator`: importa e revisa;
- `viewer`: somente consulta;
- `billing`: cobrança e faturas.

O administrador interno da plataforma deve ser separado dos administradores dos clientes.

### Entidades que devem receber `tenant_id`

- [ ] empresas;
- [ ] terceiros;
- [ ] importações;
- [ ] lançamentos;
- [ ] layouts;
- [ ] regras;
- [ ] históricos padrão;
- [ ] conversões;
- [ ] exportações;
- [ ] arquivos;
- [ ] logs de auditoria;
- [ ] registros de consumo.

### Implementações

- [ ] Criar `TenantContext`.
- [ ] Criar middleware de resolução do tenant.
- [ ] Criar associação de usuários com tenants.
- [ ] Adicionar índices iniciados por `tenant_id`.
- [ ] Criar policies por recurso.
- [ ] Aplicar escopo de tenant em todas as consultas.
- [ ] Validar tenant em ações Livewire.
- [ ] Validar tenant em downloads.
- [ ] Validar tenant em jobs.
- [ ] Validar tenant nas APIs.
- [ ] Remover fallbacks fixos como empresa ou usuário de ID `1`.
- [ ] Criar comando de auditoria para localizar registros sem tenant.
- [ ] Criar migração segura dos dados existentes.

### Estratégias de isolamento para avaliação

#### Banco compartilhado com `tenant_id`

**Vantagens:**

- menor custo;
- operação simples;
- onboarding rápido;
- adequado ao início.

**Riscos:**

- exige disciplina rigorosa de consultas;
- uma falha de autorização pode expor dados;
- restauração individual é mais complexa.

#### Schema por tenant

**Vantagens:**

- isolamento lógico maior;
- restauração mais segmentada.

**Riscos:**

- migrações e conexões mais complexas;
- operação difícil com milhares de schemas.

#### Banco por tenant

**Vantagens:**

- isolamento máximo;
- útil para clientes Enterprise.

**Riscos:**

- custo elevado;
- provisionamento e migrações complexas;
- maior carga operacional.

### Recomendação inicial

Usar banco compartilhado com `tenant_id`, índices apropriados, policies, testes de isolamento e possibilidade futura de oferecer banco dedicado para clientes Enterprise.

### Testes obrigatórios

- [ ] Tenant A não lista empresas do Tenant B.
- [ ] Tenant A não abre importação do Tenant B.
- [ ] Tenant A não altera lançamento do Tenant B.
- [ ] Tenant A não baixa arquivo do Tenant B.
- [ ] Tenant A não utiliza regra do Tenant B.
- [ ] Tenant A não seleciona empresa do Tenant B.
- [ ] Admin de um tenant não administra outro tenant.
- [ ] Job mantém o tenant correto após ser serializado.
- [ ] Exportação contém somente dados do tenant.
- [ ] Busca por ID inexistente ou externo retorna negação uniforme.

### Critério de saída

Todos os recursos críticos protegidos por tenant e suíte de isolamento executada no CI.

---

## Fase 2 — Segurança, privacidade e LGPD

**Prazo sugerido:** 4 a 6 semanas, parcialmente paralela à Fase 1.

### Autenticação

- [ ] Confirmação de e-mail.
- [ ] MFA/TOTP.
- [ ] MFA obrigatório para administradores.
- [ ] Rate limit no login.
- [ ] Revogação de sessões.
- [ ] Detecção de login suspeito.
- [ ] Cookies seguros.
- [ ] Expiração de sessão.
- [ ] Política de senha.
- [ ] Fluxo seguro de recuperação.
- [ ] Registro de dispositivos e sessões ativas.

### Autorização

- [ ] Policies para todos os recursos.
- [ ] Permissões por tenant.
- [ ] Negação por padrão.
- [ ] Autorização em ações Livewire.
- [ ] Autorização em controllers.
- [ ] Autorização em downloads.
- [ ] Autorização em comandos administrativos.
- [ ] Testes de IDOR/BOLA.

### Uploads

- [ ] Validar extensão.
- [ ] Validar MIME real.
- [ ] Usar nomes aleatórios.
- [ ] Armazenar fora da área pública.
- [ ] Limitar tamanho.
- [ ] Limitar quantidade de páginas e linhas.
- [ ] Verificar malware.
- [ ] Proteger contra ZIP bomb.
- [ ] Proteger contra CSV injection.
- [ ] Remover metadados desnecessários.
- [ ] Expirar arquivos temporários.
- [ ] Registrar hash do arquivo.

### Processos externos

- [ ] Eliminar concatenação insegura de comandos.
- [ ] Definir lista permitida de conversores.
- [ ] Executar como usuário sem privilégios.
- [ ] Aplicar timeout.
- [ ] Aplicar limite de memória.
- [ ] Aplicar limite de CPU.
- [ ] Restringir rede.
- [ ] Restringir filesystem.
- [ ] Não disponibilizar credenciais do banco.
- [ ] Registrar versão do conversor.

### Auditoria

Estrutura sugerida:

```text
audit_logs
- id
- tenant_id
- actor_user_id
- action
- auditable_type
- auditable_id
- ip_address
- user_agent
- request_id
- old_values
- new_values
- occurred_at
```

Eventos mínimos:

- [ ] login e logout;
- [ ] falhas de autenticação;
- [ ] convites;
- [ ] mudanças de papel;
- [ ] importações;
- [ ] conversões;
- [ ] alterações de regras;
- [ ] alterações e exclusões de lançamentos;
- [ ] exportações;
- [ ] downloads;
- [ ] mudanças de plano;
- [ ] acesso administrativo de suporte.

### LGPD

- [ ] Inventário de dados.
- [ ] Finalidade por dado.
- [ ] Base legal.
- [ ] Definição de controlador e operador.
- [ ] Política de privacidade.
- [ ] Termos de uso.
- [ ] Contrato de tratamento de dados.
- [ ] Política de retenção.
- [ ] Procedimento de exclusão.
- [ ] Procedimento de portabilidade.
- [ ] Plano de resposta a incidentes.
- [ ] Gestão de suboperadores.
- [ ] Processo para solicitações de titulares.
- [ ] Registro de consentimentos quando necessário.
- [ ] Canal de privacidade.

### Backup e recuperação

- [ ] Backup automático.
- [ ] Point-in-time recovery.
- [ ] Criptografia.
- [ ] Cópia em conta ou região separada.
- [ ] Teste mensal de restauração.
- [ ] Runbook de desastre.
- [ ] Definição de RPO.
- [ ] Definição de RTO.

Meta inicial sugerida:

```text
RPO: até 15 minutos
RTO: até 4 horas
```

### Critério de saída

Auditoria de segurança concluída, restauração comprovada, documentação LGPD mínima publicada e riscos críticos corrigidos.

---

## Fase 3 — Filas e processamento assíncrono

**Prazo sugerido:** 4 a 8 semanas.

### Jobs sugeridos

```text
ScanUploadedFile
ProcessImportFile
ConvertPdfToOfx
ConvertExcelToCsv
ParseBankStatement
ApplyAccountingRules
ReprocessAccountingRules
GenerateAccountingExport
GenerateBankStatement
DeleteExpiredFiles
DeleteTenantData
CalculateTenantUsage
```

### Fluxo esperado

```text
Upload
  ↓
Arquivo salvo no object storage
  ↓
Registro criado como "aguardando"
  ↓
Job enviado à fila
  ↓
Worker processa
  ↓
Progresso e checkpoints persistidos
  ↓
Resultado disponibilizado
  ↓
Usuário notificado
```

### Filas recomendadas

```text
critical
imports
conversions
exports
notifications
maintenance
```

### Implementações

- [ ] Adotar Redis para filas.
- [ ] Adotar Laravel Horizon.
- [ ] Separar filas por tipo de carga.
- [ ] Definir timeout por job.
- [ ] Definir tentativas e backoff.
- [ ] Criar dead-letter/fila de falhas.
- [ ] Criar painel de reprocessamento.
- [ ] Persistir progresso.
- [ ] Notificar conclusão.
- [ ] Implementar cancelamento quando possível.
- [ ] Criar locks distribuídos.
- [ ] Impedir processamento concorrente do mesmo arquivo.

### Idempotência

Adicionar ou considerar:

```text
file_hash
idempotency_key
processing_version
attempt_count
last_checkpoint
started_at
finished_at
```

Regras:

- [ ] O mesmo arquivo não deve gerar duplicidade acidental.
- [ ] Repetir um job não deve duplicar lançamentos.
- [ ] Webhooks devem tolerar reentrega.
- [ ] Exportações repetidas devem ser identificáveis.
- [ ] Inserções em lote devem usar chaves únicas.

### Processamento em lote

- [ ] Leitura por stream.
- [ ] Lotes de tamanho configurável.
- [ ] Transações por lote.
- [ ] Checkpoints.
- [ ] `upsert` onde aplicável.
- [ ] Paginação por cursor.
- [ ] Evitar carregar coleções inteiras.
- [ ] Benchmark por formato.

### Controle de noisy neighbor

- [ ] Limite de arquivos simultâneos por tenant.
- [ ] Limite de jobs por minuto.
- [ ] Limite de tamanho.
- [ ] Limite de lançamentos por ciclo.
- [ ] Prioridade por plano.
- [ ] Quota de armazenamento.
- [ ] Circuit breaker por conversor.
- [ ] Suspensão automática de cargas abusivas.

### Critério de saída

Nenhuma importação, conversão ou exportação pesada depende da duração da requisição web.

---

## Fase 4 — Infraestrutura de produção

**Prazo sugerido:** 4 a 6 semanas.

### Aplicação

- [ ] Nginx.
- [ ] PHP-FPM.
- [ ] OPcache.
- [ ] Imagem Docker imutável.
- [ ] Usuário sem privilégios.
- [ ] Health checks.
- [ ] Múltiplas réplicas.
- [ ] Autoscaling.
- [ ] Deploy sem downtime.
- [ ] Rollback automatizado.

### Banco

- [ ] Migrar para versão suportada.
- [ ] Usar banco gerenciado.
- [ ] Habilitar alta disponibilidade.
- [ ] Habilitar backups.
- [ ] Habilitar point-in-time recovery.
- [ ] Monitorar queries lentas.
- [ ] Revisar índices.
- [ ] Configurar pool de conexões.
- [ ] Planejar read replicas.
- [ ] Testar migrações em grande volume.

### Redis

- [ ] Serviço gerenciado.
- [ ] Alta disponibilidade.
- [ ] Separação lógica de cache, sessão e filas quando necessário.
- [ ] Política de memória.
- [ ] Monitoramento.

### Object storage

Estrutura sugerida:

```text
tenants/{tenant_uuid}/uploads/
tenants/{tenant_uuid}/processed/
tenants/{tenant_uuid}/exports/
tenants/{tenant_uuid}/temp/
```

- [ ] URLs temporárias assinadas.
- [ ] Criptografia.
- [ ] Lifecycle.
- [ ] Versionamento quando necessário.
- [ ] Retenção por plano.
- [ ] Bloqueio de acesso público.
- [ ] Registro de tamanho e hash.

### Segredos e rede

- [ ] Secret manager.
- [ ] Rotação de credenciais.
- [ ] Banco em rede privada.
- [ ] Redis em rede privada.
- [ ] TLS.
- [ ] WAF.
- [ ] Proteção DDoS.
- [ ] Acesso administrativo com MFA.
- [ ] Remover ferramentas administrativas públicas.

### Ambientes

```text
local
development
staging
production
```

- [ ] Dados anonimizados fora de produção.
- [ ] Configurações independentes.
- [ ] Credenciais independentes.
- [ ] Teste de migração em staging.
- [ ] Smoke tests pós-deploy.

### Critério de saída

Aplicação stateless, com múltiplas réplicas, banco e Redis gerenciados, arquivos em object storage e deploy automatizado.

---

## Fase 5 — Qualidade e entrega contínua

**Prazo inicial sugerido:** 4 a 8 semanas, seguido de evolução contínua.

### Testes unitários

- [ ] Normalização monetária.
- [ ] Datas.
- [ ] Regras de amarração.
- [ ] Débito e crédito.
- [ ] Normalização de descrições.
- [ ] Layouts.
- [ ] Validadores.
- [ ] Cálculos de saldo.

### Testes de integração

- [ ] Arquivos anonimizados por instituição.
- [ ] Conversão PDF → OFX.
- [ ] Excel → CSV.
- [ ] CSV/OFX → lançamentos.
- [ ] Aplicação de regras.
- [ ] Exportação para o sistema contábil.
- [ ] Falhas parciais.
- [ ] Reprocessamento.
- [ ] Idempotência.

### Testes end-to-end

Fluxo principal:

```text
Criar escritório
→ convidar usuário
→ cadastrar empresa
→ importar arquivo
→ aplicar regras
→ revisar lançamentos
→ exportar
→ baixar resultado
```

### Testes de carga

Cenários mínimos:

- [ ] 100 uploads simultâneos.
- [ ] 1 milhão de lançamentos.
- [ ] 1.000 tenants cadastrados.
- [ ] 100 tenants processando simultaneamente.
- [ ] pico de fechamento mensal.
- [ ] exportações simultâneas.
- [ ] consultas e filtros em tabelas grandes.
- [ ] reinício de workers durante processamento.

### Pipeline recomendado

```text
composer validate
composer audit
npm audit
Laravel Pint
PHPStan/Larastan
PHPUnit
testes de isolamento
testes dos conversores
build frontend
scan de secrets
scan de dependências
scan da imagem Docker
```

### Critério de saída

Fluxos críticos cobertos, CI obrigatório em pull requests e teste de carga com capacidade documentada.

---

## Fase 6 — Produto SaaS, onboarding e cobrança

**Prazo sugerido:** 6 a 10 semanas.

### Onboarding

- [ ] Cadastro self-service.
- [ ] Verificação de e-mail.
- [ ] Criação do escritório.
- [ ] Seleção de plano.
- [ ] Cadastro do meio de pagamento.
- [ ] Convite de usuários.
- [ ] Cadastro ou importação de empresas.
- [ ] Tutorial do primeiro arquivo.
- [ ] Checklist de ativação.
- [ ] Conteúdo de ajuda contextual.

### Cobrança

Estruturas sugeridas:

```text
plans
subscriptions
subscription_items
usage_records
invoices
payments
coupons
```

Funcionalidades:

- [ ] trial;
- [ ] upgrade;
- [ ] downgrade;
- [ ] cancelamento;
- [ ] inadimplência;
- [ ] período de tolerância;
- [ ] bloqueio progressivo;
- [ ] webhooks;
- [ ] reconciliação;
- [ ] emissão fiscal;
- [ ] histórico de faturas.

### Limites por plano

- [ ] empresas;
- [ ] usuários;
- [ ] arquivos;
- [ ] lançamentos;
- [ ] conversões;
- [ ] armazenamento;
- [ ] retenção;
- [ ] prioridade;
- [ ] API;
- [ ] suporte.

As verificações devem ser centralizadas em um serviço de entitlements, evitando condicionais espalhadas pelo código.

### Painel do cliente

- [ ] consumo do ciclo;
- [ ] limites;
- [ ] empresas;
- [ ] usuários;
- [ ] arquivos;
- [ ] lançamentos;
- [ ] armazenamento;
- [ ] próxima cobrança;
- [ ] faturas;
- [ ] status do plano.

### Planos iniciais para avaliação

| Plano | Empresas | Usuários | Lançamentos/mês | Perfil |
|---|---:|---:|---:|---|
| Inicial | 20 | 3 | 20.000 | Escritórios pequenos |
| Profissional | 100 | 10 | 150.000 | Escritórios médios |
| Enterprise | Customizado | Customizado | Customizado | Grandes operações |

Os limites devem ser validados com dados reais de uso e custo.

### Critério de saída

Um novo escritório consegue contratar, configurar e concluir o primeiro processamento sem intervenção manual da equipe.

---

## Fase 7 — Observabilidade e operação

**Prazo sugerido:** 3 a 6 semanas, seguido de evolução contínua.

### Métricas técnicas

- [ ] disponibilidade;
- [ ] latência p50/p95/p99;
- [ ] taxa de erro;
- [ ] queries lentas;
- [ ] conexões do banco;
- [ ] CPU;
- [ ] memória;
- [ ] filas;
- [ ] jobs com falha;
- [ ] tempo por conversor;
- [ ] armazenamento;
- [ ] custo.

### Contexto obrigatório

Logs e métricas devem carregar, quando aplicável:

```text
tenant_id
user_id
request_id
job_id
importacao_id
arquivo_id
converter_version
```

### SLO inicial sugerido

```text
Disponibilidade mensal: 99,9%
p95 das páginas normais: abaixo de 800 ms
95% dos jobs iniciados em até 60 segundos
Importações sem erro de infraestrutura: acima de 99%
Teste de restauração: mensal
```

### Operação

- [ ] Dashboard técnico.
- [ ] Dashboard por tenant.
- [ ] Alertas.
- [ ] Plantão.
- [ ] Runbooks.
- [ ] Status page.
- [ ] Gestão de incidentes.
- [ ] Pós-mortem sem culpabilização.
- [ ] Comunicação com clientes.
- [ ] Relatório de disponibilidade.

### Critério de saída

A equipe consegue detectar, diagnosticar e responder a falhas antes de depender exclusivamente de chamados dos clientes.

---

## Fase 8 — API, integrações e escala avançada

**Executar após validação comercial e operacional.**

### API pública

```text
/api/v1/companies
/api/v1/imports
/api/v1/imports/{id}
/api/v1/entries
/api/v1/exports
/api/v1/webhooks
```

Requisitos:

- [ ] autenticação por token ou OAuth;
- [ ] escopos;
- [ ] tenant derivado da credencial;
- [ ] rate limiting;
- [ ] idempotency key;
- [ ] paginação;
- [ ] versionamento;
- [ ] OpenAPI;
- [ ] webhooks assinados;
- [ ] logs e métricas;
- [ ] sandbox.

### Marketplace de layouts

- [ ] layouts oficiais;
- [ ] layouts privados;
- [ ] versões;
- [ ] homologação;
- [ ] rollback;
- [ ] métricas de erro;
- [ ] autoria;
- [ ] compatibilidade.

### Motor de regras versionado

```text
rule_sets
rule_set_versions
rules
rule_executions
```

Registrar:

- [ ] versão aplicada;
- [ ] entrada normalizada;
- [ ] regra vencedora;
- [ ] resultado;
- [ ] confiança;
- [ ] motivo;
- [ ] usuário ou job;
- [ ] data.

### Inteligência assistida

Possibilidades futuras:

- sugerir regras;
- identificar descrições semelhantes;
- recomendar conta;
- detectar anomalias;
- apontar duplicidades;
- explicar classificações.

Requisitos prévios:

- isolamento de tenants;
- consentimento e base legal;
- anonimização quando aplicável;
- avaliação de segurança;
- explicabilidade;
- revisão humana;
- proteção contra uso indevido de dados.

### Microsserviços

Primeiros candidatos, somente quando houver necessidade comprovada:

1. conversão de documentos;
2. importação;
3. exportação;
4. notificações;
5. medição de consumo.

---

## 8. Modelo de dados de referência

```text
Tenant
├── TenantUsers
├── Subscription
├── UsageRecords
├── Companies
│   ├── ThirdParties
│   ├── ImportLayouts
│   ├── RuleSets
│   ├── Imports
│   │   ├── Files
│   │   ├── ProcessingRuns
│   │   └── Entries
│   └── Exports
├── AuditLogs
├── ApiCredentials
└── Webhooks
```

### Índices sugeridos

```text
(tenant_id, id)
(tenant_id, empresa_id)
(tenant_id, created_at)
(tenant_id, status, created_at)
(tenant_id, importacao_id)
(tenant_id, empresa_id, data)
(tenant_id, empresa_id, conferido)
(tenant_id, file_hash)
```

Os índices definitivos devem ser definidos a partir de queries reais e testes de carga.

---

## 9. Roadmap dos primeiros 12 meses

### Dias 1 a 30

- produto, persona e planos;
- tenants e associação de usuários;
- `tenant_id`;
- `TenantContext`;
- policies;
- revisão das APIs;
- testes de isolamento.

### Dias 31 a 60

- object storage;
- jobs;
- Redis;
- Horizon;
- idempotência;
- limites por tenant;
- auditoria;
- segurança de uploads.

### Dias 61 a 90

- staging;
- produção;
- banco gerenciado;
- workers;
- observabilidade;
- backup e restauração;
- CI/CD;
- teste de carga;
- piloto controlado.

### Meses 4 a 6

- onboarding;
- cobrança;
- medição;
- painel;
- feature flags;
- MFA;
- suporte;
- documentação;
- expansão para 20 a 50 escritórios.

### Meses 7 a 12

- API;
- webhooks;
- integrações;
- layouts versionados;
- regras versionadas;
- analytics;
- SSO Enterprise;
- otimização de custos;
- expansão gradual.

---

## 10. Estratégia de lançamento

| Etapa | Escritórios | Objetivo |
|---|---:|---|
| Alpha | 3–5 | Corrigir o fluxo principal |
| Piloto | 10–20 | Validar onboarding e suporte |
| Beta paga | 20–50 | Validar cobrança e operação |
| Lançamento | 50–200 | Validar aquisição e escala |
| Expansão | 200–1.000 | Automatizar suporte e operação |
| Escala | 1.000+ | Otimizar custos e segmentar infraestrutura |

Não avançar apenas com base em interesse comercial. Cada etapa deve possuir critérios técnicos e operacionais.

### Gate para aumentar a base

- [ ] isolamento aprovado;
- [ ] backup restaurado em teste;
- [ ] capacidade documentada;
- [ ] taxa de erro aceitável;
- [ ] suporte dimensionado;
- [ ] cobrança confiável;
- [ ] observabilidade;
- [ ] custo por tenant conhecido;
- [ ] incidentes críticos resolvidos;
- [ ] satisfação dos pilotos.

---

## 11. Priorização

### P0 — antes de vender em escala

1. tenant explícito;
2. isolamento;
3. proteção das APIs;
4. policies;
5. testes de autorização;
6. backup restaurável;
7. jobs;
8. object storage;
9. produção segura;
10. auditoria;
11. segurança de upload;
12. requisitos mínimos de LGPD.

### P1 — antes do lançamento público

1. cobrança;
2. onboarding;
3. limites;
4. observabilidade;
5. suporte;
6. MFA;
7. consumo;
8. testes de carga;
9. CI/CD;
10. documentação.

### P2 — após product-market fit

1. API;
2. webhooks;
3. SSO;
4. marketplace;
5. inteligência assistida;
6. serviços especializados;
7. banco dedicado Enterprise.

---

## 12. Riscos principais

| Risco | Impacto | Probabilidade atual | Mitigação |
|---|---|---|---|
| Vazamento entre escritórios | Crítico | Alta sem tenant formal | Tenant, policies e testes |
| Lançamentos duplicados | Alto | Média | Idempotência e chaves únicas |
| Conversor travar servidor | Alto | Alta em escala | Jobs e isolamento |
| Perda de arquivos | Alto | Média | Object storage e backup |
| Exportação contábil incorreta | Crítico | Média | Testes golden files e versionamento |
| Falta de capacidade no fechamento | Alto | Alta | Teste de carga e autoscaling |
| Custo imprevisível por cliente | Alto | Média | Medição e quotas |
| Incidente LGPD | Crítico | Média | Programa de privacidade e segurança |
| Dependência de pessoa-chave | Alto | Alta | Documentação e runbooks |
| Suporte inviável | Alto | Média | Onboarding, telemetria e base de conhecimento |
| Migração insegura | Alto | Média | Backfill, validação e rollback |
| Credenciais expostas | Crítico | Média | Secret manager e rotação |

---

## 13. Indicadores recomendados

### Produto

- tempo até o primeiro valor;
- percentual de ativação;
- usuários ativos;
- empresas ativas;
- arquivos por tenant;
- lançamentos por tenant;
- percentual de amarração automática;
- taxa de correção manual;
- exportações concluídas;
- retenção.

### Operação

- taxa de sucesso por layout;
- tempo médio de importação;
- tempo de fila;
- falhas por conversor;
- reprocessamentos;
- chamados por tenant;
- tempo de resposta;
- tempo de resolução.

### Financeiro

- MRR;
- ARR;
- churn;
- CAC;
- LTV;
- margem bruta;
- custo de infraestrutura por tenant;
- custo por mil lançamentos;
- inadimplência.

### Segurança

- tentativas de acesso negadas;
- falhas de MFA;
- vulnerabilidades abertas;
- tempo de correção;
- acessos administrativos;
- incidentes;
- sucesso dos testes de restauração.

---

## 14. Equipe mínima sugerida

Para uma execução paralela e profissional:

- 1 líder técnico/arquiteto;
- 2 desenvolvedores backend/Laravel;
- 1 desenvolvedor frontend/Livewire;
- 1 profissional de QA/automação;
- DevOps/SRE compartilhado;
- especialista contábil de produto;
- product manager;
- suporte/customer success;
- jurídico e segurança sob demanda.

Uma equipe menor pode executar o roadmap, mas deverá reduzir o escopo simultâneo e ampliar o prazo.

---

## 15. Avaliações externas recomendadas

### Arquitetura

Solicitar avaliação de:

- especialista Laravel;
- arquiteto SaaS;
- especialista de banco;
- especialista em filas e processamento de arquivos;
- fornecedor de nuvem.

### Segurança

- threat modeling;
- revisão de código;
- análise de dependências;
- pentest;
- teste de isolamento multi-tenant;
- teste de upload;
- revisão de infraestrutura;
- revisão de resposta a incidentes.

### Contabilidade

- homologação dos layouts;
- validação de arredondamento;
- validação de débito/crédito;
- golden files;
- rastreabilidade;
- retenção;
- responsabilidade sobre correções automáticas.

### Jurídico e LGPD

- termos;
- privacidade;
- contratos;
- papel de controlador e operador;
- suboperadores;
- retenção;
- transferência internacional;
- resposta a titulares;
- incidentes.

### Financeiro

- preço;
- margem;
- custo de nuvem;
- suporte;
- impostos;
- emissão fiscal;
- meios de pagamento;
- inadimplência.

---

## 16. Perguntas para consultores e outras fontes

### Multi-tenancy

1. Banco compartilhado com `tenant_id` é adequado ao volume previsto?
2. Quais entidades devem duplicar `tenant_id` para performance?
3. Como testar automaticamente vazamento entre tenants?
4. Quando oferecer banco dedicado?
5. Como executar restauração de um tenant?

### Processamento

1. Qual o volume máximo por worker?
2. Os conversores devem ficar em containers separados?
3. Como garantir idempotência por arquivo?
4. Como limitar CPU e memória?
5. Como versionar conversores e regras?

### Banco

1. MySQL 8 ou PostgreSQL?
2. Quais índices são necessários?
3. Quando usar read replicas?
4. É necessário particionamento?
5. Como executar migrações sem downtime?

### Segurança

1. Quais ameaças específicas existem em arquivos bancários?
2. Como verificar PDFs e planilhas?
3. Quais dados precisam de criptografia adicional?
4. Qual política de retenção é adequada?
5. Como auditar acessos de suporte?

### Produto

1. Cobrar por empresa, usuário, lançamento ou consumo?
2. Qual limite gera margem sem prejudicar adoção?
3. Qual onboarding reduz tickets?
4. Qual integração possui maior valor?
5. Quais recursos devem ser Enterprise?

### Operação

1. Qual SLO é realista?
2. Qual RPO e RTO os clientes exigem?
3. Como dimensionar o suporte?
4. Quais alertas são essenciais?
5. Qual estratégia de disaster recovery?

---

## 17. Decisões a registrar

Utilizar registros de decisão arquitetural, por exemplo:

```text
docs/adr/
├── 0001-estrategia-multitenancy.md
├── 0002-banco-de-dados.md
├── 0003-object-storage.md
├── 0004-filas-e-workers.md
├── 0005-provedor-de-nuvem.md
├── 0006-gateway-de-pagamento.md
└── 0007-retencao-de-dados.md
```

Cada decisão deve registrar:

- contexto;
- alternativas;
- decisão;
- consequências;
- riscos;
- data;
- responsáveis;
- condição para revisão.

---

## 18. Próximos passos imediatos

### Semana 1

- [ ] Apresentar este documento aos responsáveis.
- [ ] Definir sponsor e líder técnico.
- [ ] Selecionar escritórios pilotos.
- [ ] Criar backlog P0.
- [ ] Abrir auditoria das rotas de API.
- [ ] Mapear todas as consultas sem `empresa_id`.
- [ ] Definir o conceito de tenant.

### Semana 2

- [ ] Criar ADR de multi-tenancy.
- [ ] Projetar tabelas `tenants` e `tenant_user`.
- [ ] Projetar migração dos dados existentes.
- [ ] Criar matriz de permissões.
- [ ] Escrever primeiros testes de isolamento.
- [ ] Medir arquivos e tempos reais.

### Semanas 3 e 4

- [ ] Implementar TenantContext.
- [ ] Proteger seleção de empresa.
- [ ] Aplicar policies nos recursos críticos.
- [ ] Proteger downloads.
- [ ] Proteger APIs.
- [ ] Prototipar um job de importação.
- [ ] Avaliar object storage e Redis.

### Entregável do primeiro mês

Uma prova técnica contendo:

- dois tenants;
- usuários independentes;
- empresas isoladas;
- testes de acesso cruzado;
- upload em object storage;
- uma importação processada por job;
- logs contendo `tenant_id` e `request_id`.

---

## 19. Conclusão

O Integrar não precisa ser reescrito para iniciar sua evolução SaaS. O domínio existente pode ser preservado e reorganizado gradualmente.

A sequência recomendada é:

```text
Sistema atual
  ↓
Fundação multi-tenant
  ↓
Segurança e testes de isolamento
  ↓
Jobs, Redis e object storage
  ↓
Infraestrutura stateless
  ↓
Observabilidade e recuperação
  ↓
Cobrança e onboarding
  ↓
API, integrações e escala avançada
```

A decisão mais importante é não confundir crescimento de infraestrutura com maturidade SaaS. Antes de buscar milhares de clientes, a plataforma precisa provar:

- isolamento;
- correção contábil;
- resiliência;
- segurança;
- recuperação;
- capacidade;
- suporte;
- viabilidade econômica.

O primeiro grande marco deve ser uma versão multi-tenant segura para um grupo pequeno de escritórios pilotos. A escala comercial deve ocorrer por etapas, acompanhada por métricas, testes e critérios objetivos de liberação.
