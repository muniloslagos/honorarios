-- =====================================================
-- Migracion 003: Tipos de archivo para informes mensuales
-- - permite guardar decreto y boleta como adjuntos del informe
-- =====================================================

USE appmuniloslagos_sgh;

ALTER TABLE monthly_report_files
  MODIFY file_type ENUM('RESPALDO', 'CONVENIO_FIRMADO', 'DECRETO', 'BOLETA', 'OTRO') NOT NULL;