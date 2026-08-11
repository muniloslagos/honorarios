-- Elimina el tratamiento Don/Doña; el certificado utiliza redacción neutral.
SET @sql = (SELECT IF(COUNT(*) > 0,
  'ALTER TABLE system_users DROP COLUMN treatment',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_users' AND COLUMN_NAME = 'treatment');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;