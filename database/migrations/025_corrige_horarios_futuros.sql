-- Corrige horários gravados no fuso do servidor (3 h à frente): nada pode ficar "no futuro"
UPDATE lessons SET video_updated_at = NOW() WHERE video_updated_at > NOW();
UPDATE lessons SET created_at = NOW() WHERE created_at > NOW();
UPDATE lessons SET updated_at = NOW() WHERE updated_at > NOW();
UPDATE users SET notif_seen_at = NOW() WHERE notif_seen_at > NOW();
UPDATE notif_reads SET read_at = NOW() WHERE read_at > NOW()
