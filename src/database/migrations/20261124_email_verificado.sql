-- Execute no banco da aplicação ANTES de publicar o código de confirmação de e-mail.
-- Pode executar novamente. Preserva usuários, senhas, pedidos e perfis.
-- Endereços antigos NÃO são considerados verificados automaticamente.
SET NAMES utf8mb4;

SET @circuito_email_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Usuario' AND COLUMN_NAME='email_verificado_em'),
    'SELECT 1', 'ALTER TABLE Usuario ADD COLUMN email_verificado_em DATETIME NULL'
);
PREPARE circuito_email_stmt FROM @circuito_email_ddl;
EXECUTE circuito_email_stmt;
DEALLOCATE PREPARE circuito_email_stmt;

SET @circuito_email_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Usuario' AND COLUMN_NAME='nome_confirmado_em'),
    'SELECT 1', 'ALTER TABLE Usuario ADD COLUMN nome_confirmado_em DATETIME NULL'
);
PREPARE circuito_email_stmt FROM @circuito_email_ddl;
EXECUTE circuito_email_stmt;
DEALLOCATE PREPARE circuito_email_stmt;

SET @circuito_email_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Usuario' AND COLUMN_NAME='email_versao'),
    'SELECT 1', 'ALTER TABLE Usuario ADD COLUMN email_versao INT UNSIGNED NOT NULL DEFAULT 0'
);
PREPARE circuito_email_stmt FROM @circuito_email_ddl;
EXECUTE circuito_email_stmt;
DEALLOCATE PREPARE circuito_email_stmt;

SET @circuito_email_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Usuario' AND COLUMN_NAME='cadastro_sigaa'),
    'SELECT 1', 'ALTER TABLE Usuario ADD COLUMN cadastro_sigaa TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE circuito_email_stmt FROM @circuito_email_ddl;
EXECUTE circuito_email_stmt;
DEALLOCATE PREPARE circuito_email_stmt;

-- Mantém a exigência mesmo se a conta tiver uma sessão local de teste aberta.
UPDATE Usuario SET cadastro_sigaa=1 WHERE forma_login='sigaa' AND cadastro_sigaa=0;

CREATE TABLE IF NOT EXISTS Email_Verificacao (
    id_user          INT NOT NULL,
    desafio          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sessao_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email_anterior   VARCHAR(150) NULL,
    versao_anterior  INT UNSIGNED NOT NULL,
    email_novo       VARCHAR(150) NOT NULL,
    nome_confirmado  VARCHAR(150) NULL,
    codigo_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expira_em        BIGINT UNSIGNED NOT NULL COMMENT 'Unix timestamp UTC',
    tentativas       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    criado_em        BIGINT UNSIGNED NOT NULL COMMENT 'Unix timestamp UTC',
    PRIMARY KEY (id_user),
    CONSTRAINT fk_email_verificacao_usuario FOREIGN KEY (id_user) REFERENCES Usuario(id_user) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Email_Limite (
    chave           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    janela          BIGINT UNSIGNED NOT NULL,
    usos            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    atualizado_em   BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (chave),
    KEY idx_email_limite_atualizado (atualizado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
