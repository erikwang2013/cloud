# Plataforma global de comercio de recursos en la nube — Diseño del sistema

## Resumen del proyecto

Plataforma de comercio de recursos en la nube orientada a usuarios globales, con soporte de modo híbrido de operación propia + proveedores externos. Los usuarios pueden comprar servidores, IP, discos en la nube, dominios y otros productos en la nube. Aprovisionamiento totalmente automático de recursos, múltiples canales de pago, múltiples monedas y múltiples idiomas.

### Pila tecnológica

| Capa | Tecnología |
|------|------|
| App de usuario | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| Panel de administración | webman-admin |
| Servidor | PHP webman (monolito modular) |
| Base de datos | MySQL 8.0 (maestro-esclavo) |
| Caché/Colas | Redis (caché + sesión + colas) |
| Almacenamiento | S3/OSS + CDN |
| Monitorización | Prometheus + Grafana + Sentry + ELK/Loki |

---

## 1. División de módulos (12 módulos principales)

| Módulo | Responsabilidad |
|------|------|
| **User** | Registro/inicio de sesión (OAuth+correo+móvil), verificación de identidad KYC, nivel de miembro, cuenta de saldo |
| **Product** | Definición de productos (SKU), precios por región, gestión de stock, categorías, búsqueda, reseñas |
| **Order** | Carrito de compra, realización de pedidos, ciclo de vida del pedido (pendiente de pago→pagado→en aprovisionamiento→completado→reembolsado), renovación/actualización |
| **Payment** | Enrutamiento de canales de pago, cotización multimoneda, tipos de cambio, reembolsos, conciliación |
| **Provisioning** | Integración con las API de cada proveedor en la nube, creación/renovación/destrucción automática de recursos |
| **Domain** | Consulta de dominios, registro, transferencia, renovación, gestión de DNS |
| **Supplier** | Registro de proveedores, aprobación, publicación de productos, liquidación, reparto |
| **Monitor** | Sondeo de estado de recursos, recopilación de uso, reglas de alerta |
| **Ticket** | Envío de tickets, asignación, seguimiento de SLA |
| **Notification** | Correo/SMS/Push de App/mensajes internos, múltiples plantillas y múltiples idiomas |
| **Report** | Informes de ingresos, informes de liquidación de proveedores, tendencias de ventas |
| **I18n** | Entradas multilingües, tipos de cambio multimoneda, múltiples zonas horarias |

---

## 2. Modelos de datos principales

### Centro de usuarios (User)

- **users** — tabla principal de usuarios (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — perfil del usuario (user_id, avatar, nickname, country)
- **user_kyc** — verificación de identidad (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — cuenta de saldo (user_id, currency, balance, frozen_balance)
- **user_balance_log** — registro de movimientos de saldo (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — direcciones del usuario (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### Centro de productos (Product)

- **product_categories** — categorías de productos (id, parent_id, name, icon, sort)
- **products** — tabla principal de productos (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — precios por región (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — imágenes de productos (product_id, url, sort)
- **product_attributes** — atributos personalizados (product_id, key, value)
- **product_reviews** — reseñas de productos (user_id, product_id, order_id, rating, content)
- **regions** — tabla de regiones (id, name, continent, country, city, data_center, status)

### Centro de pedidos (Order)

- **carts** — carrito de compra (user_id, sku_id, region_id, quantity, cycle)
- **orders** — tabla principal de pedidos (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — líneas de pedido (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — línea temporal del pedido (order_id, status, operator, remark, created_at)
- **order_invoices** — facturas (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — solicitudes de reembolso (order_id, user_id, amount, reason, status, handled_by)

### Centro de pagos (Payment)

- **payment_channels** — configuración de canales de pago (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — registros de transacciones (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — tabla de conciliación (date, channel_id, channel_total, system_total, diff, status)

### Aprovisionamiento de recursos (Provisioning)

- **resources** — tabla principal de recursos (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — detalles del servidor (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — detalles de la IP (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — detalles del disco en la nube (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — detalles del dominio (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — tareas de aprovisionamiento (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — configuración de API de proveedores en la nube (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### Gestión de recursos de servidores físicos (Host & IP Pool)

Los servidores físicos de operación propia usan Proxmox VE (edición comunitaria, gratuita) para gestionar las máquinas virtuales, creando/gestionando VM mediante la REST API, asignando IP y montando discos.

- **host_machines** — servidores host (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — pool de IP (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — registro de asignación de IP (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — detalle de discos de VM (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — registro de ampliación de disco (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### Proveedores (Supplier)

- **suppliers** — tabla principal de proveedores (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — asociación de productos de proveedor (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — liquidaciones (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — registros de retiro de fondos (supplier_id, amount, method, account_info, status)

### Servicios de dominio (Domain)

- **domain_tlds** — TLD soportados (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — transferencias de dominios (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — zonas DNS (domain_name, user_id, zone_id)
- **dns_records** — registros DNS (zone_id, type, name, value, ttl, priority)

### Tickets y notificaciones (Ticket & Notification)

- **tickets** — tickets (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — mensajes de ticket (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — registros de notificaciones (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — plantillas de notificación (code, name, channels, title_template, body_template, variables)

---

## 3. Normas de diseño de API

### Gestión de versiones

La versión de la API se especifica mediante la cabecera HTTP `X-Api-Version`, no en la ruta URL. El servidor inyecta la cabecera de versión en las rutas internas a través de middleware.

```
请求:  GET /api/auth/login
请求头: X-Api-Version: v1

内部路由 → /api/auth/login → 控制器
响应头: X-Api-Version: v1
```

**Versiones soportadas**: `v1` (predeterminada, se usa automáticamente cuando falta la cabecera)

**Mecanismo de control de versiones**: `VersionMiddleware` valida la cabecera `X-Api-Version` en todas las rutas `/api/*` y `/admin/api/*`; si falta, usa `v1` por defecto; las versiones no soportadas devuelven `400`. El número de versión ya no se incluye en la ruta URL.

**Pasos para añadir una versión nueva**:
1. Añadir el número de versión al array `VersionMiddleware::SUPPORTED`
2. Registrar el nuevo grupo de rutas en `route.php`
3. El controlador obtiene la versión mediante `$request->properties['api_version']` para el procesamiento diferenciado

### Rutas RESTful

```
统一前缀: /api
管理后台: /admin/api
```

**Grupos de rutas y matriz de middleware:**

| Grupo de rutas | Middleware | Ejemplos de endpoints |
|--------|--------|---------|
| Público (sin prefijo) | Cadena de middleware global | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | Global + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (usuario) | Global + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (sensible) | Global + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | Global + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (sensible) | Global + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### Formato de respuesta unificado

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 1523
  },
  "request_id": "req_abc123"
}
```

### Esquema de autenticación

| Extremo | Método |
|----|------|
| Usuario | JWT (access_token 2h + refresh_token 30d) + verificación en dos pasos TOTP + códigos de recuperación |
| Administración | JWT (access_token 2h + refresh_token 7d) |
| API de proveedores | API Key (prefijo sk_, almacenada con hash SHA256, se muestra una sola vez al crearse) |
| Callback de proveedores en la nube | Verificación de firma (HMAC-SHA256) |

**Funciones de autenticación implementadas**:
- Registro con correo electrónico + enlace de verificación por correo
- Registro con número de móvil + código de verificación por SMS de Twilio (enfriamiento de 60s + límite de IP 5 veces/hora)
- Inicio de sesión con Google OAuth / Apple Sign In
- Olvido de contraseña (código de verificación por correo + TTL Redis 10 min)
- Verificación en dos pasos TOTP (configuración mediante código QR, códigos de recuperación de respaldo)
- Gestión de sesiones activas (ver/revocar dispositivos de inicio de sesión, con información de client_platform)
- Cancelación de cuenta conforme a GDPR (confirmación de contraseña + borrado suave + revocación de todos los tokens)
- Alerta de inicio de sesión anómalo (notificación por correo al iniciar sesión desde una IP nueva)
- Bloqueo de inicio de sesión (5 fallos bloquean durante 15 minutos)

**Flujo de autenticación de usuario:**

```
注册流程                             登录流程
────────                             ────────
1. POST /captcha/create              1. POST /captcha/create
   ← {key, image(点击位置)}              ← {key, image}
2. POST /auth/register               2. POST /auth/login
   → {email, password, captcha}         → {login, password, captcha}
   → [WAF 扫描]                         → [WAF 扫描]
   → [限流: 3 req/min]                  → [限流: 5 req/min]
   → [密码 bcrypt(cost=12)]             → [Hash::check()]
   → [设备指纹: sha256(UA+IP)]           → [设备指纹: sha256(UA+IP)]
   → [client_platform 记录]              → [client_platform 记录]
   → User::create()                    → [失败 5 次 → 锁 15min]
   → RefreshToken::create()            → [新 IP 检测 → 邮件告警]
     user_id, token_hash,              → RefreshToken::create()
     device_fingerprint,                   user_id, token_hash,
     client_platform,                      device_fingerprint,
     expires_at                            client_platform,
   → NotificationDispatcher::send()           expires_at
     (验证邮件)                          → AuditLogger::record('user_login')
   → AuditLogger::record               ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}    OAuth (Google/Apple):
                                      ─────────────────────
                                      1. GET /auth/google
                                      2. Google 授权 → code
                                      3. GET /auth/google/callback?code=xxx
                                      4. 验证 Google token
                                      5. 新建或查找用户
                                      6. 签发 token（含 client_platform）
                                      7. AuditLogger::record('user_oauth_login')

TOTP 两步验证                          会话管理
────────────────                      ────────
1. POST /user/totp/setup               GET /user/sessions
   ← {secret, qr_code_url}                ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                      created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}          DELETE /user/sessions/{id}
3. POST /auth/login                      → RefreshToken::update(revoked=true)
   → {login, password, totp_code}        ← 成功
   或 → /auth/login/recovery
   → {login, password, recovery_code}  DELETE /user/account
                                          → 密码确认 + 软删除 + 全部 token 撤销
登录锁定机制
────────────
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### Esquema multilingüe

- Cabecera: Accept-Language: zh-CN / en-US / ja-JP
- Las columnas JSON almacenan textos multilingües: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- Los archivos i18n gestionan los textos estáticos; hay un conjunto para el frontend y otro para el backend

---

## 4. Sistema de protección de seguridad

### Modelo de protección por capas

```
┌─────────────────────────────────────────────────────┐
│ 第一层: 网络边界防护                                    │
│   DDoS清洗 / WAF / IP黑白名单 / Geo-Blocking          │
├─────────────────────────────────────────────────────┤
│ 第二层: 传输与应用防护                                  │
│   HTTPS+TLS1.3 / CSP / CORS / JWT鉴权 / 限流          │
├─────────────────────────────────────────────────────┤
│ 第三层: 数据与存储安全                                  │
│   加密存储 / 脱敏 / 审计日志 / 备份                     │
├─────────────────────────────────────────────────────┤
│ 第四层: 虚拟化与资源隔离                                 │
│   Proxmox安全加固 / VM间隔离 / 网络隔离                 │
├─────────────────────────────────────────────────────┤
│ 第五层: 运营与风控                                     │
│   操作审计 / 异常检测 / 告警 / 应急响应                  │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 Protección del perímetro de red

#### Protección DDoS

```
用户请求 → CDN (Cloudflare / 阿里云CDN)
              │
              ├── JS质询 / 验证码 (可疑流量)
              ├── 速率限制 (每IP每秒请求数)
              ├── 区域封禁 (阻断指定国家/地区)
              │
              ▼
          源站 (Nginx + webman)
```

| Capa | Medida | Descripción |
|------|------|------|
| Capa CDN | Depuración DDoS automática | El plan gratuito de Cloudflare ya soporta protección L3/L4 |
| Capa CDN | Bot Management | Identifica e intercepta bots maliciosos/scripts de fraude de pedidos |
| Capa Nginx | limit_req_zone | 10 req/s por IP; al superarse devuelve 429 |
| Capa Nginx | limit_conn | Máximo 20 conexiones concurrentes por IP |
| Capa webman | Middleware de limitación por token bucket | Límite preciso a nivel de usuario/interfaz |

#### Reglas WAF (middleware de webman)

El middleware WAF escanea las solicitudes mediante 8 categorías de grupos de reglas de expresiones regulares; las reglas se configuran en `config/security.php` y se actualizan en caliente sin reiniciar. El alcance del escaneo cubre el JSON del cuerpo de la solicitud, la ruta URL + cadena de consulta, el User-Agent y el cuerpo de solicitud original (para prevenir la evasión por codificación JSON).

**8 categorías de reglas de detección (más de 45):**

| Categoría | Cobertura |
|------|---------|
| Inyección SQL | Comillas simples/marcadores de comentario, palabras clave SQL, codificación hexadecimal, variantes de UNION, condiciones siempre verdaderas (`' OR '1'='1`), inyección de retardo temporal (`sleep`/`benchmark`), consultas apiladas, evasión con comentarios multilínea |
| XSS | Etiquetas HTML (incluidas variantes codificadas), etiquetas Script y variantes, 13 manejadores de eventos JS, objetos globales/funciones peligrosas de JS, pseudo-protocolo `javascript:`, codificación de entidades HTML, inyección de Data URI, atributos de eventos en línea |
| Inyección de comandos | Pipe seguido de comando (`\| cat`), punto y coma seguido de comando (`; whoami`), sustitución de comandos `$(cmd)` y comillas invertidas, palabras clave de comandos independientes |
| Inclusión de archivos | Path traversal (múltiples codificaciones), pseudo-protocolos PHP (`php://`/`data://`/`phar://`), sondeo de rutas absolutas (`/etc/`/`C:\`), inyección de byte nulo |
| Inyección en cabeceras HTTP | Inyección de saltos de línea CRLF (`%0d%0a`/`\r\n`), inyección en cabeceras Host/Cookie/Set-Cookie |
| **SSRF** | Direcciones IPv4 de red interna (127.x/10.x/172.16-31.x/192.168.x), alias de localhost, endpoints de metadata en la nube (169.254.169.254), protocolo file:// |
| **Inyección NoSQL** | Operadores de MongoDB ($where/$gt/$regex/$or, etc.), inyección JS en $where, comandos peligrosos de Redis (FLUSHALL/CONFIG SET/SHUTDOWN) |
| **Open Redirect** | Detección de URLs externas en parámetros como redirect_uri/return_url/next/callback, evasión con doble codificación |

**Protección a nivel de solicitud:**

| Elemento de protección | Medida |
|--------|------|
| Límite de tamaño del cuerpo | Máximo 10MB (al superarlo devuelve 413) |
| Límite de longitud de URL | Máximo 2KB (al superarlo devuelve 414, previene ReDoS) |
| Lista blanca de Content-Type | Solo se permiten application/json, multipart/form-data, application/x-www-form-urlencoded |

**Flujo de detección del WAF:**

```
请求进入
  │
  ▼
1. 获取待扫描文本
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # 请求体
   │     └── false → serialize() 回退
   ├── mb_substr(path + queryString, 0, 2048)                # URL（防 ReDoS 截断）
   ├── User-Agent 头                                          # UA
   └── file_get_contents('php://input')                      # 原始体（防 JSON 编码逃逸）
  │
  ▼
2. 加载规则（从 config/security.php）
   ├── security.waf.sqli_patterns               (9 条)
   ├── security.waf.xss_patterns                (8 条)
   ├── security.waf.cmd_injection_patterns      (5 条)
   ├── security.waf.file_inclusion_patterns     (4 条)
   ├── security.waf.header_injection_patterns   (2 条)
   ├── security.waf.ssrf_patterns               (6 条)
   ├── security.waf.nosql_injection_patterns    (3 条)
   └── security.waf.open_redirect_patterns      (2 条)
   → array_merge() + array_unique()
  │
  ▼
3. 逐条匹配
   foreach patterns as pattern:
     match($pattern, $input) ───→ 命中 → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ 命中 → 返回 403 "Request blocked by WAF"
     match($pattern, $ua)    ───→ 命中 →
     match($pattern, $raw)   ───→ 命中 →
  │
  ▼
4. match() 严格检查
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → 命中 ✓
   ├── $result === 0    → 未命中（安全放行）
   └── $result === false → 模式错误 → error_log() → 作为未命中处理
  │
  ▼
5. 全部未命中 → $next($request) 放行到下一中间件
```

```php
class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        $url = mb_substr($request->path() . '?' . $request->queryString(), 0, 2048);
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

        // 从 config/security.php 加载 8 类规则
        $patterns = array_unique(array_merge(
            config('security.waf.sqli_patterns'),
            config('security.waf.xss_patterns'),
            config('security.waf.cmd_injection_patterns'),
            config('security.waf.file_inclusion_patterns'),
            config('security.waf.header_injection_patterns'),
        ));

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input)
                || $this->match($pattern, $url)
                || $this->match($pattern, $ua)
                || $this->match($pattern, $raw)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }

    private function match(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            error_log("WAF: invalid regex pattern: $pattern");
            return false;
        }
        return $result === 1;
    }
}
```

#### Lista blanca/negra de IP

```
黑名单:
- 已知恶意 IP 库 (定期同步 AbuseIPDB)
- 频繁触发 WAF 规则的 IP (自动加入，Redis TTL 24h)
- 暴力破解登录的 IP (5次失败 → 锁定 30min)

白名单:
- Proxmox 宿主机 IP
- 云厂商回调 IP 段
- 支付网关 webhook IP 段
- 管理员办公网络 IP (可选)
```

#### Geo-Blocking

```php
// GeoIP2 库 (MaxMind)
$country = geoip($request->getRealIp());

// 可配置的阻断列表
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 Seguridad de transporte y aplicación

#### Cadena de ejecución de middleware global

Todas las solicitudes HTTP pasan por los middleware en el siguiente orden; cada middleware es independientemente comprobable:

```
请求 → VersionMiddleware        # X-Api-Version 校验（缺失默认 v1，无效返回 400）
     → CorsMiddleware            # CORS 跨域响应头
     → ClientPlatformMiddleware  # X-Client-Platform 识别（8 种平台），注入 $request->properties
     → WafMiddleware             # 8 类 45+ 规则安全扫描（SQLi/XSS/命令注入/文件包含/头注入/SSRF/NoSQL/开放重定向）
     → LocaleMiddleware          # Accept-Language 解析，设置区域
     → HashidRequestMiddleware   # 请求参数 hashid → 真实 ID 解码
     → MaintenanceMiddleware     # 维护模式（IP 白名单放行）
     ↓
  [路由中间件—按路由组附加]
     → EncryptionMiddleware      # AES-256-GCM 请求/响应体加密
     → Captcha                   # 点击验证码校验（登录/注册前）
     → AuthMiddleware            # JWT Bearer Token 验证 + 角色注入
     → AdminRoleMiddleware       # 管理员 RBAC 权限检查
     → ConfirmationMiddleware    # 敏感操作二次密码确认（5 次失败锁 15min）
     ↓
     控制器
```

#### Responsabilidades de cada middleware

| Middleware | Registro | Responsabilidad |
|--------|---------|------|
| `VersionMiddleware` | Global | Valida la cabecera `X-Api-Version`; si falta, usa `v1` por defecto; las versiones no soportadas devuelven `400` |
| `CorsMiddleware` | Global | Gestiona el preflight OPTIONS, refleja el Origin en `Access-Control-Allow-Origin` |
| `ClientPlatformMiddleware` | Global | Valida la cabecera `X-Client-Platform` e identifica la plataforma del sistema operativo del cliente (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web), inyecta `$request->properties['client_platform']` |
| `WafMiddleware` | Global (service) + instancia admin | 8 categorías con más de 45 reglas + límites de tamaño de solicitud + validación de Content-Type; registra el log de auditoría al interceptar |
| `LocaleMiddleware` | Global | Analiza la cabecera `Accept-Language` y configura la región multilingüe |
| `HashidRequestMiddleware` | Global | Decodifica automáticamente las cadenas hashid de la solicitud a IDs enteros reales |
| `MaintenanceMiddleware` | Global | Comprueba la variable de entorno `MAINTENANCE_MODE`; las IP en lista blanca pasan |
| `EncryptionMiddleware` | Grupos de rutas (/api/auth, /api, /admin/api) | Cifrado AES-256-GCM del cuerpo de solicitud/respuesta, activado por la cabecera `X-Encrypted: 1` |
| `AuthMiddleware` | Grupos de rutas (/api, /admin/api) | Verifica el access_token JWT HS256, inyecta `$request->userId` y `$request->userRole` |
| `AdminRoleMiddleware` | Grupo de rutas (/admin/api) | Comprobación de permisos RBAC del administrador |
| `ConfirmationMiddleware` | Grupos de rutas (operaciones sensibles) | Confirmación de contraseña secundaria, contador de fallos en Redis, 5 fallos bloquean 15 minutos |

#### Detalles del middleware ClientPlatform

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // 仅对 API 路由生效
        $path = $request->path();
        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/admin/api/')) {
            return $next($request);
        }

        $platform = strtolower(trim($request->header('X-Client-Platform', '')));

        if ($platform === '') {
            $platform = 'unknown';
        } elseif (!in_array($platform, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported client platform: {$platform}")
            ), 400, ['X-Client-Platform' => $platform]);
        }

        // 注入请求属性供下游使用（审计日志、会话记录）
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**Flujo de datos**: inyección del middleware → `AuditLogger` lo registra automáticamente → `AuthService::issueTokens()` escribe en `refresh_tokens` → `GET /api/user/sessions` devuelve la información de plataforma

#### Forzar HTTPS

```nginx
# Configuración de Nginx
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload";
    add_header X-Content-Type-Options "nosniff";
    add_header X-Frame-Options "DENY";
    add_header X-XSS-Protection "1; mode=block";
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
}
```

#### Refuerzo de seguridad JWT

```
- access_token 有效期 2h，refresh_token 有效期 30d
- 密钥使用 RSA256 (非对称)，定期轮换 (90天)
- jti (JWT ID) 存入 Redis 实现主动吊销
- refresh_token 绑定设备指纹 (User-Agent + IP 段)
- 换发 refresh_token 时旧 token 立即失效 (rotation)
- 敏感操作 (支付/销毁资源) 需二次验证

设备指纹:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  refresh_token 表记录此指纹，换发时校验
```

#### Política de contraseñas

```
- bcrypt 加密，cost factor = 12
- 最小 8 字符，必须包含大小写字母 + 数字
- 注册/登录连续失败 5 次 → 账号锁定 15 分钟
- 密码修改后，所有已签发 token 立即失效
- 支持 TOTP 两步验证 (用户可选开启)
```

#### Política CORS

```php
// webman 中间件
class CorsMiddleware
{
    public function process(Request $request, callable $next): Response
    {
        $allowedOrigins = config('cors.allowed_origins', []);
        $origin = $request->header('Origin');

        $response = $next($request);

        if (in_array($origin, $allowedOrigins)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type,Authorization,Accept-Language');
            $response->header('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
```

#### Seguridad de subida de archivos

```
- 白名单校验扩展名 (仅允许: jpg, jpeg, png, pdf, gif)
- 校验文件 MIME 类型 (不允许伪造 Content-Type)
- 文件大小限制: 头像 2MB, KYC 证件 5MB, 附件 10MB
- 上传后重命名: {uuid}.{ext}, 不保留原始文件名
- 图片二次处理: GD/Imagick 去除 EXIF + 元数据
- 存储路径在 web 不可访问目录, 通过 PHP 代理读取
- 病毒扫描: ClamAV (KYC 证件/用户上传文件)
```

---

### 4.3 Seguridad de datos y almacenamiento

#### Cifrado de datos sensibles

```
加密算法: AES-256-GCM (带认证的加密，防篡改)
密钥管理: 主密钥存于环境变量，每个字段使用独立派生密钥

需要加密存储的字段:
| 数据类型 | 字段 | 加密方式 |
|----------|------|----------|
| 密码 | users.password_hash | bcrypt (单向) |
| 支付密钥 | payment_channels.api_key | AES-256-GCM |
| 云厂商密钥 | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Proxmox Token | host_machines.api_token_encrypted | AES-256-GCM |
| KYC 证件号 | user_kyc.id_number | AES-256-GCM |
| 支付账号 | 提现账号 | AES-256-GCM |
| 登录密码(VNC) | resource_servers.login_password | AES-256-GCM |

密钥派生:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### Enmascaramiento de logs

```php
class LogSanitizer
{
    // 自动脱敏的字段名模式
    private array $sensitiveFields = [
        'password', 'password_hash', 'secret', 'api_key',
        'token', 'credit_card', 'cvv', 'ssn', 'id_number',
        'login_password', 'private_key',
    ];

    public function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->matchSensitive($key)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }
}

// Monolog Processor 在写入日志前自动调用
```

#### Seguridad de base de datos

```
- MySQL 使用 prepared statement (Eloquent 自动处理)
- 数据库访问账号最小权限原则:
  - app_user: SELECT, INSERT, UPDATE, DELETE (无 DDL)
  - migration_user: DDL 权限 (仅迁移时使用，IP 限制)
  - read_user: SELECT 只读 (报表/数据分析使用)
- 连接使用 SSL/TLS (PHP PDO SSL options)
- 数据库端口不对公网开放 (仅内网可访问)
- 定期备份: 全量备份 1天, binlog 实时同步
```

#### Copia de seguridad y recuperación de datos

```
备份策略:
- MySQL: 每日全量 + binlog 实时增量
- Redis: RDB 每小时 + AOF 实时持久化
- 用户上传文件: S3/OSS 自动多副本 + 跨区域复制
- Proxmox VM 快照: 每周一次 (保留 4 周)
- 备份加密: AES-256 加密后存储

恢复演练:
- 每季度执行一次灾难恢复演练
- 恢复时间目标 (RTO): < 4 小时
- 恢复点目标 (RPO): < 1 小时
```

---

### 4.4 Virtualización y aislamiento de recursos

#### Refuerzo de seguridad de Proxmox

```
1. API 访问控制:
   - Proxmox API 仅监听内网 IP (不绑定公网)
   - Token 权限最小化: 每个 role 仅授予必要权限
   - API 端口 (8006) 仅允许 PHP 应用服务器 IP 访问 (iptables)

2. SSH 加固:
   - 禁用密码登录，仅允许密钥认证
   - 禁用 root 登录，使用专用管理账户
   - SSH 端口改为非标准端口 (减少扫描)
   - Fail2ban: 5 次失败锁定 1 小时

3. 系统更新:
   - Proxmox 订阅安全更新邮件列表
   - 定期 apt update && apt upgrade
   - 内核 livepatch (Canonical Livepatch Service)

4. 防火墙 (iptables/nftables):
   - 默认拒绝所有入站
   - 仅开放: 8006 (仅应用服务器IP), SSH端口 (仅管理IP)
   - VM 网桥与宿主机管理网络的隔离
```

#### Aislamiento entre VM

```
- 每个 VM 使用独立的虚拟网桥 VLAN
- 禁止 VM 间通信 (Proxmox 防火墙规则 + VLAN 隔离)
- 用户仅能通过公网 IP 访问自己的 VM
- VM 资源限制 (cgroup): 防止单个 VM 耗尽宿主机资源
  - CPU limit: 购买的核数上限
  - RAM limit: 购买的容量上限
  - Disk IOPS limit: 防止磁盘争用
  - Network bandwidth limit: 购买的带宽上限
```

#### Seguridad en la asignación de IP

```
- IP 分配记录完整审计 (谁、何时、分配了什么 IP)
- IP 释放后冷却期 24h (防止 IP 被立即分配给其他人导致的误用)
- IP 黑名单: 被投诉/滥用的 IP 标记为不可分配
- IP 使用监控: 定期检查分配的 IP 是否正常使用中
```

---

### 4.5 Seguridad de pagos

```
1. PCI DSS 合规:
   - 信用卡数据不经过自有服务器 (Stripe Elements / Checkout)
   - card_token 由 Stripe 前端直接生成，后端仅接收 token
   - 不在日志/数据库中存储任何 CVV/完整卡号

2. 加密货币:
   - 收款私钥冷存储 (离线签名)
   - 热钱包仅保留日常周转额度
   - 收款地址生成后验证校验和
   - 大额交易 ( > $10000) 人工审核后手动确认

3. 支付防欺诈:
   - 同一用户/IP 短时间内高频支付 → 风控冻结
   - 新注册用户大额支付 → 人工审核
   - 支付金额异常 (与商品价格不匹配) → 阻断
   - 退款率过高的用户 → 标记风控

4. 回调验签:
   - Stripe: 验证 webhook signature (stripe-signature header)
   - Coinbase: 验证 webhook signature (X-CC-Webhook-Signature header)
   - 支付宝: 验证 notify_id 回调支付宝服务器二次确认
   - 所有回调: 验证 IP 是否为已知支付网关 IP 段
```

#### Seguridad de reembolsos

```
- 退款必须经过二级审批 (客服发起 → 管理员确认)
- 退款前校验: 订单状态、退款时限、退款次数
- 退款金额不能超过原订单实付金额
- 原路退回: 支付通道退款接口 + 余额退回
- 退款互斥锁 (Redis): 防止并发重复退款
```

---

### 4.6 Control de acceso y permisos

#### Modelo RBAC

```
角色层级:
  super_admin    (超级管理员 — 全部权限)
  admin          (管理员 — 除系统配置外全部)
  finance        (财务 — 支付/对账/退款/结算)
  support        (客服 — 用户/订单/工单管理)
  supplier       (供应商 — 自己的商品/订单/结算)
  user           (普通用户 — 自己的资源/订单/工单)

权限定义:
  {module}.{action}
  例: order.view, order.create, order.refund, resource.destroy

权限检查中间件:
  class RbacMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $user = Auth::user();
          $requiredPermission = $request->route->get('permission');
          
          if (!$user || !$user->hasPermission($requiredPermission)) {
              AuditLog::unauthorized($user, $requiredPermission, $request);
              return errorResponse(403, 'Forbidden');
          }
          return $next($request);
      }
  }
```

#### Límite de velocidad de API

```php
// webman 限流中间件 (Redis 令牌桶)
class RateLimitMiddleware
{
    // 默认: 60 req/min 每用户
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // 防暴力破解
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // 防批量注册
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // 支付限速
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // API 调用
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // 上传限速
    ];
    
    public function process(Request $request, callable $next): Response
    {
        $route = $request->route->getName();
        $limit = $this->limits[$route] ?? $this->limits['default'];
        $key = "ratelimit:{$request->getRealIp()}:{$route}";
        
        if (!$this->checkLimit($key, $limit)) {
            return errorResponse(429, 'Too Many Requests', [
                'retry_after' => $limit['per'],
            ]);
        }
        return $next($request);
    }
}
```

#### Aislamiento de datos de proveedores

```
数据隔离原则:
- 供应商只能查询和操作自己的资源
- 所有涉及 supplier_id 的查询自动追加 WHERE supplier_id = auth()->supplier_id

实现方式:
  // 全局 Scope
  class SupplierScope implements Scope
  {
      public function apply(Builder $builder, Model $model)
      {
          if ($user = Auth::user()) {
              if ($user->role === 'supplier') {
                  $builder->where('supplier_id', $user->supplier_id);
              }
          }
      }
  }
  
  // 在 Product/Order 等 Model 上注册
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 Auditoría de operaciones

```
审计日志记录内容:
- 操作者 ID、IP、User-Agent
- 操作时间
- 操作模块 (哪个菜单/接口)
- 操作类型: 创建/修改/删除/导出/审批
- 操作对象: 哪个资源的哪个字段
- 操作前值 / 操作后值 (字段级变更)
- 操作结果: 成功/失败
- 请求 ID (全链路追踪)

记录范围:
- 所有管理端操作 (100% 记录)
- 用户端敏感操作: 支付/销毁资源/KYC提交/修改密码 (100% 记录)
- 登录/登出 (100% 记录)
- API Key 创建/撤销 (100% 记录)

存储与保留:
- 审计日志写入独立数据库 (audit_db)，与应用库分离
- 至少保留 1 年，金融相关保留 3 年
- 支持导出为 CSV/JSON 供合规审查

审计日志中间件:
  class AuditMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $startTime = microtime(true);
          $response = $next($request);
          $duration = microtime(true) - $startTime;
          
          if ($this->shouldAudit($request)) {
              AuditLog::record([
                  'user_id'    => Auth::id(),
                  'ip'         => $request->getRealIp(),
                  'method'     => $request->method(),
                  'path'       => $request->path(),
                  'input'      => LogSanitizer::sanitize($request->all()),
                  'status'     => $response->getStatusCode(),
                  'duration'   => $duration,
                  'request_id' => $request->header('X-Request-Id'),
                  'user_agent' => $request->header('User-Agent'),
              ]);
          }
          return $response;
      }
  }
```

---

### 4.8 Reglas de control de riesgos

```
实时风控引擎:

规则 1: 新账号异常行为
  条件: 注册时间 < 24h AND (支付总额 > $500 OR 创建工单 > 5)
  动作: 标记账号为"观察中"，通知风控管理员

规则 2: 批量注册检测
  条件: 同一 IP 24h 内注册 > 3 个账号
  动作: 拒绝新注册，冻结该 IP 下新账号

规则 3: 支付异常
  条件: 同一用户 1h 内支付失败 > 5 次
  动作: 冻结支付功能 2h，生成风控工单

规则 4: 退款滥用
  条件: 同一用户 30 天内退款 > 3 笔 OR 退款率 > 20%
  动作: 限制该账号退款权限，新订单标记风控审查

规则 5: API 滥用
  条件: 单 token 1h 内 API 调用 > 10000 次
  动作: 该 token 降级 (降低限流阈值)，通知管理员

规则 6: 资源滥用
  条件: VM 被投诉 spam/DDoS/挖矿 (接收 Abuse 通知)
  动作: 自动关机，冻结资源，生成高优先级工单

风控动作:
- 标记 (flag): 仅记录，不影响使用
- 降级 (throttle): 降低限流阈值
- 冻结 (freeze): 暂时禁用特定功能
- 封禁 (ban): 账号永久封禁
```

---

### 4.9 Respuesta a incidentes

```
安全事件分级:

P0 (紧急) — 数据泄露、资金损失、平台宕机
  → 立即通知 CTO + 安全团队
  → 30 分钟内启动应急响应
  → 下线上游受影响服务，保留证据
  → 修复后 24h 内发布事件报告

P1 (严重) — 单账号被盗、支付欺诈、WAF 触发异常上升
  → 通知安全负责人
  → 2h 内处理
  → 冻结受影响账号/资源

P2 (一般) — 漏洞扫描发现中低危漏洞、异常登录告警
  → 录入工单系统
  → 下一个迭代修复

应急联系:
- 触发 P0/P1 告警后自动通知 (邮件 + 短信 + 电话)
- webman 健康检查端点: GET /health (返回 200 或告警)
- 值班表: 7×24 轮值，至少 2 人备岗
```

---

## 5. Motor de aprovisionamiento de recursos

### Arquitectura de plugins Provider

Cada combinación de tipo de producto en la nube × proveedor en la nube implementa una interfaz unificada:

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // 物理机自营专用
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

ProviderFactory enruta a la implementación concreta según (product_type, provider):
- ProxmoxProvider (servidores físicos de operación propia: servidores/discos de datos/IP)
- AwsServerProvider / AliyunServerProvider (servidores en la nube de terceros)
- GcpIpProvider (IP de terceros)
- AzureDiskProvider (discos en la nube de terceros)
- NamecheapDomainProvider / GoDaddyDomainProvider (dominios)

### Garantía de tareas asíncronas

- El Worker de aprovisionamiento sondea la tabla provision_tasks
- Control de concurrencia agrupado por provider (máximo 5 concurrencias por provider)
- Estrategia de reintento: 1min → 5min → 15min → 1h → 6h → 24h (máximo 6 veces)
- Fallo no reintentable → alerta + generación automática de ticket

### Cadena completa del pedido al aprovisionamiento de recursos

```
用户下单                               支付                             资源开通
────────                               ────                             ────────
1. POST /cart                          5. POST /orders/{id}/pay         9. OrderPaid 事件
   → addToCart(sku, region, qty)          → 密码二次确认 (Confirmation)      → ProvisioningService
                                                                             .handleOrderPaid()
2. POST /orders                           → PaymentRouter::route()
   → createOrder()                          选择支付通道                   10. 每个 OrderItem:
   ← {order, order_items}                                                    → ProvisionTask::create()
                                        6. StripeChannel::                     status=pending
3. 应用优惠券                               createPaymentIntent()
   POST /coupons/validate                   → Stripe API                 11. Redis Queue Worker
   → validate('CODE', order_total)          ← {client_secret}                → ProviderFactory
   ← {discount, coupon_id}                                                     .create(task)
                                        7. 前端 confirmCardPayment()
4. GET /orders/{id}/payment-methods     8. Stripe webhook 回调            12. Provider->create()
   → 获取可用支付通道                       → 验签 + 幂等检查                   ├→ HostSelector::select()
   ← [{channel, fee, total}]               → transaction=success              ├→ ProxmoxApi::create()
                                            → 触发 OrderPaid 事件               │  createVM(CPU,RAM,Disk)
                                                                              │  allocateIP()
                                                                              │  startVM()
                                        重试策略 (失败时)                      ├→ 创建 Resource 记录
                                        ────────────────                     └→ 更新 host_machine
                                        1min → 5min → 15min                      已分配资源量
                                        → 1h → 6h → 24h
                                        (6 次后标记失败 + 告警)           13. Order::status = completed
                                                                           → NotificationDispatcher
                                        退款流程                                ::send('resource_ready')
                                        ────────
                                        用户申请 → 客服审核 → admin 确认
                                        → provider.destroy()
                                        → payment.refund()
                                        → 原路退回
```

### Solución de servidores físicos de operación propia: Proxmox VE (edición comunitaria)

Los servidores de operación propia usan Proxmox VE (código abierto y gratuito, AGPL v3); PHP gestiona el ciclo de vida y la asignación de recursos de las VM KVM mediante la REST API de Proxmox a través de HTTP.

Arquitectura:
```
PHP (webman) ──HTTPS──> Proxmox VE API (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (分配给用户)
```

#### Encapsulación del cliente ProxmoxApi

```php
class ProxmoxApi
{
    private string $baseUrl;
    private string $token;

    public function __construct(HostMachine $host)
    {
        $this->baseUrl = "https://{$host->ip_address}:8006/api2/json";
        $this->token   = $host->api_token_encrypted;
    }

    // GET  /api2/json/nodes/{node}/...
    public function get(string $path, array $params = []): array;
    // POST /api2/json/nodes/{node}/...
    public function post(string $path, array $data = []): array;
    // PUT  /api2/json/nodes/{node}/...
    public function put(string $path, array $data = []): array;
    // DELETE /api2/json/nodes/{node}/...
    public function delete(string $path): array;
}
```

#### Operaciones de recursos

**Crear VM (servidor):**
1. HostSelector selecciona un host con recursos suficientes (ordenado por margen de cpu/ram/disk + balanceo de carga)
2. Asigna una IP del ip_pool de ese host
3. ProxmoxApi.post("/nodes/{node}/qemu") crea la VM (establece vmid, name, cores, memory, net0, ipconfig0)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") monta el disco del sistema (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") arranca la VM
6. Actualiza las cantidades asignadas de host_machine.specs (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**Actualizar CPU (en línea):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // 更新宿主机资源统计
```

**Actualizar memoria (en línea):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**Ampliar el disco del sistema:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**Crear un disco de datos por separado:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**Crear una IP por separado:**
Asignación desde el pool de IP → añadir una NIC virtual + configurar la IP mediante la API de Proxmox, o conservarla como recurso independiente para la NIC adicional de una VM existente.

**Destruir VM:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // 关机
$api->delete("/nodes/{node}/qemu/{vmid}");             // 删除 VM
releaseIp($resourceId);                                // 释放 IP 回池
$host->deallocate($specs);                             // 回收宿主机资源
```

#### Estrategia de selección de host

```php
class HostSelector
{
    public function select(int $regionId, array $specs): HostMachine
    {
        return HostMachine::where('region_id', $regionId)
            ->where('status', 'online')
            ->whereRaw('JSON_EXTRACT(specs, "$.cpu_total") - JSON_EXTRACT(specs, "$.cpu_allocated") >= ?', [$specs['cpu']])
            ->whereRaw('JSON_EXTRACT(specs, "$.ram_total_gb") - JSON_EXTRACT(specs, "$.ram_allocated_gb") >= ?', [$specs['ram']])
            ->whereRaw('JSON_EXTRACT(specs, "$.disk_total_gb") - JSON_EXTRACT(specs, "$.disk_allocated_gb") >= ?', [$specs['system_disk']])
            ->orderByRaw('JSON_EXTRACT(specs, "$.cpu_allocated") / JSON_EXTRACT(specs, "$.cpu_total") ASC')
            ->firstOrFail();
    }
}
```

#### Resumen de operaciones de desglose de recursos

| Operación | Implementación | Operación en caliente |
|------|----------|--------|
| Crear VM (CPU+memoria+disco de sistema+IP) | Proxmox create qemu | — |
| Actualizar solo CPU | PUT config cores | En línea |
| Actualizar solo memoria | PUT config memory | En línea |
| Ampliar disco de sistema | PUT resize disk | En línea (requiere soporte de la VM) |
| Crear disco de datos por separado | POST config añadir disco | En línea |
| Crear IP por separado | Asignación del pool de IP + añadir NIC a la VM | En línea |

### Ciclo de vida de los recursos

```
pending → active → destroyed (保留 30 天) → purged (不可恢复)
```

Renovación: active → (renew) → active (prolonga expired_at)
Actualización: active → (upgrade) → upgrading → active

### Origen de los recursos

| Origen | Virtualización/API | Tipos de producto | Descripción |
|------|-----------|----------|------|
| Servidores físicos de operación propia | Proxmox VE (edición comunitaria) | Servidores, discos de datos, IP | Alojados en el propio centro de datos; PHP llama a la API de Proxmox |
| Proveedores en la nube de terceros | SDK de AWS/GCP/阿里云/华为云/Azure | Servidores, IP, discos en la nube | Reventa de recursos en la nube de terceros |
| Registradores de dominios | API de Namecheap/GoDaddy/阿里云万网 | Registro/transferencia de dominios | Servicio de dominios |

### Integración de la primera fase

| Región | Servidores | IP | Discos en la nube | Dominios |
|------|--------|----|------|------|
| Asia-Pacífico | 阿里云、华为云、AWS | 阿里云、GCP | 阿里云、华为云 | 阿里云万网、Namecheap |
| Europa | AWS、GCP、Hetzner | GCP、OVH | AWS、GCP | Namecheap、Gandi |
| Norteamérica | AWS、GCP、Azure | AWS、GCP | AWS、Azure | GoDaddy、Namecheap |

---

## 6. Sistema de pagos

### Enrutamiento multicanal

PaymentRouter consulta los canales disponibles según la preferencia de moneda del usuario, calcula el importe a pagar de cada canal (incluidas las comisiones del canal) y devuelve la lista de opciones de pago.

### Flujo de pago (Stripe)

```
用户端 (Flutter)               服务端 (webman)                Stripe API
───────────────               ──────────────                ──────────
1. 选择 Stripe 支付
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. 创建 payment_transaction
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → Stripe SDK ──────────────────────────────────────────→ 6. 用户确认支付
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  验签 (stripe-signature)
                                  幂等检查 (transaction_no)
                               8. 更新 transaction=success
                               9. 触发 OrderPaid 事件
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← 支付成功页面               ← 返回订单状态
```

### Pago con criptomonedas

1. El usuario selecciona la moneda (p. ej., USDT-TRC20)
2. El backend genera la dirección de cobro mediante la API de Coinbase Commerce / BitPay
3. El Worker consulta la confirmación de la cadena de bloques cada 30s (o mediante webhook)
4. Confirmado el ingreso → se activa el evento OrderPaid

### Tipos de cambio y multimoneda

- La fuente de tipos de cambio se sincroniza periódicamente desde exchangerate-api y se almacena en Redis
- Los precios de los productos se basan en USD; el resto de monedas se convierten en tiempo real
- El tipo de cambio se fija al realizar el pedido; los reembolsos se devuelven al tipo de cambio original

### Control de visibilidad de los canales de pago

Campos de la tabla payment_channels:
- is_visible: si se muestra al usuario
- visible_regions: limita las regiones visibles; vacío significa todas
- min_amount / max_amount: rango de importe de pedido

### Conciliación

Cada madrugada se descargan los informes de liquidación de cada canal y se concilian uno a uno con las transacciones del sistema; las diferencias superiores a $0.01 generan alerta.

### Política de reembolsos

- Servidores/VPS: reembolso completo dentro de las 72h posteriores a la compra
- Dominios: reembolsables dentro de los 5 días posteriores al registro (normativa ICANN)
- IP: no reembolsable tras la compra
- Discos en la nube: misma política que los servidores
- Productos de promociones especiales: no reembolsables

Flujo de reembolso: solicitud del usuario → generación de Ticket → revisión de atención al cliente → confirmación del admin → provider.destroy() → payment.refund() → devolución por la vía original

---

## 7. Estructura de páginas del cliente

### Cliente Flutter / HarmonyOS

- **Autenticación**: inicio de sesión/registro (correo+contraseña, Google OAuth, Apple ID, móvil), olvido de contraseña, verificación en dos pasos
- **Inicio**: selector de región, entradas de categorías de productos, Banner/promociones, productos recomendados
- **Productos**: lista (filtrado por múltiples criterios), detalle (configuración/región/calculadora de precios), reseñas
- **Compra y pago**: carrito, confirmación del pedido (método de pago/dirección de facturación/saldo/código de descuento), caja, resultado del pago
- **Mis recursos**: lista de recursos (filtro por estado), operaciones de detalle (reiniciar/apagar/renovar/actualizar/destruir), SSO de consola, gráficos de uso
- **Pedidos**: lista (pendiente de pago/pagado/completado/reembolsado), detalle, facturas
- **Tickets**: lista, crear, conversación
- **Centro personal**: perfil/KYC, saldo y recarga, notificaciones, gestión de direcciones, configuración de idioma/moneda/seguridad
- **Público**: centro de ayuda, términos de servicio, acerca de

### Panel de administración webman-admin

- **Panel**: resumen + gráficos de tendencias
- **Gestión de usuarios**: lista/detalle/revisión KYC
- **Gestión de productos**: categorías/lista/precios (SKU×región)/stock/reseñas
- **Gestión de pedidos**: lista/detalle/revisión de reembolsos/facturas
- **Gestión de pagos**: configuración de canales/registros de transacciones/informes de conciliación
- **Gestión de recursos**: lista/monitorización de tareas de aprovisionamiento/configuración de API de proveedores en la nube
- **Gestión de proveedores**: revisión de registro/lista/asignación de productos/liquidación/retiro
- **Gestión de tickets**: cola/mis tickets/monitorización de SLA
- **Gestión de dominios**: precios TLD/API de registradores/gestión de transferencias
- **Notificaciones**: gestión de plantillas/registros de envío
- **Configuración del sistema**: administradores y roles/logs de operaciones/multilingüe/tipos de cambio/regiones/parámetros del sistema
- **Informes**: ingresos/liquidaciones de proveedores/análisis de ventas de productos/análisis por región

---

## 8. Sistema de notificaciones

### Cuatro canales

Email (SMTP/SendGrid) / SMS (Twilio/阿里短信) / Push (FCM/HMS) / mensajes internos

### Flujo

Disparo de evento → Notification Dispatcher → coincidencia de plantilla (código de evento + preferencia de idioma) → distribución a cada canal según preferencias del usuario → envío asíncrono mediante la cola Redis

### Tipos de notificaciones

Código de verificación de registro, pago de pedido correcto, aprovisionamiento de recurso completado, recordatorio de expiración de recurso (7d/3d/1d), respuesta de ticket, reembolso completado, alerta de seguridad, campañas promocionales

### Reintento de fallos

3 reintentos con backoff, gestionados mediante webman redis-queue.

---

## 9. Sistema de proveedores

### Proceso de registro

Registro → envío de información de la empresa + contacto + método de liquidación → revisión del administrador → publicación de productos tras la aprobación → revisión del admin de los productos → compra del usuario → reparto automático → solicitud de retiro del proveedor → pago del admin

### Aislamiento de permisos

Los proveedores solo pueden ver sus propios productos/pedidos/liquidaciones/tickets/registros de retiro. No pueden ver los ingresos de la plataforma, los datos de otros proveedores ni la configuración de canales de pago.

### Reglas de reparto

- Productos de operación propia: commission_rate = 100% (todo para la plataforma)
- Productos de terceros: commission_rate = 5%~20% (comisión de la plataforma)
- Fórmula de liquidación: importe del producto del pedido - comisión de la plataforma - comisión del canal = importe a cobrar del proveedor
- Ciclo de liquidación: semanal / mensual

### Proceso de negocio completo del proveedor

```
供应商入驻                              管理员审批
──────────                             ──────────
POST /supplier/apply                   GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,         → 审核供应商信息
     contact_phone, contact_email,       POST /admin/api/suppliers/{id}/approve
     settlement_method}                    → 确认密码
  → SupplierService::apply()               → SupplierService::approve()
  ← {supplier, status:pending}               → User::role = 'supplier'
                                              ← 成功
商品上架
────────
POST /supplier/products               管理员审核
  → {product_id, commission_rate}        → 关联供应商商品 + 设置佣金比例
  ← {supplier_product}                    → 商品状态: published

用户下单 ──→ 支付完成 ──→ 资源开通 ──→ 订单完成

定时结算 (每周一 04:17)                   提现
───────────────────────                 ──────
Cron: SupplierSettlement               POST /supplier/withdraw
  → 统计周期内已完成订单                    → 密码二次确认 (ConfirmationMiddleware)
  → 计算 total_sales - commission        → SupplierService::requestWithdraw()
  → = payable                              → 检查可提现余额
  → 创建 SupplierSettlement                 → 创建 SupplierWithdraw (status:pending)
  → Webhook: settlement.created          ← 成功

管理员打款                              管理员 API Key 管理
───────────                             ──────────────────
POST /admin/api/suppliers/              POST /admin/api/suppliers/{id}/api-keys
  withdraws/{id}/approve                  → 生成 sk_xxx (SHA256 存储)
  → 确认密码                               ← {api_key} (仅显示一次)
  → SupplierWithdraw: status=completed  DELETE /admin/api/suppliers/api-keys/{id}
  → Webhook: withdrawal.approved           → revoked=true
```

---

## 10. Monitorización y operaciones

### Monitorización de recursos

- Métricas recopiladas: uso de CPU/memoria/disco/ancho de banda, conectividad IP, IOPS de discos en la nube, resolución DNS, expiración de certificados SSL
- Método de recopilación: informe de Agent / SNMP (propio) + API de monitorización de proveedores en la nube (terceros) + sondeo de WHOIS/DNS (dominios)
- Ciclo de recopilación: 5 minutos, almacenamiento en Prometheus + VictoriaMetrics

### Reglas de alerta

| Evento de alerta | Severidad | Condición de activación |
|----------|--------|----------|
| Servidor caído | Grave | 3 pings consecutivos inalcanzables |
| CPU/memoria > 90% | Aviso | Persistente durante 10 minutos |
| Disco > 90% | Advertencia | Persistente durante 5 minutos |
| Ancho de banda > 80% | Aviso | Persistente durante 30 minutos |
| Certificado SSL < 30 días para expirar | Advertencia | Comprobación diaria |
| Dominio < 30 días para expirar | Advertencia | Comprobación diaria |
| Fallo en tarea de aprovisionamiento | Grave | 2 fallos consecutivos |
| Diferencia en conciliación de pagos | Grave | Por transacción > $0.01 |

---

## 11. Arquitectura de despliegue

### Entorno de producción

- Servidores de aplicación × 2: webman (multiproceso) + Nginx + Supervisor
- Base de datos: MySQL 8.0 maestro-esclavo (1 maestro, 2 esclavos) + Redis Cluster
- Colas: webman redis-queue (callbacks de pago/notificaciones/tareas de aprovisionamiento)
- Tareas programadas: Crontab (conciliación/liquidación/comprobación de dominios/recordatorios de renovación)
- Almacenamiento: S3/OSS + CDN
- Logs y monitorización: ELK/Loki + Prometheus + Grafana + Sentry

### Estructura de directorios

```
cloud-php/
├── apps/
│   ├── flutter/           # Flutter 客户端
│   └── harmonyos/         # HarmonyOS 客户端 (ArkTS)
├── service/               # webman 服务端
│   ├── app/
│   │   ├── controller/    # 控制器 (按模块)
│   │   ├── service/       # 业务逻辑 (按模块)
│   │   ├── model/         # 数据模型
│   │   ├── middleware/     # 中间件
│   │   ├── event/         # 事件定义
│   │   ├── listener/      # 事件监听器
│   │   ├── queue/         # 队列任务
│   │   ├── provider/      # 云厂商适配器
│   │   └── cron/          # 定时任务
│   ├── common/            # 公共库 (auth/payment/i18n/notification/helper)
│   ├── config/            # 配置文件
│   ├── database/
│   │   └── migrations/    # 数据库迁移
│   └── storage/           # 日志/缓存/上传
├── admin/                 # webman-admin
├── docs/                  # 文档
└── docker/                # Docker 配置
```

### Dependencias Composer clave

workerman/webman-framework、webman/admin、webman/redis-queue、illuminate/database、firebase/php-jwt、stripe/stripe-php、phpseclib/phpseclib、monolog/monolog

### Optimización de alta concurrencia

#### 1. Separación de lectura/escritura de MySQL

Eloquent enruta automáticamente los SELECT a la conexión read y los INSERT/UPDATE/DELETE a la conexión write.

```
配置 (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (主库)
  connections.mysql.read  → DB_READ_HOST  (从库，可配置多个实现负载均衡)
  sticky = true           → 同一请求周期内写后读走主库（防主从延迟）

环境变量:
  DB_HOST=10.0.1.1          # 主库（写）
  DB_READ_HOST=10.0.2.1     # 从库（读），可部署多个
```

**Reglas de enrutamiento de lectura/escritura:**

| Tipo de operación | Destino del enrutamiento | Ejemplo |
|---------|---------|------|
| SELECT | conexión read | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | conexión write | `Order::create(...)` |
| Todas las operaciones dentro de transacciones | conexión write | `DB::transaction(...)` |
| Lectura tras escritura (sticky) | conexión write | Dentro del mismo ciclo de solicitud |

#### 2. Estrategia de caché multinivel de Redis

`CacheService` cachea los datos de lectura de alta frecuencia; cuando Redis no está disponible, degrada automáticamente a consulta directa de la base de datos.

```
缓存分层:
  L1: Redis (进程间共享，毫秒级)
  L2: MySQL (持久化，兜底)

缓存策略:
  产品列表        TTL 5min    按 region_id + category_id + keyword 分键
  产品详情        TTL 10min   按 product_id 分键，内容变更时主动失效
  区域列表        TTL 1h      区域数据极少变动
  汇率            TTL 30min   定时任务刷新 + 主动更新
  TLD 定价        TTL 1h      TLD 价格变动频率低
  帮助文章        TTL 10min   发布/修改时主动失效
  商品分类        TTL 10min   分类树变更时主动失效

缓存预热 (部署后):
  CacheService::warmUp(['products:all', 'regions', 'tlds', 'exchange_rates'])

主动失效 (数据变更时):
  ProductController::update() → CacheService::forgetPattern('products:*')
  Crontab::ExchangeRateSync → CacheService::put('exchange_rates', $rates, TTL)
```

```php
// 使用示例
$products = CacheService::remember(
    "products:list:{$regionId}:{$categoryId}",
    CacheService::TTL_PRODUCT_LIST,
    fn() => Product::where('region_id', $regionId)->where('category_id', $categoryId)->get()
);
```

#### 3. Compresión de respuesta y límite de velocidad en Nginx

```
gzip 压缩:
  gzip on, comp_level=6
  gzip_types: application/json, text/plain, text/javascript, image/svg+xml
  效果: JSON 响应压缩率 70-85%，节省带宽

proxy 优化:
  proxy_buffering on           # 缓冲上游响应，慢客户端不占 worker
  proxy_http_version 1.1       # HTTP/1.1 长连接复用
  keep-alive 到上游             # 减少 TCP 握手

限流:
  limit_req: 10 req/s per IP (burst 20)
  limit_conn: 20 concurrent per IP
  /health 端点不限流（关闭 access_log 减 I/O）
```

#### 4. Recomendaciones de índices de base de datos

Basado en el análisis de los patrones de consulta, los siguientes índices reducen significativamente las filas escaneadas en escenarios de alta concurrencia:

| Tabla | Índice recomendado | Consultas cubiertas |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | Lista de pedidos del usuario + filtro por estado |
| `orders` | `(order_no)` (único) | Consulta exacta por número de pedido |
| `products` | `(status, category_id, sort)` | Lista de productos del frontend + filtro por categoría + ordenación |
| `product_skus` | `(product_id, status)` | Lista de SKU + filtro por estado |
| `product_regions` | `(sku_id, region_id)` (único) | Búsqueda de precios por región |
| `resources` | `(user_id, status)` | Lista de mis recursos |
| `resources` | `(expired_at, status)` | Tarea programada de comprobación de expiración |
| `provision_tasks` | `(status, next_retry_at)` | Sondeo del Worker de tareas pendientes |
| `refresh_tokens` | `(user_id, revoked)` | Consulta de gestión de sesiones |
| `payment_transactions` | `(order_id)` | Consulta de transacciones por pedido |
| `payment_transactions` | `(transaction_no)` (único) | Comprobación de idempotencia de Webhook |
| `tickets` | `(user_id, status)` | Lista de tickets del usuario |
| `notifications` | `(user_id, read_at, created_at)` | Lista de notificaciones del usuario |

#### 5. Estimación de conexiones concurrentes

```
webman 多进程:
  CPU 核数 × 进程数 = worker 数
  例: 4核 × 8 worker = 32 worker 进程
  
MySQL 连接数:
  每个 worker 维持 1 个持久连接
  32 worker × 2 实例 (service + admin) = 64 连接
  主库 32 + 从库 32，保守建议 MySQL max_connections ≥ 200

Nginx 连接数:
  worker_connections 1024 × worker_processes auto
  峰值并发 ≈ worker_connections × worker_processes / 2
  4核服务器 ≈ 2048 并发连接
```

---

## 12. Tabla general de estado de implementación

### Módulos principales

| Módulo | Estado | Descripción |
|------|------|------|
| **User** | ✅ Completado | Registro/inicio de sesión/verificación por correo/OAuth/TOTP/gestión de sesiones/baja GDPR/CRUD de direcciones |
| **Product** | ✅ Completado | Precios SKU×región, categorías, búsqueda (ES), reseñas, atributos, importación/exportación masiva |
| **Order** | ✅ Completado | Carrito, realización de pedidos, ciclo de vida, reembolsos, facturas (PDF), cupones |
| **Payment** | ✅ Completado | Canal Stripe, enrutamiento multicanal, verificación de firma de webhook, conciliación |
| **Provisioning** | ✅ Completado | Proxmox + AWS EC2 + arquitectura extensible de ProviderFactory |
| **Domain** | ✅ Completado | Precios TLD, registros DNS, aprobación de transferencias de dominios |
| **Supplier** | ✅ Completado | Aprobación de registro, publicación de productos, liquidación, retiro, gestión de API Key |
| **Monitor** | ✅ Completado | Sondeo de recursos, motor de alertas, monitorización de certificados SSL |
| **Ticket** | ✅ Completado | Creación/respuesta/asignación/cierre/seguimiento de SLA |
| **Notification** | ✅ Completado | Cuatro canales correo/SMS/Push/mensajes internos + gestión de preferencias del usuario |
| **Report** | ✅ Completado | Informes de ingresos/proveedores/regiones |
| **I18n** | ✅ Completado | Multilingüe, multimoneda, múltiples zonas horarias |

### Sistema de seguridad

| Función | Estado |
|------|------|
| WAF (8 categorías con más de 45 reglas: inyección SQL/XSS/inyección de comandos/inclusión de archivos/inyección de cabeceras/SSRF/inyección NoSQL/open redirect) | ✅ |
| Middleware CORS | ✅ |
| Middleware de identificación de plataforma ClientPlatform (8 plataformas) | ✅ |
| Límite de velocidad de API (token bucket de Redis) | ✅ |
| Geo-Blocking (MaxMind GeoIP2) | ✅ |
| Modo de mantenimiento (interruptor por variable de entorno + lista blanca de IP) | ✅ |
| Cifrado de solicitud/respuesta (AES-256-GCM) | ✅ |
| Logs de auditoría (base de datos independiente, con seguimiento de client_platform) | ✅ |
| Enmascaramiento de datos (procesamiento automático en logs/respuestas) | ✅ |
| Vinculación de huella de dispositivo JWT + rotación de tokens + registro de client_platform | ✅ |
| Contraseñas bcrypt (cost=12) + cifrado secundario Encryptable | ✅ |
| Confirmación de contraseña secundaria (ConfirmationMiddleware, 5 fallos bloquean 15 min) | ✅ |
| Middleware WAF del panel admin | ✅ |
| Monitorización de excepciones Sentry (SentryBootstrap + enmascaramiento en before_send) | ✅ |
| Feature Flags (cobertura dinámica en Redis + API del panel de administración) | ✅ |

### Funciones nuevas (2026-05-21)

| Función | Estado |
|------|------|
| API externa de proveedores (autenticación por API Key + endpoints de pedidos/recursos/liquidación/retiro) | ✅ |
| Push en tiempo real por WebSocket (WebSocket nativo de Workerman + escucha de eventos) | ✅ |
| Scripts de prueba de carga k6 (smoke/productos/concurrencia) | ✅ |

### Estadísticas del backend

| Métrica | Cantidad |
|------|------|
| Endpoints de API | 135 |
| Modelos de datos | 50+ |
| Tablas de base de datos | 50+ |
| Middleware | 15 (global 7 + rutas 6 + API externa 1 + WebSocket de admin) |
| Tareas programadas | 7 |
| Archivos de migración | 22 |
| Tests | 362 tests / 579 assertions (Service 295 + Admin 67) |
| Archivos de test | 22 |
| Scripts de prueba de carga k6 | 3 (smoke / products / concurrent) |

### Documentación

| Documento | Ruta |
|------|------|
| Especificación de diseño del sistema | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| Diseño del panel de administración | `docs/admin-design.md` |
| Documentación de la API de proveedores | `docs/supplier-api.md` |
| Lista de comprobación de despliegue | `docs/deployment.md` |
| Script de pruebas de humo de la API | `docs/api-test.sh` |

### Estado del frontend

| Extremo | Estado | Descripción |
|----|------|------|
| Flutter | 🟡 En curso | ApiClient ya integra la cabecera de versión + ApiService como capa de datos unificada; login/lista de productos/carrito/lista de recursos ya conectados a la API; el historial de pedidos/centro de notificaciones requiere verificación del entorno de compilación |
| HarmonyOS | 🔴 Fase inicial | Solo la página de login y ApiClient |
| Panel Admin | ✅ Completado | Funcionalidad completa de panel/usuarios/productos/pedidos/pagos/recursos/proveedores/tickets/dominios/notificaciones/sistema/informes/Webhook/importación y exportación |
