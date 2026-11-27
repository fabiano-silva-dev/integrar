# IntegraExpert | Módulo de emissão de NFS-e

**Especificação funcional e técnica — versão 1.0 — 27/11/2026**  
**Repositório:** `fabiano-silva-dev/integrar` · branch `master`  
**Situação:** proposta para aprovação e desenvolvimento; nenhuma alteração de código ou produção realizada.

## 1. Objetivo e escopo

Criar uma central de emissão de notas fiscais de serviço para escritórios contábeis, com operação multiempresa, utilizando o Portal Nacional da NFS-e e, preferencialmente quando homologada, a API oficial da SEFIN Nacional. A plataforma deve reduzir a digitação repetitiva e permitir emissão avulsa, importação de planilhas com layouts reutilizáveis, recorrências e emissão em lote com conferência prévia.

Princípio operacional: **Selecionar empresa → Preparar notas → Conferir → Emitir → Acompanhar**. O operador não precisa acessar o portal a cada nota nem preencher novamente dados já conhecidos. A aprovação deve ser simples, mas toda transmissão fiscal precisa ser explícita, rastreável e protegida contra duplicidade.

O MVP contempla NFS-e de serviços nacionais com prestador sendo a empresa cliente, conforme as situações fiscais efetivamente homologadas. Não inclui emissão por municípios sem suporte ao emissor nacional, faturamento financeiro, cobrança, ERP completo, importação de serviços ou emissão automática sem aprovação. Cenários fiscais complexos devem ser bloqueados até validação específica, não preenchidos com valores presumidos.

## 2. Diagnóstico do IntegraExpert e decisão de integração

Consulta ao código em `master` e aos resultados de busca no snapshot `94fadb4e1fc4b27a7d7d48ea39cd9097fd39b0d8`. Confirmado por inspeção de código, sem teste de acesso real:

- `app/Models/Empresa.php`: cadastro existente de empresas clientes, associado à operadora, integrações e certificados. Não criar novo cadastro de prestador.
- `app/Services/AutomacaoFiscal/Portais/NfseNacionalPortal.php`: valida certificado A1, aciona `NodeRunnerBridge`, registra eventos e importa extratos. Mapeia validação e extração de notas, não emissão; operação desconhecida atualmente cai em validação de acesso.
- `scripts/automacao-fiscal/runner/src/portals/nfse-emissor/NfseEmissorAdapter.ts`: suporta `extract-nfse`, e valida acesso por descoberta/certificado; operações não implementadas são rejeitadas. `NfseEmissorCertificateFlow.ts` é a base de autenticação do navegador.
- `app/Services/AutomacaoFiscal/Sefaz/NfseSefinNacionalClient.php`: já faz `GET /nfse/{chaveAcesso}` com mTLS, materialização temporária do certificado, verificação TLS, tratamento HTTP e descompactação XML GZip/Base64. Ainda não possui transmissão de DPS. Reaproveitar transporte, certificado e decodificação; separar a interpretação de respostas de emissão e reforçar a validação de identidade do XML.
- `NfseXmlDownloadService`, `NfseDanfseGenerator`, `BaixarNfseXmlJob` e `DocumentoFiscal`: estruturas existentes de documentos fiscais, download XML, DANFSe e processamento em fila; avaliar integração sem duplicar o acervo fiscal.
- `app/Livewire/ImportadorPersonalizado.php` e `ImportadorAvancado.php`: referências para UX de mapeamento e leitura de arquivos; avaliar extrair componentes genéricos, sem reutilizar regras de amarração bancária no fiscal. Existe também `Terceiro`, cuja compatibilidade com tomadores deve ser analisada antes da migration.

### 2.1. API oficial já disponível

A documentação do Sistema Nacional apresenta `POST /nfse` para receber a Declaração de Prestação de Serviço (DPS) e gerar uma NFS-e de forma síncrona, ou retornar rejeição. Há consulta de NFS-e por chave, consulta por identificador de DPS (`GET /dps/{id}` e `HEAD /dps/{id}`), parâmetros municipais e registro de eventos. A API permite que o IntegraExpert implemente um lote operacional por meio de transmissões individuais, sem presumir a existência de endpoint de lote nativo.

A utilização depende de elegibilidade do contribuinte/município, credenciamento quando exigido e certificado aceito. Para o MVP, utilizar certificado A1 autorizado da própria empresa, sem presumir que o certificado do escritório permite emitir por qualquer cliente. Validar leiaute/XSD, assinatura XML, envelope de transmissão, parâmetros tributários e credenciamento com a documentação e Swagger vigentes.

Ambientes oficiais (consultados em 27/11/2026):
- Produção: https://sefin.nfse.gov.br/SefinNacional/docs/index
- Produção restrita: https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional/docs/index

**Recomendação:** começar por uma prova de conceito da API direta, pois o mTLS já existe. Se a prova for aprovada, usar a API como driver principal desde o MVP. Manter o portal/Playwright como alternativa de evolução, apenas se necessário e permitido, reaproveitando login e runner. Se a API não estiver disponível para uma empresa, informar o motivo e permitir fluxo manual autorizado; não fazer fallback automático após tentativa ambígua de transmissão. Não contornar CAPTCHA ou controles de acesso.

## 3. Modelo de negócio e cadastros

Hierarquia: Escritório (`empresas_operadoras`) → Empresa cliente/prestador (`empresas`) → Tomadores de serviço, serviços, recorrências, layouts, lotes e notas. Exemplo: Escritório Alfa atende Clínica Exemplo Ltda.; a clínica presta serviço a Maria e a Empresa Beta. As notas são emitidas pela clínica para seus tomadores, nunca pelo escritório, salvo quando o próprio escritório for cadastrado como prestador autorizado.

### 3.1. Habilitação e configuração por empresa prestadora

Na empresa existente, criar aba **Emissão de NFS-e** com habilitação independente da coleta de documentos, situação de acesso, ambiente, driver homologado, integração/certificado referenciado e validade, identificação fiscal do prestador, inscrição municipal, município IBGE, regime tributário (incluindo Simples/MEI quando aplicável), regime especial, série DPS e configuração de sequência, quando exigida pela API.

Permitir serviço padrão, município de prestação padrão, forma de competência e parâmetros fiscais padrões explícitos. Exibir somente campos pertinentes ao regime e cenário; regras fiscais devem ser validadas por profissional e por parâmetros oficiais. Credenciais, dados sensíveis e configuração de homologação não devem aparecer em logs. Botão **Testar configuração** executa validações não emissoras; não confundir acesso bem-sucedido com autorização de emissão. A habilitação em produção exige permissão e homologação.

### 3.2. Tomadores (clientes das empresas)

CRUD por empresa: tipo PF/PJ (exterior apenas quando suportado), CPF/CNPJ normalizado, nome/razão social, fantasia opcional, e-mail, telefone, endereço (CEP, logradouro, número, complemento, bairro, município IBGE, UF, país), inscrição municipal quando aplicável, código externo para importação, observações e situação ativo/inativo. Campos obrigatórios variam por cenário e versão fiscal.

Pesquisa por nome, documento e código externo, criação rápida durante emissão e importação. Documento único por empresa para tomadores identificados; mesmo CPF/CNPJ pode existir em empresas diferentes, com dados próprios. Nunca compartilhar automaticamente dados entre empresas. Permitir inativação sem apagar histórico. Avaliar reaproveitar `Terceiro` como referência cadastral, sem misturar sua finalidade contábil; decisão final após inspecionar schema e vínculos.

### 3.3. Catálogo de serviços da empresa

Cadastro por prestador: nome curto, código interno, descrição padrão, código de tributação nacional, código municipal quando exigido, NBS quando aplicável, município/local de prestação e incidência segundo regra fiscal, natureza/exigibilidade, retenção ISS, alíquota quando pertinente, deduções e tributos federais/IBS/CBS conforme leiaute vigente e situação fiscal. Valor padrão opcional, unidade se pertinente, vigência, ativo/inativo.

Permitir descrição com variáveis controladas de competência (ex.: `{mes}/{ano}`), prévia do texto e alteração por nota. O padrão da empresa facilita preenchimento, o serviço define parâmetros do tipo de prestação e o vínculo com tomador pode sobrescrever descrição/valor autorizados. Não calcular ou presumir impostos sem regra validada. Alterações cadastrais não modificam notas antigas.

### 3.4. Serviços recorrentes por tomador

Um tomador pode possuir diversos vínculos de serviço. Campos: serviço, valor contratado, descrição específica, periodicidade (mensal inicialmente), início/fim, dia previsto de emissão, data de competência padrão, código de contrato/referência, status ativo/pausado e observações. Permitir pausa por competência, reajuste com vigência, geração de rascunhos e alteração pontual do valor sem alterar o contrato padrão. Recorrência não é cobrança nem emissão automática no MVP.

## 4. Navegação e experiência operacional

Novo menu **Emissão de NFS-e**, separado da coleta em Automação Fiscal: Visão geral; Central de emissão; Notas emitidas; Tomadores; Serviços; Recorrências; Layouts de importação; Configurações. Pode usar abas em vez de novos menus se o padrão existente favorecer navegação mais enxuta.

### 4.1. Visão geral do escritório

Competência padrão atual, com troca de período e filtro por empresa. Indicadores clicáveis: empresas habilitadas, notas a conferir, prontas, em processamento, emitidas, rejeitadas, pendentes de confirmação e total de serviços autorizados no período. Listagem por empresa com CNPJ, status de certificado, origem das demandas, quantidade prevista (somente recorrências/planilhas já conhecidas), pendências, emitidas e valor autorizado. Não confundir valor de serviço com faturamento do escritório.

Atalhos: **Nova nota**, **Importar planilha**, **Gerar recorrências**, **Continuar conferência**. A empresa selecionada globalmente deve preencher o contexto, mas toda tela de emissão deve mostrar prestador e CNPJ com clareza. Visão global permite abrir qualquer empresa autorizada; lote inicialmente limitado a um prestador.

### 4.2. Emissão avulsa

1. Escolher empresa (já selecionada por padrão), clicar em Nova nota.
2. Pesquisar tomador; se novo, cadastrar em modal. Selecionar serviço, confirmar competência, descrição e valor. Se houver vínculo recorrente, oferecer preenchimento automático.
3. Mostrar resumo fiscal e identificação do prestador/tomador; clicar **Emitir NFS-e**, com confirmação compacta e explícita. Oferecer **Salvar rascunho** e **Emitir e preparar outra**.

Formulário compacto com campos frequentes visíveis, tributação em bloco expansível e validações em linha. Em caso de sucesso, número, chave, valor, XML e DANFSe; em caso de rejeição, indicar campo/motivo e permitir corrigir. Meta de UX: até três ações principais depois da seleção da empresa, sem contar digitação/pesquisa e eventual cadastro novo; não sacrificar confirmação fiscal.

### 4.3. Importação de planilhas e layouts reutilizáveis

Aceitar XLSX, XLS e CSV (com leitores seguros), selecionar empresa e competência. No primeiro envio, assistente de configuração: escolher aba, linha do cabeçalho e primeira linha de dados; mapear documento/nome/código externo do tomador, código ou nome do serviço, descrição, valor, competência, referência externa e campos fiscais permitidos. Permitir valores fixos por layout e transformações declarativas (datas, decimal brasileiro, espaços, zeros à esquerda, concatenar texto), sem executar fórmulas/macros ou código do usuário.

Salvar layout com nome, versão, empresa, assinatura de cabeçalho, mapeamentos, formatos e valores padrão. Prévia de algumas linhas antes de salvar. Layouts pertencem à empresa e podem ser copiados explicitamente para outra empresa da mesma operadora, sem dados de tomadores nem credenciais. Mudança do cabeçalho deve exigir remapeamento, nunca trocar colunas silenciosamente. Em importações seguintes: escolher layout → enviar arquivo → conferir → emitir selecionadas.

Relacionar tomador por CPF/CNPJ normalizado ou código externo vinculado, serviço por código mapeado; nomes aproximados apenas como sugestão a confirmar. Tomador novo: permitir criação em massa explícita após validação de dados mínimos; impedir cadastro por mera semelhança de nome. Linhas vazias, totais e cabeçalhos repetidos são ignorados conforme regra explícita do layout, com contagem de descartes. CPF/CNPJ importados como número precisam ser tratados sem truncar/alterar identidades; valores inválidos ficam pendentes.

Cada importação guarda arquivo original privado, hash, layout/versão, competência, operador, linhas de origem, resultado e erros. Padrão inicial: uma linha = uma nota; agrupamento de várias linhas por referência somente em evolução específica, sem juntar serviços incompatíveis. Reimportação exata sinalizada; notas já autorizadas nunca são sobrescritas. Permitir novo upload corrigido, conciliando referências e rascunhos sem reemitir automaticamente.

### 4.4. Geração por recorrência

Selecionar empresa e competência, listar vínculos ativos no período e gerar rascunhos. Mostrar valor atual, valor anterior, descrição, dia previsto e status de emissão. Permitir marcar/desmarcar, alterar valor/descrição para o mês, pausar item e incluir nota avulsa. A geração é idempotente por vínculo e competência; uma nova geração não recria notas já existentes, salvo ocorrência adicional explicitamente identificada. Opcionalmente agendar geração de rascunhos e aviso de pendências, sem transmitir automaticamente.

## 5. Tela única de pré-conferência e lote

Todas as origens convergem para uma grade editável de rascunhos. Cabeçalho fixo: empresa/CNPJ, competência, origem, lote, certificado e ambiente. Indicadores: quantidade importada/gerada, prontas, alertas, erros impeditivos, selecionadas e soma dos valores. Total selecionado atualizado conforme seleção/edição, sem arredondamento binário.

Colunas propostas: seleção, status, tomador, CPF/CNPJ, serviço, competência, descrição resumida, valor, retenção ISS (quando pertinente), diferença em relação ao mês anterior, referência e ações. Filtros por status/tomador/serviço e pesquisa. Linha expansível ou modal para dados completos; edição rápida de valor, descrição e competência; alterações fiscais protegidas por permissão. Paginação/virtualização conforme volume, seleção explícita de página ou de todos os resultados elegíveis.

Estados visuais com texto e ícones: **Pronta**, **Atenção**, **Impedimento**, **Já emitida**. Cor é complementar. Alertas: variação de valor, possível duplicidade, tomador novo, alteração de serviço ou dados fiscais. Impedimentos: identificação inválida, valor não permitido, dados obrigatórios ausentes, competência inválida, serviço não homologado, certificado inválido, empresa não elegível, incompatibilidade de tributação. Exigir resolução ou reconhecimento explícito dos alertas permitidos; erros nunca são ignorados.

Ações: validar novamente, editar em massa campos permitidos, criar tomadores pendentes, excluir rascunho (auditado), exportar relatório de conferência, selecionar prontas, emitir selecionadas. Confirmação apresenta CNPJ, competência, quantidade e valor total; dados são congelados em snapshot no momento da aprovação. Se algo mudar antes de transmitir, revalidar e exigir nova aprovação se o conteúdo fiscal mudou.

Exemplo ilustrativo: planilha de 50 notas, 45 prontas, 3 com alertas e 2 impedidas. O usuário resolve os alertas e emite as 48 aptas; as outras duas permanecem disponíveis para correção. O lote não é uma transação fiscal atômica: cada nota tem resultado independente.

## 6. Processamento, estados e prevenção de duplicidade

Criar lote e itens em transação local; registrar usuário, horário, versão fiscal, driver e ambiente. Despachar jobs após commit; fila dedicada ou segregada de emissão, reaproveitando infraestrutura Laravel existente. Processamento sequencial por prestador/série no início, concorrência controlada entre prestadores, backoff para indisponibilidade e circuit breaker para erro sistêmico. Não bloquear a navegação do operador.

Estados da nota: rascunho → pronta/conferida → aprovada → enfileirada → transmitindo → autorizada ou rejeitada ou pendente de confirmação. Estados adicionais: impedida, descartada antes de transmissão, cancelada e substituída (quando implementados). Lote: preparando, aguardando conferência, em processamento, concluído, concluído com pendências, interrompido. Estado derivado dos itens, não de mero retorno HTTP.

DPS: reservar série/número e identificador de forma atômica quando aplicável, persistir antes do envio e não reutilizar em outra operação. Verificar convivência com outros emissores e regra oficial de numeração; escolher série exclusiva se permitido. Armazenar payload aprovado, hash, tentativas, correlação externa, resposta protegida e timestamps. Usar chave idempotente interna por operação, restrições únicas de banco e locks por nota/empresa/série. Mesmo clique duplo, reenvio de job, sessão concorrente ou queda de worker não pode gerar duas transmissões independentes da mesma intenção.

Se houver timeout, desconexão ou resposta incompleta após início da transmissão, marcar **Pendente de confirmação**, não rejeitada. Consultar DPS/nota na API ou evidência inequívoca do portal; só permitir reenvio seguro após reconciliação e conforme contrato oficial. Não criar nova DPS para contornar resposta desconhecida. Para portal sem identificador confiável, exigir intervenção manual em vez de reenviar por similaridade. Erros de validação definitivos permitem correção com nova aprovação e tratamento da DPS conforme regra fiscal vigente. Erro de rede anterior a envio confirmado pode ser retentado com o mesmo identificador quando seguro.

Cancelar lote interrompe apenas itens ainda não enviados; não cancela documentos autorizados. Se certificado vencer ou portal ficar indisponível, pausar itens afetados. Após retomar, revalidar contexto. Interface de progresso com contadores, última atualização, mensagem amigável por nota, filtros, download de relatório de falhas e reprocessamento somente do que for comprovadamente seguro.

## 7. Persistência proposta e integração fiscal

Nomes abaixo são propostas; alinhar convenções e migrations atuais antes de implementar. Todas as tabelas de negócio devem respeitar `empresa_operadora_id` e `empresa_id`, inclusive relações filhas e jobs. Preservar MySQL 5.7 conforme escopo-base, até confirmação da stack atual.

| Entidade proposta | Responsabilidade e vínculos principais |
| --- | --- |
| `nfse_configuracoes` | 1 por empresa; parâmetros, habilitação, integração, driver, ambiente e vigência. |
| `nfse_tomadores` | Cadastro de tomadores, documento normalizado, endereço, código externo e situação. |
| `nfse_servicos` | Catálogo fiscal e comercial por prestador, códigos, descrição, padrões e vigência. |
| `nfse_recorrencias` | Tomador + serviço + contrato, frequência, valor, início/fim, próxima previsão. |
| `nfse_layouts_importacao` | Layout e versões imutáveis utilizadas em importações, mapeamento JSON validado. |
| `nfse_importacoes` | Arquivo privado, hash, versão do layout, competência, origem e operador. |
| `nfse_lotes` | Agrupamento operacional, origem, aprovação, estado e totais derivados. |
| `nfse_notas` | Intenção fiscal, snapshot, vínculo a lote/importação/recorrência, DPS, chave, número e status. |
| `nfse_tentativas` | Histórico de transmissão, reconciliação, correlação, retorno e erros sanitizados. |
| `nfse_sequencias` | Reserva concorrente de numeração por prestador/ambiente/série, se exigida. |

Usar DECIMAL para valores monetários e alíquotas com escala definida pelo leiaute; CPF/CNPJ e códigos como texto, não inteiros. Competência fiscal como data real, com filtro mês/ano; timestamps de auditoria consistentes. Índices por tenant, empresa, competência, status, lote, documento e chave. Chaves únicas compostas para recorrência/competência, referência externa estável quando presente, identificador DPS e chave oficial por ambiente/prestador, de acordo com cardinalidade. Não usar hash de valor/tomador como bloqueio absoluto: notas legítimas podem ter conteúdo igual; similaridade gera alerta e requer justificativa.

A nota autorizada deve referenciar `DocumentoFiscal` ou o serviço atual responsável pela sua persistência, com chave oficial e direção emitida, sem duplicar registros coletados pelo portal. Implementar upsert seguro por empresa/ambiente/chave e testar emissão seguida de coleta e coleta seguida de reconciliação. Separar dados de homologação e produção. XML oficial e DANFSe ficam no storage privado; documento fiscal autorizado permanece imutável, exceto atualização de estado por evento oficial. Se a geração de DANFSe falhar, a nota continua autorizada e o PDF pode ser regenerado.

## 8. Arquitetura, segurança e regras fiscais

Manter o monólito Laravel + Livewire/Blade/Tailwind existente e os padrões do repositório. Criar namespace modular `App/Services/Nfse` (nome sugerido), componentes Livewire enxutos, DTO de emissão normalizado, serviços de importação/validação, recorrência, lote, transmissão e reconciliação. Definir contrato `NfseEmissionDriver` com operações de verificar elegibilidade, emitir e consultar resultado; implementar `SefinNacionalDriver` e driver fake para testes. `PortalNacionalDriver` só se necessário, com fluxo específico de emissão, autenticação e confirmação, isolado da extração atual. Operação desconhecida deve falhar explicitamente, sem cair em validação de acesso.

Na API: obter parâmetros municipais e vigência; construir DPS conforme XSD aplicável; assinar conforme regras oficiais usando certificado A1 autorizado; transmitir com TLS validado, envelope exigido e identidade consistente; validar resposta e XML estruturado, prestador, chave e DPS antes de marcar autorizada. Não confiar em texto de sucesso ou HTTP 200 isoladamente. Guardar versão de schema e regras; tratar alterações da Reforma Tributária (IBS/CBS e campos correlatos) com versionamento, sem valores fictícios ou hardcode de tributos. O motor deve suportar evolução incremental, não um motor tributário universal.

Permissões propostas: visualizar, configurar prestador, gerenciar tomadores/serviços/layouts/recorrências, preparar/importar, aprovar/emitir, consultar/baixar documentos, cancelar/substituir (futuro). Admin/gerente atribuem permissões conforme padrão atual; possibilidade de operador preparar e outro aprovar. Toda ação valida autorização no servidor. Registrar quem aprovou e o conteúdo aprovado. Usuário super_admin seleciona operadora e não recebe poderes de emissão indiscriminadamente.

Multi-tenancy: `BelongsToOperadora`, `OperadoraContext`, `EmpresaDoEscritorio` e `OperadoraStorage`; revalidar vínculos entre prestador, tomador, serviço, lote e certificado, inclusive IDs de requisição. Nos workers, restaurar tenant explicitamente e limpá-lo ao fim. Restringir consultas sem Global Scope por operadora e empresa; não copiar padrões de coleta sem revisar. Usar `insertMany()` ou mecanismo aprovado pelo projeto em inserts de models com tenant; não ignorar eventos de preenchimento. Testes IDOR/BOLA inclusive downloads e reprocessamento.

Certificados: reutilizar `CertificadoDigitalService`, criptografia e armazenamento privado; jamais gravar senha/chave privada em rascunhos, logs, JSON de jobs ou planilhas. Limpar arquivos temporários de mTLS e limitar permissões de filesystem. Permitir somente hosts oficiais/configurados e TLS verificado. Não incluir dados pessoais desnecessários em logs, capturas de tela ou mensagens. Limitar tamanho, linhas, descompactação e MIME dos arquivos, impedir macros e fórmulas executáveis; proteger exportações CSV contra injeção de fórmulas. Definir retenção de arquivos de importação/logs conforme LGPD e obrigações fiscais, sem apagar documentos oficiais indevidamente. Backups e restauração devem ser testados.

## 9. Histórico, entrega e pós-emissão

Listar notas por empresa, competência, data de emissão, tomador, número, chave, situação, origem e lote. Exibir totais de documentos autorizados e cancelados separadamente. Permitir visualizar/baixar XML e DANFSe individualmente ou ZIP dos selecionados; relatório CSV/XLSX de controle de emissão. A coleta fiscal existente deve reconhecer as notas emitidas internamente sem criar duplicatas. Diferenciar notas externas coletadas de notas geradas pelo módulo.

Evolução: envio de documentos por e-mail/WhatsApp com autorização explícita, relatórios de pendências e integração com exportação fiscal para Domínio. Cancelamento/substituição por eventos oficiais deve ter homologação própria, motivo, permissão, confirmação e atualização do acervo; no MVP, oferecer instrução para operação externa e sincronização do status, sem apagar nota autorizada. Não prometer cancelamento automático em todos os municípios.

## 10. Plano de implementação incremental

**Fase 0 — Inventário e prova técnica (bloqueante).** Inspecionar `.cursor/rules`, models, migrations, permissões, integrações, certificados, importadores, documentos e filas. Verificar Swagger/XSD de produção restrita e vigência. Com empresa de teste autorizada, provar credenciamento, parâmetros, assinatura DPS, emissão em homologação, retorno XML, consulta por DPS e recuperação de resultado incerto. Não emitir em produção. Decidir API ou portal com evidência. Se não houver credencial/empresa de teste, registrar impedimento e desenvolver apenas com fake driver até liberação.

**Fase 1 — Base e emissão avulsa.** Configuração por empresa, permissões, tomadores, serviços, snapshots, sequência e estados; emissão avulsa via driver homologado, reconciliação, XML/DANFSe e vínculo com acervo. Entrega: emitir nota simples de teste em poucos passos com rastreabilidade e sem duplicidade.

**Fase 2 — Planilhas e lotes.** Assistente de mapeamento, persistência/versionamento de layouts, importação, grade de conferência, edição e seleção, lote assíncrono, resultados e reprocessamento seguro. Entrega: importar layout novo e reutilizar no próximo mês sem remapear; lote parcialmente rejeitado recuperável.

**Fase 3 — Recorrências e visão geral multiempresa.** Vínculos de serviços, competência, geração idempotente de rascunhos, alterações pontuais, alertas e indicadores por escritório. Entrega: preparar todas as notas mensais de uma empresa, conferir e emitir selecionadas.

**Fase 4 — Piloto e evolução.** Homologar perfis fiscais de empresas reais em ambiente permitido, validar integração com coleta e UX com operadores, endurecer logs, limites, backup, monitoramento e documentação. Após aprovação explícita, habilitação controlada em produção por empresa. Depois: cancelamento/substituição, entrega automática autorizada, outros cenários fiscais e eventuais lotes multiempresa (sempre particionados por prestador).

Cada fase deve gerar migrations reversíveis quando possível, testes automatizados, documentação de operação e roteiro de rollback. Usar Docker para desenvolvimento, comandos reais do repositório e build Vite quando necessário. Se runner TS for alterado, recompilar e reiniciar serviço conforme procedimento existente. Não executar deploy, commit/push ou migrations produtivas sem autorização específica.

## 11. Critérios de aceitação e matriz mínima de testes

- Empresa habilitada utiliza seu certificado e dados corretos; acesso de outro escritório/empresa a tomadores, notas, layouts, filas, XML e DANFSe retorna negado.
- Emissão avulsa usa padrões e permite correção; dados obrigatórios condicionais e cenários fiscais não suportados são bloqueados. Nota só fica autorizada com evidência oficial consistente.
- Importar XLS/XLSX/CSV, reconhecer cabeçalho, decimal brasileiro e CPF/CNPJ; layout salvo reutiliza mapeamento no segundo mês; mudança de coluna exige revisão; linha inválida não impede conferir outras.
- Tomador existente é encontrado por documento/código; desconhecido não é cadastrado sem confirmação; duplicatas são tratadas sem mesclar dados de empresas distintas.
- Recorrência gera uma intenção por vínculo/competência, respeita vigência/pausa e alteração pontual; segunda geração não duplica. Conflito entre recorrência e planilha é sinalizado para revisão.
- Conferência calcula corretamente valores e contadores, destaca alertas e impedimentos, mantém edição após recarregar, exige confirmação do prestador e selecionadas; alterações posteriores invalidam aprovação quando necessário.
- Lote de referência de 100 rascunhos (meta inicial de UX, não limite contratual) mantém interface responsiva; 100 transmissões simuladas têm status individual, rejeição parcial e retomada segura.
- Testar duplo clique, concorrência de workers, repetição de job, falha antes/depois do envio, resposta HTTP ambígua, certificado vencido, portal indisponível e parada de worker. Nenhuma emissão duplicada em testes.
- Consulta por DPS recupera autorização após timeout; arquivo XML inválido, de outro prestador ou chave divergente não autoriza; falha de PDF não reenvia nota. Coleta posterior não duplica documento fiscal.
- Testes unitários do mapeador/validador, assinatura e decodificação; feature tests de permissões, tenant, CRUD, lotes, recorrência; contratos com fake driver; integração real somente em produção restrita autorizada; teste de navegação com operador contábil.

Indicadores do piloto: tempo para preparar/emitir nota avulsa, tempo de conferência de lote, percentual de linhas prontas sem edição, quantidade de erros/rejeições, pendências de confirmação, duplicidade (meta zero) e horas de trabalho economizadas. Metas de desempenho definitivas após medir dados reais; não confundir latência governamental com tempo de interface.

## 12. Decisões pendentes e limites desta especificação

- A arquitetura e os nomes de entidades são recomendações, não alterações realizadas. A API direta foi priorizada porque já existe transporte mTLS no IntegraExpert; a habilitação e a transmissão não foram testadas com certificado real.
- Confirmar compatibilidade de `Terceiro`, importador personalizado, modelo de permissões, schema de `DocumentoFiscal`, fila e versão atual da stack durante a fase 0. O escopo-base do projeto é anterior aos recursos fiscais encontrados no GitHub.
- Validar regimes, códigos, requisitos de inscrição/credenciamento, municípios e leiautes vigentes, incluindo RTC, com os primeiros clientes piloto e responsável fiscal. Não assumir suporte universal.
- Recorrências mensais, aprovação humana, lote por prestador e uma linha por nota são escolhas iniciais propostas para reduzir risco. Definir futuramente outras frequências, agrupamento, cancelamento no sistema e planos comerciais.
- Não foi executada homologação real, nem alterado o repositório. A visualização por screenshot do PDF oficial retornou erro de acesso; a consulta textual ao manual e às páginas oficiais foi possível. Confirmar o contrato exato no Swagger vigente antes de codificar.

## 13. Referências para desenvolvimento

Fontes consultadas em 27/11/2026:
- Repositório: https://github.com/fabiano-silva-dev/integrar/tree/master — arquivos listados na seção 2; escopo-base do projeto ChatGPT IntegraExpert (13/07/2026).
- Documentação técnica de produção: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual
- Homologação, XSD e notas técnicas: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita
- Endereços oficiais das APIs: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao
- Manual de contribuintes do Emissor Público: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-snnfse-v1.0-20250317.pdf/view
- Serviço oficial e requisitos de acesso: https://www.gov.br/pt-br/servicos/emitir-nota-fiscal-de-servico-eletronica

**Orientação ao desenvolvimento:** iniciar pela Fase 0; apresentar diagnóstico de reaproveitamento e resultado da prova de conceito antes de consolidar migrations ou driver definitivo. A entrega deste documento não autoriza emissão fiscal real nem alteração de produção.
