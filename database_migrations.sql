-- Mise à niveau vers le schéma hospitalier étendu.
-- Importer d'abord database_schema.sql pour créer les nouvelles tables.
-- Cette migration peut être relancée : chaque colonne et index est vérifié.
-- Faire une sauvegarde avant l'application.

DROP PROCEDURE IF EXISTS migrate_hospital_schema;
DELIMITER $$
CREATE PROCEDURE migrate_hospital_schema()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'two_factor_enabled') THEN
        ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'two_factor_code') THEN
        ALTER TABLE users ADD COLUMN two_factor_code VARCHAR(10) NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'two_factor_expires_at') THEN
        ALTER TABLE users ADD COLUMN two_factor_expires_at DATETIME NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'cin' AND COLUMN_TYPE <> 'varchar(255)') THEN
        ALTER TABLE patients MODIFY COLUMN cin VARCHAR(255) NOT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'cin_fingerprint') THEN
        ALTER TABLE patients ADD COLUMN cin_fingerprint CHAR(64) NULL UNIQUE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'is_archived') THEN
        ALTER TABLE patients ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medecins' AND COLUMN_NAME = 'is_archived') THEN
        ALTER TABLE medecins ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultations' AND COLUMN_NAME = 'status') THEN
        ALTER TABLE consultations ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'completed';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultations' AND COLUMN_NAME = 'is_archived') THEN
        ALTER TABLE consultations ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factures' AND COLUMN_NAME = 'is_archived') THEN
        ALTER TABLE factures ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'version') THEN
        ALTER TABLE documents ADD COLUMN version INT NOT NULL DEFAULT 1;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'parent_doc_id') THEN
        ALTER TABLE documents ADD COLUMN parent_doc_id INT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'sharing_token') THEN
        ALTER TABLE documents ADD COLUMN sharing_token VARCHAR(64) NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'is_archived') THEN
        ALTER TABLE documents ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND INDEX_NAME = 'unique_document_sharing_token') THEN
        IF NOT EXISTS (SELECT sharing_token FROM documents WHERE sharing_token IS NOT NULL GROUP BY sharing_token HAVING COUNT(*) > 1) THEN
            ALTER TABLE documents ADD UNIQUE KEY unique_document_sharing_token (sharing_token);
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Duplicate document sharing tokens must be resolved before migration';
        END IF;
    END IF;
END$$
DELIMITER ;
CALL migrate_hospital_schema();
DROP PROCEDURE migrate_hospital_schema;
