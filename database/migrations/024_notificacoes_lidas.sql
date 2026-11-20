-- Notificação lida por vídeo: ao clicar num vídeo novo, só ele sai da lista
CREATE TABLE IF NOT EXISTS notif_reads (
    user_id   INT      NOT NULL,
    lesson_id INT      NOT NULL,
    read_at   DATETIME NOT NULL,
    PRIMARY KEY (user_id, lesson_id),
    CONSTRAINT fk_nr_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_nr_lesson FOREIGN KEY (lesson_id) REFERENCES lessons (lesson_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
