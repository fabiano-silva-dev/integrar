# IntegraExpert — Análise técnica e plano de melhorias

## Contexto da revisão

Esta análise considera a `master` atual do projeto IntegraExpert no repositório:

```text
fabiano-silva-dev/integrar
```

Commit analisado:

```text
255cdf743569b98c8066af32998328d0cb076738
```

A revisão foi feita após a implementação do novo fluxo seguro de documentos do Google Drive via OAuth.

**Status (ago/2026):** os itens P0, P1 e a maior parte do P2 foram implementados, com testes Tenant/Unit. O fallback de storage legado permanece ligado por padrão (`ALLOW_LEGACY_GLOBAL_STORAGE=true`) até `storage:auditar-legados` / `storage:migrar-legados` em cada ambiente.

Objetivo deste documento:

- registrar os problemas técnicos ainda existentes;
- separar os itens por prioridade;
- orientar as correções sem alterar regras funcionais desnecessariamente;
- preservar o isolamento multi-tenant;
- evitar regressões nos módulos Documentos e Automação Fiscal;
- deixar critérios claros de aceite para o Cursor implementar e testar.

---

# Resumo executivo

A implementação recente do Google Drive ficou bem encaminhada:

- arquivos novos permanecem privados;
- Visualizar e Baixar passam pelo IntegraExpert;
- OAuth é utilizado por escritório;
- token pode ser renovado automaticamente;
- existem testes de IDOR para documentos entre escritórios;
- download e visualização usam streaming.

Porém ainda existem dois problemas críticos de multi-tenancy que devem ser corrigidos antes de considerar o isolamento do sistema confiável:

1. troca de escritório disponível para usuário comum;
2. certificado e agenda aceitos por ID sem validação explícita do tenant.

Além deles, existem riscos importantes no webhook da Evolution, remoção de permissões antigas do Google Drive, agendamentos fiscais, reversão de ajustes em massa e arquivos temporários.

---

# Prioridade geral

## P0 — bloquear antes de novas funcionalidades sensíveis

1. Corrigir troca de operadora.
2. Validar certificado e agenda no tenant.

## P1 — corrigir antes de ampliar produção

3. Webhook Evolution fail-closed.
4. Não remover permissões `domain` automaticamente do Google Drive.
5. Corrigir inconsistência de resumo NFS-e emitidas/recebidas.
6. Corrigir semântica/reversão dos ajustes em massa.

## P2 — consolidação e robustez

7. Revisar scheduler semanal/mensal/intervalo.
8. Garantir remoção da senha temporária do A1 com `finally`.
9. Limpar XMLs temporários.
10. Remover fallback legado de storage.
11. Criar CI obrigatório.

---

# P0 — 1. Troca de operadora acessível para usuário comum

## Problema

A rota:

```text
/trocar-operadora/{id}
```

é conceitualmente destinada apenas ao `super_admin`.

Porém ela está dentro somente do middleware:

```php
auth
```

e não possui:

```php
role:super_admin
```

A rota altera:

```text
session('operadora_context_id')
```

O problema é agravado pelo comportamento do `OperadoraContext::id()`.

Hoje a lógica prioriza o valor da sessão antes da identificação do perfil do usuário.

Conceitualmente:

```php
if (session()->has('operadora_context_id')) {
    return (int) session('operadora_context_id');
}

$user = Auth::user();
```

Isso permite que um usuário comum possa potencialmente definir o contexto de outro escritório.

## Impacto

O global scope `BelongsToOperadora` utiliza:

```php
OperadoraContext::id()
```

para definir:

```text
empresa_operadora_id
```

Portanto, um contexto de sessão adulterado pode alterar o tenant utilizado por diversas queries do sistema.

Isso atinge potencialmente:

- empresas;
- documentos;
- lançamentos;
- importações;
- certificados;
- automação fiscal;
- Google Drive;
- WhatsApp;
- XMLs;
- configurações.

Esse problema pode contornar proteções implementadas corretamente em outros módulos.

## Correção recomendada

Aplicar defesa em profundidade.

### 1. Restringir a rota

Adicionar:

```php
->middleware('role:super_admin')
```

Exemplo conceitual:

```php
Route::get('/trocar-operadora/{id?}', ...)
    ->middleware('role:super_admin')
    ->name('trocar-operadora');
```

### 2. Fortalecer `OperadoraContext::id()`

Usuário comum nunca deve utilizar `operadora_context_id` da sessão.

Sugestão:

```php
public static function id(): ?int
{
    $user = Auth::user();

    if (!$user) {
        return null;
    }

    if (!$user->isSuperAdmin()) {
        return $user->empresa_operadora_id;
    }

    if (session()->has('operadora_context_id')) {
        return (int) session('operadora_context_id');
    }

    return null;
}
```

A sessão de troca de operadora deve existir exclusivamente para super admin.

### 3. Limpar sessão inválida para usuários comuns

No middleware de contexto:

```php
if (!$user->isSuperAdmin()) {
    session()->forget('operadora_context_id');
}
```

Isso protege sessões antigas que já possam conter o valor.

## Testes obrigatórios

Criar teste explícito:

```text
usuário do escritório A
→ tenta /trocar-operadora/B
→ deve receber 403
```

Depois:

```text
usuário A
→ tenta acessar documento da operadora B
→ 404/403
```

Também testar:

```text
super_admin
→ seleciona operadora B
→ contexto passa a ser B
```

## Critério de aceite

- [ ] usuário comum não acessa `/trocar-operadora`;
- [ ] `operadora_context_id` é ignorado para usuário comum;
- [ ] sessão antiga adulterada não altera tenant;
- [ ] super admin continua podendo trocar de escritório;
- [ ] testes de IDOR cobrem a troca de contexto.

---

# P0 — 2. IDs de certificado e agenda sem validação explícita do tenant

## Problema

No cadastro de integrações da empresa, o Livewire envia valores como:

```text
certificado_digital_id
agenda_automacao_id
```

Esses valores chegam ao `EmpresaIntegracaoService`.

Atualmente eles são persistidos diretamente.

Conceitualmente:

```php
'certificado_digital_id' => $portalConfig['certificado_digital_id']
```

e:

```php
'agenda_automacao_id' => $cfg['agenda_automacao_id']
```

O fato do select da interface mostrar somente registros do escritório não é suficiente.

Uma requisição Livewire pode ser manipulada manualmente.

## Risco

Um usuário poderia tentar informar o ID de:

```text
certificado de outro escritório
```

ou:

```text
agenda de outro escritório
```

O banco possui FKs, mas elas garantem existência do ID, não pertencimento ao mesmo tenant.

## Correção recomendada

Resolver todos os IDs antes de salvar.

Exemplo:

```php
$certificadoId = null;

if (!empty($portalConfig['certificado_digital_id'])) {
    $certificado = CertificadoDigital::query()
        ->findOrFail((int) $portalConfig['certificado_digital_id']);

    $certificadoId = $certificado->id;
}
```

Para agenda:

```php
$agendaId = null;

if (!empty($cfg['agenda_automacao_id'])) {
    $agenda = AgendaAutomacao::query()
        ->findOrFail((int) $cfg['agenda_automacao_id']);

    $agendaId = $agenda->id;
}
```

Como os models possuem `BelongsToOperadora`, a query deve respeitar o tenant atual.

## Validação adicional

Também confirmar:

```text
empresa.empresa_operadora_id
==
OperadoraContext::requireId()
```

antes de sincronizar integrações.

## Testes obrigatórios

Criar:

```text
operadora A
certificado A

operadora B
certificado B

usuário A tenta salvar certificado B
→ falha
```

Mesmo teste para agenda.

## Critério de aceite

- [ ] certificado é resolvido pelo tenant;
- [ ] agenda é resolvida pelo tenant;
- [ ] ID manipulado de outro escritório é rejeitado;
- [ ] empresa também é validada no tenant;
- [ ] testes cobrem os dois cenários.

---

# P1 — 3. Webhook Evolution deve ser fail-closed

## Problema

A validação atualmente aceita qualquer requisição quando:

```text
EVOLUTION_API_KEY
```

está vazia.

Conceitualmente:

```php
if ($esperada === '') {
    return true;
}
```

Isso significa que uma configuração incompleta em produção transforma o webhook em público.

## Correção recomendada

Em produção:

```text
API key vazia = webhook indisponível
```

Exemplo:

```php
if ($esperada === '') {
    if (app()->environment('production')) {
        return false;
    }

    return true;
}
```

Uma alternativa melhor é nem aceitar a requisição e registrar:

```text
Evolution não configurada.
```

## Sugestão adicional

No script de produção, após configurar a Evolution, verificar obrigatoriamente:

```text
EVOLUTION_API_KEY != vazio
```

Se continuar vazia:

```text
falhar o preparo de produção
```

em vez de apenas seguir.

## Critério de aceite

- [ ] webhook sem API key retorna 401/503 em produção;
- [ ] desenvolvimento pode continuar permissivo se desejado;
- [ ] chave correta funciona;
- [ ] chave incorreta retorna 401;
- [ ] script de produção alerta ou falha com chave vazia.

---

# P1 — 4. Comando de remoção de links públicos pode apagar `domain`

## Problema

O novo comando:

```text
documentos:remover-links-publicos
```

é útil para limpar permissões antigas.

Porém `AcessoLinkDrive::ehPermissaoPublica()` considera como públicas:

```text
anyone
domain
```

O comando pode então remover permissões de domínio Google Workspace.

Exemplo legítimo:

```text
todos usuários de escritorio.com.br
```

Essa permissão não deve ser removida automaticamente.

## Correção recomendada

### Padrão

Remover somente:

```text
type = anyone
```

Não remover:

```text
type = domain
```

### Opcional

Criar parâmetro explícito:

```text
--remover-domain
```

Somente quando informado:

```text
anyone + domain
```

## Alteração conceitual

Separar:

```php
ehPermissaoAnyone()
ehPermissaoDomain()
```

ou adicionar argumento:

```php
removerPublicas(
    Drive $drive,
    string $fileId,
    bool $dryRun = false,
    bool $removerDomain = false
)
```

## Atenção

Não executar o comando real em produção antes dessa alteração.

O `--dry-run` pode continuar sendo utilizado para análise.

## Critério de aceite

- [ ] `anyone` é removido;
- [ ] `domain` é preservado por padrão;
- [ ] usuário pode optar explicitamente por remover `domain`;
- [ ] owner/user/group não são alterados;
- [ ] testes cobrem permissões mistas.

---

# P1 — 5. Resumo NFS-e pode misturar emitidas e recebidas

## Problema

A análise agora separa:

```text
NFS-e emitidas
NFS-e recebidas
```

Isso é correto.

Porém, quando a análise é aberta sem:

```text
?listagem=emitidas
```

ou:

```text
?listagem=recebidas
```

o serviço inicialmente busca os documentos sem filtro.

Depois detecta o tipo com base no primeiro registro.

O problema é que o resumo já pode ter sido calculado usando:

```text
emitidas + recebidas
```

enquanto a tabela termina mostrando apenas uma das listagens.

## Exemplo

```text
Emitidas:
15 notas
R$ 25.000

Recebidas:
10 notas
R$ 15.000
```

Acesso sem `listagem` pode resultar conceitualmente em:

```text
Resumo:
25 notas
R$ 40.000

Tabela:
15 notas emitidas
R$ 25.000
```

## Correção recomendada

Definir o tipo antes de calcular o resumo.

Fluxo:

```text
1. determinar portal
2. verificar se é NFS-e
3. determinar tipo de listagem
4. montar query filtrada
5. buscar documentos
6. calcular resumo
7. retornar query da mesma seleção
```

Evitar calcular o resumo com uma query diferente da tabela.

## Alternativa

Se `tipoListagem` não for informado:

```text
redirecionar para emitidas
```

ou exigir escolha explícita.

## Testes obrigatórios

Criar dados:

```text
2 NFS-e emitidas
3 NFS-e recebidas
```

Testar:

```text
listagem=emitidas
→ resumo 2
→ tabela 2
```

```text
listagem=recebidas
→ resumo 3
→ tabela 3
```

```text
sem listagem
→ comportamento definido e consistente
```

## Critério de aceite

- [ ] resumo e tabela sempre usam o mesmo filtro;
- [ ] link antigo sem `listagem` não mistura dados;
- [ ] emitidas e recebidas continuam separadas.

---

# P1 — 6. Ajuste em massa marca lançamento como conferido

## Problema

Ao aplicar alteração em massa, o código faz:

```php
$lancamento->conferido = true;
$lancamento->usuario = $usuario;
```

Mesmo quando o usuário apenas altera:

```text
conta
histórico
terceiro
```

Além disso, esses dois campos não são registrados entre os itens reversíveis.

## Consequência

Exemplo:

```text
ANTES
conferido = false

AJUSTE
conta alterada
conferido = true

REVERTER
conta volta ao valor anterior
conferido continua true
```

A reversão deixa o registro em estado diferente do original.

## Recomendação principal

Não marcar automaticamente o lançamento como conferido apenas porque sofreu ajuste em massa.

Alteração contábil e conferência devem ser conceitos separados.

Sugestão:

```php
// remover:
$lancamento->conferido = true;
```

Manter atualização de usuário apenas se fizer sentido como auditoria.

## Alternativa

Se a regra de negócio exigir `conferido = true`, então registrar também:

```text
conferido
usuario
```

como campos do lote e restaurá-los na reversão.

## Recomendação de produto

Preferência:

```text
alterar em massa != conferir
```

Se necessário, criar futuramente ação separada:

```text
Marcar selecionados como conferidos
```

## Critério de aceite

- [ ] reversão restaura integralmente o estado esperado;
- [ ] ajuste em massa não gera conferência acidental;
- [ ] regra fica coberta por teste.

---

# P2 — 7. Scheduler fiscal semanal/mensal/intervalo

## Problema

A estrutura de agenda possui conceitos como:

```text
timezone
dias da semana
dias do mês
intervalo
horários
```

Porém o despachador atual utiliza lógica simplificada.

Também usa período fixo:

```text
últimos 30 dias
```

para execução agendada.

O comando:

```text
automacoes:recalcular-proximas-execucoes
```

calcula basicamente a próxima ocorrência diária e ignora parte da frequência.

## Recomendação

Centralizar toda a lógica em um serviço:

```text
AgendaAutomacaoProximaExecucaoService
```

Exemplo:

```php
public function calcular(
    AgendaAutomacao $agenda,
    Carbon $referencia
): ?Carbon
```

O serviço deve tratar:

```text
manual
diaria
semanal
mensal
intervalo
```

e respeitar:

```text
timezone
dias_semana
dias_mes
horarios
intervalo_minutos
```

## Período da consulta

O período da consulta não deve ser fixado em 30 dias no comando.

Utilizar as políticas existentes da agenda/configuração.

## Regra temporária

Até essa implementação ficar pronta:

```text
Manual → habilitado
Diário → habilitado/homologar
Semanal → ocultar ou marcar beta
Mensal → ocultar ou marcar beta
Intervalo → ocultar ou marcar beta
```

---

# P2 — 8. Senha temporária do A1 deve ser apagada em `finally`

## Problema

O runner cria:

```text
cert-password.txt
```

com a senha do certificado.

O arquivo é apagado depois da execução normal.

Se ocorrer exceção antes desse ponto, o arquivo pode permanecer no disco.

## Correção recomendada

Envolver a execução em:

```php
try {
    ...
} finally {
    if ($passwordFile && File::exists($passwordFile)) {
        File::delete($passwordFile);
    }
}
```

Também:

```text
chmod 0600
```

no arquivo, se ainda não for garantido.

## Critério de aceite

Teste simulando exceção:

```text
arquivo criado
→ processo lança erro
→ arquivo não existe ao final
```

---

# P2 — 9. XML temporário precisa de limpeza

## Problema

As consultas avulsas gravam:

```text
temp/nfe-xml/{uuid}.xml
```

O cache de progresso expira após aproximadamente duas horas.

Porém o arquivo físico continua no storage.

## Correção recomendada

Criar comando:

```text
automacao-fiscal:limpar-temporarios
```

ou:

```text
documentos:limpar-temporarios
```

Remover:

```text
temp/nfe-xml/*
```

com idade superior a:

```text
24 horas
```

Executar diariamente pelo scheduler.

## Opcional

Também limpar:

```text
automacao-fiscal-runner
ZIPs temporários
artefatos temporários expirados
```

desde que não sejam registros permanentes.

---

# P2 — 10. Remover fallback de storage legado

## Problema

`OperadoraStorage::resolveRelativePath()` procura:

```text
/{empresa_operadora_id}/{subdir}/{arquivo}
```

e depois cai para:

```text
/{subdir}/{arquivo}
```

Esse segundo caminho é global e existe por compatibilidade com versões antigas.

## Risco

Em multi-tenant, fallback global reduz a garantia de isolamento.

Mesmo que hoje seja utilizado principalmente para arquivos antigos, a regra ideal é:

```text
tenant explícito
```

## Plano recomendado

### Etapa 1

Criar comando de diagnóstico:

```text
storage:auditar-legados
```

Listar arquivos sem prefixo de operadora.

### Etapa 2

Migrar arquivos para o tenant correto.

### Etapa 3

Remover fallback automático.

### Etapa 4

Se precisar de compatibilidade temporária, permitir somente com flag explícita:

```text
ALLOW_LEGACY_GLOBAL_STORAGE=false
```

Padrão de produção:

```text
false
```

---

# P2 — 11. Criar CI obrigatório

## Estado atual

Existem testes importantes no projeto, incluindo:

- isolamento multi-tenant;
- Google Drive;
- automação fiscal;
- Node runner;
- scripts.

Mas o commit atual não possui workflow/status de CI associado no GitHub.

## Recomendação

Criar:

```text
.github/workflows/ci.yml
```

Executar em:

```text
push
pull_request
```

## Pipeline sugerido

### PHP

```bash
composer install
php artisan test
vendor/bin/pint --test
```

### Frontend

```bash
npm ci
npm run build
```

### Runner Node

```bash
cd scripts/automacao-fiscal/runner
npm ci
npm run typecheck
npm run lint
npm test
npm run build
```

### Python

Executar testes dos conversores/extratores principais disponíveis no projeto.

## Banco

Os testes multi-tenant/fiscais que dependem de MySQL devem utilizar serviço MySQL no GitHub Actions.

Evitar substituir por SQLite se a implementação usa funções específicas MySQL, como:

```text
JSON_EXTRACT
DATE_FORMAT
```

---

# Revisão do novo fluxo Google Drive

## Pontos positivos

O fluxo recém-implementado deve ser mantido.

### Arquivos privados

Novos arquivos não precisam mais de:

```text
anyone with link
```

### Rotas internas

```text
/documentos/{documento}/download
/documentos/{documento}/visualizar
```

### Tenant

O `DocumentoRecebido` é resolvido pelo global scope da operadora.

### Streaming

O arquivo é buscado usando:

```text
Google Drive API
alt=media
```

e transmitido ao usuário.

### OAuth

O token é renovado automaticamente usando:

```text
refresh_token
```

### Headers

Manter:

```text
Cache-Control: private, no-store
X-Content-Type-Options: nosniff
```

### Testes

Manter o teste:

```text
usuário A não baixa documento B
```

e expandi-lo para contemplar a troca de operadora maliciosa.

---

# Observação sobre `drive_web_link`

O campo pode continuar existindo para compatibilidade e administração.

Porém:

```text
drive_web_link
```

não deve ser utilizado como mecanismo de autorização.

Regra:

```text
usuário normal:
Visualizar / Baixar via IntegraExpert

admin/super_admin:
pode opcionalmente usar "Abrir no Drive"
```

---

# Ordem recomendada de implementação

## Fase 1 — isolamento

Implementar primeiro:

```text
1. /trocar-operadora
2. OperadoraContext
3. SetOperadoraContext
4. certificado_digital_id
5. agenda_automacao_id
```

Rodar testes de tenant.

## Fase 2 — exposição externa

Depois:

```text
6. webhook Evolution
7. limpeza das permissões Google
```

## Fase 3 — consistência funcional

Depois:

```text
8. resumo NFS-e
9. ajuste em massa
```

## Fase 4 — robustez

Depois:

```text
10. scheduler
11. senha A1
12. temporários
13. storage legado
14. CI
```

---

# Testes de segurança prioritários

Adicionar uma suíte dedicada a cenários de IDOR/multi-tenant.

## Cenário 1

```text
A → documento B
```

deve falhar.

## Cenário 2

```text
A → trocar-operadora/B
```

deve falhar.

## Cenário 3

```text
A → session operadora_context_id=B
```

deve continuar vendo apenas A.

## Cenário 4

```text
A → certificado B
```

deve falhar.

## Cenário 5

```text
A → agenda B
```

deve falhar.

## Cenário 6

```text
super_admin → seleciona B
```

deve funcionar.

---

# Não fazer

Não:

- remover o multi-tenant global scope;
- confiar apenas em filtros da interface;
- confiar em IDs recebidos pelo Livewire;
- usar `withoutGlobalScope('operadora')` sem validação explícita;
- tornar arquivos do Drive públicos novamente;
- remover permissões `domain` automaticamente;
- marcar ajustes em massa como conferidos sem regra clara;
- expor scheduler semanal/mensal antes da implementação completa;
- silenciar falhas de configuração de segurança em produção.

---

# Resultado esperado após as correções

A arquitetura de segurança deve ficar:

```text
Usuário autenticado
        ↓
perfil
        ↓
OperadoraContext seguro
        ↓
BelongsToOperadora
        ↓
Empresa / Documento / Certificado / Agenda
        ↓
ação
```

Para super admin:

```text
super_admin
        ↓
seleciona escritório
        ↓
session operadora_context_id
        ↓
tenant escolhido
```

Para qualquer outro perfil:

```text
empresa_operadora_id do próprio usuário
```

sem possibilidade de sobrescrever pela sessão.

---

# Checklist final

## P0

- [x] `/trocar-operadora` somente super admin
- [x] `OperadoraContext::id()` ignora sessão para usuários comuns
- [x] middleware remove contexto indevido
- [x] certificado validado pelo tenant
- [x] agenda validada pelo tenant
- [x] testes de ataque de tenant adicionados

## P1

- [x] Evolution fail-closed em produção
- [x] comando de Drive remove apenas `anyone` por padrão
- [x] `domain` preservado
- [x] resumo NFS-e consistente
- [x] ajuste em massa não altera conferência indevidamente

## P2

- [x] scheduler centralizado
- [x] weekly/monthly respeitam configurações
- [x] senha A1 apagada em `finally`
- [x] XML temporário limpo
- [x] comandos `storage:auditar-legados` e `storage:migrar-legados`
- [ ] fallback global desligado em produção (flag default `true` até o audit)
- [x] GitHub Actions criado

---

# Prioridade máxima

Antes de trabalhar em novas funcionalidades que armazenem dados sensíveis, resolver:

```text
/trocar-operadora
```

e:

```text
certificado_digital_id / agenda_automacao_id
```

Esses são os dois pontos que mais afetam a garantia de isolamento entre escritórios.

Após essas correções, o módulo de Documentos via Google Drive e a Automação Fiscal estarão sobre uma base multi-tenant significativamente mais segura.
