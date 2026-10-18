# Perfis de acesso

Entre como administrador e abra **Gerenciar perfis de acesso** no painel inicial.
Busque o usuário por nome ou CPF e clique na linha ou no nome. Um painel abre
abaixo da lista, na mesma página, com ID, nome, matrícula, e-mail, forma de login
e perfil em destaque. Campos não preenchidos aparecem como `—`.

**Forma de Login** exibe o último método de autenticação bem-sucedido:
Google, Teste ou SIGAA. Os logins atuais gravam Teste ou SIGAA automaticamente;
a integração Google ainda não está implementada. Contas sem registro de login
mostram `—` até entrar novamente. Alterar o perfil não muda esse campo.
No painel, selecione Aluno, Laboratorista ou Administrador e clique em
**Salvar perfil**. O perfil destacado e o tipo na lista são atualizados sem
recarregar a página. **Voltar** fecha o painel e mantém a busca.
A composição visual reutiliza o estilo de `public/pages_admin/dados_pedido.php`.
A lista inclui todos os perfis, inclusive administradores.

## Gerenciar Avisos

O cartão **Gerenciar Avisos** abre diretamente a lista de usuários de todos os
perfis. O subtítulo de cada cartão identifica Aluno, Laboratorista ou
Administrador. Os mesmos cartões, menus e modais existentes são preservados.

**Filtrar** permite selecionar um ou mais perfis e as situações **Bloqueados**
e **Banidos**. Perfis selecionados são combinados entre si, assim como as
situações; quando os dois grupos são usados, o usuário deve atender a ambos.
Bloqueados inclui bloqueio manual ou automático de pedidos e exclui banidos.
A busca por nome é mantida ao aplicar ou limpar filtros. Sem seleção, todos
os usuários são exibidos. As seleções ficam preservadas ao pesquisar novamente.

O menu oferece **Mandar aviso**, **Bloquear** e **Editar**. O bloqueio
mantém o acesso à conta e às consultas, mas impede adicionar itens ao carrinho
e enviar novos pedidos, inclusive por requisições diretas.
**Banir**, em roxo, aparece somente após bloqueio manual ou automático e
impede o acesso à conta. Usuários já banidos oferecem **Remover banimento**;
a restrição existente para devoluções pendentes é mantida.
O servidor também verifica o bloqueio antes de banir, exige CSRF nas ações
administrativas e impede o administrador de banir a própria conta.

| Perfil | Acesso |
|---|---|
| Aluno (`estudante`) | Catálogo, carrinho, seus pedidos, perfil e notificações |
| Laboratorista (`laboratorista`) | Painel do laboratório e gestão de alunos; não acessa painéis de aluno ou administrador |
| Administrador (`admin`) | Gestão de usuários e perfis, além dos recursos de gestão existentes |

Novas contas começam como alunos quando a autenticação usa SIGAA.
Entrar novamente preserva o perfil atribuído pelo administrador. Esta
etapa não implementa Google nem altera o serviço institucional de autenticação.

O perfil e o bloqueio são consultados no banco em cada requisição protegida.
Assim, promoções e rebaixamentos valem no próximo acesso, sem exigir logout.
Contas bloqueadas, removidas ou com perfil desconhecido não recebem acesso.
Somente administradores ativos alteram perfis. A alteração possui proteção
CSRF e grava autor, alvo e perfis anterior/novo em `Log_Auditoria`.
Para alterar o próprio perfil, é necessário outro administrador; essa regra
evita que o único administrador remova seu próprio acesso.

## Banco existente e XAMPP

Para atualizar um site existente pelo phpMyAdmin, importe primeiro o arquivo único
[`20261018_atualizacao_completa.sql`](../src/database/migrations/20261018_atualizacao_completa.sql)
e depois publique o código. Siga as [instruções de importação](ATUALIZACAO_PHPMYADMIN.md).
Esse arquivo reúne as migrações abaixo; não é necessário executar ambas as opções.

Não é necessário recriar o banco ou recadastrar usuários. A migração mantém os
perfis existentes e define o valor padrão para novos registros:

```sql
SOURCE src/database/migrations/20261018_perfil_padrao.sql;
SOURCE src/database/migrations/20261018_forma_login.sql;
SOURCE src/database/migrations/20261018_pre_bloqueio.sql;
SOURCE src/database/migrations/20261018_avisos_fixados.sql;
```

Use o cliente MySQL na pasta do projeto ou execute o conteúdo do arquivo pelo
phpMyAdmin. Instalações novas usam `src/database/seeds/schema.sql`.

No `.env`, use a porta configurada no MySQL do XAMPP. Neste ambiente ela é
`DB_PORT=3307`, e a URL é `http://localhost/C.I.R.C.U.I.T.O/public`.
Entre com uma conta local existente e selecione **Teste (banco local)** para
validar sem depender do serviço institucional. Senhas e perfis existentes não
são redefinidos por esta mudança.

## Verificação local

Em **Gerenciar Avisos**, o botão de alfinete ao lado das ações fixa até três
usuários no topo da lista. As fixações são salvas no banco por administrador e
respeitam a busca e os filtros. Desafixar libera uma vaga. O limite também é
validado no servidor, inclusive quando existem abas abertas ao mesmo tempo.

Com Apache/MySQL ativos e a cópia servida atualizada, execute:

```powershell
C:/xampp/php/php.exe tests/perfis.php
```

A suíte exige APP_ENV local, banco XAMPP e endereços de loopback. Cria contas
temporárias, testa restrições de páginas/APIs, promoção, rebaixamento, CSRF,
auditoria e sessões existentes, e remove seus registros ao terminar. Não
precisa de credenciais SIGAA/Google e preserva as contas originais.
