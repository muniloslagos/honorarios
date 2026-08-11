-- Complemento de trazabilidad: conserva los datos de la firma directiva
-- vigentes al momento de cada decision de Finanzas.

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE finance_report_reviews ADD COLUMN previous_director_user_id BIGINT UNSIGNED NULL AFTER finance_user_id',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_report_reviews' AND COLUMN_NAME = 'previous_director_user_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE finance_report_reviews ADD COLUMN previous_director_capacity ENUM(''TITULAR'', ''SUBROGANTE'') NULL AFTER previous_director_user_id',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_report_reviews' AND COLUMN_NAME = 'previous_director_capacity');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE finance_report_reviews ADD COLUMN previous_director_signed_at DATETIME NULL AFTER previous_director_capacity',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_report_reviews' AND COLUMN_NAME = 'previous_director_signed_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE finance_report_reviews ADD CONSTRAINT fk_finance_reviews_previous_director FOREIGN KEY (previous_director_user_id) REFERENCES system_users(id) ON DELETE SET NULL',
  'SELECT 1')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_report_reviews' AND CONSTRAINT_NAME = 'fk_finance_reviews_previous_director');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
