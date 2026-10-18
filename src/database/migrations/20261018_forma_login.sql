-- Sem atribuir métodos presumidos às contas existentes.
-- O campo representa o último login bem-sucedido, não uma permissão de acesso.
ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS forma_login VARCHAR(20) NULL AFTER hash_senha;
