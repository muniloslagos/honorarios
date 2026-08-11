-- Certificados de cumplimiento y expediente documental.
-- Compatible con MySQL/MariaDB sin ADD COLUMN IF NOT EXISTS.

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE system_users ADD COLUMN treatment ENUM(''DON'', ''DONA'') NULL AFTER last_names',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_users' AND COLUMN_NAME = 'treatment');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN boleta_amount DECIMAL(14,2) NULL AFTER boleta_date',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'boleta_amount');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN decree_date DATE NULL AFTER decree_number_text',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'decree_date');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE monthly_reports r
LEFT JOIN agreements a ON a.id = r.agreement_id
LEFT JOIN decrees agreement_decree ON agreement_decree.id = a.decree_id
LEFT JOIN decrees manual_decree ON manual_decree.honorario_user_id = r.honorario_user_id
  AND manual_decree.decree_number = r.decree_number_text
SET r.decree_date = COALESCE(r.decree_date, agreement_decree.decree_date, manual_decree.decree_date)
WHERE r.decree_date IS NULL;
ALTER TABLE monthly_report_files
  MODIFY COLUMN file_type ENUM('RESPALDO', 'CONVENIO_FIRMADO', 'DECRETO', 'BOLETA', 'CERTIFICADO', 'OTRO') NOT NULL;


