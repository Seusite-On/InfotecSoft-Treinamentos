-- =============================================================
-- Portal InfotecSoft Treinamentos - estrutura inicial
-- Padrão: um arquivo .sql por alteração, aplicado pelo database/scripts/migrate.php
-- (não use ponto e vírgula dentro de textos: o migrate divide o arquivo por ele)
-- =============================================================

CREATE TABLE IF NOT EXISTS systems (
    system_id   VARCHAR(40)  NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    description VARCHAR(255) NULL,
    position    INT          NOT NULL DEFAULT 0,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modules (
    module_id  INT          NOT NULL AUTO_INCREMENT,
    system_id  VARCHAR(40)  NOT NULL,
    name       VARCHAR(80)  NOT NULL,
    icon       VARCHAR(30)  NOT NULL DEFAULT 'book',
    position   INT          NOT NULL DEFAULT 0,
    created_by INT          NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NULL,
    PRIMARY KEY (module_id),
    UNIQUE KEY uq_modules_system_name (system_id, name),
    KEY idx_modules_system_position (system_id, position),
    CONSTRAINT fk_modules_system FOREIGN KEY (system_id) REFERENCES systems (system_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    user_id             INT          NOT NULL AUTO_INCREMENT,
    name                VARCHAR(120) NOT NULL,
    company_name        VARCHAR(150) NULL,
    document            VARCHAR(20)  NULL COMMENT 'CPF ou CNPJ formatado',
    email               VARCHAR(150) NOT NULL,
    username            VARCHAR(30)  NOT NULL,
    password            VARCHAR(255) NOT NULL COMMENT 'hash gerado por password_hash()',
    role                ENUM('admin','user') NOT NULL DEFAULT 'user' COMMENT 'admin = administrador principal',
    status              ENUM('pendente','aprovado','recusado') NOT NULL DEFAULT 'pendente',
    revoked             TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = acesso revogado depois de aprovado',
    requested_system_id VARCHAR(40)  NULL COMMENT 'sistema informado no cadastro',
    decided_at          DATETIME     NULL,
    decided_by          INT          NULL,
    last_login_at       DATETIME     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_email (email),
    KEY idx_users_status (status),
    CONSTRAINT fk_users_requested_system FOREIGN KEY (requested_system_id) REFERENCES systems (system_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões administrativas (mesmo modelo da user_permissions do Stok Net)
CREATE TABLE IF NOT EXISTS user_permissions (
    user_id            INT        NOT NULL,
    can_approve_users  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Aprovar cadastros',
    can_manage_users   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Gerenciar usuários e permissões',
    can_manage_modules TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Criar e organizar módulos',
    can_manage_videos  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Publicar e editar vídeos',
    updated_by         INT        NULL,
    updated_at         DATETIME   NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quais módulos cada usuário pode assistir
CREATE TABLE IF NOT EXISTS user_module_access (
    user_id    INT      NOT NULL,
    module_id  INT      NOT NULL,
    granted_by INT      NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, module_id),
    KEY idx_uma_module (module_id),
    CONSTRAINT fk_uma_user   FOREIGN KEY (user_id)   REFERENCES users (user_id)     ON DELETE CASCADE,
    CONSTRAINT fk_uma_module FOREIGN KEY (module_id) REFERENCES modules (module_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aulas (com vídeo do YouTube, arquivo enviado ou ainda sem vídeo)
CREATE TABLE IF NOT EXISTS lessons (
    lesson_id        INT             NOT NULL AUTO_INCREMENT,
    module_id        INT             NOT NULL,
    title            VARCHAR(150)    NOT NULL,
    description      TEXT            NULL,
    duration         VARCHAR(10)     NULL COMMENT 'mm:ss',
    position         INT             NOT NULL DEFAULT 0,
    origin           ENUM('demo','admin') NOT NULL DEFAULT 'admin' COMMENT 'demo = aula do catálogo inicial',
    video_source     ENUM('none','youtube','file') NOT NULL DEFAULT 'none',
    youtube_id       VARCHAR(20)     NULL,
    file_path        VARCHAR(255)    NULL COMMENT 'relativo a storage/videos',
    file_name        VARCHAR(255)    NULL COMMENT 'nome original do arquivo enviado',
    file_size        BIGINT UNSIGNED NULL,
    file_mime        VARCHAR(80)     NULL,
    thumb_path       VARCHAR(255)    NULL COMMENT 'relativo a storage/thumbs',
    created_by       INT             NULL,
    video_updated_at DATETIME        NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NULL,
    PRIMARY KEY (lesson_id),
    KEY idx_lessons_module_position (module_id, position),
    KEY idx_lessons_source (video_source),
    CONSTRAINT fk_lessons_module FOREIGN KEY (module_id) REFERENCES modules (module_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
