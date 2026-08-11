-- =====================================================
-- Migracion 002: Campos de convenio e informe mensual
-- - agrega datos de convenio requeridos para autocompletar informes
-- - agrega datos manuales de boleta en informes mensuales
-- =====================================================

USE appmuniloslagos_sgh;

ALTER TABLE agreements
  ADD COLUMN profession_experience VARCHAR(255) NULL AFTER program_item,
  ADD COLUMN supervision_unit VARCHAR(255) NULL AFTER profession_experience;

ALTER TABLE monthly_reports
  ADD COLUMN supervision_unit VARCHAR(255) NULL AFTER profession_experience,
  ADD COLUMN boleta_number VARCHAR(80) NULL AFTER installment_number,
  ADD COLUMN boleta_date DATE NULL AFTER boleta_number;