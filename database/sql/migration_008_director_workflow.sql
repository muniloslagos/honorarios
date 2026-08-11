-- Direcciones, directores y flujo final de firma de informes.
-- Compatible con versiones antiguas de MySQL/MariaDB.
-- Puede ejecutarse nuevamente si una importacion anterior quedo incompleta.

ALTER TABLE system_users
  MODIFY COLUMN role ENUM('ADMINISTRADOR', 'RRHH', 'FINANZAS', 'HONORARIO', 'DIRECTOR') NOT NULL;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE system_users ADD COLUMN direction_id BIGINT UNSIGNED NULL AFTER profession_experience',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_users' AND COLUMN_NAME = 'direction_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS directions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  code VARCHAR(40) NOT NULL,
  mailbox_type ENUM('DIRECCION', 'DEPARTAMENTO') NOT NULL DEFAULT 'DIRECCION',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_directions_name (name),
  UNIQUE KEY uq_directions_code (code)
) ENGINE=InnoDB;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE system_users ADD CONSTRAINT fk_system_users_direction FOREIGN KEY (direction_id) REFERENCES directions(id) ON DELETE SET NULL',
  'SELECT 1')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'system_users' AND CONSTRAINT_NAME = 'fk_system_users_direction');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS director_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  system_user_id BIGINT UNSIGNED NOT NULL,
  official_position VARCHAR(180) NOT NULL,
  local_username VARCHAR(80) NULL,
  local_password_hash VARCHAR(255) NULL,
  signature_original_name VARCHAR(255) NULL,
  signature_path VARCHAR(255) NULL,
  signature_mime_type VARCHAR(80) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_director_profiles_user (system_user_id),
  UNIQUE KEY uq_director_profiles_username (local_username),
  CONSTRAINT fk_director_profiles_user FOREIGN KEY (system_user_id) REFERENCES system_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS director_directions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  director_profile_id BIGINT UNSIGNED NOT NULL,
  direction_id BIGINT UNSIGNED NOT NULL,
  assignment_type ENUM('PRINCIPAL', 'SUBROGANTE') NOT NULL,
  administrative_order INT UNSIGNED NOT NULL DEFAULT 1,
  decree_reference VARCHAR(180) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_director_direction (director_profile_id, direction_id),
  KEY idx_director_directions_direction (direction_id),
  CONSTRAINT fk_director_directions_profile FOREIGN KEY (director_profile_id) REFERENCES director_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_director_directions_direction FOREIGN KEY (direction_id) REFERENCES directions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN direction_id BIGINT UNSIGNED NULL AFTER supervision_unit',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'direction_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN reviewed_by_director_user_id BIGINT UNSIGNED NULL AFTER observations',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'reviewed_by_director_user_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN director_capacity ENUM(''TITULAR'', ''SUBROGANTE'') NULL AFTER reviewed_by_director_user_id',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'director_capacity');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN director_rejection_observation TEXT NULL AFTER director_capacity',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'director_rejection_observation');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN director_signed_at DATETIME NULL AFTER director_rejection_observation',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'director_signed_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD COLUMN director_rejected_at DATETIME NULL AFTER director_signed_at',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND COLUMN_NAME = 'director_rejected_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD CONSTRAINT fk_monthly_reports_direction FOREIGN KEY (direction_id) REFERENCES directions(id) ON DELETE SET NULL',
  'SELECT 1')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND CONSTRAINT_NAME = 'fk_monthly_reports_direction');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE monthly_reports ADD CONSTRAINT fk_monthly_reports_director FOREIGN KEY (reviewed_by_director_user_id) REFERENCES system_users(id) ON DELETE SET NULL',
  'SELECT 1')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_reports' AND CONSTRAINT_NAME = 'fk_monthly_reports_director');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE monthly_reports r
INNER JOIN system_users u ON u.id = r.honorario_user_id
SET r.direction_id = u.direction_id
WHERE r.direction_id IS NULL;