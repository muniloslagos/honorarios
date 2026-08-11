-- Corrige textos que fueron importados previamente con signos de interrogacion.
-- Ejecutar una vez sobre la base de datos de produccion.

UPDATE system_users
SET profession_experience = 'Administración'
WHERE profession_experience = 'Administraci??n';

UPDATE system_users
SET profession_experience = 'Administrador Público'
WHERE profession_experience = 'Administrador P??blico';
