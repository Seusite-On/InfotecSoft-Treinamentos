-- Senha temporária (reset pelo admin) passa a valer só por 48 horas
ALTER TABLE users ADD COLUMN password_reset_at DATETIME NULL AFTER must_change_password;
