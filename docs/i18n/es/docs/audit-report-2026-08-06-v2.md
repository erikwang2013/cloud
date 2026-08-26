# Informe de revisión de CloudPlatform (ronda 2, 2026-08-06)

> Alcance: re-verificación tras la corrección de todos los problemas de la ronda anterior (audit-report-2026-08-06.md).
> Línea base de pruebas: PHPUnit **319/319 en verde (505 assertions)**; `php -l` en 253 archivos PHP **0 errores de sintaxis**.

---

## 1. Pruebas y comprobaciones estáticas

| Elemento | Resultado |
|------|------|
| PHPUnit completo | OK (319 tests, 505 assertions) |
| `php -l` (app/common/config) | Los 253 archivos pasan |
| composer audit | **Sin vulnerabilidades de seguridad**; 1 paquete obsoleto doctrine/annotations (dependencia directa de hg/apidoc, se conserva tras evaluarlo) |
| composer.lock | Bajo control de versiones (confirmado como A) |

---

## 2. Verificación de la configuración del ecosistema

### 2.1 Uso y definición de env — completo ✓

- Todas las claves `getenv()` del código (incluido el patrón dinámico `{PROVIDER}_OAUTH_*`) tienen definición en `.env.example` o configuración opcional en forma de comentario (`#HASHIDS_ALPHABET`, `#POSTER_IMAGE_DRIVER`, `#EXCHANGE_RATE_API_URL`, `#COUNTRY_SEASON_DEFAULT`, `#SECURITY_HSTS_VALUE`)
- Elemento redundante de la plantilla (riesgo bajo): `MAIL_FROM_NAME` no tiene referencia `getenv()` en el código, solo se conserva en la plantilla

### 2.2 Bloqueo de dependencias ✓

- `service/composer.lock` confirmado; `.gitignore` ya no lo excluye; `service/.phpunit.cache/` ignorado

### 2.3 Notas del entorno

- El puerto local 8787 sigue ocupado por erp-php; cloud-php no puede arrancar localmente (sin conflicto en el entorno de despliegue)
- `composer validate` da fatal por un conflicto entre el Installer del plugin de vendor `erikwang2013/security-php` y el eval del propio composer (problema de paquetes de terceros, no del código de este proyecto)

---

## 3. Verificación de la protección de seguridad

### 3.1 Cadena de middlewares globales (11 capas, cubre todas las rutas) ✓

```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock
→ WAF (SQLi/XSS) → SecurityPlugin (31 detecciones de ataque)
→ Locale → Metrics → HashidRequest → Maintenance
```

### 3.2 Límite de peticiones en rutas públicas — 1 corrección en esta ronda

| Ruta | Middleware | Regla de límite |
|------|--------|---------|
| register / login / refresh | RateLimit | register 3/min, login 5/min |
| **forgot-password / reset-password** | **RateLimit (montado en esta ronda)** | password_reset 3/5min |
| oauthRedirect / oauthCallback (GET+POST) | RateLimit | oauth 10/60s |
| login/recovery | RateLimit | login 5/min |
| send-sms | RateLimit | sms 5/h |
| captcha/create | RateLimit | captcha 30/60s |

> **Corrección**: en las rutas `forgot-password`/`reset-password` la ronda anterior definió la regla `password_reset` pero olvidó montar el middleware (superficie de bombardeo de correos/fuerza bruta del código); esta ronda lo monta.

### 3.3 Exposición de archivos subidos — 1 corrección en esta ronda (riesgo alto)

**Problema**: la configuración nginx de `deployment.md` con `location /storage/ { alias .../service/storage/; }` expone todo el directorio storage:

```
storage/
├── backups/    ← copias de seguridad de la BD (.sql.gz) descargables públicamente
├── apple/      ← clave privada AuthKey.p8 descargable públicamente (permite firmar tokens de Apple)
├── firebase/   ← credenciales de la cuenta de servicio FCM (con clave privada) descargables públicamente
├── geoip/      ← base de datos GeoLite2
└── uploads/    ← archivos subidos (se espera que sean públicos)
```

**Corrección**: tanto deployment.md como docker/nginx.conf pasan a `location ^~ /storage/uploads/`, exponiendo solo el subdirectorio uploads.

### 3.4 Otras verificaciones ✓

- `verify-email`: token aleatorio de un solo uso (se vacía tras verificar), sin superficie de fuerza bruta/enumeración, sin necesidad de límite
- Interfaz de subida: lista blanca de type + detección de contenido MIME con finfo (corregido en la ronda anterior); uploads se sirve directamente con alias estático de nginx, sin ejecutar PHP
- JWT: HS256 + lista negra Redis (validación por jti dentro de la librería); TOTP obligatorio en el inicio de sesión + bloqueo de 15 minutos tras 5 fallos
- OAuth: verificación de firma JWKS + iss/aud/exp/nonce + `email_verified` obligatorio (corregido en la ronda anterior)
- Rutas de administración: AuthMiddleware + AdminRoleMiddleware + ConfirmationMiddleware

---

## 4. Sugerencias pendientes (no bloqueantes)

| Nivel | Asunto | Descripción |
|:---:|------|------|
| P3 | Directorio antiguo redundante `service/service/` (28K) | Contiene copias desactualizadas de Supplier/WebSocket; no lo carga PSR-4 ni está en el seguimiento; fácil de modificar por error; se recomienda eliminarlo tras confirmación manual |
| P3 | `MAIL_FROM_NAME` redundante en la plantilla | El código no lo usa; se puede conservar como configuración reservada para el nombre del remitente |
| P3 | doctrine/annotations obsoleto | Dependencia directa de hg/apidoc; eliminarlo exige sustituir el esquema de generación de documentación de API |
| P3 | Refuerzo del directorio de subida (segunda sugerencia) | Colocar `index.html` en uploads, confirmar que la capa de despliegue no ejecuta PHP (el alias de nginx ya lo evita de forma natural; hay que vigilar el escenario del servidor integrado de webman) |

---

## 5. Conclusión

Las 15 correcciones de la ronda anterior han sido todas re-verificadas como efectivas; la línea base de pruebas es estable (319/505). Esta ronda descubrió y corrigió sobre la marcha 3 puntos: **las rutas forgot/reset sin límite de peticiones montado (P1)**, **la configuración nginx de deployment.md que expone copias de seguridad y claves privadas (P0)**, **el nginx de Docker sin configuración estática para uploads (P2)**. Tras las correcciones, la prueba completa re-ejecutada pasa.

*Método de generación del informe: PHPUnit completo, php -l en 253 archivos, auditoría estática de rutas/middlewares, auditoría de configuración nginx/docker, comparación por diferencia de conjuntos entre uso y definición de env, composer audit.*
