-- Última vez que o usuário abriu as notificações (para contar os vídeos novos não vistos)
ALTER TABLE users ADD COLUMN notif_seen_at DATETIME NULL AFTER last_login_at;
-- Ponto de partida: só contam como novos os vídeos publicados a partir de agora
UPDATE users SET notif_seen_at = NOW() WHERE notif_seen_at IS NULL
