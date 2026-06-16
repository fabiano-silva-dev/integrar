# Criar testes de isolamento multi-tenant

Crie ou proponha testes para garantir isolamento entre empresas operadoras no IntegraExpert.

Antes de implementar, mostre um plano.

Cenários mínimos:

- Operadora A não lista empresas da Operadora B.
- Operadora A não abre importação da Operadora B.
- Operadora A não altera lançamento da Operadora B.
- Operadora A não baixa arquivo da Operadora B.
- Admin da Operadora A não gerencia usuários da Operadora B.
- `/trocar-empresa/{id}` rejeita empresa de outra operadora.
- Super admin acessa dados de múltiplas operadoras apenas quando autorizado.

Cuidados:

- Não depender apenas de frontend.
- Testar rotas, Livewire actions e Policies.
- Criar factories se necessário.
- Não usar dados reais de clientes.
- Manter testes claros e pequenos.

Depois de implementar, explique como executar os testes.
