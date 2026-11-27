# Catálogo público de CST e cClassTrib

## Instalação automática — pacote de 27/11/2026

Os originais oficiais, XSD e CSVs normalizados estão versionados em
`resources/nfse/`. Após `git pull`, o comando usual `./atualizar-producao.sh`
executa as migrations e `nfse:instalar-recursos`. O instalador nativo também
executa essa etapa. Não é necessário baixar planilhas, configurar o caminho
do XSD ou importar catálogos manualmente. A operação confere SHA-256, usa uma
transação para os dois catálogos e pode ser repetida sem duplicar versões.

- CST/cClassTrib: planilha oficial **IT 2025.002 v1.71**, publicada em
  **30/10/2026**, obtida no Portal NF-e/SVRS, seção Tabelas. São 173 códigos,
  73 com `indNFSe=1`, com vigências efetivas extraídas da planilha. A descrição
  vem de `Descrição cClassTrib`; `ind_gIBSCBS` vem da aba CST, unido por CST.
- cIndOp: Anexo C produção restrita **v1.01, 22/01/2026**, 26 códigos.
  A data `restrita_desde=2026-11-24` registra a evidência de implantação do
  conjunto Release 2, não uma vigência tributária presumida. Descrições e
  fundamentos expandem somente mesclagens explícitas do XLSX; códigos vêm de G2:G27.
- Anexo VII **v1.03.00**: original arquivado sem ativação automática, inclusive
  após a data prevista para o Release 3. Nova liberação exige conferir a implantação.
- Anexo VIII **v1.02.00**: complementar e orientativo, sem regra automática de enquadramento.

O CSV da consulta interativa SVRS possui defeitos de escape em dez linhas e não
informa vigências. Ele foi preservado como original, mas **não alimenta a carga**.
A planilha oficial v1.71 obtida posteriormente fornece as vigências e os
indicadores que faltavam nessa exportação. URLs, hashes e mapeamentos ficam
em `resources/nfse/pacote-oficial.json`.

Conferência sem gravação em desenvolvimento: `docker compose exec app php artisan nfse:instalar-recursos --verificar`.
A carga administrativa abaixo permanece disponível para futuras edições;
ela não é um passo exigido na instalação normal. Nenhuma flag de transmissão é alterada.

## Fonte e carga inicial

Fontes de referência:

- Portal Nacional da NF-e: https://www.nfe.fazenda.gov.br/PORTAL/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D (publicação de 30/10/2026).
- Planilha oficial v1.71 obtida em https://dfe-portal.svrs.rs.gov.br/NFe/Documentos, seção Tabelas, em 27/11/2026.
- SVRS: https://dfe-portal.svrs.rs.gov.br/DFE/ClassificacaoTributaria (consulta e exportações).
- Comunicação da API: https://dfe-portal.svrs.rs.gov.br/Cff/Noticias — GET https://cff.svrs.rs.gov.br/api/v1/consultas/classTrib, certificado ICP-Brasil, TLS 1.2. Respeitar recomendação de no máximo uma consulta diária por empresa. A sincronização autenticada fica pendente de confirmação do JSON real.

Após executar a migration, o administrador do servidor deve obter a tabela integral da fonte oficial, arquivar o original, conferir edição, data de publicação, quantidade, vigências, colunas e indicadores. Converter para CSV UTF-8 separado por ponto e vírgula (BOM opcional), com cabeçalho exato:

```csv
CST;descricaoCST;cClassTrib;descricao;dIniVig;dFimVig;indNFSe;ind_gIBSCBS;pRedIBS;pRedCBS
```

Este é um formato interno de importação; não pressupõe que a planilha ou a API tenha esses cabeçalhos. Não use dados de terceiros, não invente datas ou indicadores, nem converta códigos em números. Mapeie explicitamente os campos conforme o informe técnico aplicável; se alguma coluna não estiver disponível, não presuma valores. A descrição deve preservar a fonte, datas AAAA-MM-DD (fim vazio somente se sem fim na fonte); indNFSe obrigatório 0/1; ind_gIBSCBS opcional 0/1 ou vazio se ausente; percentuais de redução em decimal com duas casas (vazio se ausente). Use aspas em campos com ponto e vírgula. Não há arquivo real incluído nesta entrega.

Calcular SHA-256 do CSV normalizado e executar (substitua todos os parâmetros pelos metadados conferidos):

```bash
sha256sum /caminho/catalogo.csv
php artisan nfse:importar-catalogo /caminho/catalogo.csv --fonte=portal_nfe --versao="VERSAO_REAL" --publicada-em=AAAA-MM-DD --sha256=HASH_REAL --confirmar-origem
```

A opção --confirmar-origem é uma declaração administrativa de completude e conferência, não autenticação automática de fonte. A carga é global e deve ser feita somente por administrador do servidor. O comando não lê certificados, não usa rede e não transmite documentos. Mantém versões anteriores; a mesma versão não é sobrescrita. Reimportar o mesmo arquivo com mesmos metadados é idempotente.

## Uso pelo escritório

Emissão de NFS-e > Perfis tributários: selecione a vigência e pesquise código ou descrição; apenas classificações permitidas para NFS-e durante todo o mês são selecionáveis. Casos de transição no meio do mês exigem revisão específica e estão fora deste perfil mensal. O registro histórico referencia a linha e versão consultada. A versão mais recente por publicação é utilizada para novos cadastros. Uma versão mais nova pode tornar códigos antigos indisponíveis; não altera perfis anteriores. A classificação não determina automaticamente o enquadramento nem autoriza emissão. Sem carga, o campo manual continua declaratório.

## Pendências

Concluir homologação fiscal externa; implementar sincronização pela API após obter
resposta real autorizada. Os arquivos oficiais e a carga automática estão incluídos;
a execução no servidor ocorre durante sua atualização. Não habilitar emissão RTC
com base apenas no catálogo.


## Indicadores da operação (cIndOp)

Anexo VII oficial: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc . Em 27/11/2026, a v1.03.00 já foi publicada, mas sua implantação pertence ao Release 3 previsto para 07/12/2026. Não confundir publicação com implantação. Confirme a edição efetivamente implantada antes de carregar como aplicável. A correlação do Anexo VIII é orientativa e não deve ser aplicada como regra obrigatória.

Formato CSV intermediário UTF-8, separador ponto e vírgula:

```csv
cIndOp;descricao;fundamento;dIniVig;dFimVig
```

Preserve códigos com seis dígitos, descrição e referência da fonte. Fundamento e datas podem ficar vazios quando não informados; não invente vigências. Conferir todos os códigos e quantidade com o original. O pacote atual inclui o Anexo C efetivo e o Anexo VII futuro, separados. Os exemplos sintéticos dos testes antigos continuam fictícios; os testes do pacote conferem os arquivos oficiais.

Para arquivar edição ainda não implantada (substitua todos os parâmetros):

```bash
sha256sum /caminho/indop.csv
php artisan nfse:importar-indop /caminho/indop.csv --versao=VERSAO_REAL --publicada-em=AAAA-MM-DD --sha256=HASH_REAL --confirmar-origem
```

Depois de confirmar a implantação efetiva em produção restrita (não basta a previsão):

```bash
php artisan nfse:liberar-indop VERSAO_REAL --restrita-desde=AAAA-MM-DD --sha256=HASH_REAL --confirmar-implantacao
```

Se já estiver implantada, pode informar --restrita-desde na importação inicial. Data futura é rejeitada; a liberação é única e não permite sobrescrever uma data previamente confirmada. O SHA-256 assegura integridade do CSV normalizado, não autenticidade da fonte. A edição liberada mais recente pela data de implantação é usada na consulta; não há ativação automática de arquivo arquivado. Em caso de erro administrativo, corrigir via revisão técnica controlada, sem sobrescrever histórico. Não há atualização automática nem permissões de alteração pelo usuário do escritório.

A busca na tela Perfis tributários não altera o enquadramento fiscal. Com edição carregada/liberada, a seleção e gravação exigem código existente e, se fornecidas pela fonte, datas de vigência que cubram a competência mensal. Não habilita XML RTC. Sem edição liberada, entrada manual continua declaratória. A validação de combinações legais entre serviço, indicador e CST/cClassTrib depende de análise posterior.
