# IntegraExpert — Acessos e processamento simulado de NFS-e

Complemento da especificação funcional e técnica v1.5, 27/11/2026. Base: `084700a54c9b36174ca2b8acaec6cc29344bcabb` (master). Incremento 2B.2 publicado em 1bffaae, ainda não homologado.

## 1. Representação e autenticação

A funcionalidade de alternância de perfis e procurações foi implantada no Emissor Nacional em 31/10/2026. O escritório relatou e demonstrou a janela Procuração/Representação, com seleção por CPF/CNPJ e representações recentes, e a opção de login próprio por CPF/CNPJ e senha. O anúncio oficial e o FAQ também preveem acesso representado nas APIs, condicionado à autorização e às validações de certificado e assinatura. Isso não comprova que qualquer certificado ou procuração seja aceito para toda empresa/operação.

- Certificado próprio: referência ao A1 ativo, vigente e vinculado ao prestador; documento do titular deve coincidir com o cadastro.
- Certificado por representação: referência a A1 cadastrado no escritório (`empresa_id` nulo). O mesmo certificado pode ser selecionado para vários prestadores. O CNPJ/CPF representado vem do cadastro, não de entrada arbitrária durante a transmissão.
- Usuário e senha próprios: credencial do Emissor Web, não da conta GOV.BR; usuário deve coincidir com o documento do prestador.
- Usuário e senha do representante: credencial do Emissor Web e documento da empresa representada derivado do cadastro.
- API permite configurar somente as modalidades com certificado. Usuário/senha não substitui mTLS/assinatura da DPS.

Menu Emissão de NFS-e > Acesso para emissão; restrito a admin/gerente. Uma configuração por prestador, independente de `empresa_integracoes` e da coleta fiscal existente. Reaproveita `certificados_digitais` sem copiar PFX ou senha do certificado. `nfse_acessos_emissao` mantém usuário e senha com casts encrypted e hidden; nunca carregar as credenciais salvas no estado Livewire, nem enviar aos jobs. O formulário envia a nova credencial somente na ação de gravação e limpa os campos locais. Campos ambos vazios mantêm a credencial apenas no mesmo modo/documento. Troca para certificado limpa credenciais anteriores. Não registrar argumentos de requisição, formulários, tráfego, screenshots ou segredos em logs/APM; revisar middleware e ferramentas de observabilidade na homologação. Não enviar segredos por query string, CLI ou payload de fila. Certificado referenciado não pode ser excluído sem tratar vínculo; não há substituição automática.

A validade da procuração informada é apenas declaratória; todas as configurações começam `pendente`. Não existe botão que marque como validada sem prova oficial. O fluxo de login e representação real ainda não foi implementado; o adapter Node atual suporta login A1 e coleta, sem operação de emissão. A interface existente de painel ainda indica presença de certificado próprio por datas, não a disponibilidade de representação. A evolução deve mostrar separadamente o modo configurado, validade da credencial, representação confirmada e habilitação fiscal.

## 2. Prova técnica e segurança para o driver real

Priorizar API com A1 próprio e A1 representante: verificar o Swagger/XSD vigente, credenciamento por prestador, alcance dos poderes, validação mTLS e assinatura do emitente em DPS. Validar no ambiente de produção restrita, com autorização. Não presumir que a procuração aceita no portal prova a autorização da API, nem escolher automaticamente outra identidade.

Portal Web: desenvolver em operação separada dos fluxos de coleta. Login próprio por A1 ou credencial do Emissor Web, seleção de Procuração/Representação, preencher documento do prestador, aguardar validação e verificar de forma inequívoca o documento do perfil ativo e a evidência de representação antes de preencher qualquer nota. Revalidar antes de emitir. Em caso de CAPTCHA/MFA, sessão expirada, representação negada ou identidade ambígua, parar e solicitar intervenção. Não contornar desafios de autenticação. Não realizar troca de canal depois de transmissão de resultado desconhecido. Respeitar os poderes concedidos e isolar sessões por escritório e prestador. O login e a troca de perfil devem ser testados inicialmente sem emissão.

## 3. Aprovação e fila exclusivamente simuladas

Menu Emissão de NFS-e > Processamento simulado, admin/gerente, empresa selecionada e competência. Aprovação explícita de lote preparado, cenários sucesso, misto (primeira nota rejeitada, segunda incerta, demais sucesso), ou incerto. Uma simulação por lote, com autor, hash do snapshot, resultado por item e consulta fictícia de resultados incertos. `nfse_simulacoes` e `nfse_simulacao_itens` são separados da tabela fiscal; os estados do lote e notas não são modificados. Sem HTTP, certificado, credenciais, DPS, chave de acesso, XML, DANFSe ou autorização real.

Job `ProcessarSimulacaoNfseJob` usa conexão e fila padrão, `afterCommit`, três tentativas; parâmetros exclusivamente IDs de operadora, prestador e simulação. O worker revalida operadora ativa, empresa ativa, autor admin/gerente, lote preparado, hash e versões dos itens. Transação e locks por empresa e simulação, unique por lote e item; repetir job não gera duplicidade. Se lote foi desfeito antes do processamento, cancela simulação sem gerar itens. Falha de despacho ou execução deixa a simulação pendente para reenfileirar; monitoramento de failed_jobs e recuperação automática ficam pendentes. Simulações concluídas não podem ser reenviadas; para testar correção, desfazer lote, revisar e preparar novo. A consulta simulada altera apenas item incerto com operador/data, nunca reenvia uma nota.

## 4. Homologação e próximas etapas

Migration reversível `2026_11_27_160000_create_nfse_acessos_e_simulacoes.php`, novos models, services, duas páginas e job. Oito testes de feature escritos; lint PHP dos novos arquivos e verificações isoladas do simulador executadas. Ainda não há execução do PHPUnit, MySQL, worker, build Vite ou navegador neste ambiente. Aplicar migrations somente em homologação. Testar `docker compose exec app php artisan test --filter=Nfse`, `docker compose exec app npm run build`, configuração e troca de credenciais sem exposição, certificados de outras empresas, representação negada, job duplicado, lote desfeito, falhas e reenfileiramento. Verificar a fila padrão existente e o worker; para teste local usar `php artisan queue:work --queue=default --timeout=60` somente se a fila/configuração desse ambiente for compatível, sem processar jobs produtivos.

Próximos incrementos: (1) concluir homologação de todos os módulos publicados; (2) integrar prova de acesso por certificado e representação, primeiro em API/ambiente restrito e depois no portal se necessário; (3) validar login/senha do Emissor Web sem emissão; (4) driver real de DPS, aprovação fiscal independente da simulada, estados e reconciliação; (5) XML/DANFSe e acervo; (6) painel com status por modalidade. Emissão real somente após autorização específica e homologação dos casos fiscais.

## Referências oficiais consultadas em 27/11/2026

- https://www.gov.br/nfse/pt-br/noticias/nova-funcionalidade-possibilita-representacao-e-alternancia-de-perfis-na-nfse
- https://www.gov.br/nfse/pt-br/perguntas-frequentes/teste-do-perguntas (assuntos 28.1, 28.3 e 28.6)
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/atualizacoes-e-implantacoes/atualizacoes-e-implantacoes
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita
- https://sefin.nfse.gov.br/SefinNacional/docs/index


## 5. Incremento 2B.3 — Diagnóstico de acesso à API (consulta)

O FAQ oficial atualizado em novembro de 2026, assunto 17.7, esclarece que o certificado do procurador pode servir para conexão mTLS e assinatura da DPS, mantendo a identidade do prestador no documento. A representação também é aplicável em produção restrita. O assunto 28 explica a alternância de perfil e a autorização cadastrada na Receita Federal. A emissão sem certificado nas interfaces visuais tem limitações; não se deve automatizar emissão em lote por senha como alternativa à API.

Novo diagnóstico na tela Acesso para emissão: administradores/gerentes, prestador e certificado previamente cadastrados, chave conhecida de nota do próprio prestador e seleção explícita do ambiente. Reutiliza o transporte mTLS da SEFIN com GET e URLs oficiais fixas; sem POST, sem alterar a coleta. Habilitação explícita NFSE_DIAGNOSTICO_API_ENABLED=true (padrão false); limpar cache de configuração conforme procedimento do ambiente. Produção restrita é o padrão da tela; produção apenas para consulta de documento existente. Não utiliza a flag de coleta fake_mode, pois o diagnóstico tem habilitação própria. Limite de dez consultas por minuto por escritório.

Confirma estritamente namespace, Id da NFSe, documento do emitente e prestador da DPS e tpAmb. Não exibe nem armazena XML/chave original ou detalhes de exceções; registra hash da chave, configuração, certificado, ambiente, operador e status em nfse_diagnosticos_acesso. Verifica se a configuração mudou durante a consulta. Histórico não equivale a validação atual. A consulta bem-sucedida não comprova poderes de emissão, pois o certificado pode ter acesso por outro papel. Não modifica status_validacao nem habilita emissão. Falhas de consulta não provam inexistência de procuração. Registros iniciados sem conclusão são históricos de tentativa interrompida.

Próximos passos: executar os testes unitários e integrados, homologar GET com nota de teste, verificar regras e XSD vigentes, implementar DPS/assinatura/identidade e transmissão exclusivamente restrita com autorização específica. Login por senha e seleção de perfil do Emissor Web permanecem pendentes de implementação e testes com sessão real. Não contornar CAPTCHA/MFA. O Swagger interativo não pôde ser acessado nesta elaboração; confirmar endpoint e contrato antes de homologar. Não houve conexão real nem emissão.

Referência: https://www.gov.br/nfse/pt-br/perguntas-frequentes/teste-do-perguntas (17.7, 28.1–28.6).
