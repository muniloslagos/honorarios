-- Tipo de buzón para determinar el cargo que se estampa en la firma.
-- DIRECCION: Director(a)
-- DEPARTAMENTO: Jefe(a)

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE directions ADD COLUMN mailbox_type ENUM(''DIRECCION'', ''DEPARTAMENTO'') NOT NULL DEFAULT ''DIRECCION'' AFTER code',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'directions'
    AND COLUMN_NAME = 'mailbox_type');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
