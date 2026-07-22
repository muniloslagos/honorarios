DROP DATABASE IF EXISTS appmuniloslagos_sgh;
CREATE DATABASE IF NOT EXISTS appmuniloslagos_sgh
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE appmuniloslagos_sgh;

SOURCE database/sql/schema.sql;
SOURCE database/sql/seed_qa.sql;

INSERT INTO system_users (run, full_name, email, role, profession_experience, is_active)
VALUES
  ('11111111-1', 'Administrador Local', 'admin.local@municipio.cl', 'ADMINISTRADOR', 'Administración', 1),
  ('22222222-2', 'RRHH Local', 'rrhh.local@municipio.cl', 'RRHH', 'Recursos Humanos', 1),
  ('33333333-3', 'Finanzas Local', 'finanzas.local@municipio.cl', 'FINANZAS', 'Finanzas', 1),
  ('44444444-4', 'Usuario QA Honorario', 'qa.honorario@municipio.cl', 'HONORARIO', 'Administrador Público', 1)
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  email = VALUES(email),
  profession_experience = VALUES(profession_experience),
  is_active = VALUES(is_active);
