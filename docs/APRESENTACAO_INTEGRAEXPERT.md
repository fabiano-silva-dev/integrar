---
marp: true
theme: default
paginate: true
title: IntegraExpert
description: Apresentação das funcionalidades para a equipe
style: |
  section { font-family: 'Segoe UI', system-ui, sans-serif; }
  h1 { color: #1d4ed8; }
  h2 { color: #1e40af; }
  strong { color: #1d4ed8; }
---

# IntegraExpert
## Ferramenta contábil para escritórios

**Importar → Amarrar → Conferir → Exportar**

Plataforma web para processar extratos bancários, classificar lançamentos automaticamente e exportar para o **Domínio**.

---

## O problema que resolvemos

Escritórios contábeis recebem extratos em **dezenas de formatos diferentes**:

- PDFs de bancos e cooperativas (Sicoob, Sicredi, Caixa, Itaú…)
- Arquivos OFX
- Planilhas CSV/Excel personalizadas
- Arquivos TXT do próprio Domínio

**IntegraExpert** centraliza a leitura, a classificação contábil e a exportação — reduzindo trabalho manual repetitivo.

---

## Fluxo principal

```
┌─────────────┐    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│  IMPORTADO  │ →  │  AMARRADO   │ →  │  CONFERIDO  │ →  │  EXPORTADO  │
│   Extrato   │    │  Contas     │    │  Revisão    │    │  Domínio    │
└─────────────┘    └─────────────┘    └─────────────┘    └─────────────┘
```

1. Upload do extrato
2. Classificação automática por regras
3. Revisão na tabela de lançamentos
4. Geração do arquivo para o ERP contábil

---

## Visão geral do menu

| Área | Funcionalidades |
|------|-----------------|
| **Cadastros** | Empresas, Usuários, Terceiros |
| **Importação** | Extratos, Planilhas personalizadas, Histórico |
| **Conversão** | PDF → OFX (ferramenta auxiliar) |
| **Lançamentos** | Tabela de lançamentos, Regras de amarração |
| **Exportação** | Gerador de arquivo Domínio |
| **Administração** | Históricos padrão, Auditoria de conversões |

**Multi-empresa:** seletor global na barra superior — todos os dados filtrados pela empresa selecionada.

---

## Cadastros

### Empresas (clientes)
- Nome, CNPJ, código no sistema contábil
- Conta banco padrão (código Domínio)

### Terceiros
- Fornecedores e clientes vinculados aos lançamentos
- Associação automática durante a importação

### Usuários *(gerente e admin)*
- Controle de acesso por perfil

---

## Perfis de acesso

| Perfil | O que pode fazer |
|--------|------------------|
| **Operador** | Cadastros básicos, importação, conversão, lançamentos, exportação |
| **Gerente** | Tudo do operador + gestão de usuários |
| **Admin** | Tudo acima + históricos padrão por layout, auditoria de conversões PDF→OFX |

---

## Importador Avançado
### O coração do sistema

Upload de extratos com conversão automática para lançamentos contábeis.

**Formatos aceitos:** PDF, CSV, TXT, OFX (até 10 MB)

**Campos obrigatórios:**
- Empresa (seletor global)
- Layout / banco de origem
- Conta do banco no Domínio

---

## Bancos e layouts suportados (importação)

| Instituição / origem | Tipo |
|---------------------|------|
| Domínio | TXT |
| Grafeno | PDF |
| Sicoob | PDF |
| Sicredi | PDF |
| Caixa Econômica Federal | PDF |
| Caixa Internet Banking | PDF |
| Formato OFX | OFX |
| Connectere — Contas Financeiras | CSV |

Na importação, o sistema **aplica regras de amarração** e **sincroniza históricos padrão** automaticamente.

---

## O que acontece na importação

1. Arquivo enviado pelo usuário
2. **Script Python** converte PDF/TXT/OFX → CSV padronizado
3. **Laravel** cria o lote (`Importação`) e os lançamentos
4. Motor de **regras de amarração** preenche contas débito/crédito
5. Descrições novas alimentam o catálogo de históricos padrão

Resultado: centenas de lançamentos prontos para revisão em segundos.

---

## Importador Personalizado
### Para planilhas que não seguem layout de banco

**Fluxo em 4 etapas:**

1. **Upload** — CSV, XLS ou XLSX
2. **Mapeamento** — associar colunas do arquivo aos campos do lançamento
3. **Prévia** — conferir antes de gravar
4. **Confirmação** — importação definitiva

**Layouts reutilizáveis** por empresa — configure uma vez, use sempre.

---

## Importações anteriores

- Histórico de todos os lotes importados
- Filtros por data, empresa e status
- Abrir direto na tabela de lançamentos
- Excluir importação e lançamentos associados

---

## Conversão PDF → OFX
### Ferramenta auxiliar (não cria lançamentos)

Converte extrato PDF em arquivo **OFX** para uso em outros sistemas.

**Bancos disponíveis:**

Grafeno · Sicoob · Sicredi · Caixa (2 modelos) · Santander · Itaú · Bradesco · Cresol · Banco do Brasil

*(Mais bancos no conversor do que no importador direto)*

Admin pode consultar o **histórico de conversões** com metadados e download do OFX gerado.

---

## Tabela de Lançamentos
### Centro de revisão e conferência

- Grid paginado com **filtros avançados** (data, histórico, terceiro, importação, contas, valor, status conferido)
- **Edição** de contas débito/crédito, histórico e valor
- **Conferência:** clique na linha para marcar como conferido
- **Reprocessar amarrações** após alterar regras
- Destaque visual para lançamentos alterados e conferidos
- **Log de alterações** para auditoria

---

## Regras de Amarração
### O diferencial do IntegraExpert

Mapeia **descrições do extrato** → **conta contábil de contrapartida**.

| Recurso | Benefício |
|---------|-----------|
| Busca por palavra-chave | `contém`, `inicia com`, `termina com`, `exato` |
| Parte digitável | Tolera erros de OCR (I/1, O/0, espaços) |
| Débito/crédito automático | Inferido pelo sinal do valor |
| Por empresa + layout | Regras específicas por banco e cliente |

Configure uma vez — todas as importações futuras se beneficiam.

---

## Históricos padrão por layout *(admin)*

- Analisa arquivo de extrato e extrai descrições repetidas
- Define quais descrições viram base para novas regras
- Acelera a configuração inicial de cada layout de banco

---

## Exportador Contábil
### Saída para o Domínio

Gera arquivo **TXT** no layout Domínio (ISO-8859-1).

**Parâmetros:**
- Código da empresa (7 dígitos)
- CNPJ
- Importação ou período
- Tipo de nota e sistema contábil

**Validação:** aviso de lançamentos com conta débito/crédito vazia antes de exportar.

---

## Extrator Bancário
### Ferramenta analítica (rota avançada)

- Gera extrato contábil a partir dos lançamentos de uma conta banco
- Calcula saldo dia a dia no período
- Compara saldo calculado vs. saldo informado
- Exportação em CSV

Útil para conferência de saldos e análise de movimentação.

---

## Arquitetura técnica (resumo)

| Camada | Tecnologia |
|--------|------------|
| Backend | Laravel 12, PHP 8.2 |
| Frontend | Livewire 3, Tailwind CSS |
| Banco | MySQL 5.7 |
| Conversores | Python (PDF, OFX, Excel) |
| Deploy | Docker Compose |

**Híbrido PHP + Python:** parsing pesado em Python; regras de negócio e interface em Laravel.

**Produção:** `integraexpert.com.br` — containers com portas restritas a localhost.

---

## Integração com o Domínio

| Direção | Suporte |
|---------|---------|
| **Entrada** | Importação de TXT Domínio |
| **Saída** | Exportação TXT layout Domínio |
| **Contas** | Códigos do plano de contas do cliente |
| **Empresa** | Código de 7 dígitos no sistema contábil |

O IntegraExpert atua como **ponte** entre extratos bancários brutos e o ERP contábil.

---

## Workflow recomendado para o dia a dia

1. Selecionar a **empresa** no seletor global
2. **Importador Avançado** → escolher banco → informar conta → upload
3. **Tabela de lançamentos** → filtrar pela importação → conferir e ajustar
4. Criar/ajustar **regras de amarração** conforme surgem descrições novas
5. **Exportador** → gerar TXT Domínio → importar no ERP

---

## Em desenvolvimento / roadmap

Itens visíveis no menu como **"em breve"**:

- Configurações centralizadas
- Logs de sistema
- Gestão de acessos

**Empresas Operadoras** (escritório contábil / SaaS) — já implementado, fora do menu principal.

---

## Resumo executivo

**IntegraExpert** é a plataforma do escritório para:

- ✅ Importar extratos de **múltiplos bancos e formatos**
- ✅ **Classificar automaticamente** lançamentos por regras inteligentes
- ✅ **Conferir e revisar** antes de fechar o período
- ✅ **Exportar** direto para o Domínio
- ✅ Converter PDF em OFX quando necessário
- ✅ Trabalhar com **várias empresas** no mesmo ambiente

**Menos digitação manual. Mais consistência. Mais produtividade.**

---

# Obrigado!

**IntegraExpert** — integraexpert.com.br

Dúvidas e sugestões: fale com o time de desenvolvimento.
