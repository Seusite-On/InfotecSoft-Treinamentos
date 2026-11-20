-- Super usuário: libera todas as permissões administrativas e todos os módulos (inclusive os criados depois)
ALTER TABLE user_permissions ADD COLUMN is_super TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Super usuário: todas as permissões' AFTER user_id;
