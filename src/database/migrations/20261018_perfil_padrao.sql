-- Mantém os perfis existentes e define estudante para novos registros sem perfil explícito.
ALTER TABLE Usuario ALTER COLUMN tipo_perfil SET DEFAULT 'estudante';
