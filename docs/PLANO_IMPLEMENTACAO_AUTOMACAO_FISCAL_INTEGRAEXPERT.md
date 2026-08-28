# Plano de Implementação — Automação Fiscal e Integração com Portais no IntegraExpert

**Documento:** arquitetura, escopo funcional e roteiro de implementação  
**Produto:** IntegraExpert  
**Data-base:** 22/07/2026  
**Repositório de destino:** `fabiano-silva-dev/integrar`  
**Branch principal atual:** `master`  
**Baseline consultada:** commit `a32da960d0e720378a95e27603e304a1eba60260`  
**Nome sugerido no repositório:** `docs/PLANO_IMPLEMENTACAO_AUTOMACAO_FISCAL.md`
**Nome do projeto original:** automacao-portais

### Status da implementação (atualizado em 28/08/2026)

Entregas já no repositório além do roteiro por fases abaixo:

- **Análises fiscais** agrupadas por empresa + portal + competência (`AnaliseFiscalService`, rotas `/automacao-fiscal/analises` e `/automacao-fiscal/analises/{empresa}/{portal}/{competencia}`).
- **Normalização de `chave_acesso`** (somente dígitos, tamanho variável) na importação de extratos NF-e/NFS-e, com idempotência por chave.
- **Download avulso do XML** da NF-e modelo 55 via webservices SOAP (sem portal/captcha):
  1. DistDFe Ambiente Nacional + Ciência da Operação (`210210`) com A1 do destinatário;
  2. WS Contabilista SEFAZ-RS com A1 do escritório (fallback).
- Serviço `NfeXmlDownloadService`, clients em `app/Services/AutomacaoFiscal/Sefaz/`, job na fila `automacoes`.
- **Origem do download** exibida no painel (DistDFe × Contabilista) e **DANFE em PDF** após o XML (`/automacao-fiscal/xml-download/{token}/danfe`, pacote `nfephp-org/sped-da`).
- **Download XML/PDF da NFS-e nacional** (misto: listagem por período no portal + XML na Sefin + DANFSe local):
  1. Após o extrato, enfileira `BaixarNfseXmlJob` só para notas sem XML (`NfseSefinNacionalClient` mTLS, A1 da empresa);
  2. XML persistido em `{operadora}/automacao-fiscal/nfse/{empresa}/{chave}.xml`;
  3. DANFSe gerado localmente (`NfseDanfseGenerator` + CLI `scripts/automacao-fiscal/runner/src/danfse`);
  4. Análise fiscal: XML/PDF por nota, ZIP do período; consulta avulsa `xml_nfse_por_chave`.
- **Consultas avulsas** (`/automacao-fiscal/avulsas`) para testes pontuais (super_admin).
- Variáveis: `NFE_DISTDFE_*`, `NFE_RECEPCAO_EVENTO_URL`, `NFE_CONTABILISTA_*`, `NFSE_SEFIN_*`; `DB_QUEUE_RETRY_AFTER` padrão 960 (maior que o timeout do worker).
- Job de portal e-CAC/NFS-e com `WithoutOverlapping` por escritório; consulta e-CAC sem notas tratada como sucesso.

Após `git pull` em produção: `./atualizar-producao.sh` (instala `sped-da` via composer e recompila assets). Reiniciar o worker da fila `automacoes` (systemd). Rebuild do runner Node só se houver mudança em `scripts/automacao-fiscal/runner` (`sudo ./instalar-deps-automacao-fiscal.sh --yes` ou `npm ci && npm run build` na pasta do runner).

---

## 1. Decisão de produto

A automação de acesso a portais fiscais deve ser incorporada ao IntegraExpert como um novo módulo interno chamado, provisoriamente:

**Automação Fiscal**

O módulo não deve ser apresentado apenas como um conjunto de robôs que acessam sites. O valor do produto está no fluxo completo:

**Configurar → Agendar → Coletar → Registrar → Conferir → Tratar divergências**

As primeiras integrações são:

1. **e-CAC da Receita Estadual do Rio Grande do Sul**
   - autenticação por certificado digital;
   - consulta e download do extrato de NF-e e NFC-e;
   - preservação dos parâmetros e resultados da consulta.

2. **Portal Nacional da NFS-e**
   - autenticação por certificado digital do cliente ou outro método suportado pelo portal;
   - consulta e download da relação de notas de serviço;
   - preservação dos parâmetros e resultados da consulta.

A arquitetura deve nascer preparada para novos portais, sem adicionar uma nova coluna na tabela `empresas` para cada integração futura.

---

## 2. Objetivo deste plano

Este documento deve orientar a incorporação, no IntegraExpert, do desenvolvimento já realizado no projeto separado de automação.

O processo não consiste em copiar telas ou diretórios de forma direta. Antes da incorporação, é obrigatório:

1. inventariar o projeto de automação existente;
2. identificar linguagens, bibliotecas, navegadores, drivers, scripts, serviços e variáveis de ambiente;
3. separar código de integração, interface, persistência, credenciais e infraestrutura;
4. reaproveitar a lógica que já funciona;
5. adaptar a solução ao padrão Laravel, Livewire, MySQL, multi-tenancy e storage do IntegraExpert;
6. preparar desenvolvimento em Docker e produção nativa com Apache, PHP-FPM, MySQL e systemd;
7. validar novamente cada portal dentro do IntegraExpert antes de remover ou abandonar o projeto de origem.

Não reescrever uma integração funcional apenas para mudar de linguagem. O primeiro objetivo é preservar o comportamento validado e encapsulá-lo corretamente.

---

## 3. Estado atual confirmado do IntegraExpert

O IntegraExpert utiliza:

- PHP 8.2;
- Laravel 12;
- Livewire 3;
- MySQL 5.7;
- PhpSpreadsheet;
- scripts Python para processamentos auxiliares;
- Docker Compose no desenvolvimento;
- Apache, PHP-FPM e MySQL nativos em produção;
- systemd para workers e agendamento em produção;
- isolamento multi-tenant por `empresa_operadora_id`;
- `OperadoraContext` para resolver o escritório ativo;
- `OperadoraStorage` para separar arquivos por escritório;
- `BelongsToOperadora` nos models de negócio.

O cadastro atual de empresas é reduzido. A tabela e a tela trabalham principalmente com:

- nome;
- CNPJ;
- código no sistema;
- código da conta bancária.

O menu já possui o item **Configurações**, mas ele está desabilitado e identificado como “Em breve”. Esse item deverá ser ativado e passar a concentrar as configurações gerais da automação.

A fila padrão do Laravel já está configurada para o driver `database`, e o projeto já possui a migration-base das tabelas de jobs. A implementação deve aproveitar essa estrutura inicialmente, sem exigir Redis no primeiro momento.

---

## 4. Princípios obrigatórios

### 4.1. O IntegraExpert continua sendo um monólito Laravel modular

Não criar um novo sistema web paralelo para a automação.

Quando a integração existente utilizar Python, Node.js, Playwright, Selenium, Chromium ou outra ferramenta externa, ela deve funcionar como um **runner interno**, acionado pela aplicação Laravel por linha de comando ou serviço controlado.

A interface, autorização, agendamento, persistência, auditoria, logs e gestão das execuções pertencem ao IntegraExpert.

### 4.2. Apache não deve executar robôs dentro de uma requisição web longa

A interface web deve somente:

- validar a solicitação;
- registrar a execução;
- enviar o job para a fila;
- mostrar o andamento.

O acesso ao portal deve ocorrer em um worker de fila executado pelo systemd.

Não manter uma requisição HTTP aberta enquanto um navegador automatizado acessa o portal.

### 4.3. Cada execução deve ser isolada

A unidade mínima de execução deve ser:

**empresa cliente + portal + recurso consultado + período**

Exemplos:

- Empresa A + e-CAC RS + NF-e emitidas + competência 07/2026;
- Empresa A + e-CAC RS + NFC-e emitidas + competência 07/2026;
- Empresa A + Portal Nacional + NFS-e emitidas + competência 07/2026.

Uma falha em uma combinação não pode interromper as demais empresas.

### 4.4. A arquitetura deve aceitar novos portais

Não criar campos como:

- `usa_ecac_rs`;
- `usa_portal_nfse`;
- `senha_portal_x`;
- `senha_portal_y`;

diretamente na tabela `empresas`.

Os portais, recursos, credenciais, certificados e agendas devem ser relacionais e extensíveis.

### 4.5. Credenciais nunca devem aparecer em logs

Senhas, conteúdo do certificado, senha do certificado, cookies, tokens, chaves privadas e cabeçalhos de autenticação devem ser removidos dos logs antes da gravação.

---

## 5. Fase zero — inventário obrigatório do projeto de automação

Antes de alterar o IntegraExpert, analisar o projeto de origem em modo somente leitura.

Criar o documento:

`docs/INVENTARIO_PROJETO_AUTOMACAO_ORIGEM.md`

O inventário deve responder:

### 5.1. Estrutura

- linguagem e framework utilizados;
- comandos para iniciar a aplicação;
- arquivos de entrada;
- arquivos responsáveis pelo e-CAC RS;
- arquivos responsáveis pelo Portal Nacional da NFS-e;
- localização das telas existentes;
- forma atual de salvar certificados;
- forma atual de salvar parâmetros;
- forma atual de registrar resultados;
- banco de dados usado, quando houver;
- variáveis de ambiente;
- diretórios temporários;
- formato dos arquivos baixados.

### 5.2. Dependências

Identificar exatamente:

- versão do Python;
- versão do Node.js, quando aplicável;
- Playwright, Selenium, Puppeteer ou outra ferramenta;
- navegador utilizado;
- drivers instalados;
- bibliotecas para PKCS#12/PFX/P12;
- OpenSSL;
- bibliotecas de HTTP;
- bibliotecas de parsing;
- dependências de sistema operacional;
- pacotes que precisam existir no Docker de desenvolvimento;
- pacotes que precisam existir no servidor nativo de produção.

### 5.3. Fluxo de cada portal

Documentar, passo a passo:

- entrada recebida;
- autenticação;
- seleção da empresa ou representação;
- parâmetros da pesquisa;
- requisições efetuadas;
- páginas acessadas;
- arquivos baixados;
- tratamento de erros;
- saída produzida.

### 5.4. Segurança

Verificar se o projeto de origem:

- grava senha em texto puro;
- mantém certificado em diretório público;
- registra cookies ou tokens;
- usa nomes previsíveis de arquivo;
- deixa arquivos temporários após a execução;
- expõe stack trace ao usuário;
- aceita parâmetros sem validação;
- concatena parâmetros em comandos de shell.

Problemas identificados devem ser corrigidos durante a incorporação, sem destruir a versão funcional antes da validação.

### 5.5. Entrega da fase zero

Ao final, apresentar:

- componentes que serão copiados;
- componentes que serão adaptados;
- componentes que serão descartados;
- dependências novas do IntegraExpert;
- riscos técnicos;
- plano de teste por portal.

Não iniciar refatoração ampla antes de concluir esse inventário.

---

## 6. Arquitetura proposta

Criar um domínio interno de automação fiscal com estas responsabilidades:

### 6.1. Camada de aplicação Laravel

Responsável por:

- cadastro das integrações;
- upload seguro de certificados;
- credenciais;
- seleção dos recursos consultados;
- agendas;
- disparo manual;
- fila;
- status;
- logs;
- arquivos;
- autorização;
- multi-tenancy;
- telas.

Estrutura sugerida:

```text
app/
├── Jobs/
│   └── AutomacaoFiscal/
├── Livewire/
│   └── AutomacaoFiscal/
├── Models/
├── Services/
│   └── AutomacaoFiscal/
│       ├── Contratos/
│       ├── Portais/
│       ├── Runners/
│       ├── Logs/
│       ├── Agendamento/
│       └── ImportacaoEmpresas/
└── Console/
    └── Commands/
```

### 6.2. Camada de drivers de portal

Cada portal deve implementar um contrato comum.

Exemplo conceitual:

```php
interface PortalAutomacao
{
    public function codigo(): string;

    public function validarConfiguracao(EmpresaIntegracao $integracao): ResultadoValidacao;

    public function testarAutenticacao(EmpresaIntegracao $integracao): ResultadoAutenticacao;

    public function executar(SolicitacaoAutomacao $solicitacao): ResultadoAutomacao;
}
```

Drivers iniciais:

```text
app/Services/AutomacaoFiscal/Portais/EcacRsPortal.php
app/Services/AutomacaoFiscal/Portais/NfseNacionalPortal.php
```

Essas classes não devem conter interface Livewire nem regras de apresentação.

### 6.3. Runners externos

Caso o projeto original use Python ou Node.js, organizar os executores em:

```text
scripts/automacao-fiscal/
├── common/
├── ecac-rs/
├── nfse-nacional/
└── runner/
```

A comunicação Laravel → runner deve usar entrada e saída estruturadas em JSON.

O runner deve:

- receber um arquivo JSON de entrada ou JSON pelo `stdin`;
- emitir eventos em JSON;
- retornar código de saída diferente de zero em falha;
- devolver um resultado final estruturado;
- nunca imprimir senha, certificado, cookie ou token;
- aceitar diretório temporário definido pelo Laravel;
- respeitar timeout;
- encerrar corretamente o navegador;
- limpar arquivos temporários sensíveis.

Não montar comandos com concatenação livre de parâmetros. Usar `Symfony Process` ou mecanismo equivalente, com argumentos separados e validados.

---

## 7. Modelo de dados proposto

Os nomes finais podem ser ajustados ao padrão do projeto, mas o modelo conceitual deve ser preservado.

### 7.1. `portais_integracao`

Catálogo global dos portais disponíveis.

Campos mínimos:

- `id`;
- `codigo` único, como `ecac_rs` e `nfse_nacional`;
- `nome`;
- `driver`;
- `ativo`;
- `modos_autenticacao` em JSON;
- `configuracoes_publicas` em JSON;
- timestamps.

Essa tabela é global e não deve usar `BelongsToOperadora`.

### 7.2. `portal_recursos`

Lista das informações que cada portal pode coletar.

Exemplos:

- `nfe_emitidas`;
- `nfce_emitidas`;
- `nfse_emitidas`;
- `nfse_recebidas`;
- `notas_canceladas`;
- `relatorio_resumido`.

Campos:

- `id`;
- `portal_integracao_id`;
- `codigo`;
- `nome`;
- `descricao`;
- `ativo`;
- `parametros_schema` em JSON;
- timestamps.

Criar chave única por portal e código.

### 7.3. `empresa_integracoes`

Vincula a empresa cliente a um portal.

Campos:

- `id`;
- `empresa_operadora_id`;
- `empresa_id`;
- `portal_integracao_id`;
- `ativo`;
- `modo_autenticacao`;
- `certificado_digital_id`, quando aplicável;
- `status_configuracao`;
- `ultima_validacao_em`;
- `ultima_validacao_status`;
- `ultima_validacao_mensagem`;
- `configuracoes` em JSON;
- timestamps.

Criar chave única:

```text
empresa_operadora_id + empresa_id + portal_integracao_id
```

Usar `BelongsToOperadora`.

### 7.4. `empresa_integracao_recursos`

Define o que será buscado para cada cliente em cada portal.

Campos:

- `id`;
- `empresa_operadora_id`;
- `empresa_integracao_id`;
- `portal_recurso_id`;
- `ativo`;
- `agenda_automacao_id`;
- `parametros` em JSON;
- `next_run_at`;
- `last_run_at`;
- timestamps.

Criar chave única por integração e recurso.

Essa tabela permite configurar:

- cliente que consulta somente e-CAC RS;
- cliente que consulta e-CAC RS e Portal Nacional;
- cliente que consulta vários portais;
- recursos diferentes dentro do mesmo portal.

### 7.5. `certificados_digitais`

O certificado pode pertencer ao escritório ou a uma empresa cliente.

Campos:

- `id`;
- `empresa_operadora_id`;
- `empresa_id` nullable;
- `nome`;
- `tipo`, inicialmente `A1`;
- `arquivo_path`;
- `senha_criptografada`;
- `fingerprint`;
- `serial`;
- `titular`;
- `documento_titular`;
- `emissor`;
- `valido_de`;
- `valido_ate`;
- `ativo`;
- `validado_em`;
- `status_validacao`;
- timestamps.

Regras:

- `empresa_id = null`: certificado do escritório/contador;
- `empresa_id preenchido`: certificado próprio do cliente;
- armazenar o arquivo em storage privado por operadora;
- usar nome físico aleatório;
- nunca disponibilizar download do certificado pela interface;
- criptografar a senha com os recursos de criptografia do Laravel;
- registrar somente metadados não sensíveis nos logs.

### 7.6. `empresa_integracao_credenciais`

Credenciais genéricas para portais atuais ou futuros.

Campos:

- `id`;
- `empresa_operadora_id`;
- `empresa_integracao_id`;
- `usuario_criptografado`;
- `segredo_criptografado`;
- `dados_autenticacao_criptografados`;
- `ativo`;
- `validado_em`;
- `status_validacao`;
- timestamps.

Não colocar usuário e senha diretamente na tabela `empresas`.

Na tela de edição:

- nunca retornar a senha salva;
- mostrar apenas “credencial configurada”;
- campo vazio significa preservar o segredo atual;
- permitir substituir ou remover mediante autorização;
- registrar auditoria da alteração sem registrar o valor.

### 7.7. `agendas_automacao`

Agenda reutilizável dentro de um escritório.

Campos:

- `id`;
- `empresa_operadora_id`;
- `nome`;
- `ativo`;
- `timezone`, padrão `America/Sao_Paulo`;
- `frequencia`;
- `intervalo`;
- `dias_semana` em JSON;
- `dias_mes` em JSON;
- `horarios` em JSON;
- `politica_periodo_consulta`;
- `parametros_periodo` em JSON;
- `executar_atrasadas`;
- `limite_execucoes_atrasadas`;
- timestamps.

Frequências mínimas:

- diária;
- dias específicos da semana;
- semanal;
- dias específicos do mês;
- mensal;
- intervalo de N dias;
- manual.

A interface não deve exigir que o usuário escreva uma expressão cron.

### 7.8. `automacao_execucoes`

Log resumido e rastreável de cada execução.

Campos:

- `id`;
- `uuid`;
- `empresa_operadora_id`;
- `empresa_id`;
- `empresa_integracao_id`;
- `portal_recurso_id`;
- `agenda_automacao_id` nullable;
- `solicitado_por_user_id` nullable;
- `gatilho`, como `agendado`, `manual`, `reprocessamento`;
- `periodo_inicio`;
- `periodo_fim`;
- `status`;
- `etapa_atual`;
- `mensagem_usuario`;
- `quantidade_encontrada`;
- `quantidade_importada`;
- `quantidade_ignorada`;
- `quantidade_erros`;
- `iniciada_em`;
- `finalizada_em`;
- `duracao_ms`;
- `tentativa`;
- `idempotency_key`;
- timestamps.

Status mínimos:

- `pendente`;
- `na_fila`;
- `executando`;
- `sucesso`;
- `sucesso_parcial`;
- `falha`;
- `cancelada`.

Criar índice por:

- operadora;
- empresa;
- portal/recurso;
- status;
- data;
- `next_run_at`, nas tabelas de configuração;
- `idempotency_key`.

### 7.9. `automacao_execucao_logs`

Log técnico detalhado.

Campos:

- `id`;
- `empresa_operadora_id`;
- `automacao_execucao_id`;
- `nivel`;
- `etapa`;
- `mensagem`;
- `contexto_sanitizado` em JSON;
- `ocorrido_em`.

Níveis:

- `debug`;
- `info`;
- `warning`;
- `error`.

O contexto deve passar obrigatoriamente por um sanitizador que remova:

- password;
- senha;
- secret;
- token;
- cookie;
- authorization;
- conteúdo de PFX/P12;
- caminhos temporários sensíveis, quando necessário.

### 7.10. `automacao_artefatos`

Arquivos gerados ou baixados.

Campos:

- `id`;
- `empresa_operadora_id`;
- `automacao_execucao_id`;
- `tipo`;
- `nome_original`;
- `storage_path`;
- `mime_type`;
- `tamanho`;
- `hash_sha256`;
- `metadados` em JSON;
- `retencao_ate`;
- timestamps.

Todos os arquivos devem ser gravados usando `OperadoraStorage` ou uma evolução compatível dele.

### 7.11. `importacoes_empresas` e `importacao_empresa_itens`

Registrar importações de cadastro de clientes para permitir:

- prévia;
- validação;
- rastreabilidade;
- erros por linha;
- reprocessamento;
- auditoria.

Não fazer uma importação opaca que somente retorna “concluído”.

---

## 8. Ampliação do cadastro de empresas clientes

Adicionar somente campos cadastrais realmente úteis à automação e à conferência.

Campos sugeridos na tabela `empresas`:

- `razao_social`;
- `nome_fantasia`, mantendo compatibilidade com o campo atual `nome`;
- `cnpj`;
- `inscricao_estadual`;
- `inscricao_municipal`;
- `uf`;
- `codigo_municipio_ibge`;
- `municipio`;
- `ativo`;
- `codigo_sistema`;
- `codigo_conta_banco`;
- timestamps.

Não adicionar senhas, certificados ou indicadores específicos de cada portal nessa tabela.

### 8.1. Tela de empresa

Reorganizar o cadastro em abas:

1. **Dados cadastrais**
2. **Integrações**
3. **Certificados e credenciais**
4. **Agendamentos**
5. **Histórico de execuções**

Na aba **Integrações**, mostrar os portais disponíveis e os recursos de cada portal.

Exemplo:

```text
[✓] e-CAC RS
    [✓] NF-e emitidas
    [✓] NFC-e emitidas
    [ ] Relatório adicional

[✓] Portal Nacional da NFS-e
    [✓] NFS-e emitidas
    [ ] NFS-e recebidas
```

Para cada recurso ativo, permitir:

- selecionar agenda;
- configurar parâmetros específicos;
- executar agora;
- testar configuração;
- visualizar última execução;
- visualizar próxima execução.

---

## 9. Importação de clientes por planilha

Criar uma opção no cadastro de empresas:

**Importar empresas**

Formatos iniciais:

- XLSX;
- XLS;
- CSV.

O IntegraExpert já utiliza PhpSpreadsheet, que deve ser reaproveitado.

### 9.1. Fluxo

1. upload;
2. leitura da planilha;
3. detecção ou mapeamento de colunas;
4. prévia;
5. validação;
6. identificação de novos registros e atualizações;
7. confirmação;
8. gravação;
9. resumo final;
10. download dos erros.

### 9.2. Colunas aceitas

- razão social;
- nome fantasia;
- CNPJ;
- inscrição estadual;
- inscrição municipal;
- UF;
- município;
- código IBGE;
- código no sistema contábil;
- conta bancária contábil;
- ativo;
- habilitar e-CAC RS;
- habilitar NF-e;
- habilitar NFC-e;
- habilitar Portal Nacional;
- habilitar NFS-e;
- nome da agenda padrão.

### 9.3. Segurança da importação

Não importar senha de portal nem senha de certificado em planilha comum.

Certificados e credenciais devem ser configurados pela interface segura após a criação das empresas.

Caso exista uma necessidade futura de importação de segredos, criar um fluxo separado, temporário, auditado e com exclusão imediata do arquivo. Esse fluxo não pertence ao MVP.

### 9.4. Regras de gravação

- localizar empresa pelo CNPJ dentro da operadora;
- permitir `criar`, `atualizar` ou `ignorar`;
- nunca alterar empresa de outra operadora;
- validar CNPJ com `CnpjValido`;
- usar transação;
- gravar os itens com `empresa_operadora_id`;
- evitar `Model::insert()` em models com `BelongsToOperadora`;
- usar `insertMany()` quando houver inserção em lote;
- não apagar configurações existentes quando a célula da planilha estiver vazia;
- apresentar conflitos antes da confirmação.

---

## 10. Central de configurações

Ativar o item **Administração → Configurações** já existente no menu.

Criar uma tela principal:

`/configuracoes/automacao-fiscal`

A tela deve respeitar os papéis e o contexto da operadora.

Abas sugeridas:

### 10.1. Geral

- timezone;
- diretório lógico de retenção;
- período padrão de consulta;
- quantidade máxima de execuções simultâneas;
- política de tentativas;
- retenção de logs técnicos;
- retenção de artefatos;
- aviso de certificado próximo do vencimento.

### 10.2. Portais

- portais disponíveis;
- status;
- dependências;
- versão do driver;
- último teste global;
- recursos fornecidos;
- ativação por operadora, quando aplicável.

### 10.3. Certificados

- certificados do escritório;
- certificados dos clientes;
- titular;
- CNPJ;
- validade;
- status;
- empresas que utilizam o certificado;
- botão para validar;
- alerta de vencimento;
- upload/substituição;
- bloqueio de download.

### 10.4. Agendas

- agendas cadastradas;
- frequência;
- horários;
- clientes vinculados;
- recursos vinculados;
- próxima execução;
- ativar/desativar;
- duplicar agenda;
- execução manual.

### 10.5. Execuções

Log resumido destinado ao usuário final:

- empresa;
- portal;
- recurso;
- período;
- início;
- duração;
- status;
- quantidade de documentos;
- mensagem compreensível;
- link para detalhes permitidos.

### 10.6. Logs técnicos

Visível apenas para `super_admin` ou permissão explícita de suporte.

Mostrar:

- UUID de correlação;
- job da fila;
- tentativas;
- etapa;
- eventos técnicos;
- tempos;
- código de saída do runner;
- exceção sanitizada;
- artefatos de diagnóstico permitidos.

Nunca exibir segredos.

---

## 11. Agendamento e cadência dos robôs

### 11.1. Estratégia

O Laravel Scheduler deve executar apenas um despachante periódico, por exemplo:

```php
Schedule::command('automacoes:despachar')
    ->everyMinute()
    ->withoutOverlapping();
```

O comando deve procurar recursos ativos com:

```text
next_run_at <= agora
```

Para cada item elegível, deve:

1. obter lock;
2. calcular o período;
3. criar `automacao_execucoes`;
4. gerar `idempotency_key`;
5. enviar um job para a fila `automacoes`;
6. calcular e salvar a próxima execução;
7. liberar o lock.

### 11.2. Por que usar um despachante

Não criar uma entrada de cron do sistema operacional para cada empresa.

O cron/systemd deve conhecer somente o Scheduler do Laravel. As agendas dos clientes ficam no MySQL e podem ser alteradas pela interface.

### 11.3. Concorrência

Implementar:

- lock por `empresa_integracao_recurso_id`;
- limite global configurável;
- limite por portal;
- prevenção de duas execuções simultâneas do mesmo recurso;
- timeout por portal;
- tentativas com backoff;
- encerramento seguro do navegador;
- recuperação de execução abandonada.

### 11.4. Política de período

Cada recurso deve definir como o período é calculado.

Opções iniciais:

- dia anterior;
- últimos N dias;
- mês corrente;
- mês anterior;
- competência informada;
- intervalo fixo;
- desde a última execução bem-sucedida.

Para fechamento fiscal, permitir que uma mesma competência seja reconsultada sem duplicar os documentos.

### 11.5. Execução manual

O botão **Executar agora** deve usar o mesmo pipeline do agendamento:

- criar execução;
- enviar job;
- registrar usuário;
- manter log;
- respeitar lock;
- permitir informar período;
- não executar diretamente dentro do Livewire.

---

## 12. Jobs e comandos

Criar, no mínimo:

### 12.1. Comandos

```text
automacoes:despachar
automacoes:executar
automacoes:recalcular-proximas-execucoes
automacoes:limpar-temporarios
automacoes:limpar-logs
certificados:verificar-validade
```

### 12.2. Jobs

```text
ExecutarAutomacaoPortalJob
ValidarCertificadoDigitalJob
TestarIntegracaoPortalJob
ProcessarImportacaoEmpresasJob
LimparTemporariosAutomacaoJob
```

O `ExecutarAutomacaoPortalJob` deve:

1. revalidar tenant e empresa;
2. obter lock;
3. marcar execução como `executando`;
4. resolver o driver;
5. preparar diretório temporário privado;
6. executar o portal;
7. registrar eventos técnicos;
8. gravar artefatos;
9. normalizar o resultado;
10. atualizar quantidades;
11. marcar sucesso, parcial ou falha;
12. limpar dados temporários;
13. liberar lock.

---

## 13. Logs em dois níveis

### 13.1. Log do usuário final

Deve responder perguntas operacionais:

- executou?
- qual empresa?
- qual portal?
- qual período?
- quantos documentos encontrou?
- houve falha?
- o que o usuário precisa fazer?

Exemplos de mensagens:

- “Consulta concluída. Foram localizadas 128 NF-e emitidas.”
- “O certificado digital está vencido. Substitua-o para continuar.”
- “O portal estava indisponível. Uma nova tentativa será realizada.”
- “A autenticação foi concluída, mas o download do relatório falhou.”
- “Não houve documentos no período informado.”

Evitar stack trace e termos internos.

### 13.2. Log técnico de suporte

Deve registrar:

- UUID da execução;
- versão do driver;
- etapas;
- duração de cada etapa;
- tentativas;
- URL base ou identificador de tela, sem parâmetros sensíveis;
- status HTTP permitido;
- seletor ou operação que falhou;
- código de saída;
- exceção;
- stack trace sanitizada;
- versão do navegador e driver;
- metadados dos arquivos;
- host de execução;
- PID, quando útil.

### 13.3. Auditoria

Registrar separadamente ações administrativas:

- upload ou substituição de certificado;
- alteração de credencial;
- ativação/desativação de portal;
- alteração de agenda;
- execução manual;
- cancelamento;
- alteração de parâmetros.

A auditoria registra quem, quando e o que mudou, nunca o valor secreto anterior ou posterior.

### 13.4. Retenção sugerida

Valores iniciais configuráveis:

- execuções resumidas: 24 meses;
- logs técnicos: 90 dias;
- artefatos brutos: conforme necessidade fiscal e contrato;
- temporários: remover ao final ou em rotina diária;
- evidências de erro: retenção menor e controlada.

---

## 14. Segurança de certificados e credenciais

### 14.1. Armazenamento

- storage privado;
- diretório separado por `empresa_operadora_id`;
- subdiretório específico de certificados;
- nomes físicos aleatórios;
- permissão restrita no servidor;
- senha criptografada;
- segredos fora do Git;
- segredos fora do `.env.example`;
- nenhum arquivo em `public/`.

Estrutura lógica sugerida:

```text
storage/app/
└── {empresa_operadora_id}/
    └── automacao-fiscal/
        ├── certificados/
        ├── execucoes/
        ├── temporarios/
        └── artefatos/
```

Evoluir `OperadoraStorage` para suportar esses subdiretórios sem quebrar os fluxos atuais.

### 14.2. Validação no upload

Ao receber um PFX/P12:

- validar extensão e conteúdo;
- limitar tamanho;
- testar abertura com a senha;
- extrair metadados;
- validar data;
- verificar correspondência do titular;
- calcular fingerprint e SHA-256;
- rejeitar certificado inválido;
- remover o upload temporário em caso de erro.

### 14.3. APP_KEY

Como os segredos serão criptografados pelo Laravel:

- manter backup seguro da `APP_KEY`;
- não regenerar a chave em produção;
- documentar procedimento de recuperação;
- considerar rotação planejada no futuro.

Perder a `APP_KEY` significa perder a capacidade de descriptografar credenciais.

### 14.4. Interface

- não exibir senha;
- não permitir download do certificado;
- não preencher segredo salvo no HTML;
- usar confirmação para remover;
- aplicar autorização no backend;
- registrar auditoria;
- exigir HTTPS;
- desabilitar `APP_DEBUG` em produção.

---

## 15. Migração das integrações existentes

Para cada portal, seguir o mesmo processo.

### 15.1. e-CAC RS

1. localizar o código funcional no projeto de origem;
2. reproduzir o teste atual sem alteração;
3. identificar entradas e saídas;
4. separar autenticação, consulta, download e parsing;
5. mover o código para o driver/runner do IntegraExpert;
6. substituir storage local pelo storage por operadora;
7. substituir configuração local por `empresa_integracoes`;
8. substituir logs livres pelos dois níveis de log;
9. acionar somente via job;
10. validar com certificado do escritório;
11. validar seleção de empresas autorizadas;
12. validar NF-e;
13. validar NFC-e;
14. validar período sem movimento;
15. validar certificado vencido;
16. validar indisponibilidade do portal.

### 15.2. Portal Nacional da NFS-e

1. localizar o código funcional;
2. preservar o login que já foi validado;
3. identificar certificado usado;
4. identificar parâmetros de pesquisa;
5. separar autenticação, consulta, download e parsing;
6. encapsular no driver;
7. registrar NFS-e emitidas;
8. validar cliente com certificado próprio;
9. validar período sem notas;
10. validar falha de autenticação;
11. validar alteração de sessão;
12. validar download e integridade dos arquivos.

### 15.3. Regra de transição

Durante a incorporação:

- não apagar o projeto de origem;
- não alterar simultaneamente origem e destino sem rastreabilidade;
- manter exemplos sanitizados;
- comparar a saída dos dois projetos;
- considerar a integração migrada somente após resultados equivalentes.

---

## 16. Normalização dos dados coletados

O download bruto deve ser preservado, mas o resultado também deve ser normalizado no MySQL.

Criar, em etapa própria, uma estrutura como:

`documentos_fiscais`

Campos conceituais:

- operadora;
- empresa;
- portal;
- recurso;
- execução;
- tipo de documento;
- chave de acesso;
- identificador externo;
- número;
- série;
- modelo;
- data de emissão;
- competência;
- CNPJ/CPF do emitente;
- CNPJ/CPF do destinatário;
- valor;
- situação;
- cancelado em;
- dados complementares;
- hash do registro;
- timestamps.

Regras:

- usar chave de acesso quando disponível;
- para NFS-e sem chave padrão, usar identificador externo e chave composta;
- fazer `upsert` idempotente;
- guardar origem;
- não duplicar documento a cada nova varredura;
- registrar alterações de situação;
- manter vínculo com a execução que atualizou o documento.

---

## 17. Painel operacional e futura conferência fiscal

### 17.1. Painel operacional inicial

Criar uma visão por competência e empresa:

- empresas configuradas;
- portais ativos;
- recursos ativos;
- última execução;
- próxima execução;
- status;
- quantidade encontrada;
- certificados próximos do vencimento;
- configurações incompletas;
- falhas que exigem ação.

Status visuais:

- não configurado;
- pronto;
- agendado;
- executando;
- concluído;
- concluído com alerta;
- falhou;
- certificado vencido.

### 17.2. Conferência fiscal

A conferência com a contabilidade deve ser implementada após a coleta estar estável e existir uma fonte contábil importável.

Fluxo futuro:

**Coleta do portal → Importação do relatório contábil → Normalização → Comparação → Divergências → Justificativa → Fechamento**

Comparações iniciais:

- documento no portal e ausente na contabilidade;
- documento na contabilidade e ausente no portal;
- valor divergente;
- situação divergente;
- documento cancelado contabilizado como ativo;
- diferença de número, série, modelo ou data;
- total por tipo de documento;
- total por competência.

A estrutura criada nesta implementação deve permitir essa evolução sem refazer certificados, portais, logs ou agendamento.

---

## 18. Multi-tenancy e autorização

Todas as novas entidades de negócio devem:

- possuir `empresa_operadora_id`;
- usar `BelongsToOperadora`, quando aplicável;
- respeitar `OperadoraContext`;
- usar `EmpresaDoEscritorio` ou `OperadoraContext::resolveEmpresa()`;
- impedir IDOR/BOLA;
- separar arquivos por operadora;
- impedir um escritório de visualizar certificados, credenciais, execuções e logs de outro;
- bloquear criação pelo `super_admin` sem operadora selecionada;
- possuir testes de isolamento.

Exceções globais:

- `portais_integracao`;
- `portal_recursos`.

Papéis sugeridos:

- `operador`: visualizar painel e iniciar execução quando permitido;
- `gerente`: configurar empresas, integrações e agendas;
- `admin`: administrar certificados e configurações do escritório;
- `super_admin`: suporte técnico global e catálogo de portais.

Para logs técnicos, preferir permissão específica em vez de depender somente do nome do papel.

---

## 19. Infraestrutura de desenvolvimento

O desenvolvimento continua em Docker Compose.

Atualizar o `Dockerfile` somente após o inventário do projeto de origem.

Adicionar as dependências exatas usadas pelos robôs, como:

- navegador headless;
- bibliotecas de sistema;
- OpenSSL;
- biblioteca Python ou Node;
- driver correspondente;
- fontes ou certificados de CA estritamente necessários.

Não instalar simultaneamente Playwright, Selenium e Puppeteer sem necessidade.

Comandos de desenvolvimento devem usar:

```bash
docker compose exec app composer install
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose exec app php artisan queue:work database --queue=automacoes,default
docker compose exec app php artisan schedule:work
```

Adicionar um serviço de worker no `docker-compose.yml` somente se isso melhorar o fluxo local sem tornar a app dependente de Docker em produção.

Criar documentação dos comandos dos runners.

---

## 20. Infraestrutura de produção

A produção continua nativa:

- Ubuntu/Debian;
- Apache;
- PHP-FPM;
- MySQL;
- Python/Node, conforme inventário;
- navegador headless, quando necessário;
- systemd;
- sem container para servir a aplicação.

### 20.1. Apache

O Apache apenas atende a aplicação Laravel.

Não utilizar CGI ou requisição longa para executar os robôs.

### 20.2. Worker de fila

Criar serviço systemd semelhante a:

```text
integraexpert-queue-automacoes.service
```

Comando conceitual:

```bash
php artisan queue:work database \
  --queue=automacoes,default \
  --sleep=3 \
  --tries=3 \
  --timeout=900 \
  --max-time=3600
```

Os valores finais dependem do tempo real de cada portal.

Configurar reinício automático e usuário `www-data`, respeitando o padrão de produção do projeto.

### 20.3. Scheduler

Criar serviço systemd para:

```bash
php artisan schedule:work
```

Ou usar timer systemd equivalente, conforme o padrão já adotado no servidor.

Não criar agendas individuais no cron do Linux.

### 20.4. Diretórios e permissões

Garantir:

- `storage` gravável pelo worker;
- certificados legíveis somente pelo usuário da aplicação;
- navegador com diretório temporário isolado;
- limpeza de perfis temporários;
- espaço em disco monitorado;
- logs do systemd com rotação;
- nenhuma senha em argumentos de processo visíveis no `ps`.

### 20.5. Deploy

Evoluir `atualizar-producao.sh` para:

- instalar dependências PHP;
- instalar dependências Node somente quando necessário;
- validar dependências dos runners;
- executar migrations;
- gerar assets;
- limpar e recriar caches;
- reiniciar PHP-FPM quando aplicável;
- reiniciar workers da automação;
- validar Scheduler;
- executar diagnóstico sem acessar portais reais automaticamente.

Não incluir a instalação destrutiva de navegador ou pacotes do sistema em todo deploy. Dependências de SO devem ter instalador idempotente separado.

---

## 21. Tratamento de erros

Criar um catálogo de erros de domínio.

Exemplos:

- `CERTIFICADO_INVALIDO`;
- `CERTIFICADO_VENCIDO`;
- `CERTIFICADO_SENHA_INCORRETA`;
- `PORTAL_INDISPONIVEL`;
- `AUTENTICACAO_FALHOU`;
- `EMPRESA_NAO_AUTORIZADA`;
- `SESSAO_EXPIRADA`;
- `PARAMETRO_INVALIDO`;
- `DOWNLOAD_FALHOU`;
- `ARQUIVO_INVALIDO`;
- `PARSER_FALHOU`;
- `TIMEOUT`;
- `NAVEGADOR_ENCERROU`;
- `EXECUCAO_DUPLICADA`;
- `CONFIGURACAO_INCOMPLETA`.

Cada código deve possuir:

- mensagem para usuário;
- orientação;
- nível;
- possibilidade de nova tentativa;
- detalhes técnicos separados.

---

## 22. Testes obrigatórios

### 22.1. Unitários

- cálculo de próxima execução;
- cálculo de período;
- sanitização de logs;
- geração de `idempotency_key`;
- validação de configuração;
- resolução do driver;
- parser de retorno;
- criptografia/casts;
- importação e mapeamento de planilha.

### 22.2. Feature

- isolamento entre operadoras;
- autorização por papel;
- upload de certificado;
- senha não retornada;
- ativação de recursos;
- agendamento;
- execução manual;
- importação de empresas;
- log resumido;
- bloqueio de log técnico;
- download de artefato autorizado;
- impedimento de acesso cruzado.

### 22.3. Integração dos portais

Preferir fixtures e respostas sanitizadas para testes automatizados.

Os testes reais contra portais devem ser separados, manuais ou controlados, porque:

- dependem de certificado;
- podem bloquear por excesso de acesso;
- podem mudar sem aviso;
- não devem rodar em toda pipeline.

### 22.4. Produção

Criar comando de diagnóstico:

```text
automacoes:diagnostico
```

Ele deve verificar:

- dependências;
- navegador;
- permissão do storage;
- OpenSSL;
- fila;
- Scheduler;
- banco;
- diretórios;
- configuração dos drivers;

sem utilizar certificado real e sem executar consultas fiscais.

---

## 23. Fases de implementação

### Fase 0 — inventário

- analisar origem;
- documentar dependências;
- documentar fluxos;
- identificar riscos;
- definir estratégia de migração.

**Aceite:** inventário aprovado e integrações reproduzíveis no projeto de origem.

### Fase 1 — fundação de dados e segurança

- migrations;
- models;
- relações;
- criptografia;
- storage;
- catálogo de portais;
- recursos;
- seeders idempotentes;
- testes de tenant.

**Aceite:** estrutura criada sem integração real e com isolamento validado.

### Fase 2 — cadastro e configurações

- ampliar empresas;
- abas de empresa;
- central de configurações;
- certificados;
- credenciais;
- recursos;
- agendas;
- permissões.

**Aceite:** é possível configurar um cliente de ponta a ponta sem executar portal.

### Fase 3 — importação de empresas

- upload;
- mapeamento;
- prévia;
- validação;
- gravação;
- erros;
- criação de vínculos de portal sem segredos.

**Aceite:** planilha cria/atualiza clientes dentro do tenant sem duplicar CNPJ.

### Fase 4 — fila, agenda e logs

- comandos;
- jobs;
- dispatcher;
- locks;
- logs em dois níveis;
- execução manual;
- systemd de desenvolvimento/documentação.

**Aceite:** driver de teste simulado executa por agenda e manualmente.

### Fase 5 — migração do e-CAC RS

- incorporar código;
- configurar certificado;
- consultar NF-e;
- consultar NFC-e;
- armazenar resultado;
- comparar saída com projeto de origem.

**Aceite:** resultados equivalentes e rastreáveis.

### Fase 6 — migração do Portal Nacional da NFS-e

- incorporar login;
- configurar certificado;
- consultar notas;
- armazenar resultado;
- comparar saída.

**Aceite:** resultados equivalentes e rastreáveis.

### Fase 7 — normalização e painel operacional

- documentos fiscais;
- idempotência;
- totais;
- dashboard;
- alertas;
- vencimento de certificados.

**Aceite:** gestor visualiza situação de todas as empresas do escritório.

### Fase 8 — produção

- instalador de dependências;
- worker systemd;
- Scheduler;
- permissões;
- backup;
- monitoramento;
- deploy;
- rollback;
- piloto com poucas empresas.

**Aceite:** piloto executado sem exposição de segredos e sem intervenção manual no servidor.

### Fase 9 — conferência com a contabilidade

- definir arquivo de origem do Domínio;
- importar;
- comparar;
- divergências;
- justificativas;
- fechamento.

**Aceite:** painel aponta diferenças reproduzíveis entre portal e contabilidade.

---

## 24. Estratégia de piloto

Não ativar todas as empresas no primeiro deploy.

Ordem recomendada:

1. uma empresa de teste;
2. uma empresa real com e-CAC RS;
3. uma empresa real com Portal Nacional;
4. uma empresa usando os dois;
5. cinco empresas;
6. lote maior.

No piloto, medir:

- duração;
- taxa de sucesso;
- falhas por portal;
- consumo de memória;
- consumo de disco;
- bloqueios;
- vencimento de sessão;
- quantidade de documentos;
- reprocessamento;
- duplicidade;
- tempo de suporte.

---

## 25. Backup e recuperação

Antes da produção:

- backup do MySQL;
- backup criptografado dos certificados;
- backup seguro da `APP_KEY`;
- teste de restauração;
- política para artefatos;
- documentação de troca de servidor.

O backup de banco contendo credenciais criptografadas não é suficiente sem a `APP_KEY`.

Os certificados não devem ficar apenas no servidor sem cópia segura e controlada.

---

## 26. Observabilidade

Registrar métricas mínimas:

- execuções por portal;
- sucesso;
- falha;
- duração;
- documentos encontrados;
- tentativas;
- fila pendente;
- execução travada;
- certificado a vencer;
- espaço em disco;
- worker ativo;
- Scheduler ativo.

No início, essas métricas podem ser calculadas pelo MySQL e exibidas no painel. Não é obrigatório introduzir uma plataforma de observabilidade nova nesta fase.

---

## 27. Itens fora do escopo inicial

Não implementar junto, salvo decisão específica:

- cobrança automática por quantidade de consultas;
- Redis/Horizon obrigatório;
- microsserviços;
- Kubernetes;
- API pública;
- automação de todos os portais municipais;
- importação de senha por planilha comum;
- acesso irrestrito a logs técnicos;
- painel contábil completo antes da estabilização da coleta;
- exclusão do projeto de origem antes da homologação;
- rotação automática da `APP_KEY`;
- MFA próprio do IntegraExpert dentro desta mesma entrega.

---

## 28. Entregáveis obrigatórios no repositório

1. `docs/INVENTARIO_PROJETO_AUTOMACAO_ORIGEM.md`;
2. este plano versionado em `docs/PLANO_IMPLEMENTACAO_AUTOMACAO_FISCAL.md`;
3. migrations;
4. models e relações;
5. seeders de portais e recursos;
6. services e contratos;
7. drivers;
8. runners incorporados;
9. componentes Livewire;
10. telas;
11. jobs;
12. comandos;
13. testes;
14. instalador idempotente de dependências do runner;
15. units do systemd;
16. atualização do deploy;
17. documentação de operação;
18. documentação de solução de problemas;
19. checklist de segurança;
20. relatório de homologação por portal.

---

## 29. Critérios gerais de aceite

A implementação somente é considerada pronta quando:

- o projeto de origem estiver inventariado;
- e-CAC RS e Portal Nacional funcionarem dentro do IntegraExpert;
- certificados estiverem privados e criptografados;
- nenhum segredo aparecer em log;
- cada cliente puder habilitar portais e recursos diferentes;
- cada recurso puder ter agenda;
- existir execução manual;
- as execuções ocorrerem pela fila;
- o Scheduler apenas despachar tarefas;
- houver logs resumidos e técnicos;
- o suporte conseguir localizar uma falha por UUID;
- a importação de empresas possuir prévia e validação;
- o isolamento multi-tenant estiver coberto por testes;
- o ambiente Docker reproduzir a automação;
- a produção nativa possuir dependências documentadas;
- worker e Scheduler forem gerenciados pelo systemd;
- o deploy possuir rollback;
- a coleta for idempotente;
- uma nova varredura não duplicar documentos;
- o piloto for concluído.

---

## 30. Instruções de execução para o agente no Cursor

Ao iniciar este trabalho:

1. leia as regras em `.cursor/rules/`;
2. confirme o HEAD atual da branch `master`;
3. não assuma que este documento reflete commits posteriores à data-base;
4. tenha acesso simultâneo ao IntegraExpert e ao projeto de automação de origem;
5. execute primeiro a Fase 0;
6. não altere produção;
7. não commite nem faça push sem autorização;
8. não copie credenciais ou certificados reais para o repositório;
9. use dados sanitizados nos testes;
10. apresente o inventário antes das migrations;
11. implemente uma fase por vez;
12. após cada fase, execute os testes no Docker;
13. registre arquivos alterados e decisões;
14. evite refatorações fora do escopo;
15. preserve os fluxos bancários existentes do IntegraExpert;
16. preserve o multi-tenancy;
17. não substituir uma integração funcional sem comparação de resultados;
18. não usar a requisição web para processamento longo;
19. não criar colunas específicas de portal na tabela `empresas`;
20. não usar `Model::insert()` em models com `BelongsToOperadora`;
21. não expor senha em propriedades preenchidas da interface;
22. não instalar dependências extras sem justificar;
23. não ativar robôs em massa no primeiro deploy;
24. produzir um resumo ao final de cada fase com:
    - implementado;
    - testes;
    - pendências;
    - riscos;
    - próximos passos.

---

## 31. Recomendação final de arquitetura

A melhor estrutura para o IntegraExpert é:

- **empresas** guardam os dados cadastrais;
- **portais** definem integrações disponíveis;
- **recursos** definem o que cada portal fornece;
- **empresa_integracoes** vinculam clientes aos portais;
- **empresa_integracao_recursos** definem o que buscar;
- **certificados e credenciais** ficam separados e protegidos;
- **agendas** definem a cadência;
- **Scheduler** identifica tarefas vencidas;
- **fila** executa cada cliente de forma isolada;
- **drivers/runners** acessam os portais;
- **execuções e logs** garantem rastreabilidade;
- **artefatos e documentos normalizados** alimentam o painel;
- **conferência fiscal** compara o portal com a contabilidade.

Essa estrutura evita que o IntegraExpert se transforme em uma coleção de scripts e permite adicionar novos portais sem reconstruir o cadastro de clientes ou o mecanismo de agendamento.
