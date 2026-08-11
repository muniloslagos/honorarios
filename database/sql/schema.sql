-- =====================================================
-- Base de datos: Sistema Personal a Honorarios
-- Motor: MySQL 8+
-- =====================================================

CREATE DATABASE IF NOT EXISTS appmuniloslagos_sgh
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE appmuniloslagos_sgh;

-- -----------------------------------------------------
-- 1) Usuarios del sistema (todos los perfiles)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS system_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run VARCHAR(12) NOT NULL,
  first_names VARCHAR(120) NULL,
  last_names VARCHAR(120) NULL,
  full_name VARCHAR(180) NOT NULL,
  email VARCHAR(180) NULL,
  phone VARCHAR(30) NULL,
  role ENUM('ADMINISTRADOR', 'RRHH', 'FINANZAS', 'HONORARIO', 'DIRECTOR') NOT NULL,
  profession_experience VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_system_users_run_role (run, role),
  KEY idx_system_users_role (role)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- 2) Decretos
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS decrees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  honorario_user_id BIGINT UNSIGNED NOT NULL,
  decree_number VARCHAR(80) NOT NULL,
  decree_date DATE NOT NULL,
  pdf_original_name VARCHAR(255) NULL,
  pdf_path VARCHAR(255) NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_decrees_user_number (honorario_user_id, decree_number),
  KEY idx_decrees_user (honorario_user_id),
  CONSTRAINT fk_decrees_honorario_user
    FOREIGN KEY (honorario_user_id) REFERENCES system_users(id),
  CONSTRAINT fk_decrees_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES system_users(id)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- 3) Convenios
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS agreements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  honorario_user_id BIGINT UNSIGNED NOT NULL,
  agreement_number VARCHAR(80) NOT NULL,
  agreement_date DATE NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  installments_total INT UNSIGNED NULL,
  program_item VARCHAR(255) NOT NULL,
  profession_experience VARCHAR(255) NULL,
  supervision_unit VARCHAR(255) NULL,
  decree_id BIGINT UNSIGNED NULL,
  pdf_original_name VARCHAR(255) NULL,
  pdf_path VARCHAR(255) NULL,
  status ENUM('VIGENTE', 'NO_VIGENTE', 'PENDIENTE_FIRMA') NOT NULL DEFAULT 'VIGENTE',
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agreements_user_number (honorario_user_id, agreement_number),
  KEY idx_agreements_user (honorario_user_id),
  KEY idx_agreements_decree (decree_id),
  CONSTRAINT fk_agreements_honorario_user
    FOREIGN KEY (honorario_user_id) REFERENCES system_users(id),
  CONSTRAINT fk_agreements_decree
    FOREIGN KEY (decree_id) REFERENCES decrees(id),
  CONSTRAINT fk_agreements_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES system_users(id)
) ENGINE=InnoDB;

-- Funciones del convenio (1 convenio puede tener muchas funciones)
CREATE TABLE IF NOT EXISTS agreement_functions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agreement_id BIGINT UNSIGNED NOT NULL,
  function_text VARCHAR(255) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_agreement_functions_agreement (agreement_id),
  CONSTRAINT fk_agreement_functions_agreement
    FOREIGN KEY (agreement_id) REFERENCES agreements(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- 4) Informes mensuales
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS monthly_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  honorario_user_id BIGINT UNSIGNED NOT NULL,

  report_month TINYINT UNSIGNED NOT NULL,
  report_year SMALLINT UNSIGNED NOT NULL,

  provider_name VARCHAR(180) NOT NULL,
  profession_experience VARCHAR(255) NOT NULL,
  supervision_unit VARCHAR(255) NULL,

  source_type ENUM('MANUAL', 'CONVENIO') NOT NULL DEFAULT 'CONVENIO',
  agreement_id BIGINT UNSIGNED NULL,

  -- Campos manuales o autocompletados desde convenio
  program_activity_text VARCHAR(255) NOT NULL,
  decree_number_text VARCHAR(80) NULL,
  decree_date DATE NULL,
  agreement_start_date DATE NULL,
  agreement_end_date DATE NULL,
  installment_number INT UNSIGNED NULL,
  boleta_number VARCHAR(80) NULL,
  boleta_date DATE NULL,
  boleta_amount DECIMAL(14,2) NULL,

  -- Permite multiples informes manuales en mismo mes/anio,
  -- pero evita duplicar un informe para el mismo convenio y periodo.
  agreement_validation_id BIGINT GENERATED ALWAYS AS (
    CASE WHEN source_type = 'CONVENIO' THEN agreement_id ELSE NULL END
  ) STORED,

  status ENUM('BORRADOR', 'ENVIADO', 'OBSERVADO', 'APROBADO', 'RECHAZADO', 'APROBADO_PAGO') NOT NULL DEFAULT 'BORRADOR',
  observations TEXT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  submitted_at TIMESTAMP NULL,

  UNIQUE KEY uq_monthly_report_convenio_period (honorario_user_id, report_year, report_month, agreement_validation_id),
  KEY idx_monthly_reports_user (honorario_user_id),
  KEY idx_monthly_reports_status (status),
  KEY idx_monthly_reports_agreement (agreement_id),

  CONSTRAINT fk_monthly_reports_honorario_user
    FOREIGN KEY (honorario_user_id) REFERENCES system_users(id),
  CONSTRAINT fk_monthly_reports_agreement
    FOREIGN KEY (agreement_id) REFERENCES agreements(id),
  CONSTRAINT chk_monthly_reports_source
    CHECK (
      (source_type = 'MANUAL') OR
      (source_type = 'CONVENIO' AND agreement_id IS NOT NULL)
    )
) ENGINE=InnoDB;

-- Actividades del informe (dejar preparado, parte funcional en desarrollo)
CREATE TABLE IF NOT EXISTS monthly_report_activities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id BIGINT UNSIGNED NOT NULL,
  activity_date DATE NULL,
  function_title VARCHAR(255) NULL,
  activity_description TEXT NOT NULL,
  hours_worked DECIMAL(5,2) NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_report_activities_report (report_id),
  CONSTRAINT fk_monthly_report_activities_report
    FOREIGN KEY (report_id) REFERENCES monthly_reports(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- Archivos asociados al informe
CREATE TABLE IF NOT EXISTS monthly_report_files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id BIGINT UNSIGNED NOT NULL,
  file_type ENUM('RESPALDO', 'CONVENIO_FIRMADO', 'DECRETO', 'BOLETA', 'CERTIFICADO', 'HISTORICO', 'OTRO') NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_report_files_report (report_id),
  CONSTRAINT fk_monthly_report_files_report
    FOREIGN KEY (report_id) REFERENCES monthly_reports(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- 5) Firmas del personal a honorarios
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS honorario_signatures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  honorario_user_id BIGINT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(80) NOT NULL,
  size_bytes BIGINT UNSIGNED NULL,
  uploaded_by_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_honorario_signatures_user (honorario_user_id),
  CONSTRAINT fk_honorario_signatures_user
    FOREIGN KEY (honorario_user_id) REFERENCES system_users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_honorario_signatures_uploader
    FOREIGN KEY (uploaded_by_user_id) REFERENCES system_users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB;
-- -----------------------------------------------------
-- 6) Solicitudes de firma remota
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS signature_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id BIGINT UNSIGNED NOT NULL,
  report_file_id BIGINT UNSIGNED NOT NULL,
  honorario_user_id BIGINT UNSIGNED NOT NULL,
  recipient_email VARCHAR(180) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  opened_at DATETIME NULL,
  signed_at DATETIME NULL,
  signed_signature_path VARCHAR(255) NULL,
  signer_ip VARCHAR(45) NULL,
  signer_user_agent VARCHAR(500) NULL,
  status ENUM('PENDIENTE', 'FIRMADO', 'EXPIRADO', 'ANULADO') NOT NULL DEFAULT 'PENDIENTE',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_signature_requests_token (token_hash),
  KEY idx_signature_requests_report (report_id),
  KEY idx_signature_requests_user (honorario_user_id),
  CONSTRAINT fk_signature_requests_report FOREIGN KEY (report_id) REFERENCES monthly_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_signature_requests_file FOREIGN KEY (report_file_id) REFERENCES monthly_report_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_signature_requests_user FOREIGN KEY (honorario_user_id) REFERENCES system_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- -----------------------------------------------------
-- 7) Configuración SMTP
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS smtp_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  host VARCHAR(255) NOT NULL,
  port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
  encryption ENUM('tls', 'ssl', 'none') NOT NULL DEFAULT 'tls',
  username VARCHAR(255) NOT NULL,
  password_encrypted TEXT NOT NULL,
  from_email VARCHAR(180) NOT NULL,
  from_name VARCHAR(180) NOT NULL,
  reply_to_email VARCHAR(180) NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_smtp_settings_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES system_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
-- -----------------------------------------------------
-- 8) Historial/Auditoria
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  target_type VARCHAR(60) NOT NULL,
  target_id BIGINT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  detail TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_actor (actor_user_id),
  KEY idx_audit_target (target_type, target_id),
  CONSTRAINT fk_audit_actor_user
    FOREIGN KEY (actor_user_id) REFERENCES system_users(id)
) ENGINE=InnoDB;


