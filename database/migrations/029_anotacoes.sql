-- Anotações pessoais por aula: cada usuário vê só as próprias, na aula onde escreveu
CREATE TABLE IF NOT EXISTS lesson_notes (
    note_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    lesson_id  INT NOT NULL,
    body       TEXT NOT NULL,
    video_time INT NULL COMMENT 'segundo do vídeo marcado na anotação',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    KEY idx_notes_user_lesson (user_id, lesson_id),
    CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_lesson FOREIGN KEY (lesson_id) REFERENCES lessons (lesson_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Permissão própria (recurso de usuário, ligado por padrão)
ALTER TABLE user_permissions ADD COLUMN can_take_notes TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Fazer anotações nas aulas'
