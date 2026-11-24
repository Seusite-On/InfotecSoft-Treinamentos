-- Sistemas criados e editados pelo portal: ícone, cor e "aberto para todos os usuários aprovados"
ALTER TABLE systems ADD COLUMN icon VARCHAR(30) NULL AFTER description;
ALTER TABLE systems ADD COLUMN color VARCHAR(7) NULL AFTER icon;
ALTER TABLE systems ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER color;
UPDATE systems SET is_public = 1, icon = 'receipt', color = '#7C3AED' WHERE system_id = 'fiscal'
