# Esquemas oficiais — produção restrita

Pacote oficial de produção restrita de **24/11/2026** incluído no Git, com
os dez schemas originais e suas dependências relativas. O ZIP original está
em `resources/nfse/oficiais/`; os hashes estão em `resources/nfse/pacote-oficial.json`.

A configuração usa `DPS_v1.01.xsd` deste diretório por padrão, inclusive quando
`NFSE_DPS_XSD_RESTRITA` estiver vazia. Não é necessário baixar ou extrair arquivos
no servidor. O atualizador executa `nfse:instalar-recursos` após as migrations,
verifica os hashes e carrega os catálogos locais de forma idempotente.

Fonte: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita

O processo web deve ter somente leitura neste diretório. A inclusão dos schemas
e a validação local não habilitam transmissão nem substituem a homologação
com certificado autorizado e resposta real da SEFIN.
