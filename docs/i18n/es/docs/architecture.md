# Documento de diseño de arquitectura de CloudPlatform

## 1. Resumen del sistema

CloudPlatform es una plataforma global de comercio de recursos en la nube que soporta un modelo híbrido de servidores físicos propios + proveedores externos. Los usuarios pueden comprar servidores (VM), direcciones IP, discos en la nube, dominios y otros productos desde Web/dispositivos móviles; el sistema procesa automáticamente el pago y la entrega de recursos.

### 1.1 Decisiones de arquitectura principales

| Decisión | Elección | Motivo |
|------|------|------|
| Framework de backend | PHP webman (Workerman) | Residente en memoria, dirigido por eventos, multiproceso, respuesta en milisegundos |
| Patrón de arquitectura | Monolito modular | Módulos divididos verticalmente por negocio, capa MVC interna, desacoplamiento entre módulos por eventos |
| Panel de administración | Instancia webman independiente (webman-admin + Layui) | Aísla el tráfico de administración del de usuarios; dominios de fallo separados |
| ORM | Illuminate/Eloquent | Ecosistema Laravel maduro: consultas relacionadas, Scope, eventos, migraciones |
| Clave primaria distribuida | Snowflake 64-bit | Sin dependencia de autoincremento, soporte nativo de sharding |
| Ofuscación de ID | Hashids | Oculta el volumen real de IDs externos, impide el rastreo por crawlers |
| Autenticación | JWT HS256 | Autenticación sin estado, Access 15min + Refresh 30d |
| Cifrado de transporte | AES-256-GCM | Cifrado/descifrado transparente por middleware, GCM autenticado contra manipulación |
| Cifrado de campos | AES-128-ECB | Cast Eloquent con cifrado/descifrado automático, cifrado determinista (el cifrado permite consultas por igualdad; el inicio de sesión y la validación de unicidad dependen de ello); solo soporta ECB |
| Cola de mensajes | Redis Queue | Procesamiento asíncrono de callbacks de pago, distribución de notificaciones y aprovisionamiento de recursos |
| Motor de búsqueda | database (por defecto) / Elasticsearch 8.x | webman-scout con driver database por defecto (fallback a SQL LIKE); con ES se activa el índice con segmentación IK |
| Virtualización | Proxmox VE + kvm-server | Las VM propias se aprovisionan con Rust kvm-server (gRPC :50051, descubrimiento con registro e-cat/etcd); la capa de driver es actualmente un driver simulado, el driver real libvirt es Phase 2 |
| Clientes | Flutter | Un único código base para iOS/macOS/Windows/Linux/Web + HarmonyOS |

### 1.2 Límites del sistema

```
┌──────────────────────────────────────────────────────────────────┐
│                        Lado del usuario                          │
│  Flutter (iOS/macOS/Windows/Linux/Web) + HarmonyOS (ArkTS)      │
└─────────────────────────┬────────────────────────────────────────┘
                          │ HTTPS + JWT
┌─────────────────────────┼────────────────────────────────────────┐
│                    Proxy inverso Nginx                           │
│  Terminación SSL / compresión gzip / límite de frecuencia /      │
│  Upgrade WebSocket                                               │
└─────────────────────────┬────────────────────────────────────────┘
                          │
┌─────────────────────────┼────────────────────────────────────────┐
│              Servidor webman (multiproceso)                      │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ Cadena de middlewares global: Version→CORS→SecurityHeaders│     │
│  │             →ClientPlatform→GeoBlock→WAF→SecurityPlugin  │     │
│  │             →RateLimit→Locale→Metrics→Hashid→Maintenance  │     │
│  │             →[middlewares de ruta]                        │     │
│  └─────────────────────────────────────────────────────────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌───────┐     │
│  │  User   │ │ Product │ │  Order  │ │ Payment │ │Provision│    │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘ └───────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐               │
│  │ Domain  │ │Supplier │ │ Ticket  │ │  Notif  │  ...           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ Servidor WebSocket (:8282) — push en tiempo real         │     │
│  └─────────────────────────────────────────────────────────┘     │
└──────────┬──────────────┬────────────────┬───────────────────────┘
           │              │                │
    ┌──────┴──────┐ ┌─────┴─────┐ ┌───────┴────────┐
    │  MySQL 8.0  │ │ Redis 7.x │ │ Elasticsearch  │
    │  (maestro/  │ │(caché/cola)│ │    8.x        │
    │   esclavo)  │ └───────────┘ └────────────────┘
    └─────────────┘
           │
    ┌──────┴──────────────────────┐
    │  kvm-server (Rust gRPC)     │
    │  registro y descubrimiento  │
    │  e-cat / etcd               │
    │  driver simulado (libvirt   │
    │  Phase 2)                   │
    └──────┬──────────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  API Proxmox VE (:8006)     │
    │  Virtualización KVM/QEMU    │
    │  Pool de IP / pool de discos│
    │  / servidores físicos       │
    └─────────────────────────────┘
```

---

## 2. Arquitectura de componentes

### 2.1 Diseño de doble instancia

El proyecto incluye dos instancias webman independientes que comparten la base de datos MySQL:

```
                    ┌─────────────────────┐
                    │   admin/ (Layui)     │
  Administrador ───▶│   puerto: 8788       │
                    │   Middlewares: WAF→ACL│
                    └──────────┬───────────┘
                               │ SQL
                    ┌──────────┴───────────┐
                    │     MySQL 8.0        │
                    └──────────┬───────────┘
                               │ SQL
  Usuario/API ────────▶│   service/           │
                    │   puerto: 8787       │
                    │   12 globales + 6    │
                    │   de ruta            │
                    └─────────────────────┘
```

| Instancia | Puerto | Responsabilidad | Middlewares |
|------|------|------|--------|
| **service** | 8787 | API de usuario + API de administración + WebSocket | 12 globales + 6 de ruta + SupplierApiKey |
| **admin** | 8788 | Panel HTML de administración (Layui) | WafMiddleware + AccessControl |

### 2.2 Estructura de capas por módulo

Cada módulo de negocio sigue una estructura de capas uniforme:

```
app/{Module}/
├── controller/     # Capa HTTP: validación de parámetros, llamada a Service, retorno de Response
│   └── external/   # Controladores de API externa (autenticación con API Key de proveedor)
├── service/        # Lógica de negocio: sin dependencias HTTP, reutilizable por Controller/Queue Worker
├── model/          # Modelos de datos Eloquent: relaciones, ámbitos de consulta, Casts
├── event/          # Definición de eventos de dominio (OrderPaid, TicketCreated, etc.)
├── listener/       # Listeners de eventos (Provisioning, push WebSocket)
├── provider/       # Adaptadores de proveedores de nube (ProxmoxProvider, etc.)
├── queue/          # Consumidores de cola (ProvisionWorker, EmailSender, etc.)
└── cron/           # Tareas programadas (ExchangeRateSync, ExpirationCheck, etc.)
```

### 2.3 Capas de la librería común

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # Servicio de CAPTCHA de clic
├── clientplatform/      # ClientPlatformMiddleware (encabezado X-Client-Platform)
├── confirmation/        # Middleware de confirmación de contraseña
├── encryption/          # Middleware de cifrado de transporte AES-256-GCM
├── feature/             # Feature Flags (interruptores de funciones)
├── hashid/              # Middleware de decodificación de solicitudes Hashids + servicio de codificación
├── helper/              # Formato de Response + CacheService
├── http/                # Utilidades de cliente HTTP
├── i18n/middleware/     # LocaleMiddleware multilingüe
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # Servicio de ID snowflake + Trait Eloquent
├── metrics/             # Recopilador de métricas Prometheus + renderizador + middleware de conteo de solicitudes HTTP
├── version/             # VersionMiddleware (versión de API desde URL, p. ej. /api/v1/...)
└── webhook/             # Distribuidor de eventos Webhook
```

### 2.4 Módulo CDN

El módulo CDN a nivel de producto (`service/app/cdn/`) conecta cuatro proveedores mediante el patrón de adaptadores, usando servidores o buckets como origen del CDN:

```
CdnAdapterInterface
  ├── CloudflareAdapter   REST v4 (certificado automático SSL SaaS), sin registro ICP
  ├── CloudFrontAdapter   aws-sdk-php (CloudFront + ACM), sin registro ICP
  ├── AliyunCdnAdapter    Firma RPC, requiere registro ICP
  └── TencentCdnAdapter   Firma TC3, requiere registro ICP
         ▲
CdnAdapterFactory.resolve(type, accountId, strict)
  ① cuenta vinculada (provider_account_id) → ② cuenta activa code=cdn-{type} → ③ respaldo env
  strict=true (eliminación/purga): solo la cuenta vinculada; si falta, devuelve 4003 sin cambio silencioso
```

**Gestión de cuentas:** reutiliza el modelo `provider_apis` (credenciales cifradas con Encryptable), CRUD en `/admin/providers` desde el panel de administración (RbacMiddleware), `code` convencional `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, credenciales env degradadas a fallback.

**Modelo de datos:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; la clave privada se elimina de cert_config antes de guardarla). Aislamiento de permisos: los recursos CDN pasan la comprobación de propiedad vía `resource.user_id`; los que no son del usuario devuelven 404 uniformemente.

---

## 3. Pipeline de ejecución de middlewares

### 3.1 Cadena de middlewares global (todas las solicitudes)

```
Solicitud HTTP
  │
  ▼
1. VersionMiddleware         ← lee la versión de la API desde el URL (`/api/v1/...`); versión no válida → 400
  │                            Solo aplica a /api/v1/ y /admin/api/v1/
  ▼
2. CorsMiddleware            ← OPTIONS preflight devuelve encabezados CORS, reflejo de Origin
  ▼
3. SecurityHeadersMiddleware ← Encabezados de respuesta de seguridad HSTS / X-Frame-Options / CSP / Referrer-Policy
  ▼
4. ClientPlatformMiddleware  ← Identificación del encabezado X-Client-Platform (8 plataformas), inyecta properties
  │                            Solo aplica a /api/v1/ y /admin/api/v1/
  ▼
5. GeoBlockMiddleware        ← Bloqueo de países GEO_BLOCKED_COUNTRIES (MaxMind GeoIP2)
  ▼
6. WafMiddleware             ← Escaneo de 45+ reglas en 8 categorías (cuerpo JSON + URL + UA + cuerpo crudo)
  │                          ← Lista blanca de Content-Type + límite de 10MB del cuerpo + límite de 2KB de URL
  │                            Coincidencia → AuditLogger::threat() → 403
  ▼
7. SecurityPlugin            ← 31 tipos de detección de ataques (XSS/inyección SQL/SSRF/deserialización, etc.), listas blanca/negra de IP
  ▼
8. RateLimitMiddleware       ← Límite de frecuencia en todas las rutas (doble cubo por IP y por token)
  ▼
9. LocaleMiddleware          ← Análisis de Accept-Language, establece la región
  ▼
10. MetricsMiddleware        ← Conteo de solicitudes HTTP y registro de latencia de Prometheus
  ▼
11. HashidRequestMiddleware  ← Cadenas hashid de los parámetros de solicitud → decodificación a IDs enteros reales
  ▼
12. MaintenanceMiddleware    ← Comprobación de MAINTENANCE_MODE, las IPs de la lista blanca pasan
  │
  ▼
[Middlewares de ruta — adjuntos por grupo de rutas]
  │
  ├─ /health (monitorización interna) ──
  │   InternalTokenMiddleware      ← Validación de token interno en /health/live|ready|deps
  │
  ├─ /api/v1/auth ─────────────────────
  │   EncryptionMiddleware          ← Cifrado/descifrado del cuerpo de solicitud/respuesta AES-256-GCM
  │
  ├─ /api/v1 (autenticación de usuario) ─
  │   EncryptionMiddleware
  │   AuthMiddleware                ← Verificación de JWT Bearer Token → $request->userId/role
  │
  ├─ /api/v1 (operaciones sensibles) ──
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← Doble confirmación de contraseña, contador en Redis, 5 fallos bloquean 15min
  │
  ├─ /api/v1/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← Verificación SHA256 de sk_xxx → $request->supplierId
  │
  ├─ /admin/api/v1 ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← Comprobación de permisos RBAC
  │
  └─ /admin/api/v1 (operaciones sensibles) ─
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
Controller → Service → Model → DB
```

### 3.2 Detalle de cada middleware

| Middleware | Ubicación | Registro | Responsabilidad |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | Global | Valida la versión de API desde el URL (p. ej. `/api/v1/...`) |
| `CorsMiddleware` | common/Security | Global | OPTIONS preflight, reflejo de Origin |
| `SecurityHeadersMiddleware` | common/Security | Global | Encabezados de respuesta de seguridad HSTS / X-Frame-Options / CSP / Referrer-Policy |
| `ClientPlatformMiddleware` | common/ClientPlatform | Global | Identificación de `X-Client-Platform` en 8 plataformas |
| `GeoBlockMiddleware` | common/Security | Global | Bloqueo geográfico GEO_BLOCKED_COUNTRIES (MaxMind GeoIP2) |
| `WafMiddleware` | common/Security | Global(service)+admin | 45+ reglas en 8 categorías + límites de solicitud |
| `SecurityPlugin` | Erikwang2013\Security | Global | 31 tipos de detección de ataques, listas blanca/negra de IP |
| `RateLimitMiddleware` | common/Security | Global | Límite de frecuencia con cubo de tokens en Redis (doble cubo por IP y por token) |
| `LocaleMiddleware` | common/I18n | Global | Análisis de Accept-Language |
| `MetricsMiddleware` | common/Metrics | Global | Conteo de solicitudes HTTP y latencia de Prometheus |
| `HashidRequestMiddleware` | common/Hashid | Global | Decodificación de solicitudes hashid |
| `MaintenanceMiddleware` | common/Security | Global | Modo mantenimiento + lista blanca de IP |
| `InternalTokenMiddleware` | common/Security | Grupo de rutas | Validación de token interno en `/health/live|ready|deps` |
| `EncryptionMiddleware` | common/Encryption | Grupo de rutas | Cifrado/descifrado AES-256-GCM |
| `AuthMiddleware` | common/Auth | Grupo de rutas | Verificación de JWT Bearer Token |
| `AdminRoleMiddleware` | common/Auth | Grupo de rutas | RBAC de administrador |
| `ConfirmationMiddleware` | common/Confirmation | Grupo de rutas | Doble confirmación de contraseña |
| `SupplierApiKeyMiddleware` | common/Auth | Grupo de rutas | Verificación de firma SHA256 de la API Key sk_xxx |

---

## 4. Arquitectura de datos

### 4.1 Clave primaria distribuida: Snowflake

```
Estructura de ID Snowflake de 64 bits:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  Marca de tiempo   Centro de   Nodo de    Número de
  en milisegundos   datos       trabajo    secuencia
  Época: 2024-01-01
  Vida máxima: ~69 años
```

Todos los modelos Eloquent lo generan automáticamente en el evento `creating` mediante el Trait `HasSnowflakeId`. El tipo de columna en la base de datos es `bigint unsigned`.

### 4.2 Ofuscación de ID: Hashids

```
Flujo de solicitud:
  Cliente: GET /api/v1/products/aB3xK7mQ9w
    → HashidRequestMiddleware decodifica → int(1234567890)
      → Controller/Service opera con el ID entero
        → Response::success() / Response::paginated()
          → hashids_encode_ids() codifica recursivamente todos los campos id/*_id
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 Conexiones de base de datos

```
┌────────────────────┐     ┌────────────────────┐
│  MySQL principal   │     │  MySQL de lectura  │
│  (escritura)       │     │  (lectura)         │
│  DB_HOST           │     │  DB_READ_HOST      │
└────────┬───────────┘     └────────┬───────────┘
         │ write                    │ read (SELECT)
         └──────────┬───────────────┘
                    │
         ┌──────────┴───────────┐
         │  Eloquent Capsule    │
         │  sticky = true       │
         │  conexión persistente│
         │  (PDO)               │
         └──────────────────────┘
                    │
         ┌──────────┴───────────┐
         │  base audit (conexión│
         │  independiente)      │
         │  almacenamiento      │
         │  aislado de logs de  │
         │  auditoría           │
         └──────────────────────┘
```

### 4.4 Capas de cifrado

| Capa | Algoritmo | Implementación | Uso |
|------|------|------|------|
| Transporte | AES-256-GCM | EncryptionMiddleware | Cifrado del cuerpo de solicitudes/respuestas de la API, autenticación GCM |
| Campos | AES-128-ECB | Cast Encryptable | Cifrado/descifrado automático de campos sensibles (cifrado determinista: mismo texto claro → mismo cifrado; el inicio de sesión y la validación de unicidad consultan por igualdad sobre el cifrado; solo soporta ECB, cambiar de cipher requiere migración de recifrado) |
| Hash | bcrypt + SHA256 | JWT / API Key | Almacenamiento irreversible de contraseñas/tokens |
| Clave primaria | Hashids | Response + Middleware | Ofuscación de IDs externos |

### 4.5 Capas de caché

```
L1: Capa de caché Redis (CacheService)
    Lista de productos TTL 5min | Detalle de producto TTL 10min
    Regiones TTL 1h | Tipos de cambio TTL 30min | TLD TTL 1h
    Estrategia de invalidación: forget / forgetPattern activos al cambiar los datos

L2: Capa de consulta MySQL (Eloquent + optimización de índices)
    13 índices compuestos/cobertura para consultas de alta frecuencia

L3: Compresión de respuestas Nginx (gzip level 6)
    Las respuestas JSON se comprimen entre 70-85%
```

### 4.6 Internacionalización (i18n)

```
Accept-Language: zh-CN,zh;q=0.9
         │
         ▼
  LocaleMiddleware (middleware global)
         │  Analiza el idioma principal → zh-CN
         │  I18n::setLocale('zh-CN')
         │  Carga i18n/zh-CN/messages.php
         ▼
  Controller / Service
         │
         ├── I18n::trans('auth.login_success')  →  '登录成功'
         ├── I18n::translateField($jsonField)   →  toma el valor según el idioma
         └── I18n::getLocale()                  →  'zh-CN'
```

| Capacidad | Descripción |
|------|------|
| Análisis del encabezado | `LocaleMiddleware` analiza automáticamente el encabezado `Accept-Language` |
| Fallback de idioma | Idioma no soportado → `fallback_locale` |
| Traducción estática | 120 entradas, cubren 15 módulos (`i18n/{locale}/messages.php`) |
| Sustitución de parámetros | `I18n::trans('key', ['field' => 'value'])` |
| Campos JSON | `translateField()` procesa columnas JSON multilingües |

---

## 5. Arquitectura de seguridad

### 5.1 Sistema de reglas WAF (45+ reglas en 8 categorías)

| Categoría | Nº de reglas | Alcance de detección |
|------|--------|---------|
| Inyección SQL | 9 | Caracteres de comentario, palabras clave, codificación hexadecimal, consultas UNION, condiciones siempre verdaderas, blind time-based, consultas apiladas |
| XSS | 8 | Etiquetas HTML, variantes de Script, 13 manejadores de eventos, pseudo-protocolo JS, codificación de entidades, Data URI |
| Inyección de comandos | 5 | Comandos tras pipe, comandos tras punto y coma, $(cmd), backticks, palabras clave de comandos independientes |
| Inclusión de archivos | 4 | Path traversal, pseudo-protocolos PHP, rutas absolutas, null byte |
| Inyección de cabeceras HTTP | 2 | Saltos de línea CRLF, inyección en Host/Cookie/Set-Cookie |
| SSRF | 6 | IPs de red interna, localhost, metadata de nube, protocolo file:// |
| Inyección NoSQL | 3 | Operadores de MongoDB, comandos peligrosos de Redis |
| Redirección abierta | 2 | redirect_uri a URLs externas, bypass con doble codificación |

**Alcance del escaneo:** las reglas de inyección de valores (SQLi/XSS/inyección de comandos/cabeceras/SSRF/NoSQL/redirección abierta) escanean la query string, el cuerpo de la solicitud y el User-Agent; en la ruta URL solo se aplican los patrones de inclusión de archivos (path traversal) como validación estructural. Las rutas de negocio contienen palabras comunes como select/insert/alert (p. ej. `/order_item/select`); si se escaneara toda la ruta, se bloquearían por error todos los endpoints CRUD, por lo que la ruta no participa en la coincidencia de inyección de valores.

**Protección a nivel de solicitud:** lista blanca de Content-Type, límite de 10MB del cuerpo de solicitud, límite de 2KB de URL

### 5.2 Sistema de autenticación

```
┌─────────────────────────────────────────────┐
│              Métodos de autenticación       │
├──────────────┬──────────────┬───────────────┤
│  Usuario     │  Admin       │  API proveedor│
│  JWT HS256   │  JWT HS256   │  API Key      │
│  Access 15min│  Access 2h   │  prefijo sk_  │
│  Refresh 30d │  Refresh 7d  │  SHA256       │
│  TOTP opc.   │              │  se muestra   │
│  OAuth opc.  │              │  una sola vez │
└──────────────┴──────────────┴───────────────┘
```

---

## 6. Arquitectura de despliegue

### 6.1 Topología de producción

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │  ← Protección perimetral propia de la plataforma (DDoS/Bot),
               │  DDoS / Bot     │    sin relación con el módulo CDN de producto (cuatro
               └────────┬────────┘    proveedores, ver §2.4)
                        │
               ┌────────┴────────┐
               │  Nginx × 2      │
               │  SSL / gzip     │
               │  limit_req      │
               └──┬──────────┬───┘
                  │          │
         ┌────────┴──┐  ┌───┴──────────┐
         │ webman × 2│  │ webman × 2   │
         │ service   │  │ admin        │
         │ :8787     │  │ :8788        │
         │ :8282 WS  │  │              │
         └─────┬─────┘  └──────┬───────┘
               │               │
         ┌─────┴──────┬───────┴───────┐
         │ MySQL      │ Redis Cluster │
         │ maestro/   │ caché+cola    │
         │ esclavos   │               │
         │ 1+2        │               │
         └─────────────┴───────────────┘
               │
         ┌─────┴──────────────────────┐
         │  kvm-server (Rust gRPC)    │
         │  registro e-cat / etcd     │
         │  driver simulado           │
         │  (libvirt Phase 2)         │
         └─────┬──────────────────────┘
               │
         ┌─────┴──────────────────────┐
         │  Clúster Proxmox VE        │
         │  Servidores físicos × N    │
         │  Virtualización KVM/QEMU   │
         └────────────────────────────┘
```

### 6.2 Modelo de procesos

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (por defecto 8)
├── Queue Worker: provisioning     (×2)
├── Queue Worker: email            (×5)
├── Queue Worker: sms              (×10)
├── Queue Worker: push             (×20)
├── WebSocket Worker               (×2, puerto 8282)
└── Cron Timer                     (×1)

webman admin (8788)
└── HTTP Worker × 4
```

---

## 7. Dependencias técnicas

### 7.1 Framework principal

| Paquete | Versión | Uso |
|----|------|------|
| workerman/webman-framework | ^2.1 | Framework web (residente en memoria, multiproceso) |
| illuminate/database | ^10.0 | ORM Eloquent |
| illuminate/events | ^10.0 | Sistema de eventos |
| illuminate/redis | ^10.0 | Cliente Redis |
| webman/redis-queue | ^1.0 | Cola de mensajes Redis |

### 7.2 Paquetes del ecosistema erikwang2013

| Paquete | Uso |
|----|------|
| snowflake-php | Clave primaria distribuida de 64 bits |
| hashids | Ofuscación de ID de API |
| encryptable | Cifrado de campos de base de datos |
| encryption | Cifrado de transporte AES-256-GCM |
| jwt-webman | Autenticación JWT |
| webman-scout | Búsqueda de texto completo en Elasticsearch |
| season | Emoji de banderas de países |
| poster-php | CAPTCHA de clic |

### 7.3 Integraciones de terceros

| Paquete | Uso |
|----|------|
| stripe/stripe-php | Pagos con Stripe |
| twilio/sdk | SMS |
| kreait/firebase-php | Push FCM |
| guzzlehttp/guzzle | Cliente HTTP (API Proxmox, etc.) |
| sentry/sentry | Monitorización de excepciones |
| phpoffice/phpspreadsheet | Exportación a Excel |
