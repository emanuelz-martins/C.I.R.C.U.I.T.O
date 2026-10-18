-- C.I.R.C.U.I.T.O. - Atualização de perfis e Gerenciar Avisos (18/10/2026)
-- No phpMyAdmin, selecione o banco do site e importe ESTE arquivo antes de atualizar o código.
-- Não use o schema completo para atualizar uma instalação existente.
-- Não cria contas, não troca senhas, não altera perfis já atribuídos e não apaga dados.
-- Pode ser executado novamente: colunas e tabela existentes são preservadas.
-- Compatível com MySQL/MariaDB sem depender de ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

-- Novas contas sem perfil explícito passam a ser alunos; registros existentes são mantidos.
ALTER TABLE Usuario ALTER COLUMN tipo_perfil SET DEFAULT 'estudante';

-- Última forma de login bem-sucedida: Google, Teste ou SIGAA.
-- Contas existentes ficam sem método presumido até o próximo login.
SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'forma_login'),
    'SELECT ''forma_login já existe; mantida'' AS resultado',
    'ALTER TABLE Usuario ADD COLUMN forma_login VARCHAR(20) NULL AFTER hash_senha'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

-- Bloqueio de pedidos: mantém acesso à conta; não equivale ao banimento.
SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'pre_bloqueado_manual'),
    'SELECT ''pre_bloqueado_manual já existe; mantida'' AS resultado',
    'ALTER TABLE Usuario ADD COLUMN pre_bloqueado_manual TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'pre_bloqueio_motivo'),
    'SELECT ''pre_bloqueio_motivo já existe; mantida'' AS resultado',
    'ALTER TABLE Usuario ADD COLUMN pre_bloqueio_motivo TEXT NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'pre_bloqueado_por_nome'),
    'SELECT ''pre_bloqueado_por_nome já existe; mantida'' AS resultado',
    'ALTER TABLE Usuario ADD COLUMN pre_bloqueado_por_nome VARCHAR(150) NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'pre_bloqueado_por_tipo'),
    'SELECT ''pre_bloqueado_por_tipo já existe; mantida'' AS resultado',
    'ALTER TABLE Usuario ADD COLUMN pre_bloqueado_por_tipo VARCHAR(30) NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'pre_bloqueado_em'),
    'SELECT ''pre_bloqueado_em já existe; mantida'' AS resultado',
    'ALTER TABLE Usuario ADD COLUMN pre_bloqueado_em DATETIME NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

-- Até três usuários fixados por administrador, sem alterar permissões.
CREATE TABLE IF NOT EXISTS Aviso_Usuario_Fixado (
    id_admin INT NOT NULL,
    posicao TINYINT UNSIGNED NOT NULL,
    id_user INT NOT NULL,
    PRIMARY KEY (id_admin, posicao),
    UNIQUE KEY uq_aviso_fixado_usuario (id_admin, id_user),
    CONSTRAINT chk_aviso_fixado_posicao CHECK (posicao BETWEEN 1 AND 3),
    CONSTRAINT fk_aviso_fixado_admin FOREIGN KEY (id_admin) REFERENCES Usuario (id_user) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_fixado_usuario FOREIGN KEY (id_user) REFERENCES Usuario (id_user) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Atualização concluída. Agora atualize os arquivos do site.' AS resultado;
