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
