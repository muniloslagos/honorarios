ALTER TABLE system_users
  ADD COLUMN IF NOT EXISTS first_names VARCHAR(120) NULL AFTER run,
  ADD COLUMN IF NOT EXISTS last_names VARCHAR(120) NULL AFTER first_names,
  ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL AFTER email;

UPDATE system_users
SET first_names = COALESCE(first_names, full_name)
WHERE first_names IS NULL OR first_names = '';

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