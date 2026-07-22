USE honorarios_db;

-- Usuario Honorario de pruebas (RUN QA)
INSERT INTO system_users (run, full_name, email, role, profession_experience)
VALUES ('44444444-4', 'Usuario QA Honorario', 'qa.honorario@municipio.cl', 'HONORARIO', 'Administrador Publico')
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  email = VALUES(email),
  profession_experience = VALUES(profession_experience),
  is_active = 1;

-- Decreto de ejemplo
INSERT INTO decrees (
  honorario_user_id,
  decree_number,
  decree_date,
  pdf_original_name,
  pdf_path,
  created_by_user_id
)
SELECT u.id, 'DEC-2026-101', '2026-01-10', 'decreto-2026-101.pdf', 'uploads/decrees/decreto-2026-101.pdf', u.id
FROM system_users u
WHERE u.run = '44444444-4' AND u.role = 'HONORARIO'
ON DUPLICATE KEY UPDATE
  decree_date = VALUES(decree_date),
  pdf_original_name = VALUES(pdf_original_name),
  pdf_path = VALUES(pdf_path);

-- Convenio de ejemplo
INSERT INTO agreements (
  honorario_user_id,
  agreement_number,
  agreement_date,
  start_date,
  end_date,
  installments_total,
  program_item,
  decree_id,
  pdf_original_name,
  pdf_path,
  status,
  created_by_user_id
)
SELECT
  u.id,
  'CONV-2026-014',
  '2026-01-01',
  '2026-01-01',
  '2026-12-31',
  12,
  'Programa de Apoyo Comunitario',
  d.id,
  'convenio-2026-014.pdf',
  'uploads/agreements/convenio-2026-014.pdf',
  'VIGENTE',
  u.id
FROM system_users u
LEFT JOIN decrees d
  ON d.honorario_user_id = u.id
 AND d.decree_number = 'DEC-2026-101'
WHERE u.run = '44444444-4' AND u.role = 'HONORARIO'
ON DUPLICATE KEY UPDATE
  agreement_date = VALUES(agreement_date),
  start_date = VALUES(start_date),
  end_date = VALUES(end_date),
  installments_total = VALUES(installments_total),
  program_item = VALUES(program_item),
  decree_id = VALUES(decree_id),
  status = VALUES(status),
  pdf_original_name = VALUES(pdf_original_name),
  pdf_path = VALUES(pdf_path);

-- Funciones de ejemplo del convenio
INSERT INTO agreement_functions (agreement_id, function_text, sort_order)
SELECT a.id, 'Levantamiento y consolidacion de informacion territorial', 1
FROM agreements a
JOIN system_users u ON u.id = a.honorario_user_id
WHERE u.run = '44444444-4'
  AND u.role = 'HONORARIO'
  AND a.agreement_number = 'CONV-2026-014'
  AND NOT EXISTS (
      SELECT 1
      FROM agreement_functions af
      WHERE af.agreement_id = a.id
        AND af.function_text = 'Levantamiento y consolidacion de informacion territorial'
  );

INSERT INTO agreement_functions (agreement_id, function_text, sort_order)
SELECT a.id, 'Elaboracion de reportes mensuales para seguimiento del programa', 2
FROM agreements a
JOIN system_users u ON u.id = a.honorario_user_id
WHERE u.run = '44444444-4'
  AND u.role = 'HONORARIO'
  AND a.agreement_number = 'CONV-2026-014'
  AND NOT EXISTS (
      SELECT 1
      FROM agreement_functions af
      WHERE af.agreement_id = a.id
        AND af.function_text = 'Elaboracion de reportes mensuales para seguimiento del programa'
  );
