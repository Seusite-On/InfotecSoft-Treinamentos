-- Registro de acessos (logins, tentativas e saídas) para o painel do administrador
CREATE TABLE IF NOT EXISTS access_logs (
    log_id     BIGINT       NOT NULL AUTO_INCREMENT,
    user_id    INT          NULL,
    username   VARCHAR(150) NULL,
    event      VARCHAR(20)  NOT NULL,
    ip         VARCHAR(45)  NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_access_created (created_at),
    KEY idx_access_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
