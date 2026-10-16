-- db/002_vagas.sql
-- Aplicar UMA vez (phpMyAdmin > banco "escalas" > aba SQL),
-- e também colar no final do db/schema.sql para os próximos ambientes.
--
-- Uma "vaga" diz quantas pessoas um departamento precisa em um evento.
-- Ex.: Culto de domingo, Louvor, função "Violão", quantidade 1.
-- funcao vazia ('') = qualquer membro do departamento pode ocupar a vaga.
-- É isto que a IA usa para saber o que precisa preencher.

CREATE TABLE vagas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    evento_id       INT UNSIGNED NOT NULL,
    departamento_id INT UNSIGNED NOT NULL,
    funcao          VARCHAR(80) NOT NULL DEFAULT '',
    quantidade      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_vaga (evento_id, departamento_id, funcao),
    INDEX idx_vagas_depto (departamento_id, evento_id),
    -- Se o evento for excluído, as vagas dele vão junto
    FOREIGN KEY (evento_id) REFERENCES eventos(id) ON DELETE CASCADE,
    FOREIGN KEY (departamento_id) REFERENCES departamentos(id)
) ENGINE=InnoDB;
