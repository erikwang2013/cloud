# Informe de revisión global de CloudPlatform

**Fecha**: 2026-08-06
**Alcance de la revisión**: service completo (app / common / config / tests) + configuración del ecosistema + protección de seguridad
**Método**: suite de pruebas PHPUnit, comprobación completa de sintaxis PHP, auditoría de rutas/middlewares, revisión de código de la nueva función OAuth, verificación de coherencia de variables de entorno y configuración, auditoría de seguridad de dependencias, pruebas de humo

---

## 1. Conclusión general

| Dimensión | Conclusión |
|------|------|
| Pruebas | **Los 314 pasan todos** (494 assertions tras corregir 2 bugs) |
| Sintaxis | 287 archivos PHP, 0 errores de sintaxis |
| Seguridad de dependencias | composer audit sin vulnerabilidades conocidas; 1 paquete obsoleto (doctrine/annotations) |
| Arquitectura de seguridad | Protección multinivel completa (WAF de doble motor, lista blanca CORS, cifrado de transporte, cifrado de campos, bcrypt cost=12, lista negra JWT, registros de auditoría) |
| Problemas graves | **1 P0 (id_token de Apple sin verificar → toma de cuentas posible), 4 P1** |
| Configuración del ecosistema | **.env.example carece de 31 variables en uso**, incluida toda la configuración OAuth; los canales de notificación son implementaciones de marcador |

---

## 2. Resultados de las pruebas

```
OK (314 tests, 494 assertions)
```

### Los 2 bugs corregidos en esta ronda

| ID | Archivo | Problema | Corrección |
|----|------|------|------|
| B1 | `service/common/captcha/CaptchaService.php:31` | Lee `$result['extra']['targets']`, pero la librería devuelve `extra.texts` → `target_count` siempre 0 | Cambiado a `extra.texts` |
| B2 | `vendor/erikwang2013/poster-php/src/Captcha/ClickCaptcha.php:17` | El valor por defecto de la librería es `targetCount = 5`, en contradicción con el contrato del README de la propia librería (medium=3 objetivos) → 3 tests de Captcha fallaban | Valor por defecto 5 → 3 |

> B2 es un bug de una librería vendored (vendor/ está seguida por git, la corrección puede persistir). Se recomienda enviar también la corrección al repositorio upstream.

---

## 3. Problemas de seguridad graves (P0 / P1)

### P0-1. `id_token` de Apple sin verificar — toma de cuentas directa
**Archivo**: `service/app/user/service/OAuthService.php:180-192` (`appleProfile()`)

```php
$parts  = explode('.', $tokenData['id_token']);
$claims = json_decode(base64_decode($parts[1]), true);   // solo decode base64, sin validar firma/iss/aud/exp
```

Un atacante puede construir su propio `id_token` y falsificar cualquier email para completar el inicio de sesión OAuth. `resolveUser()` empareja por email con usuarios existentes y emite token directamente → **toma de cuentas arbitraria**.

**Corrección**: verificar la firma con el JWKS de Apple (`https://appleid.apple.com/auth/keys`) + `Firebase\JWT\JWT::decode($idToken, $keys, ['ES256'])`, y validar `iss=appleid.apple.com`, `aud=client_id`, `exp` y `nonce`.

### P1-1. El inicio de sesión OAuth no valida `email_verified`
**Archivo**: `OAuthService.php:163-178, 282-303`

Google/Facebook/Microsoft/LinkedIn devuelven todos el campo `email_verified`, pero el código lo ignora por completo. Un usuario con email sin verificar en el proveedor puede usar ese email para vincular/tomar cuentas registradas. La ruta de GitHub ya valida `verified` (correcto); el resto de proveedores deben validarlo de forma unificada.

### P1-2. El middleware de límite de peticiones existe pero nunca se monta — la documentación no coincide con la implementación
**Archivo**: `common/Security/RateLimitMiddleware.php` + `config/security.php` + `config/route.php`

- `security.php` ya configura reglas como login=5/min, register=3/min
- `RateLimitMiddleware` **no es referenciado por ninguna ruta** (grep en todo el repositorio solo encuentra la propia clase)
- `docs/features.md` afirma que el login tiene "límite 5 req/min" y el registro "límite 3 req/min" — en realidad no existe
- El informe de auditoría anterior (`security-audit-2026-08-04.md`) marcó este punto como OK; solo se revisó la configuración sin verificar el montaje. Esta ronda lo corrige.

**Impacto**: los endpoints públicos de login/registro/olvidé contraseña/restablecer contraseña/códigos de recuperación/captcha se pueden atacar por fuerza bruta sin límite (el login solo depende del bloqueo por cuenta, no protege contra relleno de credenciales ni inundación a nivel de IP).

**Corrección**: montar `RateLimitMiddleware` en las rutas públicas `/api/auth/*`, `/api/captcha/*`, etc. (se puede montar en el grupo global `''`, diferenciando por parámetro `route`).

### P1-3. El 2FA TOTP no se aplica en el flujo de inicio de sesión
**Archivo**: `AuthService.php:64-97` (`login()`) + `AuthController.php` + `config/features.php`

`user->totp_enabled` solo se comprueba en `totpVerify/totpDisable/totpRecoveryCodes`; **`login()` nunca lo valida**. Un usuario con 2FA activado sigue obteniendo un access token válido solo con la contraseña — el 2FA es papel mojado (`FEATURE_TOTP` activada por defecto).

**Corrección**: al iniciar sesión, si `totp_enabled`, emitir un token temporal y exigir que la verificación TOTP tenga éxito antes de canjearlo por el token final (o exigir el parámetro con el código totp).

### P1-4. Los canales de notificación son implementaciones de marcador — la verificación de email/el restablecimiento de contraseña no funcionan en producción
**Archivo**: `app/Notification/Queue/EmailSender.php`, `SmsSender.php`, `PushSender.php`

Los tres consumidores solo simulan el envío con `error_log()` y además marcan `send_status` como `sent`. Consecuencias:
- **El flujo de contraseña olvidada se rompe**: `AuthController::forgotPassword()` genera un código de verificación y "envía" el correo, pero el correo nunca llega → el usuario no puede restablecer su contraseña por sí mismo
- La verificación de email en el registro y la alerta de inicio de sesión desde IP nueva fallan igualmente
- En `.env.example` hay 7 variables `SMTP_*`/`MAIL_FROM_*` que ningún código lee (configuración muerta)

**Corrección**: integrar envío de correo real (SDK de PHPMailer/SendGrid), eliminar el marcador de estado `sent` que induce a error; o marcar claramente la función como no terminada y eliminar las promesas correspondientes de la documentación.

---

## 4. Problemas de seguridad (P2)

| ID | Archivo | Problema |
|----|------|------|
| P2-1 | `app/Controller/UploadController.php:23` | El parámetro `type` se concatena a la ruta `uploads/{$type}/...` sin validación de lista blanca → **path traversal** que puede escribir fuera del directorio de subida (nombres de archivo aleatorios, sin sobreescritura posible, pero puede contaminar el sistema de archivos); se recomienda limitar type a una lista blanca de enumerados y añadir `index.php`/`.htaccess` de protección al directorio de almacenamiento |
| P2-2 | Mismo archivo | Solo valida la extensión, sin detección de contenido MIME (los archivos polyglot pueden explotarse mediante caché/reenvío); se recomienda validar el MIME real con `finfo` |
| P2-3 | `AuthController.php:131-158` | El código de restablecimiento de contraseña de 6 dígitos dura 600s sin límite de intentos → en 10 minutos se pueden enumerar por fuerza bruta 1 millón de combinaciones; `forgotPassword` sin límite de frecuencia → bombardeo de correos |
| P2-4 | `AuthController.php:333-348` | Generar/ver los códigos de recuperación de `totpRecoveryCodes` solo requiere iniciar sesión, sin confirmación de contraseña; debería montarse `ConfirmationMiddleware` |
| P2-5 | `common/Auth/Middleware/AuthMiddleware.php:31` | La clave de la comprobación manual de lista negra es `jwt_blacklist:{sha256(token)}`, que no coincide con el formato `jwt_blacklist:{jti}` de la librería → código muerto (la protección real la hace `decode()` dentro de la librería, efectivo pero redundante); se recomienda eliminar o usar la interfaz de la librería |
| P2-6 | `OAuthService.php:67-94` | El parámetro `redirect` de `authorizeUrl` se guarda en state pero nunca se usa (parámetro muerto); state no está vinculado al proveedor; todo el flujo OAuth carece de nonce (proveedores OIDC, falta de defensa en profundidad, corregir junto con P0-1) |
| P2-7 | `OAuthService.php:31-37, 236-238` | La API v2 de X (Twitter) `userinfo` no devuelve email → el inicio de sesión con X falla inevitablemente con "Email not provided", defecto funcional; requiere documentación o conectar el endpoint `/2/email` |
| P2-8 | `AuthController.php:436-442` | `deviceFingerprint` usa `strrpos($ip, '.')` para recortar el segmento IPv4; con clientes IPv6 degenera en cadena vacía → huella débil; se recomienda usar los primeros 64 bits o el hash de la IP completa |

---

## 5. Integridad de la configuración del ecosistema

### 5.1 Variables que faltan en .env.example (referenciadas por `getenv()` en el código pero no definidas) — 31

| Categoría | Variables |
|------|------|
| **Credenciales OAuth (función nueva, totalmente sin documentar)** | `GOOGLE/APPLE/FACEBOOK/X/MICROSOFT/LINKEDIN/GITHUB_OAUTH_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT_URI` (21) |
| **Específicas de Apple** | `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY_PATH` |
| **Funciones clave** | `APP_URL` (los enlaces del correo de verificación dependen de ella; si falta, los enlaces del correo quedan mal), `APP_ENV`, `APP_VERSION` |
| **Seguridad** | `INTERNAL_MONITOR_TOKEN` (protección de los endpoints /health/*), `MAINTENANCE_MODE`, `MAINTENANCE_ALLOWED_IPS`, `WEBHOOK_SECRET`, `JWT_LEEWAY` |
| **Nube/almacenamiento** | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `BACKUP_S3_BUCKET`, `BACKUP_S3_REGION`, `DB_READ_HOST` |
| **Feature flags (8)** | `FEATURE_SSL_PRODUCT`, `FEATURE_OBJECT_STORAGE`, `FEATURE_USAGE_BILLING`, `FEATURE_PROMETHEUS`, `FEATURE_CDN_PRODUCT`, `FEATURE_SUPPLIER_RATING`, `FEATURE_AFFILIATE`, `FEATURE_GRAPHQL` |
| **Otros** | `METRICS_PORT`, `WS_PORT`, `GEOIP_DB_PATH` (solo comentado en .env.example), `SSL_STAGING`, `HASHIDS_ALPHABET`, `POSTER_IMAGE_DRIVER`, `EXCHANGE_RATE_API_URL`, `COUNTRY_SEASON_DEFAULT` |

### 5.2 Definidas en .env.example pero no usadas en el código — 7

`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` (el envío de correo no está implementado, ver P1-4)

### 5.3 Incoherencia de la cobertura i18n

| Idioma | messages.php | billing | health | storage |
|------|:---:|:---:|:---:|:---:|
| en-US / zh-CN | 129 / 129 | 10 / 16 | 8 / 16 | 9 / 16 |
| ja-JP | 63 | 10 | 8 | 9 |
| ko-KR | 51 | 10 | 8 | 9 |
| fr-FR / de-DE / es-ES | 44 | 10 | 8 | 9 |

- Los idiomas no chinos ni ingleses carecen de más de la mitad de las claves de traducción; zh-CN tiene 6-8 claves más que en-US en billing/health/storage (la dirección de sincronización está invertida)
- **Faltan todas las claves de traducción relacionadas con OAuth** (los mensajes de error están en inglés codificado)

### 5.4 Otros problemas del ecosistema

| ID | Problema |
|----|------|
| E1 | `service/composer.lock` está ignorado por `.gitignore` y no se ha confirmado — las dependencias de la aplicación no están bloqueadas en versión, el despliegue no es reproducible (riesgo de despliegue) |
| E2 | `service/.phpunit.cache/` aparece en git status (no está ignorado) |
| E3 | El puerto 8787 choca con otro proyecto local, erp-php; cloud-php no puede arrancar en esta máquina (confirmado: 8787 lo ocupa el WorkerMan de erp-php) |
| E4 | Las funciones de límite de peticiones/correo que afirma `docs/features.md` no coinciden con la realidad (ver P1-2 / P1-4); la documentación debe corregirse en paralelo |
| E5 | La dependencia `doctrine/annotations` está obsoleta (aviso de composer audit); se recomienda evaluar su eliminación |

---

## 6. Sugerencias de optimización (no bloqueantes)

1. **Creación de servicios con DI**: el constructor de `AuthController` hace directamente `new AuthService()/OAuthService()`; se recomienda integrarlo en el contenedor (soporte nativo de webman), para facilitar las pruebas y la sustitución.
2. **Refuerzo del directorio de subida**: colocar `index.html` en el directorio y deshabilitar la ejecución de PHP (nginx `location ~ \.php { deny all; }`).
3. **Ajuste de los regex del WAF**: los `sqli_patterns` de `security.php` contienen patrones amplios como `\b(select|update|delete|...)\b`; con el límite global, los tickets de usuarios o reseñas que contengan esas palabras sufrirán 403 indebidos; se recomienda aplicar solo a parámetros sensibles o estrechar los regex.
4. **Registros de auditoría**: `AuditLogger::record('user_registered', ['user_id' => null])` no registra el ID del usuario nuevo; se recomienda registrar el ID real.
5. **Cobertura de pruebas OAuth**: `OAuthServiceTest` cubre la construcción de URLs y el canje de código, pero `resolveUser()` (ruta de BD) y la ruta de verificación de firma de Apple no tienen pruebas; tras la corrección del P0, es obligatorio añadir casos de prueba de fallo de verificación.
6. **Integración CI**: el proyecto tiene un directorio `.github`; se recomienda añadir GitHub Actions: `composer install && phpunit` + `composer audit`, para prevenir regresiones.
7. **Restricción de métodos HTTP**: que las rutas OAuth registren el callback tanto GET como POST es razonable (Apple lo necesita); el resto de operaciones de escritura públicas ya son explícitamente POST, OK.

---

## 7. Lista priorizada de correcciones

| Prioridad | Asunto | Trabajo |
|:---:|------|:---:|
| P0 | Verificación de firma del id_token de Apple (JWKS + iss/aud/exp/nonce) | Medio |
| P1 | Validar `email_verified` en todos los proveedores OAuth | Pequeño |
| P1 | Montar RateLimitMiddleware en las rutas públicas | Pequeño |
| P1 | Aplicar TOTP obligatorio en el flujo de inicio de sesión | Medio |
| P1 | Implementar envío de correo real (o marcar como no terminado) | Medio |
| P1 | Completar las 31 variables que faltan en .env.example + documentación de configuración OAuth | Pequeño |
| P2 | Lista blanca de type en subidas + validación MIME | Pequeño |
| P2 | Límite de peticiones para el código de restablecimiento/olvidé contraseña | Pequeño |
| P2 | Confirmación de contraseña en la interfaz de códigos de recuperación | Pequeño |
| P2 | Confirmar composer.lock, ignorar .phpunit.cache | Mínimo |
| P3 | Limpiar el código muerto de lista negra, ajustar los regex del WAF, completar i18n | Medio |

---

## 8. Estado de las correcciones (2026-08-06)

| Prioridad | Asunto | Estado |
|:---:|------|:---:|
| P0 | Verificación de firma del id_token de Apple (JWKS + iss/aud/exp/nonce) | ✅ Corregido |
| P1 | Validar `email_verified` en todos los proveedores OAuth (X con respaldo /2/email) | ✅ Corregido |
| P1 | Montar RateLimitMiddleware (rutas auth/oauth/password/sms/captcha + 4 reglas nuevas) | ✅ Corregido |
| P1 | TOTP obligatorio en el flujo de inicio de sesión (bloqueo de 15 minutos tras 5 fallos, contador independiente contra DoS) | ✅ Corregido |
| P1 | Envío de correo real (symfony/mailer SMTP; estado dev-stub si no está configurado) | ✅ Corregido |
| P1 | Completar las 31 variables de .env.example + documentación de configuración OAuth | ✅ Corregido |
| P2 | Lista blanca de type en subidas + detección de contenido MIME con finfo | ✅ Corregido |
| P2 | Límite de peticiones para el código de restablecimiento/olvidé contraseña (5 fallos → 429 durante 10 minutos) | ✅ Corregido |
| P2 | Confirmación de contraseña en la interfaz de códigos de recuperación | ✅ Corregido |
| P2 | Designorar y confirmar composer.lock, ignorar .phpunit.cache | ✅ Corregido |
| P3 | Limpieza del código muerto de lista negra, ajuste de los regex del WAF (3 reglas estructurales), completar i18n (reescritura del contenido erróneo de billing/health/storage en zh-CN, `trans()` con fallback_locale) | ✅ Corregido |
| E3 | Puerto 8787 ocupado por erp-php, no puede arrancar localmente | ⚠️ Problema de entorno; sin conflicto en el entorno de despliegue |
| E5 | doctrine/annotations obsoleto | ⚠️ Se conserva tras evaluarlo (dependencia directa de hg/apidoc; eliminarlo rompería la generación de documentación de API) |

Pruebas adicionales: OAuth 12 casos (incluidos parámetro nonce, verificación de firma, rechazo de email_verified, respaldo de email en X), 2 casos tras el ajuste del WAF. Línea base completa: **319/319 en verde (505 assertions)**.

*Método de generación del informe: pruebas completas PHPUnit, `php -l` en 287 archivos, auditoría estática de rutas/middlewares, comparación por diferencia de conjuntos entre uso y definición de env, composer audit, sondeo de puertos y procesos. Línea base de pruebas: 314/314 en verde.*
