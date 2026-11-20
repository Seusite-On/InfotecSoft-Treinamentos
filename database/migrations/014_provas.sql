-- Provas por módulo (perguntas de múltipla escolha) e tentativas dos usuários
CREATE TABLE IF NOT EXISTS quizzes (
    quiz_id     INT AUTO_INCREMENT PRIMARY KEY,
    module_id   INT NOT NULL,
    title       VARCHAR(150) NOT NULL,
    pass_pct    TINYINT NOT NULL DEFAULT 60,
    points      INT NOT NULL DEFAULT 30,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    updated_at  DATETIME NOT NULL,
    UNIQUE KEY uq_quiz_module (module_id),
    CONSTRAINT fk_quiz_module FOREIGN KEY (module_id) REFERENCES modules (module_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quiz_questions (
    question_id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id     INT NOT NULL,
    position    INT NOT NULL DEFAULT 0,
    question    VARCHAR(500) NOT NULL,
    options     TEXT NOT NULL,
    correct     TINYINT NOT NULL,
    explanation VARCHAR(600) NULL,
    KEY idx_qq_quiz (quiz_id, position),
    CONSTRAINT fk_qq_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes (quiz_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quiz_attempts (
    attempt_id  INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id     INT NOT NULL,
    user_id     INT NOT NULL,
    score       INT NOT NULL,
    total       INT NOT NULL,
    passed      TINYINT(1) NOT NULL DEFAULT 0,
    points      INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    KEY idx_qa_user (user_id, quiz_id),
    CONSTRAINT fk_qa_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes (quiz_id) ON DELETE CASCADE,
    CONSTRAINT fk_qa_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
