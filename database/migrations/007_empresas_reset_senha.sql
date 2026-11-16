-- Empresas atendidas (cadastro por CNPJ) e troca obrigatória de senha após reset pelo administrador
CREATE TABLE IF NOT EXISTS companies (
    company_id INT          NOT NULL AUTO_INCREMENT,
    cnpj       CHAR(14)     NOT NULL,
    name       VARCHAR(120) NOT NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id),
    UNIQUE KEY uq_companies_cnpj (cnpj)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO companies (cnpj, name) VALUES ('10340101000165', 'InfotecSoft');
ALTER TABLE users ADD COLUMN company_id INT NULL AFTER company_name;
ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password;
UPDATE users SET company_id = (SELECT company_id FROM companies WHERE cnpj = '10340101000165') WHERE company_id IS NULL AND REPLACE(REPLACE(REPLACE(IFNULL(document, ''), '.', ''), '/', ''), '-', '') = '10340101000165';
UPDATE lessons SET origin = 'demo' WHERE origin = 'admin' AND created_by IS NULL AND video_source = 'youtube';
