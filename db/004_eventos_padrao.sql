-- db/004_eventos_padrao.sql
-- Cultos padrão: eventos que se repetem toda semana (ex.: domingo 10h).
-- dia_semana usa o mesmo número do date('w') do PHP:
--   0 = domingo, 1 = segunda, 2 = terça, 3 = quarta, 4 = quinta, 5 = sexta, 6 = sábado

CREATE TABLE IF NOT EXISTS eventos_padrao (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    igreja_id   INT UNSIGNED NOT NULL,
    nome        VARCHAR(120) NOT NULL,
    dia_semana  TINYINT UNSIGNED NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fim    TIME NOT NULL,
    ativo       TINYINT(1) NOT NULL DEFAULT 1,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_padrao (igreja_id, nome, dia_semana, hora_inicio),
    CONSTRAINT ck_padrao_dia CHECK (dia_semana BETWEEN 0 AND 6),
    FOREIGN KEY (igreja_id) REFERENCES igrejas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;