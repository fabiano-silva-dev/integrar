# Prompts reutilizáveis — IntegraExpert

Coleção de prompts organizados por contexto de uso. Copie o conteúdo do arquivo desejado para um novo chat no Cursor (ou outra ferramenta) e complemente com o contexto específico da tarefa.

## Estrutura

| Pasta | Uso |
|-------|-----|
| [`cursor/`](cursor/) | Regras do projeto, UX guiada, segurança multi-tenant e implementação segura |
| [`dev/`](dev/) | Revisão de PR, testes de isolamento, Livewire e importadores |
| [`produto/`](produto/) | Demonstração, proposta comercial, planos e posicionamento |
| [`suporte/`](suporte/) | Análise de erros de importação e resposta ao cliente |

## Como usar

1. Abra o arquivo do prompt que corresponde à tarefa.
2. Copie o conteúdo para o chat.
3. Indique o arquivo, tela, fluxo ou problema específico (ex.: `@app/Livewire/ImportadorAvancado.php`).
4. Para prompts que pedem aprovação antes de alterar arquivos, aguarde o retorno antes de prosseguir.

## Índice

### Cursor (regras e qualidade)

- [`revisar-chat-e-atualizar-regras.md`](cursor/revisar-chat-e-atualizar-regras.md) — Extrair aprendizados do chat e propor atualizações em `.cursor/rules/`
- [`revisar-regras-existentes.md`](cursor/revisar-regras-existentes.md) — Auditar regras duplicadas, desatualizadas ou conflitantes
- [`analisar-ux-fluxos-guiados.md`](cursor/analisar-ux-fluxos-guiados.md) — Diagnóstico de UX no fluxo Importar → Amarrar → Conferir → Exportar
- [`revisar-seguranca-multitenant.md`](cursor/revisar-seguranca-multitenant.md) — Revisão de isolamento entre escritórios/operadoras
- [`implementar-com-seguranca.md`](cursor/implementar-com-seguranca.md) — Implementação incremental com plano e validações

### Desenvolvimento

- [`revisar-pr.md`](dev/revisar-pr.md) — Revisão de alterações como Pull Request
- [`criar-testes-isolamento.md`](dev/criar-testes-isolamento.md) — Testes de isolamento multi-tenant
- [`diagnosticar-livewire.md`](dev/diagnosticar-livewire.md) — Análise de componente Livewire
- [`revisar-importador.md`](dev/revisar-importador.md) — Revisão de rotina de importação

### Produto e comercial

- [`roteiro-demonstracao.md`](produto/roteiro-demonstracao.md) — Roteiro para demo com escritório contábil
- [`proposta-comercial.md`](produto/proposta-comercial.md) — Proposta baseada no Plano Bronze
- [`planos-comerciais.md`](produto/planos-comerciais.md) — Estrutura Bronze, Prata, Ouro e personalizado
- [`posicionamento-integraexpert.md`](produto/posicionamento-integraexpert.md) — Textos de posicionamento do produto

### Suporte

- [`analisar-erro-importacao.md`](suporte/analisar-erro-importacao.md) — Diagnóstico de erro de importação
- [`responder-cliente.md`](suporte/responder-cliente.md) — Resposta ao cliente (WhatsApp e formal)

## Relação com `.cursor/rules/`

Os prompts em `cursor/` complementam as regras permanentes em [`.cursor/rules/`](../.cursor/rules/). Use `revisar-chat-e-atualizar-regras.md` ou `revisar-regras-existentes.md` quando quiser evoluir essas regras com base em conversas ou auditoria.
