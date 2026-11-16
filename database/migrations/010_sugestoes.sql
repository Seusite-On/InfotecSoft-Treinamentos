-- Sugestões de melhoria enviadas pelos usuários
CREATE TABLE IF NOT EXISTS suggestions (
    suggestion_id INT          NOT NULL AUTO_INCREMENT,
    user_id       INT          NOT NULL,
    category      VARCHAR(20)  NOT NULL DEFAULT 'melhoria',
    message       TEXT         NOT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'nova',
    admin_note    VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NULL,
    PRIMARY KEY (suggestion_id),
    KEY idx_suggestions_status (status),
    KEY idx_suggestions_user (user_id),
    CONSTRAINT fk_suggestions_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
