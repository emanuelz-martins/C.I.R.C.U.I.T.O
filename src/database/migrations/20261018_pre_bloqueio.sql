-- Campos já usados pelo pré-bloqueio manual; preserva os valores existentes.
ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_manual TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueio_motivo TEXT NULL;
ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_por_nome VARCHAR(150) NULL;
ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_por_tipo VARCHAR(30) NULL;
ALTER TABLE Usuario ADD COLUMN IF NOT EXISTS pre_bloqueado_em DATETIME NULL;
