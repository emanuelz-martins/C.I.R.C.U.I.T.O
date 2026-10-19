-- Adiciona a identidade Google sem alterar os logins, IDs ou dados existentes.
-- O login SIGAA continua contendo o CPF formatado. Não cria coluna cpf.
SET NAMES utf8mb4;

SET @circuito_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Usuario' AND COLUMN_NAME='google_sub'),
 'SELECT 1', 'ALTER TABLE Usuario ADD COLUMN google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL');
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Usuario' AND INDEX_NAME='uq_usuario_google_sub'),
 'SELECT 1', 'ALTER TABLE Usuario ADD UNIQUE KEY uq_usuario_google_sub (google_sub)');
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

-- Permite URLs de foto Google sem truncar; preserva fotos existentes.
ALTER TABLE Usuario MODIFY COLUMN foto_perfil TEXT NULL;

SELECT 'Identidade Google adicionada. Logins existentes preservados.' AS resultado;
