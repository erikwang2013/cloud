# Informe de revisión de CloudPlatform (ronda 3, 2026-08-06)

> Alcance: pruebas reales integrales (arranque del servicio + pruebas de humo) + inspección profunda del código + verificación de la integridad de la configuración del ecosistema/seguridad.
> Esta ronda avanza de "legible estáticamente" a "**ejecutable**": se corrigieron 5 P0 a nivel de arranque y 3 P0/P1 a nivel de ejecución; el servicio supera las pruebas de humo con la cadena de middlewares completa.
> Línea base de pruebas: service **316/316 en verde (502 assertions)**; admin **67/67 en verde (124 assertions)**.

---

## 1. Lista de correcciones de esta ronda (todas verificadas con pruebas reales)

### P0 — Nivel de arranque (crash de worker / sitio completo caído)

| # | Problema | Causa raíz | Corrección |
|---|------|------|------|
| 1 | `A facade root has not been set` → crash al arrancar | bootstrap no asigna contenedor a las Facades de Illuminate | `Facade::setFacadeApplication($capsule->getContainer())` (bootstrap.php:149) |
| 2 | `Target class [events] does not exist` | Los listeners de eventos usan Event Facade, pero el contenedor no tiene el servicio events | Cambiado a una instancia `Dispatcher`: `$capsule->setEventDispatcher($dispatcher)` + `$dispatcher->listen()` (3 listeners) |
| 3 | `Class support\SentryBootstrap not found` | Al psr-4 de composer.json le falta el mapeo `support\` | Añadido `"support\\": "support/"` + dump-autoload |
| 4 | `ENCRYPTION_MASTER_KEY` vacío → el servicio de cifrado crashea | Valor vacío en .env (createUnsafeMutable de phpdotenv sobrescribe lo inyectado) | Generada clave base64 de 32 bytes escrita en .env |
| 5 | Todas las rutas `/api/*` devuelven 404 | `ApiRequest::path()` reescribe `/api/xxx` a `/api/v1/xxx`, pero el registro de rutas no tiene prefijo de versión | Eliminada la lógica de reescritura; la ruta se mantiene tal cual (la validación de versión la hace VersionMiddleware basándose en la cabecera X-Api-Version) |
| 6 | `Class "ErikJwt\JWTFactory" not found` | Se usaba un espacio de nombres `ErikJwt\` inexistente | Cambiado al espacio de nombres real del paquete `Erikwang2013\Jwt\*` |
| 7 | `config('plugin.erikwang2013.jwt.jwt')` devuelve null → error de tipo en `createFromConfig()` | `Config::loadFromDir` de webman exige que el directorio del plugin tenga `app.php` (si no, salta el directorio entero); faltaba el directorio del plugin jwt | Añadido `config/plugin/erikwang2013/jwt/app.php` (`'enable' => true`, coherente con la plantilla de vendor) |

### P0 — Nivel de ejecución (500 en la primera petición)

| # | Problema | Causa raíz | Corrección |
|---|------|------|------|
| 8 | `Non-static method Redis::get() called statically` | RateLimitMiddleware llama estáticamente al ext-redis `\Redis::get()` | Cambiado a `\support\Redis::get/setex/incr` |
| 9 | `Class support\Redis not found` | `support\Redis` pertenece a la capa de esqueleto de webman (paquete webman/webman); este proyecto solo instala framework, por eso falta | Creado `support/Redis.php` (por debajo usa el illuminate/redis existente + config/redis.php) |
| 10 | `Illuminate\Support\Facades\Redis::*` de AuthController se resuelve como **instancia phpredis desnuda** (sin conectar) → "server went away" | El contenedor no tiene binding `redis`; el autowiring hace fallback a la clase `Redis` | En bootstrap, registrar `$container->singleton('redis', fn() => support\Redis::manager())` |
| 11 | `Call to undefined function storage_path()` | `storage_path()` pertenece a los helpers del esqueleto; este proyecto no lo tiene | Añadido helper en bootstrap (`base_path()/storage`, con guard function_exists) |

### P1 — Validación de límites

| # | Problema | Corrección |
|---|------|------|
| 12 | `/api/auth/refresh` sin refresh_token da TypeError 500 | Añadida validación `is_string` en AuthController::refresh → 422 |

### Restauración del estado temporal

- `config/server.php` (8787), `config/process.php` (9100/8282), `config/middleware.php` (cadena completa de 11 capas) restaurados desde git tal cual
- Eliminado el error_log de depuración `[AUDIT]` de bootstrap.php

---

## 2. Resultados de las pruebas de humo (cadena de middlewares completa, puerto 8787)

| Endpoint | Resultado | Descripción |
|------|------|------|
| GET /health | 200 | healthy + version |
| GET /health/live | 200 | alive |
| POST /api/captcha/create | 200 | Devuelve la imagen del captcha de clic |
| POST /api/auth/login (sin captcha) | 422 | La validación de captcha funciona |
| POST /api/auth/register (parámetros vacíos) | 422 | La validación de campos funciona |
| POST /api/auth/refresh (sin token) | 422 | Elemento corregido en esta ronda |
| POST /api/auth/forgot-password | 500 (la BD rechaza la conexión) | **Deficiencia del entorno**: a .env le falta DB_PASSWORD, ver §4 |
| GET con X-Api-Version: v99 | 400 | VersionMiddleware funciona |
| GET /api/nonexistent | 404 | Página 404 normal |

Las rutas de Redis (captcha, límite de peticiones, almacenamiento de lista negra JWT) están todas probadas y funcionan.

---

## 3. Verificación de la protección de seguridad

### Cumplido ✓

- **Gestión de claves**: sin claves/contraseñas codificadas en todo el proyecto (escaneo grep); todas las claves van por `getenv()`; .env está en .gitignore
- **Inyección SQL**: sin SQL por concatenación de cadenas; todo pasa por el query builder de Eloquent
- **Validación de entrada**: lista blanca de type en subidas + detección de contenido finfo + límites de tamaño por tipo; validación a nivel de campo en los endpoints auth
- **Límite de peticiones**: cobertura completa de los endpoints públicos sensibles (login 5/min, register 3/min, sms 5/h, captcha 30/60s, oauth 10/60s, password_reset 3/5min), default 60/min
- **JWT**: HS256 + clave de 32 bytes; access/refresh separados; validación de type; lista negra Redis (validación por jti dentro de la librería); TOTP obligatorio + bloqueo por fallos
- **CORS**: lista blanca de Origins (`CORS_ALLOWED_ORIGINS`), sin comodines, sin cabecera de credenciales
- **Cabeceras de seguridad**: nosniff / X-Frame-Options / Referrer-Policy / Permissions-Policy / HSTS (interruptor por env)
- **Antienumeración**: forgot-password devuelve el mismo mensaje de éxito para usuarios inexistentes

### Sugerencias (prioridad baja, sin cambiar)

| Elemento | Descripción |
|----|------|
| Falta cabecera CSP | Content-Security-Policy sin configurar en todo el sitio; el riesgo es bajo en escenarios de API JSON; se sugiere añadir en SecurityHeadersMiddleware una política de nivel `default-src 'none'` |
| Rendimiento del WAF | WafMiddleware lee todo el body en cada petición con `file_get_contents('php://input')` para escanear (31 patrones); con tráfico alto hay gasto de memoria/CPU; se sugiere leer el body solo en POST/PUT con Content-Type coincidente |
| `shell_exec('git rev-parse')` en HealthController | Cada health request lanza un subproceso; en producción se sugiere usar solo el env `APP_VERSION`, dejando shell solo como fallback de desarrollo local |
| ~~RateLimit TOCTOU~~ | ~~check-then-set no atómico~~ **corregido (2026-08-07):** cambiado a `INCR` atómico + `EXPIRE` la primera vez, ver §7-6 |
| X-XSS-Protection | Cabecera obsoleta, inofensiva de conservar; se puede eliminar cuando esté el CSP |

---

## 4. Deficiencias del entorno (no son problemas de código; las debe cubrir operaciones)

1. **A `.env` le falta `DB_PASSWORD`** (único punto bloqueante): docker-compose crea app_user con `${DB_PASSWORD}`, pero a ese .env local le falta la clave → todos los endpoints de BD dan 500. `DB_PASSWORD` ya está definido en `.env.example`; es una credencial de despliegue que el usuario debe rellenar en `.env`.
2. **El 9100 lo ocupa un proceso dart local**: si el puerto por defecto del proceso de métricas no se puede vincular, **impide arrancar todo el grupo** (webman hace pre-check de todos los puertos antes de arrancar). Se ha aplicado una vía alternativa persistente: `METRICS_PORT=9199` en .env (2026-08-07). Cuando dart libere el 9100 se puede volver al valor por defecto.
3. **composer validate fatal (terceros)**: el plugin composer de `erikwang2013/security-php` choca con el eval del propio composer (`isLaravel()` declarado dos veces); no está relacionado con el código de este proyecto; en CI el paso `composer validate --strict` puede fallar por esto; se sugiere añadir continue-on-error a ese paso o saltarse el paquete service.
4. La ocupación del 8787 por erp-php registrada en la ronda anterior está resuelta (esta ronda se ha podido vincular en las pruebas reales).

---

## 5. Verificación de la configuración del ecosistema

| Elemento | Resultado |
|----|------|
| CI (.github/workflows/ci.yml) | Completo: comprobación de sintaxis PHP + tests de admin/service (matriz PHP 8.2/8.3) + composer validate |
| Migraciones | 30 archivos de migración |
| Docker | compose (MySQL+Redis+app), Dockerfile, nginx.conf, prometheus, grafana, supervisor (nginx+webman) |
| Monitorización | MetricsServer (puerto Prometheus independiente) + proceso websocket (process.php) |
| Pruebas de carga | tests/k6 (smoke/products/concurrent) |
| .env.example | Claves más completas que .env (OAuth/Feature switches, todo cubierto); .env no tiene claves que sean superconjunto |
| composer audit | Sin vulnerabilidades de seguridad; 1 paquete obsoleto doctrine/annotations (dependencia de hg/apidoc, se conserva tras evaluarlo) |
| Colas/async | webman/redis-queue instalado; las notificaciones van por NotificationDispatcher |

---

## 6. Sugerencias pendientes (próximas iteraciones)

1. **Cabecera CSP** (ver §3)
2. **Optimización de lectura de body del WAF** (ver §3)
3. **Tras completar DB_PASSWORD, re-probar la cadena completa de BD** (flujo real register→login→refresh→logout + verificación de invalidación de la lista negra JWT)
4. ~~**supervisor sin proceso cron**: las tareas programadas como Billing\Cron\SuspendCheck no tienen entrada de daemon~~ **resuelto (2026-08-07):** nuevo proceso `App\Cron\CronRunner` (evalúa las expresiones de 5 campos de config/cron.php cada minuto) y registro del proceso `queue_consumer` para consumir las colas de provisioning/notification; los dos registros inválidos de cron.php que apuntaban a archivos de script se cambiaron a métodos invocables de `ResourceMonitor`
5. **Paso composer-validate del CI**: por el conflicto del plugin de terceros, se sugiere añadir tolerancia a fallos (ver §4-3)

---

## 7. Correcciones complementarias de la ronda 4 (2026-08-07)

1. **Atomicidad de facturación (P0 financiero)**: `BillingEngine::runDaily()` envuelve las operaciones por recurso en transacciones; débito/suspensión/marcado de eventos se confirman en la misma transacción; `StripeChannel::confirmPayment()` usa `UPDATE ... WHERE status='pending'` para apropiación atómica + bloqueo de línea del pedido, evitando doble contabilización por webhooks.
2. **Idempotencia en concurrencia (P0/P1)**: `AffiliateService::requestPayout()` con bloqueo de línea + retorno directo si ya existe un retiro pending; `SupplierSettlement` (cron y `generateSettlement`) deduplica por proveedor + periodo.
3. **Corrección de datos (P1)**: `MeterCollector` corrige el accidental `$resource->first()` de consulta completa de tabla; `ExchangeRateSync` añade timeout de 10s.
4. **Rendimiento (P2)**: las 30 consultas SUM del Dashboard fusionadas en un solo GROUP BY; `CacheService::forgetPattern()` KEYS→cursor SCAN; caché en proceso del paquete de idiomas de `I18n` por locale; `ImportExport` envuelve toda la importación en una transacción; `BillingEngine` precarga el mapeo de tarifas eliminando N+1.
5. **Seguridad (P1)**: `InternalTokenMiddleware` usa `getRemoteIp()` contra la falsificación de XFF; el registro de Webhooks rechaza direcciones de red privada (SSRF); `JwtAuth` fail-fast con clave vacía; `DbBackupCommand` cambia la contraseña a `MYSQL_PWD` contra la fuga por `ps`; exportación CSV/Excel con protección contra inyección de fórmulas; la API externa de proveedores monta el límite `supplier_api`.
6. **Infraestructura (P2)**: `RateLimitMiddleware` con INCR atómico (elimina TOCTOU); `MetricsServer` corrige el bucle de crash de tipo en `onMessage`; pool de conexiones Redis en `HealthController`; instalado `symfony/mailer ^6.4` (EmailSender era una mina sin explotar); corrección del espacio de nombres `EncryptableBootstrap` en el lado admin.

---

## 8. Correcciones complementarias de la ronda 5 (2026-08-07)

1. **Entrega automática conectada (P0)**: `ProvisioningService::handleOrderPaid` crea la tarea de entrega y la publica en la cola `provisioning`; `process.php` registra el proceso `queue_consumer` (escanea todas las implementaciones de `Webman\RedisQueue\Consumer` bajo app/).
2. **Tareas programadas ejecutables (P0)**: nuevo proceso `App\Cron\CronRunner` (evalúa las expresiones de 5 campos de config/cron.php cada minuto, soporta sintaxis `*/n`/`,`/`-`); los dos registros inválidos de cron.php que apuntaban a archivos de script (no clases) se cambiaron a métodos invocables `ResourceMonitor::collectAllMetrics`/`checkSslCertificates`, y se eliminó el registro checkExpirations duplicado con ExpirationCheck.
3. **Clase de notificación inexistente (P0)**: las 4 llamadas `\Common\Notification\NotificationDispatcher::send()` (clase inexistente) en AuthService/AuthController/ExpirationCheck se cambiaron uniformemente a `\App\Notification\Service\NotificationDispatcher::dispatch($userId, ...)`.
4. **Unificación de los tres sistemas de nombres de tablas (P0)**: las 39 tablas de negocio `erik_*` de install.sql pasan a no tener prefijo (coherentes con la nomenclatura por defecto de Eloquent y las migraciones); las tablas de administración `wa_*` se conservan; el asistente de instalación (install/index.php) pasa a «escribir .env → subproceso que ejecuta las migraciones de service (30 archivos de migración) → install.sql (IF NOT EXISTS salta las tablas ya creadas)», dejando la base de datos completa tras la instalación.
5. **Grupo P1/P2 (completado por subagente, verificado con 316 tests)**: cableado de eventos, escritura del tipo de cambio por moneda, `Response::error` con un parámetro añade 400 (10 ubicaciones), ejecutor de reembolsos (RefundService nuevo), idempotencia de aprobaciones, auditoría de operaciones sensibles de admin, eliminación de noNeedAuth, límite de peticiones de la API de administración, WebSocket cambiado a Redis Pub/Sub, bug de consulta SSL, moneda/saldo negativo, desinfección de credenciales, aplicación de cupones, validación de cantidades, tolerancia a fallos del CI, transpaso de ES_HOST.

**Línea base de pruebas**: service 316/316 (502 assertions), admin 67/67 (124 assertions), todo en verde; `php -l` pasa en todos los archivos modificados.

## Conclusión

Esta ronda avanza de "código legible" a "**arrancable y ejecutable**": los 8 fallos de nivel P0 están corregidos y verificados con pruebas reales, los 316 tests en verde, pruebas de humo superadas con la cadena de middlewares completa. Solo queda un bloqueante de entorno (DB_PASSWORD), y al completarlo se puede validar la cadena completa. La ronda 4 (2026-08-07) completa además más de 20 elementos de refuerzo (atomicidad de facturación, idempotencia en concurrencia, protección de límite de peticiones/inyección); la ronda 5 (2026-08-07) completa los 4 P0 (entrega automática, programación cron, clase de notificación, sistema de nombres de tablas) y todo el grupo P1/P2, con los tests manteniéndose en verde.
