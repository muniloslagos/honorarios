-- Revision de Finanzas, aprobacion de pago y trazabilidad documental.
-- Compatible con MySQL/MariaDB sin ADD COLUMN IF NOT EXISTS.

ALTER TABLE monthly_reports
  MODIFY COLUMN status ENUM('BORRADOR', 'ENVIADO', 'OBSERVADO', 'APROBADO', 'RECHAZADO', 'APROBADO_PAGO') NOT NULL DEFAULT 'BORRADOR';

ALTER TABLE monthly_report_files
  MODIFY COLUMN file_type ENUM('RESPALDO', 'CONVENIO_FIRMADO', 'DECRETO', 'BOLETA', 'CERTIFICADO', 'HISTORICO', 'OTRO') NOT NULL;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN finance_reviewed_by_user_id BIGINT UNSIGNED NULL AFTER director_rejected_at',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'finance_reviewed_by_user_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN finance_approved_at DATETIME NULL AFTER finance_reviewed_by_user_id',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'finance_approved_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN finance_rejected_at DATETIME NULL AFTER finance_approved_at',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'finance_rejected_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN finance_observation TEXT NULL AFTER finance_rejected_at',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'finance_observation');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD CONSTRAINT fk_monthly_reports_finance_user FOREIGN KEY (finance_reviewed_by_user_id) REFERENCES system_users(id) ON DELETE SET NULL',
  'SELECT 1')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND CONSTRAINT_NAME = 'fk_monthly_reports_finance_user');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS finance_report_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id BIGINT UNSIGNED NOT NULL,
  finance_user_id BIGINT UNSIGNED NOT NULL,
  action ENUM('APROBADO_PAGO', 'RECHAZADO') NOT NULL,
  observation TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_finance_reviews_report (report_id),
  KEY idx_finance_reviews_user (finance_user_id),
  CONSTRAINT fk_finance_reviews_report FOREIGN KEY (report_id) REFERENCES monthly_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_finance_reviews_user FOREIGN KEY (finance_user_id) REFERENCES system_users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS monthly_report_file_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id BIGINT UNSIGNED NOT NULL,
  source_file_id BIGINT UNSIGNED NULL,
  finance_review_id BIGINT UNSIGNED NULL,
  stage ENUM('ORIGINAL', 'FIRMADO_FUNCIONARIO', 'FIRMADO_DIRECTOR', 'CERTIFICADO') NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_report_file_history_version (report_id, stage, stored_path),
  KEY idx_report_file_history_report (report_id),
  KEY idx_report_file_history_review (finance_review_id),
  CONSTRAINT fk_report_file_history_report FOREIGN KEY (report_id) REFERENCES monthly_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_file_history_source FOREIGN KEY (source_file_id) REFERENCES monthly_report_files(id) ON DELETE SET NULL,
  CONSTRAINT fk_report_file_history_review FOREIGN KEY (finance_review_id) REFERENCES finance_report_reviews(id) ON DELETE SET NULL
) ENGINE=InnoDB;
