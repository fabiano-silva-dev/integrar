# Revisar segurança multi-tenant

Analise o trecho, tela, rota ou fluxo indicado do IntegraExpert com foco em segurança multi-tenant.

Objetivo:
Garantir que um escritório não consiga acessar, visualizar, alterar, baixar ou exportar dados de outro escritório.

Não altere código ainda.

Verifique:

- uso de `empresa_operadora_id`;
- validação de empresa pertencente à operadora;
- rotas que recebem IDs;
- Livewire actions;
- Controllers;
- Policies;
- downloads de arquivos;
- importações;
- exportações;
- regras de amarração;
- gerenciamento de usuários;
- troca de empresa;
- jobs e comandos.

Entregue um diagnóstico com:

- arquivo/componente;
- risco encontrado;
- cenário de exploração;
- impacto;
- sugestão de correção;
- teste recomendado.

Priorize riscos como:

- IDOR/BOLA;
- acesso cruzado entre operadoras;
- consulta sem filtro por operadora;
- download sem validação;
- edição de lançamento de outra operadora;
- troca de empresa sem validação;
- usuário admin de um escritório gerenciando usuários de outro.

Aguarde minha aprovação antes de alterar arquivos.
