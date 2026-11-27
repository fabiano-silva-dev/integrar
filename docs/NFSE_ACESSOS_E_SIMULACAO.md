# IntegraExpert — Acessos e processamento simulado de NFS-e

Complemento da especificação funcional e técnica v1.16, 27/11/2026. Inclui o incremento 2B.13 de autenticação Web. As seções de incrementos anteriores abaixo são históricas.

## Atualização 2B.13 — Login Web com senha, A1 e procuração

Em **Emissão de NFS-e > Acesso para emissão**, selecionar a empresa, escolher uma das quatro modalidades e o canal **Emissor Web**, salvar e clicar em **Testar acesso ao Emissor Web**. Usuário e senha são os do próprio Emissor Nacional, não os da conta GOV.BR. Certificados suportados: A1 PFX/P12 já cadastrados; não há suporte a A3/token neste fluxo.

O teste é executado na fila `database/default`, com apenas escritório, empresa e identificador da tentativa no job. Revalida usuário ativo e papel admin/gerente/super admin, escritório, empresa, modo, titular, credenciais e validade antes e depois da navegação. A configuração é identificada por HMAC; alteração de senha, certificado ou procuração invalida o resultado. Duplo clique reaproveita a tentativa pendente; a execução do mesmo job é idempotente. Tentativas abandonadas por mais de cinco minutos são encerradas ao solicitar novo teste. Limite: cinco solicitações por minuto por escritório. Requer worker da fila `default`; não utiliza a conexão `sync` das requisições web.

No runner, cada tentativa cria um contexto Chromium isolado, recebe credenciais por stdin privado e encerra a sessão no final. Não utiliza arquivos de entrada, argumentos de CLI, trace, screenshots, vídeo, logs de páginas, cookies persistentes ou estado de sessão. O PEM do A1 é obtido pelo serviço existente e enviado em memória ao runner; o fallback de extração para PFX legado usa o diretório temporário restrito e a limpeza já existentes nesse serviço. A impressão SHA-256 do certificado é conferida com o cadastro antes de enviá-lo ao runner. O certificado é apresentado apenas a `https://certificado.nfse.gov.br`.

O login com senha usa os campos públicos conferidos em 27/11/2026: `Inscricao`, `Senha` e formulário `/EmissorNacional/Login`. O acesso com A1 usa a opção pública **Acesso via certificado digital**. Autenticação exige identificação do titular no cabeçalho, saída disponível e ausência do formulário de senha. Por representação, abre **Procuração/Representação**, informa o CPF/CNPJ do prestador selecionado e clica em **Representar**; confirma representante, representado e aviso de atuação no cabeçalho/alerta. Nunca usa o histórico de representações nem o CPF/CNPJ digitado no modal como prova do perfil ativo.

Rotas externas, mutações diferentes de autenticação/representação e rotas fiscais são bloqueadas. CAPTCHA/MFA, recusa de login, perfil ambíguo, procuração recusada e mudanças de layout resultam em acesso não confirmado, sem tentativas alternativas de identidade. O histórico persiste somente modo, usuário solicitante, hash de configuração, código controlado, situação e datas. `portal_confirmado` significa acesso observado naquele instante; não concede poderes de emissão, não autoriza API nem altera os bloqueios de produção/DPS.

O atualizador padrão prepara Node 24+, dependências do runner, Chromium e build; reaproveita o instalador de automação com `--skip-systemd`, sem alterar o modo fake nem habilitar transmissão. O worker `default` já instalado é reiniciado pelo atualizador. `--skip-npm` também pula essa preparação. O servidor precisa poder acessar os repositórios de pacotes e o download do Playwright. `PLAYWRIGHT_BROWSERS_PATH` pode substituir o cache compartilhado padrão `/var/cache/integrar-playwright`.

Provas automatizadas: schemas/identidade, fixtures do portal para as quatro modalidades e recusas, e testes Laravel de isolamento, rotação de credencial, revogação, idempotência, expiração e certificado. Fixtures não comprovam o DOM autenticado real: a homologação com credenciais autorizadas e procuração real permanece necessária, especialmente o menu e o aviso do perfil ativo. Nenhuma emissão fiscal ou login com credencial real foi realizado neste incremento.

Verificação em 27/11/2026, CI [46043380853](https://github.com/fabiano-silva-dev/integrar/actions/runs/46043380853): 39 testes unitários do runner e 10 testes Chromium do acesso Web aprovados; typecheck, lint, build do runner, build frontend e scripts aprovados. Os 11 testes PHP específicos de acesso Web também passaram. A suíte PHP geral continua com as 27 falhas anteriores ao incremento, já identificadas no CI da base; não é uma suíte globalmente verde. Neste workspace não há PHP/Docker nem worker ativo para reiniciar; a execução PHP e Chromium foi realizada no CI. O atualizador reinicia os workers instalados e ativos no servidor durante o deploy.

Fontes oficiais para homologação: [login do Emissor Nacional](https://www.nfse.gov.br/EmissorNacional/Login) e [FAQ, assunto 28 — procuração/representação](https://www.gov.br/nfse/pt-br/perguntas-frequentes/teste-do-perguntas). A tela pública foi inspecionada; os seletores autenticados seguem o fluxo documentado e falham de forma fechada quando não há confirmação inequívoca.

## 1. Representação e autenticação

A funcionalidade de alternância de perfis e procurações foi implantada no Emissor Nacional em 31/10/2026. O escritório relatou e demonstrou a janela Procuração/Representação, com seleção por CPF/CNPJ e representações recentes, e a opção de login próprio por CPF/CNPJ e senha. O anúncio oficial e o FAQ também preveem acesso representado nas APIs, condicionado à autorização e às validações de certificado e assinatura. Isso não comprova que qualquer certificado ou procuração seja aceito para toda empresa/operação.

- Certificado próprio: referência ao A1 ativo, vigente e vinculado ao prestador; documento do titular deve coincidir com o cadastro.
- Certificado por representação: referência a A1 cadastrado no escritório (`empresa_id` nulo). O mesmo certificado pode ser selecionado para vários prestadores. O CNPJ/CPF representado vem do cadastro, não de entrada arbitrária durante a transmissão.
- Usuário e senha próprios: credencial do Emissor Web, não da conta GOV.BR; usuário deve coincidir com o documento do prestador.
- Usuário e senha do representante: credencial do Emissor Web e documento da empresa representada derivado do cadastro.
- API permite configurar somente as modalidades com certificado. Usuário/senha não substitui mTLS/assinatura da DPS.

Menu Emissão de NFS-e > Acesso para emissão; restrito a admin/gerente. Uma configuração por prestador, independente de `empresa_integracoes` e da coleta fiscal existente. Reaproveita `certificados_digitais` sem copiar PFX ou senha do certificado. `nfse_acessos_emissao` mantém usuário e senha com casts encrypted e hidden; nunca carregar as credenciais salvas no estado Livewire, nem enviar aos jobs. O formulário envia a nova credencial somente na ação de gravação e limpa os campos locais. Campos ambos vazios mantêm a credencial apenas no mesmo modo/documento. Troca para certificado limpa credenciais anteriores. Não registrar argumentos de requisição, formulários, tráfego, screenshots ou segredos em logs/APM; revisar middleware e ferramentas de observabilidade na homologação. Não enviar segredos por query string, CLI ou payload de fila. Certificado referenciado não pode ser excluído sem tratar vínculo; não há substituição automática.

A validade da procuração informada é apenas declaratória; todas as configurações começam `pendente`. Não existe botão que marque como validada sem prova oficial. O incremento 2B.13 acima implementa o teste de login e representação; o adapter de coleta existente permanece independente. A interface de painel ainda indica presença de certificado próprio por datas, não a disponibilidade de representação.

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
