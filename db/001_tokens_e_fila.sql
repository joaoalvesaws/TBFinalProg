-- db/001_tokens_e_fila.sql
-- Aplicar UMA vez (phpMyAdmin > banco "escalas" > aba SQL),
-- e também colar no final do db/schema.sql para os próximos ambientes.

-- 1) Links de "definir senha" (convite) e "recuperar senha".
--    Guardamos só o HASH do token: se alguém ler o banco, não consegue usar os links.
CREATE TABLE tokens_senha (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    tipo       ENUM('CONVITE','RECUPERACAO') NOT NULL,
    expira_em  DATETIME NOT NULL,
    usado_em   DATETIME NULL,
    criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token_hash (token_hash),
    INDEX idx_tokens_usuario (usuario_id, usado_em),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- 2) A fila ganha um carimbo de hora para o cron destravar envios
--    que ficaram presos em EM_ENVIO (ex.: o script caiu no meio).
ALTER TABLE fila_envios
    ADD COLUMN atualizado_em DATETIME NULL AFTER enviado_em;
