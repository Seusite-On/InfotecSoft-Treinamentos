-- Permissões separadas para cada recurso (nem todo usuário pode tudo)
ALTER TABLE user_permissions ADD COLUMN can_manage_systems TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Criar, editar e excluir sistemas';
ALTER TABLE user_permissions ADD COLUMN can_manage_quizzes TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Criar e editar provas';
ALTER TABLE user_permissions ADD COLUMN can_view_team TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ver o progresso da equipe';
ALTER TABLE user_permissions ADD COLUMN can_view_access_log TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ver os últimos acessos';
ALTER TABLE user_permissions ADD COLUMN can_manage_suggestions TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ver e responder sugestões de todos';
ALTER TABLE user_permissions ADD COLUMN can_post_videos TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Postar vídeos (+5 pontos)';
ALTER TABLE user_permissions ADD COLUMN can_send_suggestions TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Enviar sugestões';
-- Quem já tinha o acesso "de carona" continua com ele
UPDATE user_permissions SET can_manage_systems = can_manage_modules, can_manage_quizzes = can_manage_modules, can_view_access_log = can_manage_users, can_manage_suggestions = can_manage_users, can_view_team = GREATEST(can_view_reports, can_manage_users)
