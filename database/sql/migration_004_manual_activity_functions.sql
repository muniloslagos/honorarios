-- =====================================================
-- Migracion 004: Funcion por actividad en informe mensual
-- - permite guardar actividades manuales asociadas a una funcion
-- =====================================================

USE appmuniloslagos_sgh;

ALTER TABLE monthly_report_activities
  ADD COLUMN function_title VARCHAR(255) NULL AFTER activity_date;