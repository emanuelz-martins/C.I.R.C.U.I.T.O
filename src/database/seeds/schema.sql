-- ============================================================
--  C.I.R.C.U.I.T.O. – Modelo Físico MySQL
--  Gerado com base na Modelagem Lógica (Figura 5)
--  Estrutura consolidada: perfis, avisos, fixações e login Google.
--  Cria um banco vazio e atualiza as colunas desta versão em bancos existentes.
--  Não cadastra contas, não apaga dados e não altera perfis já atribuídos.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Termo_Uso
-- (sem dependências externas, criado antes de Termo_Aceito)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Termo_Uso (
    id_termo   INT            NOT NULL AUTO_INCREMENT,
    versao     VARCHAR(20)    NOT NULL,
    texto      TEXT           NOT NULL,
    ativo      TINYINT(1)     NOT NULL DEFAULT 1,
    PRIMARY KEY (id_termo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Categoria
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Categoria (
    id_cat     INT            NOT NULL AUTO_INCREMENT,
    nome       VARCHAR(100)   NOT NULL,
    descricao  VARCHAR(255)       NULL,
    PRIMARY KEY (id_cat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Usuário
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Usuario (
    id_user               INT            NOT NULL AUTO_INCREMENT,
    nome                  VARCHAR(150)   NOT NULL,
    login                 VARCHAR(100)   NOT NULL UNIQUE,
    hash_senha            VARCHAR(255)   NOT NULL,
    forma_login           VARCHAR(20)        NULL,
    google_sub            VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    matricula             VARCHAR(50)        NULL,
    tipo_perfil           VARCHAR(30)    NOT NULL DEFAULT 'estudante',
    foto_perfil           TEXT               NULL,
    email                 VARCHAR(150)       NULL,
    turma                 VARCHAR(100)       NULL,
    descricao             TEXT               NULL,
    bloqueado             TINYINT(1)     NOT NULL DEFAULT 0,
    pre_bloqueado_manual   TINYINT(1)     NOT NULL DEFAULT 0,
    pre_bloqueio_motivo    TEXT               NULL,
    pre_bloqueado_por_nome VARCHAR(150)       NULL,
    pre_bloqueado_por_tipo VARCHAR(30)        NULL,
    pre_bloqueado_em       DATETIME           NULL,
    preferencias_notific  VARCHAR(255)       NULL,
    PRIMARY KEY (id_user),
    UNIQUE KEY uq_usuario_google_sub (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Termo_Aceito  (N:N entre Usuário e Termo_Uso)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Termo_Aceito (
    id_user     INT    NOT NULL,
    id_termo    INT    NOT NULL,
    data_aceite DATE   NOT NULL,
    PRIMARY KEY (id_user, id_termo),
    CONSTRAINT fk_ta_user  FOREIGN KEY (id_user)  REFERENCES Usuario   (id_user)  ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ta_termo FOREIGN KEY (id_termo) REFERENCES Termo_Uso (id_termo) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Componente
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Componente (
    id_comp         INT            NOT NULL AUTO_INCREMENT,
    id_cat          INT            NOT NULL,
    nome            VARCHAR(150)   NOT NULL,
    qtd_disponivel  INT            NOT NULL DEFAULT 0,
    qtd_max_user    INT            NOT NULL DEFAULT 1,
    nivel_minimo    INT            NOT NULL DEFAULT 0,
    status_atual    VARCHAR(30)    NOT NULL DEFAULT 'disponivel',
    imagem_url      VARCHAR(255)       NULL,
    descricao       VARCHAR(255)       NULL,
    PRIMARY KEY (id_comp),
    CONSTRAINT fk_comp_cat FOREIGN KEY (id_cat) REFERENCES Categoria (id_cat) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Movimentacao_Estoque
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Movimentacao_Estoque (
    id_mov    BIGINT         NOT NULL AUTO_INCREMENT,
    id_comp   INT            NOT NULL,
    id_user   INT            NOT NULL,
    tipo      VARCHAR(30)    NOT NULL,
    quantidade INT           NOT NULL,
    data      DATE           NOT NULL,
    motivo    VARCHAR(255)       NULL,
    PRIMARY KEY (id_mov),
    CONSTRAINT fk_mov_comp FOREIGN KEY (id_comp) REFERENCES Componente (id_comp) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_mov_user FOREIGN KEY (id_user) REFERENCES Usuario    (id_user) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Log_Auditoria
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Log_Auditoria (
    id_log          BIGINT         NOT NULL AUTO_INCREMENT,
    id_user         INT            NOT NULL,
    origem_ip       VARCHAR(45)        NULL,
    acao            VARCHAR(100)   NOT NULL,
    tabela_afetada  VARCHAR(100)       NULL,
    detalhes_json   TEXT               NULL,
    data_hora       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_log),
    CONSTRAINT fk_log_user FOREIGN KEY (id_user) REFERENCES Usuario (id_user) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Notificacao
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Notificacao (
    id_not    INT            NOT NULL AUTO_INCREMENT,
    id_user   INT            NOT NULL,
    titulo    VARCHAR(150)   NOT NULL,
    mensagem  TEXT           NOT NULL,
    tipo      VARCHAR(20)    NOT NULL DEFAULT 'automatica',
    humor     VARCHAR(10)        NULL DEFAULT NULL,
    id_pedido INT                NULL,
    id_nota_atraso INT           NULL,
    requer_resposta TINYINT(1) NOT NULL DEFAULT 0,
    resposta_pendente TINYINT(1) NOT NULL DEFAULT 0,
    lida      TINYINT(1)     NOT NULL DEFAULT 0,
    data      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_not),
    CONSTRAINT fk_not_user FOREIGN KEY (id_user) REFERENCES Usuario (id_user) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Pedido
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Pedido (
    id_pedido              INT            NOT NULL AUTO_INCREMENT,
    id_user                INT            NOT NULL,
    id_laboratorista_responsavel INT           NULL,
    nome_laboratorista_responsavel VARCHAR(150) NULL,
    fluxo_livre_laboratoristas TINYINT(1) NOT NULL DEFAULT 0,
    codigo_rastreio        VARCHAR(50)        NULL,
    status                 VARCHAR(30)    NOT NULL DEFAULT 'enviado',
    obs_laboratorista      TEXT               NULL,
    data_criacao           DATE           NOT NULL,
    data_retirada_prevista DATE               NULL,
    data_retirada_real     DATE               NULL,
    data_devolucao_prevista DATE              NULL,
    data_devolucao_real    DATE               NULL,
    PRIMARY KEY (id_pedido),
    CONSTRAINT fk_ped_user FOREIGN KEY (id_user) REFERENCES Usuario (id_user) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Item_Pedido
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Item_Pedido (
    id_item          INT          NOT NULL AUTO_INCREMENT,
    id_pedido        INT          NOT NULL,
    id_comp          INT          NOT NULL,
    qtd_solicitada   INT          NOT NULL,
    qtd_aprovada     INT              NULL,
    estado_devolucao VARCHAR(30)      NULL,
    PRIMARY KEY (id_item),
    CONSTRAINT fk_ip_pedido FOREIGN KEY (id_pedido) REFERENCES Pedido     (id_pedido) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT fk_ip_comp   FOREIGN KEY (id_comp)   REFERENCES Componente (id_comp)   ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Ocorrencia
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Ocorrencia (
    id_ocorrencia  INT            NOT NULL AUTO_INCREMENT,
    id_item        INT            NOT NULL,
    tipo           VARCHAR(50)    NOT NULL,
    descricao      TEXT               NULL,
    custo_reposicao DECIMAL(10,2)     NULL,
    PRIMARY KEY (id_ocorrencia),
    CONSTRAINT fk_oc_item FOREIGN KEY (id_item) REFERENCES Item_Pedido (id_item) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Renovacao
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Renovacao (
    id_renovacao INT          NOT NULL AUTO_INCREMENT,
    id_pedido    INT          NOT NULL,
    nova_data    DATE         NOT NULL,
    status       VARCHAR(30)  NOT NULL DEFAULT 'pendente',
    motivo       TEXT             NULL,
    PRIMARY KEY (id_renovacao),
    CONSTRAINT fk_ren_pedido FOREIGN KEY (id_pedido) REFERENCES Pedido (id_pedido) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Turma: categorias de turmas criadas pelo administrador
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Turma (
    id_turma  INT          NOT NULL AUTO_INCREMENT,
    nome      VARCHAR(100) NOT NULL,
    descricao VARCHAR(300) NULL,
    PRIMARY KEY (id_turma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Pedido_Atraso_Nota (
    id_nota INT NOT NULL AUTO_INCREMENT,
    id_pedido INT NOT NULL,
    id_user INT NOT NULL,
    id_laboratorista INT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'aguardando-aluno',
    obrigatoria TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_nota),
    KEY idx_pan_pedido_status (id_pedido, status),
    KEY idx_pan_user_status (id_user, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Pedido_Atraso_Mensagem (
    id_msg INT NOT NULL AUTO_INCREMENT,
    id_nota INT NOT NULL,
    autor_tipo VARCHAR(20) NOT NULL,
    mensagem TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_msg),
    KEY idx_pam_nota_data (id_nota, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Até três usuários fixados por administrador em Gerenciar Avisos.
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

-- ------------------------------------------------------------
-- Atualização de instalações anteriores (reexecutável)
-- CREATE TABLE IF NOT EXISTS não acrescenta colunas a tabelas existentes.
-- As verificações abaixo funcionam em MySQL e MariaDB.
-- SIGAA mantém o CPF formatado em login. Não cria coluna cpf.
-- Google usa google_sub e um login técnico próprio.
-- ------------------------------------------------------------
ALTER TABLE Usuario ALTER COLUMN tipo_perfil SET DEFAULT 'estudante';

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'forma_login'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD COLUMN forma_login VARCHAR(20) NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'google_sub'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD COLUMN google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'foto_perfil'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD COLUMN foto_perfil TEXT NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'email'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD COLUMN email VARCHAR(150) NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'turma'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD COLUMN turma VARCHAR(100) NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'descricao'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD COLUMN descricao TEXT NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'pre_bloqueado_manual'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD COLUMN pre_bloqueado_manual TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'pre_bloqueio_motivo'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD COLUMN pre_bloqueio_motivo TEXT NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'pre_bloqueado_por_nome'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD COLUMN pre_bloqueado_por_nome VARCHAR(150) NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'pre_bloqueado_por_tipo'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD COLUMN pre_bloqueado_por_tipo VARCHAR(30) NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'pre_bloqueado_em'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD COLUMN pre_bloqueado_em DATETIME NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Notificacao' AND COLUMN_NAME = 'tipo'),
    'SELECT 1',
    'ALTER TABLE Notificacao ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT ''automatica'''
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Notificacao' AND COLUMN_NAME = 'humor'),
    'SELECT 1',
    'ALTER TABLE Notificacao ADD COLUMN humor VARCHAR(10) NULL DEFAULT NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Notificacao' AND COLUMN_NAME = 'id_pedido'),
    'SELECT 1',
    'ALTER TABLE Notificacao ADD COLUMN id_pedido INT NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Notificacao' AND COLUMN_NAME = 'id_nota_atraso'),
    'SELECT 1',
    'ALTER TABLE Notificacao ADD COLUMN id_nota_atraso INT NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Notificacao' AND COLUMN_NAME = 'requer_resposta'),
    'SELECT 1',
    'ALTER TABLE Notificacao ADD COLUMN requer_resposta TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Notificacao' AND COLUMN_NAME = 'resposta_pendente'),
    'SELECT 1',
    'ALTER TABLE Notificacao ADD COLUMN resposta_pendente TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Pedido' AND COLUMN_NAME = 'id_laboratorista_responsavel'),
    'SELECT 1',
    'ALTER TABLE Pedido ADD COLUMN id_laboratorista_responsavel INT NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Pedido' AND COLUMN_NAME = 'nome_laboratorista_responsavel'),
    'SELECT 1',
    'ALTER TABLE Pedido ADD COLUMN nome_laboratorista_responsavel VARCHAR(150) NULL'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Pedido' AND COLUMN_NAME = 'fluxo_livre_laboratoristas'),
    'SELECT 1',
    'ALTER TABLE Pedido ADD COLUMN fluxo_livre_laboratoristas TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;
-- Amplia URLs de foto sem truncar ou substituir valores existentes.
ALTER TABLE Usuario MODIFY COLUMN foto_perfil TEXT NULL;

SET @circuito_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND INDEX_NAME = 'uq_usuario_google_sub'),
    'SELECT 1',
    'ALTER TABLE Usuario ADD UNIQUE KEY uq_usuario_google_sub (google_sub)'
);
PREPARE circuito_stmt FROM @circuito_ddl;
EXECUTE circuito_stmt;
DEALLOCATE PREPARE circuito_stmt;
