-- Pontos por vídeo postado (5 pontos para quem publica um vídeo novo no portal)
ALTER TABLE lessons ADD COLUMN post_points INT NOT NULL DEFAULT 0 AFTER created_by
