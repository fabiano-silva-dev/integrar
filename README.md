# Sistema Integrar - Ferramenta Contábil

Uma aplicação web desenvolvida em Laravel + Livewire para processamento e gerenciamento de lançamentos contábeis.

## 🚀 Funcionalidades

### 📥 Importador de CSV
- Upload de arquivos CSV com lançamentos contábeis
- Suporte a diferentes formatos de data (DD/MM/AAAA, YYYY-MM-DD)
- Processamento automático de valores com vírgula decimal
- Validação de dados e tratamento de erros
- Controle de importações com status e logs

### 📊 Tabela de Lançamentos
- Visualização em tabela com paginação
- Filtros por data, histórico, terceiro e status
- Seleção múltipla para edição em massa
- Edição de contas de débito, crédito e terceiros
- Log completo de alterações
- Sistema de conferência de lançamentos

### 📤 Exportador Contábil
- Exportação em formatos CSV e TXT
- Múltiplos layouts: Padrão, Contábil e Simples
- Seleção de período para exportação
- Download direto dos arquivos gerados

### 📋 Lista de Importações
- Histórico completo de todas as importações
- Status de processamento (pendente, processando, concluído, erro)
- Detalhes de cada importação (total de registros, processados, erros)
- Filtros por data e status

### 👥 Gerenciador de Terceiros
- Cadastro e edição de terceiros
- Associação automática de terceiros aos lançamentos
- Busca e filtros por nome e código
- Histórico de alterações

### 🔗 Gerenciador de Amarrações
- Configuração de amarrações entre contas
- Mapeamento automático de lançamentos
- Regras de negócio personalizáveis

### 📝 Log de Alterações
- Rastreamento completo de mudanças
- Histórico por terceiro, conta e valor
- Timestamp de alterações
- Identificação do usuário que fez a alteração

## 🛠️ Tecnologias

- **Backend**: Laravel 12 (PHP 8.2+)
- **Frontend**: Livewire 3 + Tailwind CSS
- **Banco de Dados**: MySQL 5.7
- **Servidor de produção**: Apache + PHP-FPM + MySQL + systemd

## 🚀 Instalação nativa em produção (sem Docker)

O script `instalar-nativo-producao.sh` automatiza a instalação nativa em servidores
Debian/Ubuntu. Ele pede confirmação antes de cada ação e pode:

- instalar Apache, PHP-FPM, MySQL, Composer, Node.js, `poppler-utils` (`pdftotext` para plano de contas PDF) e dependências Python;
- gerar um dump do MySQL que está no Docker (quando disponível) ou importar `--backup-file`;
- parar os containers sem remover os volumes;
- criar o banco e usuário MySQL nativos e restaurar o dump;
- configurar `.env`, permissões (deploy user + grupo `www-data`), Composer, Vite e o ambiente Python;
- criar symlink `/var/www/html` → projeto (conversores Python);
- configurar o VirtualHost do Apache, worker da fila e agendador Laravel no systemd (`www-data`);
- executar migrações, otimizações e testes de saúde (inclui `pdftotext`).

Antes de alterar o servidor, simule todo o fluxo:

```bash
sudo ./instalar-nativo-producao.sh \
  --dry-run \
  --yes
```

Para executar a instalação com confirmação em cada etapa (na raiz do projeto):

```bash
cd /home/fabiano/Projetos/integrar
sudo ./instalar-nativo-producao.sh
```

Sem parâmetros, o script usa automaticamente:
- **Projeto:** raiz do repositório (onde está o atalho `./instalar-nativo-producao.sh`)
- **Domínio:** `integraexpert.com.br`
- **Banco:** `integrar`

Caso o container Docker não esteja disponível, indique um dump:

```bash
sudo ./instalar-nativo-producao.sh --backup-file /backup/integrar.sql
```

> **Atenção:** mantenha os volumes Docker até validar a aplicação nativa. O
> script executa `docker compose down`, mas nunca remove volumes. Após configurar
> TLS (Certbot), o `APP_URL` já aponta para `https://integraexpert.com.br`.
> Credenciais do banco Docker, quando usadas no dump, seguem o container ativo.

Os testes isolados do instalador não exigem root e não alteram o sistema:

```bash
bash tests/scripts/test_instalar_nativo_producao.sh
```

Antes da migração em produção, gere um relatório de diagnóstico (somente
leitura) e envie o arquivo Markdown gerado para análise:

```bash
sudo bash diagnostico_migracao_nativa.sh --quiet
# relatório em storage/app/diagnostico_migracao_*.md
```

## 📋 Pré-requisitos

- Debian ou Ubuntu com systemd
- acesso root (`sudo`)
- DNS do domínio apontado para o servidor
- Docker apenas durante a migração, caso o banco atual esteja em container

## 🚀 Instalação

1. **Clone o repositório**
   ```bash
   git clone <url-do-repositorio>
   cd integrar
   ```

2. **Inicie os containers**
   ```bash
   docker-compose up -d
   ```

3. **Aguarde a inicialização**
   - O Laravel será criado automaticamente
   - As migrações serão executadas
   - O Livewire será instalado

4. **Acesse a aplicação**
   ```
   http://localhost:8081
   ```

## 📁 Estrutura do Projeto

```
integrar/
├── app/
│   ├── Livewire/
│   │   ├── ImportadorCsv.php
│   │   ├── TabelaLancamentos.php
│   │   ├── ExportadorContabil.php
│   │   ├── ListaImportacoes.php
│   │   ├── GerenciadorTerceiros.php
│   │   └── GerenciadorAmarracoes.php
│   └── Models/
│       ├── Lancamento.php
│       ├── Importacao.php
│       ├── Terceiro.php
│       ├── Amarracao.php
│       ├── AlteracaoLog.php
│       └── User.php
├── database/migrations/
│   ├── create_lancamentos_table.php
│   ├── create_importacoes_table.php
│   ├── create_terceiros_table.php
│   ├── create_amarracoes_table.php
│   └── create_alteracoes_log_table.php
├── resources/views/
│   ├── layouts/
│   │   └── app.blade.php
│   └── livewire/
│       ├── importador-csv.blade.php
│       ├── tabela-lancamentos.blade.php
│       ├── exportador-contabil.blade.php
│       ├── lista-importacoes.blade.php
│       ├── gerenciador-terceiros.blade.php
│       └── gerenciador-amarracoes.blade.php
└── docker-compose.yml
```

## 📊 Estrutura do Banco de Dados

### Tabela: `lancamentos`
- `id` - Chave primária
- `data` - Data do lançamento
- `historico` - Descrição do lançamento
- `conta_debito` - Código da conta de débito
- `conta_credito` - Código da conta de crédito
- `valor` - Valor do lançamento
- `terceiro` - Nome do terceiro (preenchido automaticamente)
- `usuario` - Usuário responsável
- `codigo_filial_matriz` - Código da filial/matriz
- `nome_empresa` - Nome da empresa
- `numero_nota` - Número da nota fiscal
- `importacao_id` - Referência à importação
- `terceiro_id` - Referência ao terceiro
- `detalhes_operacao` - Detalhes da operação
- `conta_debito_original` - Conta de débito original
- `conta_credito_original` - Conta de crédito original
- `conferido` - Status de conferência
- `amarracao_id` - Referência à amarração
- `arquivo_origem` - Nome do arquivo importado
- `linha_arquivo` - Número da linha no arquivo original
- `processado` - Status de processamento
- `created_at`, `updated_at` - Timestamps

### Tabela: `importacoes`
- `id` - Chave primária
- `nome_arquivo` - Nome do arquivo importado
- `total_registros` - Total de registros no arquivo
- `registros_processados` - Registros processados com sucesso
- `status` - Status da importação (pendente, processando, concluído, erro)
- `erro_mensagem` - Mensagem de erro (se houver)
- `usuario` - Usuário que fez a importação
- `codigo_empresa` - Código da empresa
- `cnpj_empresa` - CNPJ da empresa
- `data_inicial` - Data inicial dos lançamentos
- `data_final` - Data final dos lançamentos
- `created_at`, `updated_at` - Timestamps

### Tabela: `terceiros`
- `id` - Chave primária
- `nome` - Nome do terceiro
- `codigo` - Código do terceiro
- `cnpj_cpf` - CNPJ/CPF do terceiro
- `created_at`, `updated_at` - Timestamps

### Tabela: `amarracoes`
- `id` - Chave primária
- `nome` - Nome da amarração
- `regra` - Regra de amarração
- `created_at`, `updated_at` - Timestamps

### Tabela: `alteracoes_log`
- `id` - Chave primária
- `lancamento_id` - Referência ao lançamento
- `campo_alterado` - Nome do campo alterado
- `valor_anterior` - Valor anterior
- `valor_novo` - Novo valor
- `tipo_alteracao` - Tipo da alteração (terceiro, conta, valor)
- `usuario` - Usuário que fez a alteração
- `data_alteracao` - Data/hora da alteração
- `created_at`, `updated_at` - Timestamps

## 📄 Formato do CSV de Importação

O arquivo CSV deve conter as seguintes colunas separadas por ponto e vírgula (;):

```
Data;Histórico;Conta Débito;Conta Crédito;Valor
01/07/2024;Pagamento de fornecedor;1.1.1.01;2.1.1.01;1500,00
```

### Formatos Suportados:
- **Data**: DD/MM/AAAA, YYYY-MM-DD, DD-MM-YYYY, MM/DD/YYYY
- **Valor**: Usar vírgula como separador decimal (ex: 1.500,00)

## 🔄 Atualizar produção após `git pull`

```bash
git pull
./atualizar-producao.sh
```

Padrão: Apache nativo — `composer`/`npm` como dono do projeto, `artisan` como `www-data`.
O `sudo` é solicitado automaticamente quando necessário.
Em desenvolvimento com Docker: `./atualizar-producao.sh --docker`

O script roda composer, build de assets (requer Node.js 20+ no servidor),
migrations, limpeza de cache e reload dos serviços. Ver opções em
`script-manutencao/README.md`.

## 🔧 Comandos Úteis

### Acessar o container da aplicação
```bash
docker-compose exec app bash
```

### Executar comandos Artisan
```bash
docker-compose exec app php artisan migrate
docker-compose exec app php artisan make:model NomeModelo
docker-compose exec app php artisan make:livewire NomeComponente
```

### Ver logs
```bash
docker-compose logs app
docker-compose logs db
```

### Reiniciar serviços
```bash
docker-compose restart
```

## 🌐 URLs da Aplicação

- **Página Inicial**: http://localhost:8081
- **Importador**: http://localhost:8081/importador
- **Tabela de Lançamentos**: http://localhost:8081/tabela
- **Exportador**: http://localhost:8081/exportador
- **Lista de Importações**: http://localhost:8081/importacoes
- **Gerenciador de Terceiros**: http://localhost:8081/terceiros
- **Gerenciador de Amarrações**: http://localhost:8081/amarracoes

## 📝 Exemplo de Uso

1. **Importar dados**
   - Acesse o Importador
   - Faça upload do arquivo CSV
   - Aguarde o processamento
   - Verifique o status na Lista de Importações

2. **Gerenciar lançamentos**
   - Acesse a Tabela de Lançamentos
   - Use os filtros para encontrar registros
   - Selecione múltiplos itens para edição em massa
   - Edite contas e terceiros conforme necessário
   - Marque lançamentos como conferidos

3. **Gerenciar terceiros**
   - Acesse o Gerenciador de Terceiros
   - Cadastre novos terceiros
   - Edite informações existentes
   - Associe terceiros aos lançamentos

4. **Configurar amarrações**
   - Acesse o Gerenciador de Amarrações
   - Configure regras de mapeamento
   - Defina relacionamentos entre contas

5. **Exportar dados**
   - Acesse o Exportador
   - Selecione o período
   - Escolha o formato e layout
   - Faça download do arquivo gerado

## 🔒 Segurança

- Validação de tipos de arquivo (apenas CSV/TXT)
- Limite de tamanho de arquivo (10MB)
- Sanitização de dados de entrada
- Log de todas as alterações
- Controle de acesso por usuário

## 🐛 Solução de Problemas

### Erro de conexão com banco
```bash
docker-compose restart
```

### Problemas de permissão
```bash
docker-compose exec app chmod -R 777 storage bootstrap/cache
```

### Extensão MySQL não encontrada
```bash
docker-compose exec app docker-php-ext-install pdo_mysql
```

## 📞 Suporte

Para dúvidas ou problemas, consulte:
- Documentação do Laravel: https://laravel.com/docs
- Documentação do Livewire: https://laravel-livewire.com/docs
- Issues do projeto no GitHub

---

**Desenvolvido com ❤️ para processos contábeis**
