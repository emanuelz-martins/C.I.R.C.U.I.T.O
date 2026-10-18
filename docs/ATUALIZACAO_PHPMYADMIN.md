# Atualizar o banco antes de publicar

Este commit inclui alterações no banco. O deploy do código não executa estas migrações.

1. Abra o phpMyAdmin do servidor e selecione o banco usado pelo site.
2. Em **Exportar**, salve uma cópia do banco atual.
3. Em **Importar**, selecione [`20261018_atualizacao_completa.sql`](../src/database/migrations/20261018_atualizacao_completa.sql) e execute.
   Também é possível copiar todo o conteúdo desse arquivo para a aba **SQL**.
4. Aguarde a conclusão sem erros. Só então publique o código deste commit.
5. Entre como administrador e confira **Gerenciar perfis de acesso** e **Gerenciar Avisos**.

O arquivo é único e já inclui as quatro migrações desta atualização. Não é necessário
importar os outros arquivos de migração nem o `seeds/schema.sql` em um banco existente.
Se houver erro na importação, guarde a mensagem e resolva antes de publicar.
Após corrigir a causa, o arquivo pode ser executado novamente.

## Alterações incluídas

- Padrão `estudante` para novas contas sem perfil explícito.
- Coluna `Usuario.forma_login`, inicialmente vazia para contas sem registro do método.
- Cinco campos de bloqueio manual de pedidos em `Usuario`.
- Tabela `Aviso_Usuario_Fixado` para até três fixações por administrador.

Colunas e tabela que já existirem são mantidas. O arquivo não redefine senhas,
não troca os perfis existentes, não cadastra usuários e não remove pedidos ou outros dados.
Os perfis e as credenciais do XAMPP não são enviados para o servidor.
