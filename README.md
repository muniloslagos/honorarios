# Sistema Honorarios (Etapa 1)

Esta primera etapa implementa:

- Login con ClaveUnica (OAuth2 Authorization Code)
- Definicion de perfiles base (Administrador, RRHH, Finanzas, Honorario)
- Acceso habilitado solo para perfil Honorario
- Dashboard inicial para personal a honorarios

## 1) Configurar credenciales ClaveUnica

1. Copia `.env.example` a `.env`
2. Completa los valores:

- `CU_CLIENT_ID`: entregado por ClaveUnica
- `CU_CLIENT_SECRET`: entregado por ClaveUnica
- `CU_REDIRECT_URI`: debe coincidir exactamente con la registrada en ClaveUnica
- `CU_AUTH_URL`, `CU_TOKEN_URL`, `CU_USERINFO_URL`: endpoints de tu integracion
- `HONORARIO_RUN_WHITELIST`: RUN permitidos para entrar como HONORARIO, separados por coma
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`: conexion a MySQL

Ejemplo:

```env
APP_NAME=Personal a Honorarios
APP_URL=https://app.muniloslagos.cl/sgh

CU_CLIENT_ID=tu_client_id
CU_CLIENT_SECRET=tu_client_secret
CU_REDIRECT_URI=https://app.muniloslagos.cl/sgh/auth/callback

CU_AUTH_URL=https://accounts.claveunica.gob.cl/openid/authorize/
CU_TOKEN_URL=https://accounts.claveunica.gob.cl/openid/token/
CU_USERINFO_URL=https://accounts.claveunica.gob.cl/openid/userinfo/

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=appmuniloslagos_sgh
DB_USER=appmuniloslagos_sgh
DB_PASS=tu_password_mysql

HONORARIO_RUN_WHITELIST=12345678-9,11111111-1
```

## 2) Registrar Redirect URI en ClaveUnica

En el portal de integracion, la URI de retorno debe ser exactamente:

- `https://app.muniloslagos.cl/sgh/auth/callback`

Si usas otro dominio/puerto/ruta, actualiza tambien `APP_URL` y `CU_REDIRECT_URI`.

## 3) Probar login

1. Abre `https://app.muniloslagos.cl/sgh/`
2. Presiona "Ingresar con ClaveUnica"
3. Autentica con ClaveUnica
4. Si el RUN viene en la respuesta y esta en `HONORARIO_RUN_WHITELIST`, entra al dashboard

## 4) Cambiar de ambiente (Sandbox, QA, Produccion)

No necesitas cambiar codigo. Solo edita `.env` y reemplaza estos dos valores:

- `CU_CLIENT_ID`
- `CU_CLIENT_SECRET`

Credenciales disponibles:

- Sandbox: `d5631f8293d240109475ed2f3e64d780` / `e8090b8a8d7a405bbdcb808fd2b7bc08`
- QA: `a236b0f54ab44754939e1d8d08cea24e` / `b1a177399ff3418f995e53202e7eb110`
- Produccion: `41f5e0f9e0e44dae8dd12798b6dd8e58` / `d9767a6001d04abb98f9cd84ab71fc31`

Importante: valida con ClaveUnica que la `CU_REDIRECT_URI` del ambiente coincida exactamente con la URI registrada para ese Client ID.

## 5) Error: Redirect URI no valida

Si aparece "La solicitud fallo debido a que la URI de redireccion no es valida", revisa:

1. Que `.env` tenga el `CU_CLIENT_ID` y `CU_CLIENT_SECRET` del ambiente correcto.
2. Que `CU_REDIRECT_URI` sea exactamente la misma registrada en ClaveUnica.
3. Que no haya diferencias en `http` vs `https`, `localhost` vs `127.0.0.1`, puerto o slash final.

Conclusiones relevantes de la guia oficial:

1. `localhost` no esta permitido como Redirect URI.
2. La Redirect URI no puede incluir query string; solo esquema, autoridad y path.
3. Para produccion, la Redirect URI debe usar dominio `.gob.cl`.
4. Si necesitas cambiar la Redirect URI registrada, debes hacerlo en Cerofilas en el tramite de actualizacion de URIs.

Puedes abrir `https://app.muniloslagos.cl/sgh/diagnostico-claveunica.php` para ver la Redirect URI y la Authorization URL reales que envia el sistema.

## 5.1) Despliegue en servidor

El repositorio no versiona `.env`, por lo que en produccion debes crear ese archivo manualmente en la raiz del proyecto.

Pasos:

1. Copia `.env.example` a `.env` en el servidor.
2. Completa `CU_CLIENT_ID`, `CU_CLIENT_SECRET` y `HONORARIO_RUN_WHITELIST`.
3. Verifica que `APP_URL` y `CU_REDIRECT_URI` sigan apuntando a `https://app.muniloslagos.cl/sgh`.

## 6) Como agregar usuarios que pueden ingresar

En esta etapa, los usuarios autorizados para perfil Honorario se agregan en:

- `HONORARIO_RUN_WHITELIST` dentro de `.env`

Formato:

- sin puntos
- con guion
- separados por coma

Ejemplo:

`HONORARIO_RUN_WHITELIST=12345678-9,11111111-1`

Mas adelante se puede reemplazar por un modulo Administrador con base de datos para alta/baja de usuarios desde interfaz.

## Estructura creada

- `index.php`: pantalla de ingreso
- `login.php`: redireccion a ClaveUnica
- `auth/callback`: procesamiento OAuth2 y login
- `dashboard.php`: panel del perfil Honorario
- `logout.php`: cierre de sesion
- `src/bootstrap.php`: carga de entorno y utilidades
- `src/claveunica.php`: cliente OAuth2 ClaveUnica
- `src/roles.php`: perfiles y reglas de acceso
- `src/auth.php`: sesion/autorizacion

## Base de datos MySQL

Se agregaron scripts en:

- `database/sql/schema.sql`
- `database/sql/seed_qa.sql`
- `database/sql/migration_001_monthly_reports_rules.sql`

### Crear estructura

```bash
mysql -u root -p < database/sql/schema.sql
```

### Cargar datos QA iniciales

```bash
mysql -u root -p < database/sql/seed_qa.sql
```

### Aplicar reglas nuevas de informes mensuales sobre BD existente

```bash
mysql -u root -p < database/sql/migration_001_monthly_reports_rules.sql
```

Con esto quedan creadas las tablas para:

- usuarios y perfiles
- convenios
- funciones por convenio
- decretos
- informes mensuales
- actividades del informe (en desarrollo)
- archivos de informe
- auditoria

## Pantallas operativas (perfil Honorario)

- `convenios.php`: agregar y listar convenios
- `decretos.php`: agregar y listar decretos
- `informe_mensual.php`: crear informe mensual con origen manual o convenio

Reglas en la creacion de informe:

- Se permite mas de un informe en el mismo mes/anio para un usuario.
- Si usa `source_type=CONVENIO`, no puede repetirse el mismo convenio para el mismo mes/anio.
- Si es `MANUAL`, no aplica esa validacion.
- Al crear informe, la carga de actividades queda en estado "En desarrollo".

Reglas aplicadas para `monthly_reports`:

- Se permiten multiples informes en un mismo mes/anio por usuario.
- Si el informe usa `source_type=CONVENIO`, no se permite duplicar el mismo convenio en el mismo mes/anio.
- Si el informe es `MANUAL`, no se aplica esa validacion.
- La vigencia del informe se guarda como `agreement_start_date` y `agreement_end_date`.

## Notas

- Si la estructura del `userinfo` de tu integracion trae el RUN con otro nombre de campo, ajusta la funcion `extractRunFromUserInfo` en `src/roles.php`.
- El control de acceso de Honorario hoy se mantiene por lista blanca de RUN en `.env`; en una etapa posterior se reemplazara por control en base de datos.
