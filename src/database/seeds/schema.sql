-- ============================================================
--  C.I.R.C.U.I.T.O. – Modelo Físico MySQL
--  Gerado com base na Modelagem Lógica (Figura 5)
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
    matricula             VARCHAR(50)        NULL,
    tipo_perfil           VARCHAR(30)    NOT NULL,
    bloqueado             TINYINT(1)     NOT NULL DEFAULT 0,
    preferencias_notific  VARCHAR(255)       NULL,
    PRIMARY KEY (id_user)
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
-- Migração: adiciona coluna tipo em Notificacao
-- 'automatica' = gerada pelo sistema (pedidos, status, etc.)
-- 'aviso'      = mensagem direta enviada pelo laboratorista
-- ------------------------------------------------------------
ALTER TABLE Notificacao
    ADD COLUMN IF NOT EXISTS tipo VARCHAR(20) NOT NULL DEFAULT 'automatica' AFTER mensagem;

-- ------------------------------------------------------------
-- Migração: adiciona coluna foto_perfil em Usuario
-- ------------------------------------------------------------
ALTER TABLE Usuario
    ADD COLUMN IF NOT EXISTS foto_perfil VARCHAR(255) NULL AFTER tipo_perfil;

-- ------------------------------------------------------------
-- Migração: adiciona coluna humor em Notificacao
-- 'feliz'  = pedido aceito / boas notícias
-- 'triste' = pedido negado / atraso
-- 'neutro' = aviso de prazo / mensagem neutra
-- NULL     = sem rosto (usa padrão)
-- ------------------------------------------------------------
ALTER TABLE Notificacao
    ADD COLUMN IF NOT EXISTS humor VARCHAR(10) NULL DEFAULT NULL AFTER tipo;

-- ------------------------------------------------------------
-- Migração: perfil expandido do usuário
-- email, turma, descricao
-- ------------------------------------------------------------
ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS email    VARCHAR(150) NULL AFTER foto_perfil;
ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS turma    VARCHAR(100) NULL AFTER email;
ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS descricao TEXT         NULL AFTER turma;

-- ------------------------------------------------------------
-- Turma: categorias de turmas criadas pelo administrador
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Turma (
    id_turma  INT          NOT NULL AUTO_INCREMENT,
    nome      VARCHAR(100) NOT NULL,
    descricao VARCHAR(300) NULL,
    PRIMARY KEY (id_turma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Migração: controle de posse de pedido por laboratorista
-- id_laboratorista_responsavel / nome_laboratorista_responsavel
-- fluxo_livre_laboratoristas = 1 libera acesso para todos sem etapa inicial
-- ------------------------------------------------------------
ALTER TABLE Pedido
    ADD COLUMN IF NOT EXISTS id_laboratorista_responsavel INT NULL AFTER id_user;

ALTER TABLE Pedido
    ADD COLUMN IF NOT EXISTS nome_laboratorista_responsavel VARCHAR(150) NULL AFTER id_laboratorista_responsavel;

ALTER TABLE Pedido
    ADD COLUMN IF NOT EXISTS fluxo_livre_laboratoristas TINYINT(1) NOT NULL DEFAULT 0 AFTER nome_laboratorista_responsavel;

-- ------------------------------------------------------------
-- Migração: suporte a notas obrigatórias de atraso
-- ------------------------------------------------------------
ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS id_pedido INT NULL;
ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS id_nota_atraso INT NULL;
ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS requer_resposta TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS resposta_pendente TINYINT(1) NOT NULL DEFAULT 0;

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

-- ------------------------------------------------------------
-- Migração: chat unificado de acompanhamento do pedido
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Pedido_Chat (
    id_chat INT NOT NULL AUTO_INCREMENT,
    id_pedido INT NOT NULL,
    id_user INT NOT NULL,
    id_laboratorista INT NULL,
    status_renovacao VARCHAR(20) NOT NULL DEFAULT 'nenhuma',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_chat),
    UNIQUE KEY uk_pedido_chat (id_pedido),
    KEY idx_chat_user (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Pedido_Chat_Mensagem (
    id_msg INT NOT NULL AUTO_INCREMENT,
    id_chat INT NOT NULL,
    autor_tipo VARCHAR(20) NOT NULL,
    tipo_evento VARCHAR(40) NOT NULL DEFAULT 'mensagem',
    mensagem TEXT NOT NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_msg),
    KEY idx_pcm_chat_data (id_chat, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
