-- Cadastro pode pedir acesso a "Todos os sistemas"
ALTER TABLE users ADD COLUMN requested_all TINYINT(1) NOT NULL DEFAULT 0 AFTER requested_system_id;
