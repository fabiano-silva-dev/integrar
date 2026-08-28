# IntegraExpert — Download seguro de documentos do Google Drive via OAuth

**Status:** implementado. Novos arquivos e pastas permanecem privados; Visualizar e Baixar passam pelo IntegraExpert com OAuth do escritório.

Em produção, após o deploy, remover permissões públicas antigas (nativo, sem Docker):

```bash
sudo -u www-data php artisan documentos:remover-links-publicos --dry-run
sudo -u www-data php artisan documentos:remover-links-publicos
```

Opção `--operadora=` limita a um escritório. `--dry-run` só lista, não altera o Drive.

---

## Objetivo

Substituir o acesso aos documentos do Google Drive via link público (`anyone with link`) por um fluxo seguro em que:

- os arquivos permaneçam **privados no Google Drive**;
- o usuário autenticado clique em **Visualizar** ou **Baixar** dentro do IntegraExpert;
- o IntegraExpert valide o acesso do usuário ao escritório/empresa/documento;
- o IntegraExpert utilize o OAuth já autorizado do escritório para buscar o arquivo no Google Drive;
- o arquivo seja transmitido diretamente ao navegador;
- o usuário não precise abrir o Google Drive, fazer novo login ou autorizar novamente a cada download.

A experiência desejada deve ser:

```text
Usuário clica em "Baixar"
        ↓
IntegraExpert valida usuário + escritório + empresa + documento
        ↓
IntegraExpert usa OAuth salvo do escritório
        ↓
Google Drive API
        ↓
IntegraExpert transmite o arquivo
        ↓
Download automático no navegador
```

Para visualização:

```text
Usuário clica em "Visualizar"
        ↓
IntegraExpert valida acesso
        ↓
Busca o arquivo no Google Drive via OAuth
        ↓
Retorna inline no navegador
```

---

## Contexto atual do projeto

O projeto já possui infraestrutura de OAuth do Google por escritório.

Principais pontos existentes:

- `App\Models\Documentos\ContaGoogle`
  - tokens de acesso e refresh token;
  - tokens criptografados no banco;
  - isolamento por `empresa_operadora_id`.

- `App\Http\Controllers\OAuth\GoogleOAuthController`
  - autorização OAuth;
  - armazenamento do `state`;
  - associação da conta Google ao escritório;
  - armazenamento de `access_token` e `refresh_token`.

- `App\Services\Documentos\GoogleDriveService`
  - integração com a API do Google Drive;
  - `streamArquivo` / `gravarArquivo` para download sem carregar o arquivo inteiro na memória;
  - novos uploads e pastas **não** recebem permissão pública.

- `App\Http\Controllers\Documentos\DocumentoDriveArquivoController`
  - `GET /documentos/{documento}/download`
  - `GET /documentos/{documento}/visualizar`

- `App\Models\Documentos\DocumentoRecebido`
  - possui `empresa_operadora_id`, `empresa_id`, `drive_file_id`, `drive_web_link`, `drive_path` e demais informações do documento.
  - o acesso usa `drive_file_id` (e cópias em `metadados.copias_drive`), não o link público.

- `App\Services\Documentos\AcessoLinkDrive`
  - detecta e remove permissões `anyone` / `domain`;
  - **não** cria mais `type = anyone`.

---

# Regra principal de segurança

## NÃO publicar documentos do escritório como `anyone`

Arquivos fiscais, extratos bancários, boletos, DANFEs, XMLs, relatórios e demais documentos recebidos devem permanecer privados no Google Drive.

O IntegraExpert deve ser o intermediador do acesso.

### Regra

O download só pode ocorrer quando:

1. o usuário estiver autenticado;
2. existir um escritório ativo no contexto;
3. o documento pertencer ao mesmo `empresa_operadora_id`;
4. a empresa do documento pertencer ao mesmo escritório;
5. o documento possuir `drive_file_id`;
6. existir uma conta Google conectada para o escritório;
7. o usuário tiver permissão para acessar o módulo Documentos.

Nunca confiar apenas em:

- ID recebido pela URL;
- `drive_file_id` enviado pelo navegador;
- `empresa_id` enviado pelo navegador;
- link público salvo no banco.

O documento deve ser sempre resolvido no backend usando os scopes/validações de tenant do projeto.

---

# Comportamento esperado

## 1. Botão "Baixar"

Adicionar ou alterar a ação de download do documento para apontar para uma rota do próprio IntegraExpert.

Exemplo conceitual:

```text
GET /documentos/{documento}/download
```

O controller deve:

1. exigir autenticação;
2. resolver `DocumentoRecebido` dentro do tenant atual;
3. verificar que o documento está disponível no Drive;
4. obter a `ContaGoogle` do mesmo `empresa_operadora_id`;
5. usar `GoogleDriveService`;
6. baixar/streamar o conteúdo;
7. devolver ao navegador com nome correto do arquivo, `Content-Type` correto e `Content-Disposition: attachment`.

O navegador deve iniciar o download automaticamente.

---

## 2. Botão "Visualizar"

Criar rota semelhante:

```text
GET /documentos/{documento}/visualizar
```

A validação deve ser idêntica à do download.

Diferença:

```http
Content-Disposition: inline
```

Utilizar principalmente para:

- PDF;
- imagens;
- XML/texto quando fizer sentido.

Caso o tipo MIME não seja seguro ou adequado para visualização inline, realizar download normal.

---

# GoogleDriveService

Adicionar ao serviço métodos claros para buscar conteúdo de arquivo.

Sugestão conceitual:

```php
baixarArquivo(ContaGoogle $conta, string $fileId): array
```

Retorno sugerido:

```php
[
    'conteudo' => $conteudo,
    'nome' => $nome,
    'mime' => $mime,
    'tamanho' => $tamanho,
]
```

Ou, preferencialmente, implementar streaming quando a biblioteca/API utilizada permitir.

Evitar carregar arquivos grandes desnecessariamente inteiros na memória.

---

# Renovação do token

O usuário NÃO deve precisar autorizar novamente o Google a cada download.

Ao executar a chamada:

- se o access token estiver válido, utilizá-lo;
- se estiver expirado, renovar automaticamente usando o `refresh_token`;
- atualizar o token salvo quando necessário;
- caso o refresh token tenha sido revogado ou expirado, apresentar mensagem clara:

```text
A conexão com o Google Drive deste escritório expirou.
Reconecte a conta em Configurações > Documentos > Google Drive.
```

Não exibir erro técnico ou stack trace ao usuário.

---

# Tratamento de erros

## Documento sem arquivo no Drive

```text
Este documento ainda não possui arquivo disponível no Google Drive.
```

## Conta Google desconectada

```text
A conta Google deste escritório não está conectada.
```

## Arquivo removido diretamente no Google Drive

```text
O arquivo não foi encontrado no Google Drive.
Ele pode ter sido removido ou movido fora do IntegraExpert.
```

Registrar o erro no log de documentos.

## Falha de comunicação com Google

```text
Não foi possível acessar o Google Drive agora.
Tente novamente em alguns instantes.
```

Registrar detalhes técnicos somente no log.

---

# Alteração no compartilhamento do Google Drive

## Estado atual

`AcessoLinkDrive` não cria permissão pública. Novos arquivos e pastas permanecem privados.

## Comportamento

Para novos arquivos:

```text
arquivo privado
```

Não executar:

```text
type=anyone
role=reader
```

O acesso deve acontecer pelo IntegraExpert utilizando OAuth.

---

# Compatibilidade com arquivos antigos

Existem arquivos que podem já ter sido publicados como `anyone with link`.

Não quebrar o funcionamento durante a implantação.

## Fase 1

- novos arquivos deixam de receber permissão pública;
- downloads passam a utilizar OAuth;
- links antigos podem continuar existindo temporariamente.

## Fase 2

Comando Artisan (já disponível):

```text
php artisan documentos:remover-links-publicos
php artisan documentos:remover-links-publicos --dry-run
php artisan documentos:remover-links-publicos --operadora=1
```

O comando deve:

1. iterar somente documentos do escritório informado ou de todos os escritórios quando executado pelo sistema;
2. localizar o arquivo no Drive;
3. identificar permissões `anyone`;
4. remover somente a permissão pública criada anteriormente;
5. preservar permissões legítimas existentes;
6. registrar resultado.

Adicionar opção de simulação:

```text
--dry-run
```

---

# Links salvos atualmente

`DocumentoRecebido` possui:

```text
drive_web_link
```

O sistema não deve depender mais desse campo para permitir acesso ao arquivo.

A principal referência deve ser:

```text
drive_file_id
```

`drive_web_link` pode continuar salvo por compatibilidade, porém não deve ser utilizado como mecanismo principal de autorização ou acesso.

---

# Explorador de Documentos

No `App\Livewire\Documentos\ExploradorDocumentos`:

## Arquivo individual

Mostrar ações:

```text
Visualizar
Baixar
Mover
Excluir
```

Quando aplicável.

### Visualizar

Abrir rota interna do IntegraExpert.

### Baixar

Abrir rota interna do IntegraExpert.

Não direcionar diretamente para `drive_web_link`.

---

# Download em lote

O IntegraExpert já possui fluxo de compactação/download de documentos.

Manter a experiência:

```text
selecionar arquivos
→ Baixar selecionados
→ ZIP
```

Porém os arquivos do ZIP devem ser obtidos pela API do Google Drive utilizando OAuth.

Nunca depender de URLs públicas.

---

# Download de pasta

Fluxo esperado:

```text
Empresa
  ↓
Ano
  ↓
Tipo
  ↓
Baixar pasta
```

O backend:

1. identifica os documentos visíveis naquele nível;
2. valida tenant;
3. busca cada `drive_file_id`;
4. monta ZIP;
5. devolve ao usuário.

Manter proteção contra mistura de documentos de escritórios diferentes.

---

# Controle de acesso

Não alterar as regras gerais do módulo sem necessidade.

Porém garantir explicitamente que as rotas:

```text
/documentos/{documento}/download
/documentos/{documento}/visualizar
```

não sejam acessíveis somente por conhecer o ID.

O model deve ser obtido através do contexto da operadora.

Exemplo conceitual:

```php
DocumentoRecebido::query()->findOrFail($id);
```

desde que o `BelongsToOperadora` esteja ativo.

Quando usar `withoutGlobalScope('operadora')`, deve existir validação explícita:

```php
$documento->empresa_operadora_id === OperadoraContext::requireId()
```

Evitar `withoutGlobalScope()` para essas rotas quando não for necessário.

---

# Segurança adicional

Adicionar headers adequados.

Para download:

```text
X-Content-Type-Options: nosniff
Cache-Control: private
```

Para documentos sensíveis, evitar cache público.

Não registrar:

- conteúdo do arquivo;
- access token;
- refresh token;
- client secret;
- URLs contendo credenciais.

---

# Arquivos grandes

A implementação deve evitar carregar arquivos grandes inteiros na memória quando isso não for necessário.

Preferir streaming ou resposta de download em streaming.

O sistema deve continuar funcionando adequadamente com arquivos de dezenas ou centenas de MB.

---

# Logs

Registrar eventos relevantes através do sistema existente de logs do módulo Documentos.

Exemplos:

```text
Documento baixado pelo usuário.
Documento visualizado pelo usuário.
Arquivo não encontrado no Google Drive.
Falha ao renovar OAuth.
Permissão pública antiga removida.
```

Contexto útil:

```text
documento_id
empresa_id
empresa_operadora_id
user_id
drive_file_id
```

Não registrar tokens.

---

# Testes obrigatórios

## Tenant

Usuário do escritório A não pode:

```text
GET /documentos/{documento-do-escritorio-B}/download
```

Resultado esperado:

```text
404 ou 403
```

Nunca retornar o arquivo.

Mesmo teste para `/visualizar`.

## Download

Mockar Google Drive.

Validar:

- documento correto;
- nome correto;
- MIME correto;
- `attachment`.

## Visualização

Validar:

- PDF retorna `inline`;
- MIME correto;
- usuário autorizado consegue visualizar.

## Conta Google ausente

Validar mensagem amigável.

## Arquivo inexistente

Mockar resposta 404 do Google.

Validar:

- usuário não recebe erro 500;
- mensagem amigável;
- erro registrado.

## OAuth expirado

Simular access token expirado.

Validar:

```text
refresh token
→ novo access token
→ download realizado
```

## Tenant + IDOR

Criar explicitamente teste manipulando ID de documento de outro escritório.

Este teste é obrigatório.

## Acesso público

Adicionar teste garantindo que o upload de um novo documento NÃO chama rotina que configure:

```text
type=anyone
```

---

# Migração gradual

Não apagar `drive_web_link` imediatamente.

Primeiro alterar todas as telas para utilizar as novas rotas internas.

Depois avaliar remoção futura do campo em migration separada.

---

# Critérios de aceite

- [x] arquivo novo enviado ao Drive permanece privado;
- [x] usuário clica em **Baixar** e o download começa diretamente;
- [x] usuário não precisa fazer login no Google;
- [x] usuário não vê tela intermediária do Google;
- [x] access token expirado é renovado automaticamente;
- [x] botão **Visualizar** abre PDF/imagem pelo IntegraExpert;
- [x] ninguém consegue baixar documento de outro escritório alterando a URL;
- [x] download em lote continua funcionando;
- [x] ZIP continua funcionando;
- [x] nenhum novo arquivo recebe `anyone with link`;
- [x] tokens Google permanecem criptografados;
- [x] logs não armazenam tokens;
- [x] testes de isolamento multi-tenant passam;
- [x] arquivos antigos continuam acessíveis durante a transição;
- [x] existe caminho para remoção das permissões públicas antigas (`documentos:remover-links-publicos`).

---

# Não fazer

Não:

- criar novo sistema OAuth se o existente puder ser reutilizado;
- expor `drive_file_id` como autorização;
- usar `drive_web_link` como controle de acesso;
- publicar arquivos como `anyone` apenas para facilitar download;
- remover arquivos antigos ou permissões antigas automaticamente sem rotina controlada;
- alterar o fluxo de classificação de documentos;
- alterar a estrutura empresa/ano/tipo;
- remover funcionalidades atuais de mover/excluir/ZIP;
- quebrar compatibilidade com o módulo Documentos existente.

---

# Resultado final desejado

A experiência do usuário deve continuar simples:

```text
📄 Visualizar
⬇️ Baixar
📦 Baixar selecionados
```

Mas internamente:

```text
Google Drive privado
        ↓
OAuth do escritório
        ↓
IntegraExpert valida tenant/permissão
        ↓
Usuário recebe o arquivo
```

O Google Drive deve funcionar apenas como armazenamento.

A autorização do usuário final deve ser controlada pelo IntegraExpert.
