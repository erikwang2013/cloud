# Documentación de la API de CloudPlatform

## Resumen

**URL base:** `https://api.example.com`

**Control de versiones:** la versión de la API va en la ruta URL, p. ej. `/api/v1/auth/login`; las versiones no soportadas devuelven `400`.

**Métodos de autenticación:**

| Extremo | Método | Encabezado |
|----|------|--------|
| Cliente | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| Administración | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| API externa de proveedores | API Key | `Authorization: Bearer sk_xxx...` |
| Webhook de Stripe | Verificación de firma | `Stripe-Signature: ...` |

**Plataforma de cliente:** se recomienda incluir el encabezado `X-Client-Platform` en todas las solicitudes a la API; soporta `ios/android/macos/windows/linux/web/harmonyos/ipados`.

**Idiomas:** se recomienda incluir el encabezado `Accept-Language` en todas las solicitudes a la API (`zh-CN` / `en-US`); afecta a los textos traducidos y al valor devuelto de los campos JSON multilingües. Si falta, el valor por defecto es `en-US`.

---

## Formato de respuesta unificado

### Éxito

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### Paginación

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### Error

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### Códigos de estado HTTP

| code | Descripción |
|------|------|
| 0 | Éxito |
| 400 | Error de parámetros de solicitud / versión de API no soportada / plataforma de cliente no soportada |
| 401 | No autenticado |
| 403 | Sin permisos / bloqueado por WAF |
| 404 | El recurso no existe (firstOrFail/findOrFail sin coincidencia se mapea uniformemente a 404) |
| 413 | Cuerpo de solicitud demasiado grande (>10MB) |
| 414 | URL demasiado larga (>2KB) |
| 415 | Content-Type no soportado |
| 422 | Fallo de validación de parámetros |
| 429 | Límite de frecuencia de solicitudes superado |

---

## Grupos de rutas y matriz de middlewares

| Grupo de rutas | Middlewares | Prefijo |
|--------|--------|------|
| Público | Cadena de middlewares global | `/health`, `/api/v1/*` |
| `/health` (interno) | Global + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/v1/auth` | Global + Encryption | `/api/v1/auth/*` |
| `/api/v1` (usuario) | Global + Encryption + Auth | `/api/v1/user/*`, `/api/v1/cart`, `/api/v1/orders` |
| `/api/v1` (sensible) | Global + Encryption + Auth + Confirmation | `/api/v1/orders/{id}/pay` |
| `/api/v1/supplier/external` | Version + SupplierApiKey | API externa de proveedores |
| `/admin/api/v1` | Global + Encryption + Auth + AdminRole | API del panel de administración |
| `/admin/api/v1` (sensible) | Global + Encryption + Auth + AdminRole + Confirmation | Operaciones administrativas sensibles |

---

## 1. Endpoints públicos

### Comprobación de salud

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### Estado del servicio

```
GET /api/v1/status
→ {
  "overall": "operational",
  "components": {
    "api": "healthy",
    "database": "healthy",
    "redis": "healthy",
    "payment_gateway": "healthy",
    "provisioning": "healthy"
  }
}
```

### Productos

```
GET /api/v1/products
   Parámetros: category_id, region_id, keyword, supplier_id, page (por defecto 1), page_size (por defecto 20, máximo 50)
  → Lista paginada de productos (incluye category, skus.regionPrices)

GET /api/v1/products/search
   Parámetros: q (obligatorio), page
  → Búsqueda de texto completo con Elasticsearch

GET /api/v1/products/{id}
  → Detalle del producto (incluye category, skus, images, reviews)

GET /api/v1/products/{productId}/reviews
  → Lista de reseñas + avg_rating + total + distribution
   Enumeración de estados: pending(pendiente de revisión)/approved(aprobada)/rejected(rechazada), solo se devuelven las aprobadas
```

### Dominios

```
GET /api/v1/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/v1/domain/tlds
  → Lista de TLD disponibles (caché en Redis 1h)
```

### Centro de ayuda

```
GET /api/v1/help
   Parámetros: category, page
   Encabezados: Accept-Language (en-US / zh-CN)
  → Artículos de ayuda paginados

GET /api/v1/help/categories
  → Lista de categorías de artículos

GET /api/v1/help/{slug}
  → Detalle de un artículo
```

---

## 2. Endpoints de autenticación

### CAPTCHA

```
POST /api/v1/captcha/create
   Encabezados: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### Registro

```
POST /api/v1/auth/register
   Encabezados: X-Encrypted: 1
   Cuerpo (cifrado): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Límite de frecuencia: 3 req/min
```

- `deviceFingerprint` (opcional): registra la huella del dispositivo al registrarse y se valida al iniciar sesión/renovar; si no se envía, se omite el enlace de huella
- email/phone se cifran con Encryptable determinista (ECB, consulta por igualdad sobre el cifrado) antes de almacenarse; tanto la validación de unicidad como las consultas de inicio de sesión se realizan sobre el cifrado

### Inicio de sesión

```
POST /api/v1/auth/login
   Encabezados: X-Encrypted: 1
   Cuerpo (cifrado): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Límite de frecuencia: 5 req/min, 5 fallos bloquean 15min
```

- `login` se consulta por igualdad sobre el cifrado (cifrado determinista Encryptable); las consultas en claro no alcanzan las columnas cifradas

### Renovación de token

```
POST /api/v1/auth/refresh
   Encabezados: X-Encrypted: 1
   Cuerpo (cifrado): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- Si `deviceFingerprint` no coincide con el registrado → 401 `Device mismatch`; el token de refresco se consulta por hash del cifrado

### OAuth

Proveedores soportados: google, apple, facebook, x, microsoft, linkedin, github
(se habilitan según la configuración de `{PROVIDER}_OAUTH_CLIENT_ID` en `.env`)

```
GET /api/v1/auth/{provider}            → { url }        # Redirige a la página de autorización (PKCE/nonce contra replay)
GET /api/v1/auth/{provider}/callback?code=xxx&state=yyy
POST /api/v1/auth/{provider}/callback  Cuerpo: { code, state }
```

- Apple/Microsoft devuelven id_token; el servidor verifica la firma con JWKS y comprueba iss/aud/exp/nonce
- Todos los proveedores exigen `email_verified=true` para permitir el inicio de sesión; si no, 422
- Si `state` falta o no coincide → 422 (protección CSRF, caduca a los 5 minutos)
- Límite de frecuencia del flujo OAuth: 10 veces por 60 segundos (redirect + callback)

### Restablecimiento de contraseña

```
POST /api/v1/auth/forgot-password
   Cuerpo: { email }
  → Envía un correo con el código de verificación

POST /api/v1/auth/reset-password
   Cuerpo: { email, code, password }
  → Restablecimiento exitoso
  → 5 errores acumulados → 429 limitado durante 10 minutos
```

### Verificación de correo

```
GET /api/v1/auth/verify-email?token=xxx
  → Verificación exitosa
```

### Verificación por SMS

```
POST /api/v1/auth/send-sms
   Cuerpo: { phone }
  → Envía el código de verificación por SMS (enfriamiento de 60s)
```

### Verificación en dos pasos TOTP

```
POST /api/v1/user/totp/setup        → { secret, qr_url }        # No persistido; debe confirmarse con verify en 10 minutos
POST /api/v1/user/totp/verify       Cuerpo: { code } → { verified: true }   # En la primera activación devuelve mensaje de activación exitosa
POST /api/v1/user/totp/disable      Cuerpo: { password }             # Requiere confirmación de contraseña; si no, 403
GET /api/v1/user/totp/recovery-codes → { recovery_codes }        # Genera 8 códigos de un solo uso cada vez; requiere contraseña; si no, 403
POST /api/v1/auth/login/recovery    Cuerpo: { login, password, recovery_code }
```

- Tras activar TOTP, el inicio de sesión debe incluir `totp_code`; si no, 401
- 5 errores consecutivos de TOTP → el usuario queda bloqueado 15 minutos (login_lock)

---

## 3. Endpoints de usuario (requieren autenticación)

### Perfil

```
GET /api/v1/user/profile
PUT /api/v1/user/profile
   Cuerpo: { nickname?, avatar?, country?, language?, timezone? }
```

### Verificación de identidad KYC

```
POST /api/v1/user/kyc
   Cuerpo: { id_type, id_number, real_name, front_image, back_image }
```

### Saldo

```
GET /api/v1/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/v1/user/balance/transactions
   Parámetros: page
  → Historial de movimientos de saldo
```

### Gestión de direcciones

```
GET /api/v1/user/addresses
POST /api/v1/user/addresses
   Cuerpo: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/v1/user/addresses/{id}
DELETE /api/v1/user/addresses/{id}
```

### Gestión de sesiones

```
GET /api/v1/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/v1/user/sessions/{id}
  → Revoca la sesión indicada

DELETE /api/v1/user/account
   Cuerpo: { confirm_password }
  → Eliminación de cuenta según RGPD
```

### Notificaciones

```
GET /api/v1/user/notifications
   Parámetros: page
  → Lista paginada de notificaciones

POST /api/v1/user/notifications/{id}/read
  → Marcar como leída

GET /api/v1/user/notification-prefs
PUT /api/v1/user/notification-prefs
   Cuerpo: { email: {order_paid: true, ...}, push: {...} }
```

### Correo

```
POST /api/v1/user/resend-verify-email
  → Reenviar el correo de verificación
```

### Subida de archivos

```
POST /api/v1/upload
   Cuerpo: multipart/form-data { file, type: avatar/kyc/attach }
   Límites: avatar 2MB, kyc 5MB, attach 10MB
   Permitidos: jpg, jpeg, png, gif, pdf
   Nota: validación de lista blanca de tipos + detección de contenido con finfo (extensión y MIME no coinciden → 422)
```

---

## 4. Carrito y pedidos

### Carrito

```
POST /api/v1/cart
   Cuerpo: { sku_id, region_id, quantity, cycle }
GET /api/v1/cart
DELETE /api/v1/cart/{id}
PUT /api/v1/cart/{id}
   Cuerpo: { quantity }
```

> Convención de campos de importe (decidido en D4/P4.2): todos los importes son string con 4 decimales (p. ej. "9.9900"); prohibido number/float —
> es coherente con la salida cruda de las columnas DECIMAL de MySQL a través de PDO; la precisión la soporta la propia cadena de 4dp. Aplica a todos los endpoints de pedidos/saldo/reportes.

### Pedidos

```
POST /api/v1/orders
  → Crea el pedido desde el carrito
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/v1/orders
   Parámetros: page, status (pending/paid/provisioning/completed/refunded; los valores no válidos devuelven 400)
  → Mi lista de pedidos

GET /api/v1/orders/{id}
  → Detalle del pedido (incluye items, timeline)

GET /api/v1/orders/{id}/payment-methods
  → Canales de pago disponibles + importe a pagar en cada canal

POST /api/v1/orders/{id}/pay    🔒 confirmación de contraseña
   Cuerpo: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### Cupones

```
POST /api/v1/coupons/validate
   Cuerpo: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp (p. ej. "2.0000")

422: no válido/caducado/no cumple las condiciones de uso
```

### Facturas

```
GET /api/v1/invoices
   Parámetros: page
GET /api/v1/invoices/{id}
GET /api/v1/invoices/{id}/download
  → Descarga PDF
```

---

## 5. Gestión de recursos

```
GET /api/v1/resources
   Parámetros: page, status
  → Mi lista de recursos

GET /api/v1/resources/{id}
  → Detalle del recurso

GET /api/v1/resources/{id}/status
  → Estado actual del recurso + métricas

GET /api/v1/resources/{id}/console
  → URL de VNC/consola

POST /api/v1/resources/batch
   Cuerpo: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. Gestión de DNS

```
GET /api/v1/dns/{domain}
  → Lista de registros DNS

POST /api/v1/dns/{domain}/records
   Cuerpo: { type, name, value, ttl?, priority? }

DELETE /api/v1/dns/{domain}/records/{id}   🔒 confirmación de contraseña
```

---

## 7. Tickets

```
POST /api/v1/tickets
   Cuerpo: { resource_id?, category, priority?, title, content }

GET /api/v1/tickets
   Parámetros: page, status

GET /api/v1/tickets/{id}

POST /api/v1/tickets/{id}/reply
   Cuerpo: { content }
```

---

## 8. Proveedores (API interna)

```
POST /api/v1/supplier/apply
   Cuerpo: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/v1/supplier/settlements
  → Lista de liquidaciones

POST /api/v1/supplier/withdraw    🔒 confirmación de contraseña
   Cuerpo: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/v1/supplier/products
POST /api/v1/supplier/products
   Cuerpo: { product_id, commission_rate }
DELETE /api/v1/supplier/products/{id}
```

---

## 9. API externa de proveedores

**Autenticación:** `Authorization: Bearer sk_xxx...` (verificación de firma SHA256)

**Límite de frecuencia:** 120 req/min (retiros 10 req/min)

```
GET /api/v1/supplier/external/orders
   Parámetros: page, page_size, status, from, to

GET /api/v1/supplier/external/orders/{id}
  → Detalle del pedido (solo los asociados a este proveedor)

GET /api/v1/supplier/external/resources
   Parámetros: page, status, type

GET /api/v1/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/v1/supplier/external/settlements
   Parámetros: page, status

GET /api/v1/supplier/external/settlements/{id}

POST /api/v1/supplier/external/withdraw
   Cuerpo: { amount, account_info: { method, ... } }

GET /api/v1/supplier/external/withdraws
   Parámetros: page
```

---

## 10. API del panel de administración

**Autenticación:** JWT Bearer Token + rol de administrador

### Panel

```
GET /admin/api/v1/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### Gestión de usuarios

```
GET /admin/api/v1/users               Parámetros: page, status, keyword
GET /admin/api/v1/users/export       → Descarga Excel
GET /admin/api/v1/users/{id}
PUT /admin/api/v1/users/{id}/status   Cuerpo: { status }
```

### Revisión de KYC

```
GET /admin/api/v1/kyc                 Parámetros: page, status

POST /admin/api/v1/kyc/{id}/approve   🔒 confirmación de contraseña
   Cuerpo: { confirm_password }

POST /admin/api/v1/kyc/{id}/reject    🔒 confirmación de contraseña
   Cuerpo: { confirm_password, reason }
```

### Gestión de productos

```
POST /admin/api/v1/products
PUT /admin/api/v1/products/{id}
DELETE /admin/api/v1/products/{id}         🔒 confirmación de contraseña
POST /admin/api/v1/products/{productId}/skus
PUT /admin/api/v1/skus/{id}
POST /admin/api/v1/skus/{skuId}/region-price
GET /admin/api/v1/products/export         → Descarga CSV
POST /admin/api/v1/products/import        → Subida CSV upsert
```

### Gestión de pedidos

```
GET /admin/api/v1/orders               Parámetros: page, status, keyword
GET /admin/api/v1/orders/export       → Descarga Excel
GET /admin/api/v1/orders/{id}

POST /admin/api/v1/orders/{id}/refund  🔒 confirmación de contraseña
   Cuerpo: { confirm_password, amount?, reason }
```

### Gestión de pagos

```
GET /admin/api/v1/payments/channels
PUT /admin/api/v1/payments/channels/{id}
GET /admin/api/v1/payments/transactions   Parámetros: page, channel, status
GET /admin/api/v1/payments/reconcile      Parámetros: date; records.status: verified/mismatch/unverified
POST /admin/api/v1/payments/reconcile/run   Parámetros: date; dispara la conciliación diaria
```

### Recursos y aprovisionamiento

```
GET /admin/api/v1/provisioning/tasks               Parámetros: page, status
POST /admin/api/v1/provisioning/tasks/{id}/retry
POST /admin/api/v1/provisioning/resources/{id}/upgrade
   Cuerpo: { cpu?, ram?, disk? }
POST /admin/api/v1/provisioning/resources/{id}/destroy   🔒 confirmación de contraseña
GET /admin/api/v1/provisioning/hosts
```

### Gestión de proveedores

```
GET /admin/api/v1/suppliers                  Parámetros: page, status
GET /admin/api/v1/suppliers/export          → Descarga Excel

POST /admin/api/v1/suppliers/{id}/approve    🔒 confirmación de contraseña
POST /admin/api/v1/suppliers/{id}/settle     🔒 confirmación de contraseña
   Cuerpo: { period_start, period_end, confirm_password }

POST /admin/api/v1/suppliers/withdraws/{id}/approve  🔒 confirmación de contraseña
```

### API Key de proveedores

```
GET /admin/api/v1/suppliers/{id}/api-keys
POST /admin/api/v1/suppliers/{id}/api-keys
   Cuerpo: { name }
  ← { api_key: "sk_xxx...", prefix } (se muestra solo una vez)

DELETE /admin/api/v1/suppliers/api-keys/{id}
```

### Gestión de tickets

```
GET /admin/api/v1/tickets                   Parámetros: page, status, priority, assigned_to
POST /admin/api/v1/tickets/{id}/assign      Cuerpo: { user_id }
POST /admin/api/v1/tickets/{id}/close
```

### Gestión de dominios

```
GET /admin/api/v1/domains/tlds
POST /admin/api/v1/domains/tlds
   Cuerpo: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/v1/domains/tlds/{id}
DELETE /admin/api/v1/domains/tlds/{id}
GET /admin/api/v1/domains/zones              Parámetros: page
GET /admin/api/v1/domains/transfers          Parámetros: page
POST /admin/api/v1/domains/transfers/{id}/approve
```

### Gestión de notificaciones

```
GET /admin/api/v1/notifications/templates
PUT /admin/api/v1/notifications/templates/{id}
   Cuerpo: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/v1/notifications/log          Parámetros: page
```

### Cupones

```
GET /admin/api/v1/coupons
POST /admin/api/v1/coupons
   Cuerpo: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/v1/coupons/{id}
```

### Artículos de ayuda

```
GET /admin/api/v1/help
POST /admin/api/v1/help
   Cuerpo: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/v1/help/{id}
DELETE /admin/api/v1/help/{id}              → Eliminación blanda (status=archived)
```

### API de proveedores de nube

```
GET /admin/api/v1/providers
POST /admin/api/v1/providers
   Cuerpo: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/v1/providers/{id}
DELETE /admin/api/v1/providers/{id}         → Deshabilitar (status=disabled)
```

### Gestión de Webhooks

```
GET /admin/api/v1/webhooks
POST /admin/api/v1/webhooks
   Cuerpo: { url }
DELETE /admin/api/v1/webhooks               Cuerpo: { id }
POST /admin/api/v1/webhooks/test            Cuerpo: { url }
```

### Reportes

```
GET /admin/api/v1/reports/revenue            Parámetros: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp (coherente con SUM(DECIMAL) y el agregado con bcmath)
GET /admin/api/v1/reports/supplier           Parámetros: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/v1/reports/region             Parámetros: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### Monitorización

```
GET /admin/api/v1/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/v1/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### Registros de auditoría

```
GET /admin/api/v1/audit-logs                 Parámetros: page, user_id, action, from, to
  → Registros de auditoría paginados (incluye client_platform)
```

### Feature Flags

```
GET /admin/api/v1/features
  → [{ name, enabled, default, source }]

PUT /admin/api/v1/features/{name}
   Cuerpo: { action: enable/disable/toggle/reset }
```

### Configuración del sistema

```
PUT /admin/api/v1/system/config              🔒 confirmación de contraseña
```

### Importación y exportación de productos

```
GET /admin/api/v1/products/export           → Descarga CSV
POST /admin/api/v1/products/import          → Subida CSV upsert
```

### Exportaciones de proveedores y usuarios

```
GET /admin/api/v1/suppliers/export          → Descarga Excel
GET /admin/api/v1/users/export              → Descarga Excel
GET /admin/api/v1/orders/export             → Descarga Excel
```

---

## 11. Certificados SSL

### Lado del usuario

```
GET /api/v1/ssl/plans
  → Lista de planes SSL (DV/OV/EV, precios con register/renew/transfer)

GET /api/v1/ssl-certs
  → Mi lista de certificados (incluye status: pending/active/expired/revoked)

GET /api/v1/ssl-certs/{id}
  → Detalle del certificado (dominio, entidad emisora, validez, estado de renovación)

GET /api/v1/ssl-certs/{id}/download
  → Descargar archivos del certificado (cadena de certificados + clave privada)

POST /api/v1/ssl-certs/{id}/auto-renew
   Cuerpo: { auto_renew: true/false }
  → Activar/desactivar la renovación automática
```

### Lado de administración

```
GET /admin/api/v1/ssl/plans              → Lista de planes
POST /admin/api/v1/ssl/plans             → Crear plan
PUT /admin/api/v1/ssl/plans/{id}         → Actualizar plan
DELETE /admin/api/v1/ssl/plans/{id}      → Eliminar plan
GET /admin/api/v1/ssl/certs              → Todos los certificados
POST /admin/api/v1/ssl/certs/{id}/revoke → Revocar certificado
```

---

## 12. Almacenamiento de objetos

Almacenamiento de objetos compatible con S3, con subida/descarga mediante URLs prefirmadas; las claves no salen del servidor.

```
GET /api/v1/storage/buckets
  → Mi lista de buckets (uso, estado)

GET /api/v1/storage/buckets/{id}
  → Detalle del bucket

POST /api/v1/storage/buckets/{id}/presign-upload
   Cuerpo: { filename, content_type, size }
  → { upload_url, object_key } URL de subida prefirmada (con caducidad)

POST /api/v1/storage/buckets/{id}/presign-download
   Cuerpo: { object_key }
  → URL de descarga prefirmada (con caducidad)

GET /api/v1/storage/buckets/{id}/credentials
  → Credenciales de acceso temporales (válidas por poco tiempo, para subida directa con SDK)
```

---

## 13. Aceleración CDN

### Lado del usuario

```
GET /api/v1/cdn/domains
  → Mi lista de dominios CDN (origen, estado, plan)

POST /api/v1/cdn/domains
  Cuerpo: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
            origin_type (server|storage), origin_value, cert_config? }
  → Crear dominio CDN (se crea en el proveedor y se vincula el origen)
  → con provider_type=aliyun|tencent el dominio debe completar el registro ICP (sin él, devuelve 4002)
  → la respuesta incluye el campo de aviso requires_icp_registration
  → Resolución de credenciales: primero la cuenta vinculada del dominio (provider_account_id); si no,
    la cuenta provider_apis activa que coincide con code=cdn-{provider_type}; si no hay ninguna,
    respaldo de configuración env

GET /api/v1/cdn/domains/{id}
  → Detalle del dominio CDN

DELETE /api/v1/cdn/domains/{id}
  → Eliminar dominio CDN (desactiva el dominio en el proveedor, idempotente)

POST /api/v1/cdn/domains/{id}/purge
  Cuerpo: { urls: ["https://cdn.example.com/path"] }
  → Purgar caché (las URLs repetidas se deduplican automáticamente, idempotente; máximo 100)

GET /api/v1/cdn/domains/{id}/stats
  → Resumen del dominio (cdn_domain / provider_type / plan / status / purged_at)
```

### Lado de administración

```
GET /admin/api/v1/cdn/domains            → Todos los dominios CDN (con el usuario al que pertenecen)
PUT /admin/api/v1/cdn/domains/{id}       → Actualizar el plan del dominio (plan en lista blanca: standard | pro | enterprise)
```

Las rutas CDN de administración usan `RbacMiddleware('cdn.manage')` y los cambios de plan se registran en el log de auditoría (`admin_cdn_update_plan`). Las credenciales de cuentas de proveedor se mantienen mediante CRUD en `/admin/api/v1/providers` (RbacMiddleware `provider.config`, `code` convencional `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, credenciales cifradas con Encryptable).

### Códigos de error CDN

| code | Descripción |
|------|------|
| 4001 | Parámetros CDN faltantes o inválidos (urls vacío, provider_type inválido, formato de dominio incorrecto) |
| 4002 | El dominio no completó el registro ICP (se mapea cuando la API de Aliyun/Tencent Cloud lo rechaza) |
| 4003 | Credenciales del proveedor CDN no configuradas (cuenta ausente/deshabilitada; la instantánea estricta no cambia silenciosamente) |
| 4005 | Fallo al purgar la caché CDN |
| 5001 | Fallo al llamar a la API del proveedor CDN |

> Los recursos CDN que no pertenecen al usuario (ajenos o inexistentes) devuelven uniformemente **404** (mapeo findOrFail, sin revelar la existencia del recurso), sin código de negocio independiente.

---

## 14. Facturación por uso

```
GET /admin/api/v1/billing/rates          → Lista de tarifas (por tipo de recurso/especificación)
POST /admin/api/v1/billing/rates         → Crear tarifa
PUT /admin/api/v1/billing/rates/{id}     → Actualizar tarifa
DELETE /admin/api/v1/billing/rates/{id}  → Eliminar tarifa
GET /admin/api/v1/billing/usage          → Resumen de uso (agregado por usuario/recurso)
```

Pipeline de facturación: ResourceMonitor recopila cada 5 minutos → UsageAggregator agrega cada hora → BillingEngine cobra a diario; si el saldo es insuficiente, el recurso se suspende.

---

## 15. Comisiones de afiliados (Affiliate)

### Lado del usuario

```
GET /api/v1/affiliate/summary
  → Resumen de comisiones (acumulado/pendiente de liquidar/retirable, número de enlaces, tasa de conversión)

POST /api/v1/affiliate/links
   Cuerpo: { source? }
  → Generar enlace de recomendación (?ref=CODE)

GET /api/v1/affiliate/earnings
   Parámetros: status, page
  → Detalle de comisiones (pedido de origen, porcentaje, estado: pending/approved/paid)

POST /api/v1/affiliate/payout
   Cuerpo: { amount, method }
  → Solicitar retiro
```

### Lado de administración

```
GET /admin/api/v1/affiliate/plans                → Lista de planes de comisión
POST /admin/api/v1/affiliate/plans               → Crear plan de comisión
GET /admin/api/v1/affiliate/earnings             → Todas las comisiones
POST /admin/api/v1/affiliate/earnings/{id}/approve → Revisar comisión
GET /admin/api/v1/affiliate/payouts              → Lista de solicitudes de retiro
POST /admin/api/v1/affiliate/payouts/{id}/approve → Revisar/pagar retiro
```

---

## 16. GraphQL

```
POST /graphql
  → Consultas públicas (datos de solo lectura: productos, dominios, ayuda, etc.)
   Límites: profundidad de consulta 5 niveles, complejidad 100

POST /api/v1/graphql                          🔒 requiere autenticación
  → Consultas completas (incluye datos del usuario)
```

**Las operaciones sensibles siguen siendo solo REST:** pagos, retiros, reembolsos y revisión de KYC no pasan por GraphQL.

---

## 17. Valoraciones de proveedores y reseñas de productos

### Público

```
GET /api/v1/regions
  → Lista de regiones disponibles (incluye moneda/zona horaria)

GET /api/v1/suppliers/{supplierId}/ratings
  → Lista de valoraciones del proveedor (cuatro dimensiones: calidad/soporte/velocidad de entrega/relación calidad-precio, solo las aprobadas)
```

### Lado del usuario (requiere autenticación)

```
POST /api/v1/products/{productId}/reviews
   Cuerpo: { rating, content, images? }
  → Enviar reseña del producto (una por pedido, se muestra tras la revisión)

POST /api/v1/supplier/ratings
   Cuerpo: { supplier_id, quality, support, delivery_speed, value, comment? }
  → Enviar valoración del proveedor (una por pedido)

GET /api/v1/supplier/ratings/me
  → Mis valoraciones
```

### Lado de administración

```
GET /admin/api/v1/suppliers/{id}/ratings          → Todas las valoraciones (incluye pending)
POST /admin/api/v1/suppliers/ratings/{id}/approve → Aprobar
POST /admin/api/v1/suppliers/ratings/{id}/hide    → Ocultar
```

---

## 18. Webhook de pagos

```
POST /api/v1/payments/webhook/stripe
   Encabezados: Stripe-Signature: ...
  → Callback de Stripe (pago exitoso/reembolso/disputa); si la verificación de firma falla devuelve 400
```

---

## 19. Eventos WebSocket

**Conexión:** `ws://host:8282` (en despliegue con docker, el WS pasa por el proxy inverso de nginx; la dirección de conexión es `ws://host/ws/`, y el 8282 solo se expone dentro del contenedor)

La autenticación se hace con el primer mensaje tras la conexión (el token no va en la URL ni en los logs de acceso): tras establecer la conexión hay que enviar primero el mensaje `auth`; si no se autentica en 30 segundos, se desconecta; si la autenticación falla, devuelve `error` y desconecta.

### Cliente → Servidor

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### Servidor → Cliente

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### Eventos push

| Evento | Datos | Cuándo se dispara |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | Pago exitoso |
| `resource.provisioned` | `{resource_id, type, ip_address}` | Entrega del recurso completada |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | El recurso está a punto de vencer |
| `ticket.updated` | `{ticket_id, title, status}` | Cambio de estado del ticket |
| `notification.new` | `{notification_id, title, body}` | Nueva notificación |

---

## 20. Referencia de códigos de error

| code | Descripción |
|------|------|
| 400 | Error de parámetros / versión de API no soportada / plataforma de cliente no soportada |
| 401 | No autenticado / token caducado / API Key no válida / huella de dispositivo no coincide (Device mismatch) |
| 403 | Sin permisos / no es rol de proveedor / bloqueado por WAF / fallo de confirmación de contraseña |
| 404 | El recurso no existe (firstOrFail/findOrFail sin coincidencia se mapea uniformemente a 404) |
| 413 | Cuerpo de solicitud superior a 10MB |
| 414 | URL superior a 2KB |
| 415 | Content-Type fuera de la lista blanca (solo se permiten application/json, multipart/form-data, x-www-form-urlencoded) |
| 422 | Fallo de validación de parámetros (correo ya registrado / stock insuficiente / saldo retirable insuficiente / solicitud ya enviada) |
| 429 | Límite de frecuencia de solicitudes superado |
| 500 | Error del servidor |

### Mensajes 422 habituales

| Mensaje | Endpoint |
|------|------|
| `Email or phone required` | /api/v1/auth/register |
| `Email already registered` | /api/v1/auth/register |
| `Invalid credentials` | /api/v1/auth/login |
| `Account temporarily locked` | /api/v1/auth/login |
| `You already have a supplier application` | /api/v1/supplier/apply |
| `Insufficient withdrawable balance` | /api/v1/supplier/withdraw |
| `Product already assigned to this supplier` | /api/v1/supplier/products |
| `Invalid or revoked API key` | /api/v1/supplier/external/* |
| `Captcha verification failed` | /api/v1/auth/login, /api/v1/auth/register |
| `Email already verified` | /api/v1/user/resend-verify-email |
| `Password too short` | /api/v1/auth/register |
| `Unknown feature: xxx` | /admin/api/v1/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/v1/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/v1/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/v1/orders/{id}/refund |
