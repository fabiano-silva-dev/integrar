# Catálogo público de CST e cClassTrib

## Fonte e carga inicial

Fontes de referência:

- Portal Nacional da NF-e: https://www.nfe.fazenda.gov.br/PORTAL/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D (publicação de 30/10/2026 localizada na pesquisa).
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

Obter e importar a tabela oficial completa; conferir a normalização com o arquivo original; executar CI e homologação fiscal; implementar sincronização pela API após obter resposta real autorizada e tabela de cIndOp. Nenhuma classificação real foi carregada nesta implementação. Não habilitar emissão RTC com base apenas no catálogo.


## Indicadores da operação (cIndOp)

Anexo VII oficial: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc . Em 27/11/2026, a v1.03.00 já foi publicada, mas sua implantação pertence ao Release 3 previsto para 07/12/2026. Não confundir publicação com implantação. Confirme a edição efetivamente implantada antes de carregar como aplicável. A correlação do Anexo VIII é orientativa e não deve ser aplicada como regra obrigatória.

Formato CSV intermediário UTF-8, separador ponto e vírgula:

```csv
cIndOp;descricao;fundamento;dIniVig;dFimVig
```

Preserve códigos com seis dígitos, descrição e referência da fonte. Fundamento e datas podem ficar vazios quando não informados; não invente vigências. Conferir todos os códigos e quantidade com o original. Não há arquivo oficial carregado nesta entrega. Os exemplos de teste no repositório são exclusivamente fictícios.

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
