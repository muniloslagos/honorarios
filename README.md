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

Ejemplo:

```env
APP_NAME=Sistema Honorarios
APP_URL=http://localhost/honorarios

CU_CLIENT_ID=tu_client_id
CU_CLIENT_SECRET=tu_client_secret
CU_REDIRECT_URI=http://localhost/honorarios/callback.php

CU_AUTH_URL=https://accounts.claveunica.gob.cl/openid/authorize/
CU_TOKEN_URL=https://accounts.claveunica.gob.cl/openid/token/
CU_USERINFO_URL=https://accounts.claveunica.gob.cl/openid/userinfo/

HONORARIO_RUN_WHITELIST=12345678-9,11111111-1
```

## 2) Registrar Redirect URI en ClaveUnica

En el portal de integracion, la URI de retorno debe ser exactamente:

- `http://localhost/honorarios/callback.php`

Si usas otro dominio/puerto/ruta, actualiza tambien `APP_URL` y `CU_REDIRECT_URI`.

## 3) Probar login

1. Abre `http://localhost/honorarios`
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

Puedes abrir `http://localhost/honorarios/diagnostico-claveunica.php` para ver la Redirect URI y la Authorization URL reales que envia el sistema.

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
- `callback.php`: procesamiento OAuth2 y login
- `dashboard.php`: panel del perfil Honorario
- `logout.php`: cierre de sesion
- `src/bootstrap.php`: carga de entorno y utilidades
- `src/claveunica.php`: cliente OAuth2 ClaveUnica
- `src/roles.php`: perfiles y reglas de acceso
- `src/auth.php`: sesion/autorizacion

## Notas

- Si la estructura del `userinfo` de tu integracion trae el RUN con otro nombre de campo, ajusta la funcion `extractRunFromUserInfo` en `src/roles.php`.
- Esta etapa no incluye base de datos aun. El control de acceso de Honorario se hace por lista blanca de RUN en `.env`.
