# Implementar com segurança

Implemente a alteração solicitada no IntegraExpert de forma incremental e segura.

Antes de alterar código, faça um plano com:

- arquivos que serão alterados;
- objetivo da alteração;
- riscos envolvidos;
- validações necessárias;
- testes recomendados.

Durante a implementação, siga estas regras:

- Não alterar escopo além do solicitado.
- Não refatorar partes não relacionadas.
- Preservar comportamento existente quando possível.
- Validar permissões no backend.
- Considerar multi-tenant e `empresa_operadora_id` quando aplicável.
- Manter UX guiada em fluxos operacionais.
- Evitar botões ou inputs fora de contexto.
- Não expor dados de outros escritórios.
- Não remover logs ou validações existentes sem justificar.
- Não salvar dados sensíveis em código, prompt ou documentação.

Depois de implementar, entregue:

- resumo das alterações;
- arquivos modificados;
- riscos mitigados;
- testes executados ou sugeridos;
- pontos que precisam de validação manual.
