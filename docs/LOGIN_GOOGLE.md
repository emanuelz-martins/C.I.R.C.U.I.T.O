# Login Google institucional

O botão Google fica no painel branco da tela de login. Ele aceita somente contas
com e-mail verificado no formato `nome.12345@aluno.iffar.edu.br`: nome, ponto,
cinco dígitos e domínio exato. O domínio de organização informado pelo Google
(`hd`) também deve ser `aluno.iffar.edu.br`.

O servidor verifica assinatura RS256, emissor, destinatário, validade e nonce do
ID token. O filtro visual de contas do Google não substitui essa verificação.
São pedidos apenas os dados básicos de identidade; nenhuma senha Google é
recebida ou armazenada pelo C.I.R.C.U.I.T.O.

## Configurar o cliente Web

1. Abra o [Google Cloud Console](https://console.cloud.google.com/) e selecione
   ou crie um projeto para o C.I.R.C.U.I.T.O.
2. Configure o Google Auth Platform: nome do aplicativo, e-mail de suporte e
   público. A opção de público interno só se aplica se o projeto pertencer à
   organização institucional. Se estiver em teste com público externo, adicione
   as contas institucionais autorizadas como usuários de teste.
3. Em **Clientes**, crie um cliente OAuth do tipo **Aplicativo da Web**.
4. Cadastre estas **origens JavaScript autorizadas**, sem caminho ou barra final:

   ```text
   http://localhost
   https://circuito.alwaysdata.net
   ```

   Se usar outra porta ou outro endereço para acessar o site, cadastre a origem
   correspondente também. `127.0.0.1` e `localhost` são origens distintas.
5. Copie o **ID do cliente**, terminado em `.apps.googleusercontent.com`, para o
   `.env` de cada ambiente:

   ```dotenv
   GOOGLE_CLIENT_ID=SEU_ID.apps.googleusercontent.com
   ```

Este fluxo usa Google Identity Services com janela de seleção e callback
JavaScript. Não usa Client Secret nem URI de redirecionamento OAuth. Não coloque
segredos em arquivos públicos. O ID do cliente é público e aparece no navegador.

Enquanto o ID não estiver configurado, o botão fica desabilitado com a mensagem
“Acesso Google em configuração”. Depois de configurar, recarregue a tela.
O login real precisa ser testado com uma conta institucional permitida e com uma
conta externa, que deve ser recusada pelo servidor.

## Banco de dados e contas

Antes de publicar o código, execute no banco selecionado pelo phpMyAdmin o SQL de
[`20261018_google_login.sql`](../src/database/migrations/20261018_google_login.sql).
Ele adiciona `google_sub`, seu índice único e amplia `foto_perfil` para URLs.
Não remove usuários, pedidos, IDs ou logins existentes. A migração pode ser
executada novamente. Se o banco ainda não recebeu as alterações anteriores de
perfis/avisos, aplique primeiro a migração completa anterior.

- **SIGAA:** recebe o CPF e continua salvando no campo `login`, formatado como
  `000.000.000-00`. Não cria coluna separada `cpf`. A senha SIGAA não é salva.
- **Google:** usa `google_sub` como identidade estável e gera um `login` técnico
  próprio. Salva nome completo, e-mail e URL da foto fornecidos pelo Google.
  Não solicita CPF nem reconstrói CPF a partir do e-mail.
- **Teste:** continua usando login e senha com hash no banco local.

Novas contas Google entram como alunos. Nos acessos seguintes, nome, e-mail e
foto são sincronizados e o perfil definido pelo administrador é preservado.
Banidos não entram; o bloqueio de pedidos permanece valendo. O e-mail verificado
de contas Google não pode ser substituído pela edição local de perfil.

Uma conta SIGAA/Teste já existente **não é vinculada automaticamente** à conta
Google com o mesmo e-mail, pois o e-mail antigo era editável. Sem uma vinculação
comprobatória futura, o primeiro acesso Google cria outra conta; pedidos e perfis
da conta antiga continuam associados ao ID antigo.

A foto é salva como URL HTTPS do Google. O projeto não baixa uma cópia; sua
exibição depende da disponibilidade desse endereço.

## Requisitos e verificação local

PHP 8.1 ou superior com cURL, OpenSSL, mbstring e PDO MySQL. A validação JWT usa
`firebase/php-jwt` versionado em `src/vendor/`, com licença e origem documentadas,
sem instalação adicional no deploy. O servidor precisa acessar por HTTPS os
certificados públicos do Google. O cache temporário fica em `var/cache/`, fora
de `public/` e ignorado pelo Git.

```powershell
C:/xampp/php/php.exe tests/google.php
C:/xampp/php/php.exe tests/perfis.php
```

Os testes Google usam chaves efêmeras e banco local; não substituem o teste real
com o cliente Web configurado. Os fixtures são removidos ao concluir.

Referências: [verificação do ID token](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token),
[configuração do cliente](https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid),
[referência JavaScript](https://developers.google.com/identity/gsi/web/reference/js-reference).
