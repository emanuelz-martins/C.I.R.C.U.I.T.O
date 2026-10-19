# Atualizar o banco antes de publicar

Este commit inclui alterações no banco. O deploy do código não executa estas migrações.

1. Abra o phpMyAdmin do servidor e selecione o banco usado pelo site.
2. Em **Exportar**, salve uma cópia do banco atual.
3. Na aba **SQL**, cole o conteúdo de
   [`schema.sql`](../src/database/seeds/schema.sql) e execute.
   Se preferir, o mesmo arquivo pode ser selecionado em **Importar**.
4. Aguarde a conclusão sem erros. Só então publique o código deste commit.
5. Entre como administrador e confira **Gerenciar perfis de acesso** e **Gerenciar Avisos**.

O schema consolidado cria uma instalação nova e atualiza as colunas desta versão
em bancos existentes. Não é necessário executar também os arquivos separados
de migração. Eles continuam disponíveis para atualizações pontuais.
Se houver erro na importação, guarde a mensagem e resolva antes de publicar.
Após corrigir a causa, o arquivo pode ser executado novamente.

## Alterações incluídas

- Padrão `estudante` para novas contas sem perfil explícito.
- Coluna `Usuario.forma_login`, inicialmente vazia para contas sem registro do método.
- Cinco campos de bloqueio manual de pedidos em `Usuario`.
- Tabela `Aviso_Usuario_Fixado` para até três fixações por administrador.
- Identidade `Usuario.google_sub`, com índice único, para contas Google.
- `Usuario.foto_perfil` como `TEXT` para armazenar URLs de foto Google.
- Campos de perfil, notificações e controle de pedidos já usados pela aplicação.

Colunas e tabela que já existirem são mantidas. O arquivo não redefine senhas,
não troca os perfis existentes, não cadastra usuários e não remove pedidos ou outros dados.
Os perfis e as credenciais do XAMPP não são enviados para o servidor.

O `.env.example` versionado contém o ID público Google. O servidor precisa ter
esse ID no seu próprio `.env`, junto de suas configurações de conexão. O deploy
não substitui o `.env` nem executa o schema automaticamente.
