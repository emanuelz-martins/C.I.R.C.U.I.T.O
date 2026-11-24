# Nome e confirmação de e-mail

O acesso SIGAA continua sendo validado no serviço institucional do IFFar. Esse
serviço não representa acesso a todas as instituições que usam SIGAA. A senha
institucional é usada somente na autenticação e na confirmação de identidade;
não é armazenada pelo C.I.R.C.U.I.T.O.

## Comportamento

- No primeiro acesso SIGAA, `conta_email.php` aparece antes do painel, com um
  aviso explicando a identificação pelo nome e o contato sobre pedidos.
- Entre os dados do perfil, somente o nome é obrigatório. Matrícula, foto e
  descrição continuam opcionais e podem ser preenchidas em **Perfil → +**.
- O e-mail precisa ser confirmado para concluir o cadastro. A autenticação
  SIGAA recém-realizada dispensa digitar CPF e senha de novo nesse momento.
  A autorização dura 15 minutos; se vencer, a página pede nova autenticação.
- URL direta e POST de pedido não contornam o cadastro pendente. A exigência
  SIGAA persiste inclusive ao usar uma sessão local de teste da mesma conta.
- Para trocar o e-mail depois, o botão no modal do perfil abre a confirmação
  da identidade da própria conta, seguida do novo endereço e de um código.
  Contas locais de teste usam a senha local; contas SIGAA usam o serviço SIGAA.
- O endereço antigo só é substituído após o código correto. Se já era
  verificado, recebe um aviso após a alteração. Falha nesse aviso não desfaz
  a troca concluída e é registrada sem dados sensíveis no log do servidor.
- Contas Google mantêm o e-mail do provedor. O login com token Google validado
  registra a verificação; a edição comum ou administrativa não troca esse e-mail.
- Nenhum endereço antigo é marcado como verificado pela migração. Usuários
  Google existentes têm a verificação registrada no próximo login Google válido.
- E-mail é contato, não uma chave para fundir contas ou redefinir o login SIGAA.
  Contas com o mesmo contato continuam distintas.

## Banco existente: executar antes de publicar

Faça um backup e execute o conteúdo de
[`20261124_email_verificado.sql`](../src/database/migrations/20261124_email_verificado.sql)
na aba **SQL** do phpMyAdmin, com o banco correto selecionado. Não é necessário
importar um dump nem executar `schema.sql` inteiro no servidor existente.

A atualização adiciona `email_verificado_em`, `nome_confirmado_em`, `email_versao`
e `cadastro_sigaa` em `Usuario`, além das tabelas `Email_Verificacao` e
`Email_Limite`. É idempotente e preserva senhas, usuários, pedidos e perfis.
O cadastro SIGAA existente é identificado pelo último método registrado;
qualquer autenticação SIGAA posterior também registra essa exigência persistente.

`src/database/seeds/schema.sql` já contém a mesma atualização, tanto para banco
novo quanto para instalações anteriores. O GitHub Actions atual publica os
arquivos, mas **não executa migrações MySQL**: por isso o SQL é entregue separado.

O schema também consolida `Componente.descricao` como `TEXT`. Para bancos que
ainda usam `VARCHAR(255)`, a ampliação está em
[`20261124_descricao_componente.sql`](../src/database/migrations/20261124_descricao_componente.sql).
Essa alteração preserva as descrições existentes e permite textos maiores.

## Remetente no servidor

O remetente escolhido é `iffar.circuito@gmail.com`. Por ser Gmail, o servidor
Alwaysdata usará **SMTP do Gmail**; não se deve simplesmente informar esse
endereço como remetente de outro servidor sem autorização.

No `.env` privado do servidor, mantenha as configurações de banco e Google e
adicione:

```dotenv
MAIL_TRANSPORT=smtp
MAIL_FROM_ADDRESS=iffar.circuito@gmail.com
MAIL_FROM_NAME=C.I.R.C.U.I.T.O.
EMAIL_VERIFICATION_KEY=COLOQUE_AQUI_64_CARACTERES_HEXADECIMAIS_ALEATORIOS
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_ENCRYPTION=tls
SMTP_USERNAME=iffar.circuito@gmail.com
SMTP_PASSWORD=COLOQUE_A_SENHA_DE_APP_DO_GMAIL
```

`APP_ENV=production`, `APP_DEBUG=false` e `APP_URL=https://circuito.alwaysdata.net`
devem continuar configurados. As telas e o endpoint exigem HTTPS em produção.

Gere a chave **no seu terminal** com
`php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` e copie a saída para o `.env`.
Ela é um segredo diferente da senha SMTP. Trocar a chave invalida códigos em
andamento; mantenha-a estável entre publicações e instâncias do mesmo ambiente.

Ative a verificação em duas etapas da conta Gmail e gere uma senha de app,
se essa opção estiver disponível para a conta. Use essa senha no `.env`,
sem colocar a senha comum do Gmail no projeto ou no chat. Veja a
[orientação oficial do Google](https://support.google.com/accounts/answer/185833?hl=pt-BR).
O envio usa [PHPMailer 7.2.0](https://github.com/PHPMailer/PHPMailer/releases/tag/v7.2.0),
versionado com licença em `src/vendor/phpmailer`, sem download no deploy.

Sem SMTP e chave válidos, o fluxo recusa confirmar o e-mail. Não simula entrega.
O envio real e a entrega na caixa de entrada precisam ser testados depois da
configuração do servidor; os testes automatizados não contatam o Gmail.

## Desenvolvimento e validação

O `.env.example` usa `MAIL_TRANSPORT=smtp` também no desenvolvimento. Para enviar
e-mails reais pelo XAMPP, preencha a senha de app e a chave de verificação no
`.env` privado, mantendo `APP_ENV=local` e as configurações do banco local.
O arquivo de produção para upload mantém `APP_ENV=production` e `DB_PROFILE=alwaysdata`.

Se a rede local tiver IPv6 sem conectividade com o Gmail, use
`SMTP_FORCE_IPV4=true` no `.env` local. Isso resolve o domínio por IPv4 a cada
conexão, preservando a validação TLS para `smtp.gmail.com`. O exemplo deixa
essa opção desativada; não é necessário alterar o IPv6 do Windows.

Para testes sem envio, use `MAIL_TRANSPORT=local` somente com `APP_ENV=local`
e `APP_URL` em localhost.
As mensagens de teste são arquivos JSON fora do diretório servido pelo Apache,
no diretório temporário do PHP. Para descobrir o caminho:

```sh
php -r "require 'src/services/email_envio.php'; echo emailDiretorioLocal(), PHP_EOL;"
```

Essa saída local é recusada em produção. Os códigos não aparecem na resposta
da API nem no navegador.
Não publique esses arquivos. Em Windows, o diretório temporário depende do
usuário que executa o Apache/PHP.

Antes dos testes automatizados, altere temporariamente `MAIL_TRANSPORT=local`
no `.env` do XAMPP, que é lido tanto pelo Apache quanto pelo PHP do terminal.
Não basta alterar uma variável só no terminal. Ao terminar, volte a `smtp`
para retomar o envio real.

Validação com o Apache e MySQL do XAMPP ativos, a partir da pasta do projeto no XAMPP:

```sh
php tests/schema.php
php tests/email_conta.php
php tests/google.php
php tests/perfis.php
```

Os testes de e-mail usam contas e destinatários sintéticos `.invalid`, removem
os dados criados ao terminar e exigem transporte local. Nenhuma mensagem real
é enviada. Os testes devem ser executados em sequência, pois comparam os
usuários originais do banco.

Os códigos valem por até 10 minutos e aceitam cinco tentativas. O reenvio exige
60 segundos e há limites persistentes por usuário e IP para envio, código e
reautenticação. Só o HMAC do código fica no banco. Cada desafio pertence a uma
conta, endereço e autorização de sessão; uma confirmação invalida os demais
fluxos da conta. CSRF e bloqueios transacionais protegem as operações.
O formulário de login SIGAA/local também exige CSRF, pois a autenticação
SIGAA recém-realizada autoriza o cadastro inicial de e-mail.

`Email_Limite` remove contadores sem uso há dois dias durante as operações.
Desafios são substituídos no reenvio e removidos na confirmação/cancelamento;
para reduzir a retenção dos cadastros abandonados, pode-se executar periodicamente:

```sql
DELETE FROM Email_Verificacao WHERE expira_em < UNIX_TIMESTAMP() - 86400;
```

Isso remove somente desafios vencidos há mais de um dia, sem excluir usuários.
