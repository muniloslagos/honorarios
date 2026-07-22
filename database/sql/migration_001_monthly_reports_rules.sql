-- =====================================================
-- Migracion 001: Reglas de informes mensuales
-- - multiples informes por mes/anio permitidos
-- - unicidad por convenio + periodo (solo source_type=CONVENIO)
-- - vigencia por fechas inicio/fin
-- =====================================================

USE appmuniloslagos_sgh;

-- 1) Quitar restriccion antigua: un informe por usuario/mes/anio
ALTER TABLE monthly_reports
  DROP INDEX uq_monthly_report_period;

-- 2) Reemplazar campo de vigencia libre por fechas
ALTER TABLE monthly_reports
  DROP COLUMN agreement_validity_text,
  ADD COLUMN agreement_start_date DATE NULL AFTER decree_number_text,
  ADD COLUMN agreement_end_date DATE NULL AFTER agreement_start_date;

-- 3) Columna generada para validar duplicidad solo en informes por convenio
ALTER TABLE monthly_reports
  ADD COLUMN agreement_validation_id BIGINT
  GENERATED ALWAYS AS (
    CASE WHEN source_type = 'CONVENIO' THEN agreement_id ELSE NULL END
  ) STORED
  AFTER installment_number;

-- 4) Regla de unicidad por convenio + periodo
ALTER TABLE monthly_reports
  ADD UNIQUE KEY uq_monthly_report_convenio_period (
    honorario_user_id,
    report_year,
    report_month,
    agreement_validation_id
  );

-- 5) Validacion de coherencia source_type/agreement_id
ALTER TABLE monthly_reports
  ADD CONSTRAINT chk_monthly_reports_source
  CHECK (
    (source_type = 'MANUAL') OR
    (source_type = 'CONVENIO' AND agreement_id IS NOT NULL)
  );
