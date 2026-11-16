-- Existe só um Fusion Web: o "Fusion Web 2.0" deixa de existir.
-- Vídeos publicados nele (se houver) vão para o módulo de mesmo nome do Fusion Web, e o resto é removido.
UPDATE lessons l JOIN modules m2 ON m2.module_id = l.module_id AND m2.system_id = 'fusion-web-2' JOIN modules m1 ON m1.system_id = 'fusion-web' AND m1.name = m2.name SET l.module_id = m1.module_id, l.position = l.position + 100 WHERE l.video_source <> 'none';
UPDATE modules m SET m.system_id = 'fusion-web', m.position = m.position + 100 WHERE m.system_id = 'fusion-web-2' AND EXISTS (SELECT 1 FROM lessons l WHERE l.module_id = m.module_id AND l.video_source <> 'none') AND NOT EXISTS (SELECT 1 FROM (SELECT name FROM modules WHERE system_id = 'fusion-web') x WHERE x.name = m.name);
INSERT IGNORE INTO user_module_access (user_id, module_id, created_at) SELECT DISTINCT a.user_id, m1.module_id, NOW() FROM user_module_access a JOIN modules m2 ON m2.module_id = a.module_id AND m2.system_id = 'fusion-web-2' JOIN modules m1 ON m1.system_id = 'fusion-web';
UPDATE users SET requested_system_id = 'fusion-web' WHERE requested_system_id = 'fusion-web-2';
DELETE FROM modules WHERE system_id = 'fusion-web-2';
UPDATE systems SET active = 0 WHERE system_id = 'fusion-web-2';
UPDATE systems SET position = 1 WHERE system_id = 'fusion-web';
UPDATE systems SET position = 2 WHERE system_id = 'fusion-desktop';
UPDATE systems SET position = 3 WHERE system_id = 'e-trade';
UPDATE systems SET position = 4 WHERE system_id = 'avante';
