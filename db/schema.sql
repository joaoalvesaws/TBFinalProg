CREATE TABLE igrejas (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome      VARCHAR(120) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE departamentos (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    igreja_id INT UNSIGNED NOT NULL,
    nome      VARCHAR(120) NOT NULL,
    UNIQUE KEY uq_dep_igreja_nome (igreja_id, nome),
    FOREIGN KEY (igreja_id) REFERENCES igrejas(id)
) ENGINE=InnoDB;

CREATE TABLE usuarios (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    igreja_id   INT UNSIGNED NULL,          -- NULL para o administrador geral
    nome        VARCHAR(120) NOT NULL,
    email       VARCHAR(190) NOT NULL UNIQUE,
    telefone    VARCHAR(20) NULL,
    senha_hash  VARCHAR(255) NOT NULL,      -- gerado com password_hash()
    perfil      ENUM('ADMIN_GERAL','ADMIN_IGREJA','LIDER','VOLUNTARIO')
                NOT NULL DEFAULT 'VOLUNTARIO',
    ativo       TINYINT(1) NOT NULL DEFAULT 1,
    consentimento_whatsapp_em DATETIME NULL, -- LGPD: quando aceitou receber mensagens
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (igreja_id) REFERENCES igrejas(id)
) ENGINE=InnoDB;

-- Mesma pessoa em vários departamentos, com função em cada um
CREATE TABLE departamento_usuario (
    departamento_id INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    funcao          VARCHAR(80) NULL,
    e_lider         TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (departamento_id, usuario_id),
    FOREIGN KEY (departamento_id) REFERENCES departamentos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE eventos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    igreja_id   INT UNSIGNED NOT NULL,
    nome        VARCHAR(120) NOT NULL,
    data        DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fim    TIME NOT NULL,
    INDEX idx_eventos_igreja_data (igreja_id, data),
    FOREIGN KEY (igreja_id) REFERENCES igrejas(id)
) ENGINE=InnoDB;

CREATE TABLE escalas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    evento_id       INT UNSIGNED NOT NULL,
    departamento_id INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    funcao          VARCHAR(80) NULL,
    status          ENUM('RASCUNHO','ENVIADA','ACEITA') NOT NULL DEFAULT 'RASCUNHO',
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_escala_evento_usuario (evento_id, usuario_id),
    INDEX idx_escalas_usuario (usuario_id),
    FOREIGN KEY (evento_id) REFERENCES eventos(id),
    FOREIGN KEY (departamento_id) REFERENCES departamentos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- Sem coluna de motivo, por causa da LGPD
CREATE TABLE bloqueios (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED NOT NULL,
    data_inicio DATE NOT NULL,
    data_fim    DATE NOT NULL,
    INDEX idx_bloqueios_usuario (usuario_id, data_inicio, data_fim),
    CHECK (data_fim >= data_inicio),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE trocas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    escala_id       INT UNSIGNED NOT NULL,   -- escala de origem
    solicitante_id  INT UNSIGNED NOT NULL,
    destinatario_id INT UNSIGNED NOT NULL,
    status          ENUM('PENDENTE','ACEITA','RECUSADA','AGUARDANDO_LIDER',
                         'APROVADA_PELO_LIDER','REJEITADA_PELO_LIDER')
                    NOT NULL DEFAULT 'PENDENTE',
    precisa_lider   TINYINT(1) NOT NULL DEFAULT 0,  -- 1 se violou alguma regra
    aprovado_por    INT UNSIGNED NULL,
    validade        DATETIME NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (escala_id) REFERENCES escalas(id),
    FOREIGN KEY (solicitante_id) REFERENCES usuarios(id),
    FOREIGN KEY (destinatario_id) REFERENCES usuarios(id),
    FOREIGN KEY (aprovado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE auditoria (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED NULL,
    acao        VARCHAR(60) NOT NULL,        -- ex.: 'ESCALA_CRIADA'
    entidade    VARCHAR(60) NOT NULL,        -- ex.: 'escalas'
    entidade_id INT UNSIGNED NULL,
    dados       JSON NULL,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auditoria_entidade (entidade, entidade_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE notificacoes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    titulo     VARCHAR(150) NOT NULL,
    mensagem   TEXT NOT NULL,
    lida       TINYINT(1) NOT NULL DEFAULT 0,
    criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_usuario (usuario_id, lida),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- Fila lida pelo cron_avisos.php
CREATE TABLE fila_envios (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id    INT UNSIGNED NOT NULL,
    canal         ENUM('WHATSAPP','EMAIL') NOT NULL,
    template      VARCHAR(80) NULL,          -- nome do template aprovado na Meta
    mensagem      TEXT NOT NULL,
    status        ENUM('PENDENTE','EM_ENVIO','ENVIADO','ERRO') NOT NULL DEFAULT 'PENDENTE',
    tentativas    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    agendado_para DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em    DATETIME NULL,
    criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fila_pendentes (status, agendado_para),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;