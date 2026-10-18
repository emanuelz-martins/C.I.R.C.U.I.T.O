-- Até três usuários fixados por administrador, sem alterar os perfis de acesso.
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
