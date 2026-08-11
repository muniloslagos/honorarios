-- Firmas gráficas administradas para el personal a honorarios.
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
  CONSTRAINT fk_honorario_signatures_user FOREIGN KEY (honorario_user_id) REFERENCES system_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_honorario_signatures_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES system_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;