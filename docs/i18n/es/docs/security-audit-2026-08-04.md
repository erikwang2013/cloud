# Informe de auditoría de seguridad — cloud-php

**Fecha**: 2026-08-04
**Alcance**: proyecto completo (service + admin)
**Metodología**: revisión de configuración, auditoría de middlewares, inspección de código

---

## Evaluación general: **B+ (buena, 4 brechas que corregir)**

El proyecto tiene una arquitectura de seguridad multicapa sólida. El plugin erikwang2013/security-php con 31 detectores es lo más destacado. A continuación, el desglose detallado.

---

## 1. Defensas existentes (verificadas)

### Transporte y cifrado
| Mecanismo | Implementación | Estado |
|-----------|---------------|--------|
| Cifrado de transporte de API | AES-256-GCM mediante erikwang2013/encryption | OK |
| Cifrado de campos de BD | AES-128-ECB mediante erikwang2013/encryptable (determinista, consultable) | OK |
| Rotación de claves | ENCRYPTION_PREVIOUS_KEYS con claves antiguas separadas por comas | OK |
| Ofuscación de IDs | Hashids con sal configurable y longitud mínima 12 | OK |
| Hash de contraseñas | bcrypt cost=12, longitud mínima 8 | OK |

### Autenticación y control de acceso
| Mecanismo | Implementación | Estado |
|-----------|---------------|--------|
| Autenticación JWT | erikwang2013/jwt-webman, HS256, TTL de access 900s + refresh 30d | OK |
| Lista negra JWT | Revocación de tokens respaldada por Redis | OK |
| MFA/TOTP | 6 dígitos, periodo de 30s, compatible con Google/MS Authenticator | OK |
| RBAC | Middleware AccessControl de admin + plugin\admin\api\Auth::canAccess() | OK |
| Almacenamiento de sesión | Redis (db2) | OK |
| Captcha | Captcha de clic-texto erikwang2013/poster-php para login/registro | OK |

### Detección de ataques (WAF — doble capa)
| Capa | Cobertura | Estado |
|-------|----------|--------|
| WafMiddleware personalizado | SQLi, XSS, CMDi, path traversal, inyección en cabeceras, SSRF, NoSQLi, redirección abierta | OK |
| Security Plugin (31 detectores) | Todo lo anterior + XXE, deserialización, LDAP, cabecera de correo, SSTI, ataque JWT, cabecera Host, request smuggling, GraphQL, XPATH, JNDI/Log4Shell, SSI, inyección CSV, fuga de datos, prototype pollution, WebSocket, evasión de CORS, DNS rebinding | OK |

### Límite de peticiones (solo service)
| Ruta | Rate | Burst | Per | Estado |
|-------|------|-------|-----|--------|
| default | 60 | 10 | 60s | OK |
| login | 5 | 2 | 60s | OK |
| register | 3 | 0 | 60s | OK |
| pay | 10 | 3 | 60s | OK |
| upload | 10 | 2 | 60s | OK |
| supplier_api | 120 | 20 | 60s | OK |
| supplier_withdraw | 10 | 3 | 60s | OK |

### Otras protecciones
| Mecanismo | Implementación | Estado |
|-----------|---------------|--------|
| Límites de tamaño de petición | Body de 10MB, URL de 2KB | OK |
| Validación de Content-Type | Lista blanca: JSON, multipart, form-urlencoded | OK |
| Sentencias preparadas de BD | PDO::ATTR_EMULATE_PREPARES = false | OK |
| Separación lectura/escritura de BD | Escritura al maestro, lectura a la réplica, sesiones fijas | OK |
| Registro de auditoría | Base de auditoría separada; LogSanitizer redacta campos sensibles | OK |
| Modo de mantenimiento | Las IPs de la lista blanca pasan; el resto recibe 503 + Retry-After | OK |
| Auto-bloqueo de IP | 5 infracciones en 60s, luego bloqueo de 15min | OK |
| Modo estricto SQL | Evita el truncamiento de datos y la conversión implícita de tipos | OK |

---

## 2. Brechas y recomendaciones

### Brecha 1 (Media): CORS refleja cualquier origen
**Archivo**: `service/common/security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

Esto devuelve el eco de cualquier Origin que envíe el cliente, permitiendo de facto que cualquier sitio web haga peticiones entre orígenes autenticadas. El detector cors del plugin de seguridad puede capturar parte de la inyección de cabeceras, pero el propio middleware no ofrece una lista blanca de orígenes.

**Corrección**: añadir una comprobación de lista blanca. Si el origen no está en la lista permitida, responder con `Access-Control-Allow-Origin: null` u omitir la cabecera por completo.

### Brecha 2 (Media): faltan cabeceras de seguridad de respuesta
Ni service ni admin establecen las cabeceras HTTP de seguridad críticas:

| Cabecera | Recomendada | Actual |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | Falta |
| X-Content-Type-Options | nosniff | Falta |
| X-Frame-Options | DENY o SAMEORIGIN | Falta |
| Content-Security-Policy | Política con nonce/hash | Falta |
| X-XSS-Protection | 1; mode=block | Falta |
| Referrer-Policy | strict-origin-when-cross-origin | Falta |
| Permissions-Policy | Restringir cámara/micrófono/geolocalización | Falta |

**Recomendación**: añadir un SecurityHeadersMiddleware a las pilas de middlewares de service y admin. Corrección de gran impacto y poco esfuerzo.

### Brecha 3 (Baja): admin/config/security.php carece de límite de peticiones
**Archivo**: `admin/config/security.php`

El panel de administración no tiene configuración rate_limits. El middleware WAF de admin solo comprueba límites de tamaño de petición/Content-Type. Un ataque de fuerza bruta al login de admin no tiene límite de peticiones a nivel de aplicación.

**Recomendación**: añadir rate_limits a admin/config/security.php o aplicar el RateLimitMiddleware a las rutas de admin.

### Brecha 4 (Baja): GeoBlockMiddleware definido pero no activado
**Archivo**: `service/common/security/GeoBlockMiddleware.php`

El middleware existe y es funcional, pero no está registrado en `service/config/middleware.php`. Si se necesita el bloqueo geográfico, añadirlo a la pila.

### Brecha 5 (Informativa): gasto del doble WAF
Tanto WafMiddleware (personalizado, 40+ patrones de regex) como SecurityMiddleware (plugin, 31 detectores) se ejecutan en cada petición. Su cobertura de patrones se solapa significativamente en SQLi, XSS, inyección de comandos, path traversal, inyección de cabeceras, SSRF, NoSQLi y redirección abierta.

**Recomendación**: el plugin de seguridad es más completo (31 detectores frente a 8 categorías) y tiene lista negra de IPs, lista blanca de campos y deduplicación de registros. Considerar la eliminación del WafMiddleware personalizado confiando solo en el plugin, o al menos eliminar de WafMiddleware los patrones solapados.

### Brecha 6 (Informativa): la clase Validator es mínima
**Archivo**: `service/common/helper/Validator.php`

Solo tiene required(), email(), minLength(). Faltan: longitud máxima, validación numérica, desinfección de cadenas, validación de URL, coincidencia de patrones. Los controladores que no usan validación a nivel de framework corren el riesgo de aceptar entradas malformadas.

---

## 3. Plugin de seguridad — estado de los 31 detectores

| # | Detector | Modo | Notas |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF coincide con el contenido de textarea; debe seguir en log |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }} coincide con plantillas Vue/Angular |
| 13 | nosql_injection | **log** | $ne/$gt coincide con variables de shell/LaTeX |
| 14 | open_redirect | block | |
| 15 | jwt_attack | block | |
| 16 | host_header | block | |
| 17 | request_smuggling | block | |
| 18 | graphql_injection | block | |
| 19 | xpath_injection | block | |
| 20 | jndi_injection | block | |
| 21 | ssi_injection | block | |
| 22 | csv_injection | block | |
| 23 | data_leak | block | |
| 24 | prototype_pollution | block | |
| 25 | websocket | block | |
| 26 | cors | block | |
| 27 | dns_rebinding | **log** | Host de loopback (127.0.0.1/localhost) ya no da 403 (normal en desarrollo/pruebas; solo se registra) |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

Los 31 detectores están activados. 4 en modo solo-registro (riesgo de falsos positivos documentado). Configuración correcta.

---

## 4. Orden de ejecución de middlewares (service)

```
1. VersionMiddleware          — parseo de la cabecera de versión de API
2. CorsMiddleware              — cabeceras CORS (demasiado permisivo, ver Brecha 1)
3. ClientPlatformMiddleware    — detección de SO/plataforma
4. WafMiddleware               — WAF personalizado (40+ patrones de regex)
5. SecurityMiddleware           — WAF del plugin (31 detectores)
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — decodificación de IDs
8. MaintenanceMiddleware       — comprobación del modo de mantenimiento
```

---

## 5. Resumen

| Categoría | Nota | Problemas clave |
|----------|-------|------------|
| Detección de ataques | **A** | 31 detectores, doble capa WAF (redundante pero exhaustiva) |
| Autenticación | **A-** | bcrypt+MFA+lista negra JWT; falta límite de peticiones en admin |
| Seguridad de transporte | **B+** | AES-256-GCM correcto; faltan cabeceras HSTS/CSP |
| Validación de entrada | **B** | El WAF captura ataques; la validación a nivel de aplicación es fina |
| Control de acceso | **A-** | RBAC + comprobación de sesión; CORS demasiado permisivo |
| Auditoría/Registros | **A** | Base de auditoría separada, redacción de campos sensibles |
| Límite de peticiones | **B+** | Bien configurado para service; falta para admin |

**Orden de corrección priorizado:**
1. Añadir cabeceras de seguridad de respuesta (HSTS, CSP, X-Frame-Options, etc.)
2. Restringir CORS a una lista blanca en lugar de reflejar cualquier origen
3. Añadir límite de peticiones al panel de administración
4. Activar GeoBlockMiddleware si se necesita el bloqueo geográfico
5. Considerar consolidar las capas WAF para reducir el gasto de regex por petición

---

## 6. Remediación aplicada (2026-08-04)

### Corregido
| Brecha | Corrección | Archivos modificados |
|-----|-----|---------------|
| CORS refleja cualquier origen | Modo lista blanca con la variable de entorno `CORS_ALLOWED_ORIGINS`; soporta comodines `*.example.com` y `*` para todos | `service/common/security/CorsMiddleware.php` |
| Faltan cabeceras de seguridad | Nuevo `SecurityHeadersMiddleware` añadido a las pilas de service y admin: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection, Permissions-Policy, HSTS (opt-in por env) | `service/common/security/SecurityHeadersMiddleware.php`, `admin/app/middleware/SecurityHeadersMiddleware.php` |
| Admin sin límite de peticiones | Añadida configuración `rate_limits` + `RateLimitMiddleware` al panel de administración (default 60/min, login 5/min) | `admin/config/security.php`, `admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock no activado | Registrado `GeoBlockMiddleware` en la pila de middlewares de service | `service/config/middleware.php` |

### Nuevas variables de entorno
| Variable | Propósito | Valor por defecto |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | Orígenes permitidos separados por comas | (vacío = denegar todos) |
| `SECURITY_HSTS_ENABLE` | Activar la cabecera HSTS | false |
| `SECURITY_HSTS_VALUE` | Valor de la cabecera HSTS | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | Valor de X-Frame-Options | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | Códigos de país bloqueados (ISO 3166-1) | (vacío = desactivado) |
| `GEOIP_DB_PATH` | Ruta del .mmdb de GeoLite2 | storage_path('geoip/GeoLite2-Country.mmdb') |

### Pipeline de middlewares actualizado

**Service:**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin:**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
