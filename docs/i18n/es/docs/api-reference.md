# Documentación de la API de CloudPlatform

## Resumen

**URL base:** `https://api.example.com`

**Control de versiones:** se especifica mediante el encabezado HTTP `X-Api-Version: v1`. Si falta, el valor por defecto es `v1`; las versiones no soportadas devuelven `400`. La versión no va en la ruta URL.

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
| Público | Cadena de middlewares global | `/health`, `/api/*` |
| `/health` (interno) | Global + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/auth` | Global + Encryption | `/api/auth/*` |
| `/api` (usuario) | Global + Encryption + Auth | `/api/user/*`, `/api/cart`, `/api/orders` |
| `/api` (sensible) | Global + Encryption + Auth + Confirmation | `/api/orders/{id}/pay` |
| `/api/supplier/external` | Version + SupplierApiKey | API externa de proveedores |
| `/admin/api` | Global + Encryption + Auth + AdminRole | API del panel de administración |
| `/admin/api` (sensible) | Global + Encryption + Auth + AdminRole + Confirmation | Operaciones administrativas sensibles |

---

## 1. Endpoints públicos

### Comprobación de salud

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### Estado del servicio

```
GET /api/status
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
GET /api/products
   Parámetros: category_id, region_id, keyword, supplier_id, page (por defecto 1), page_size (por defecto 20, máximo 50)
  → Lista paginada de productos (incluye category, skus.regionPrices)

GET /api/products/search
   Parámetros: q (obligatorio), page
  → Búsqueda de texto completo con Elasticsearch

GET /api/products/{id}
  → Detalle del producto (incluye category, skus, images, reviews)

GET /api/products/{productId}/reviews
  → Lista de reseñas + avg_rating + total + distribution
   Enumeración de estados: pending(pendiente de revisión)/approved(aprobada)/rejected(rechazada), solo se devuelven las aprobadas
```

### Dominios

```
GET /api/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/domain/tlds
  → Lista de TLD disponibles (caché en Redis 1h)
```

### Centro de ayuda

```
GET /api/help
   Parámetros: category, page
   Encabezados: Accept-Language (en-US / zh-CN)
  → Artículos de ayuda paginados

GET /api/help/categories
  → Lista de categorías de artículos

GET /api/help/{slug}
  → Detalle de un artículo
```

---

## 2. Endpoints de autenticación

### CAPTCHA

```
POST /api/captcha/create
   Encabezados: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### Registro

```
POST /api/auth/register
   Encabezados: X-Encrypted: 1
   Cuerpo (cifrado): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Límite de frecuencia: 3 req/min
```

- `deviceFingerprint` (opcional): registra la huella del dispositivo al registrarse y se valida al iniciar sesión/renovar; si no se envía, se omite el enlace de huella
- email/phone se cifran con Encryptable determinista (ECB, consulta por igualdad sobre el cifrado) antes de almacenarse; tanto la validación de unicidad como las consultas de inicio de sesión se realizan sobre el cifrado

### Inicio de sesión

```
POST /api/auth/login
   Encabezados: X-Encrypted: 1
   Cuerpo (cifrado): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Límite de frecuencia: 5 req/min, 5 fallos bloquean 15min
```

- `login` se consulta por igualdad sobre el cifrado (cifrado determinista Encryptable); las consultas en claro no alcanzan las columnas cifradas

### Renovación de token

```
POST /api/auth/refresh
   Encabezados: X-Encrypted: 1
   Cuerpo (cifrado): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- Si `deviceFingerprint` no coincide con el registrado → 401 `Device mismatch`; el token de refresco se consulta por hash del cifrado

### OAuth

Proveedores soportados: google, apple, facebook, x, microsoft, linkedin, github
(se habilitan según la configuración de `{PROVIDER}_OAUTH_CLIENT_ID` en `.env`)

```
GET /api/auth/{provider}            → { url }        # Redirige a la página de autorización (PKCE/nonce contra replay)
GET /api/auth/{provider}/callback?code=xxx&state=yyy
POST /api/auth/{provider}/callback  Cuerpo: { code, state }
```

- Apple/Microsoft devuelven id_token; el servidor verifica la firma con JWKS y comprueba iss/aud/exp/nonce
- Todos los proveedores exigen `email_verified=true` para permitir el inicio de sesión; si no, 422
- Si `state` falta o no coincide → 422 (protección CSRF, caduca a los 5 minutos)
- Límite de frecuencia del flujo OAuth: 10 veces por 60 segundos (redirect + callback)

### Restablecimiento de contraseña

```
POST /api/auth/forgot-password
   Cuerpo: { email }
  → Envía un correo con el código de verificación

POST /api/auth/reset-password
   Cuerpo: { email, code, password }
  → Restablecimiento exitoso
  → 5 errores acumulados → 429 limitado durante 10 minutos
```

### Verificación de correo

```
GET /api/auth/verify-email?token=xxx
  → Verificación exitosa
```

### Verificación por SMS

```
POST /api/auth/send-sms
   Cuerpo: { phone }
  → Envía el código de verificación por SMS (enfriamiento de 60s)
```

### Verificación en dos pasos TOTP

```
POST /api/user/totp/setup        → { secret, qr_url }        # No persistido; debe confirmarse con verify en 10 minutos
POST /api/user/totp/verify       Cuerpo: { code } → { verified: true }   # En la primera activación devuelve mensaje de activación exitosa
POST /api/user/totp/disable      Cuerpo: { password }             # Requiere confirmación de contraseña; si no, 403
GET /api/user/totp/recovery-codes → { recovery_codes }        # Genera 8 códigos de un solo uso cada vez; requiere contraseña; si no, 403
POST /api/auth/login/recovery    Cuerpo: { login, password, recovery_code }
```

- Tras activar TOTP, el inicio de sesión debe incluir `totp_code`; si no, 401
- 5 errores consecutivos de TOTP → el usuario queda bloqueado 15 minutos (login_lock)

---

## 3. Endpoints de usuario (requieren autenticación)

### Perfil

```
GET /api/user/profile
PUT /api/user/profile
   Cuerpo: { nickname?, avatar?, country?, language?, timezone? }
```

### Verificación de identidad KYC

```
POST /api/user/kyc
   Cuerpo: { id_type, id_number, real_name, front_image, back_image }
```

### Saldo

```
GET /api/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/user/balance/transactions
   Parámetros: page
  → Historial de movimientos de saldo
```

### Gestión de direcciones

```
GET /api/user/addresses
POST /api/user/addresses
   Cuerpo: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/user/addresses/{id}
DELETE /api/user/addresses/{id}
```

### Gestión de sesiones

```
GET /api/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/user/sessions/{id}
  → Revoca la sesión indicada

DELETE /api/user/account
   Cuerpo: { confirm_password }
  → Eliminación de cuenta según RGPD
```

### Notificaciones

```
GET /api/user/notifications
   Parámetros: page
  → Lista paginada de notificaciones

POST /api/user/notifications/{id}/read
  → Marcar como leída

GET /api/user/notification-prefs
PUT /api/user/notification-prefs
   Cuerpo: { email: {order_paid: true, ...}, push: {...} }
```

### Correo

```
POST /api/user/resend-verify-email
  → Reenviar el correo de verificación
```

### Subida de archivos

```
POST /api/upload
   Cuerpo: multipart/form-data { file, type: avatar/kyc/attach }
   Límites: avatar 2MB, kyc 5MB, attach 10MB
   Permitidos: jpg, jpeg, png, gif, pdf
   Nota: validación de lista blanca de tipos + detección de contenido con finfo (extensión y MIME no coinciden → 422)
```

---

## 4. Carrito y pedidos

### Carrito

```
POST /api/cart
   Cuerpo: { sku_id, region_id, quantity, cycle }
GET /api/cart
DELETE /api/cart/{id}
PUT /api/cart/{id}
   Cuerpo: { quantity }
```

> Convención de campos de importe (decidido en D4/P4.2): todos los importes son string con 4 decimales (p. ej. "9.9900"); prohibido number/float —
> es coherente con la salida cruda de las columnas DECIMAL de MySQL a través de PDO; la precisión la soporta la propia cadena de 4dp. Aplica a todos los endpoints de pedidos/saldo/reportes.

### Pedidos

```
POST /api/orders
  → Crea el pedido desde el carrito
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/orders
   Parámetros: page, status (pending/paid/provisioning/completed/refunded; los valores no válidos devuelven 400)
  → Mi lista de pedidos

GET /api/orders/{id}
  → Detalle del pedido (incluye items, timeline)

GET /api/orders/{id}/payment-methods
  → Canales de pago disponibles + importe a pagar en cada canal

POST /api/orders/{id}/pay    🔒 confirmación de contraseña
   Cuerpo: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### Cupones

```
POST /api/coupons/validate
   Cuerpo: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp (p. ej. "2.0000")

422: no válido/caducado/no cumple las condiciones de uso
```

### Facturas

```
GET /api/invoices
   Parámetros: page
GET /api/invoices/{id}
GET /api/invoices/{id}/download
  → Descarga PDF
```

---

## 5. Gestión de recursos

```
GET /api/resources
   Parámetros: page, status
  → Mi lista de recursos

GET /api/resources/{id}
  → Detalle del recurso

GET /api/resources/{id}/status
  → Estado actual del recurso + métricas

GET /api/resources/{id}/console
  → URL de VNC/consola

POST /api/resources/batch
   Cuerpo: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. Gestión de DNS

```
GET /api/dns/{domain}
  → Lista de registros DNS

POST /api/dns/{domain}/records
   Cuerpo: { type, name, value, ttl?, priority? }

DELETE /api/dns/{domain}/records/{id}   🔒 confirmación de contraseña
```

---

## 7. Tickets

```
POST /api/tickets
   Cuerpo: { resource_id?, category, priority?, title, content }

GET /api/tickets
   Parámetros: page, status

GET /api/tickets/{id}

POST /api/tickets/{id}/reply
   Cuerpo: { content }
```

---

## 8. Proveedores (API interna)

```
POST /api/supplier/apply
   Cuerpo: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/supplier/settlements
  → Lista de liquidaciones

POST /api/supplier/withdraw    🔒 confirmación de contraseña
   Cuerpo: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/supplier/products
POST /api/supplier/products
   Cuerpo: { product_id, commission_rate }
DELETE /api/supplier/products/{id}
```

---

## 9. API externa de proveedores

**Autenticación:** `Authorization: Bearer sk_xxx...` (verificación de firma SHA256)

**Límite de frecuencia:** 120 req/min (retiros 10 req/min)

```
GET /api/supplier/external/orders
   Parámetros: page, page_size, status, from, to

GET /api/supplier/external/orders/{id}
  → Detalle del pedido (solo los asociados a este proveedor)

GET /api/supplier/external/resources
   Parámetros: page, status, type

GET /api/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/supplier/external/settlements
   Parámetros: page, status

GET /api/supplier/external/settlements/{id}

POST /api/supplier/external/withdraw
   Cuerpo: { amount, account_info: { method, ... } }

GET /api/supplier/external/withdraws
   Parámetros: page
```

---

## 10. API del panel de administración

**Autenticación:** JWT Bearer Token + rol de administrador

### Panel

```
GET /admin/api/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### Gestión de usuarios

```
GET /admin/api/users               Parámetros: page, status, keyword
GET /admin/api/users/export       → Descarga Excel
GET /admin/api/users/{id}
PUT /admin/api/users/{id}/status   Cuerpo: { status }
```

### Revisión de KYC

```
GET /admin/api/kyc                 Parámetros: page, status

POST /admin/api/kyc/{id}/approve   🔒 confirmación de contraseña
   Cuerpo: { confirm_password }

POST /admin/api/kyc/{id}/reject    🔒 confirmación de contraseña
   Cuerpo: { confirm_password, reason }
```

### Gestión de productos

```
POST /admin/api/products
PUT /admin/api/products/{id}
DELETE /admin/api/products/{id}         🔒 confirmación de contraseña
POST /admin/api/products/{productId}/skus
PUT /admin/api/skus/{id}
POST /admin/api/skus/{skuId}/region-price
GET /admin/api/products/export         → Descarga CSV
POST /admin/api/products/import        → Subida CSV upsert
```

### Gestión de pedidos

```
GET /admin/api/orders               Parámetros: page, status, keyword
GET /admin/api/orders/export       → Descarga Excel
GET /admin/api/orders/{id}

POST /admin/api/orders/{id}/refund  🔒 confirmación de contraseña
   Cuerpo: { confirm_password, amount?, reason }
```

### Gestión de pagos

```
GET /admin/api/payments/channels
PUT /admin/api/payments/channels/{id}
GET /admin/api/payments/transactions   Parámetros: page, channel, status
GET /admin/api/payments/reconcile      Parámetros: date; records.status: verified/mismatch/unverified
POST /admin/api/payments/reconcile/run   Parámetros: date; dispara la conciliación diaria
```

### Recursos y aprovisionamiento

```
GET /admin/api/provisioning/tasks               Parámetros: page, status
POST /admin/api/provisioning/tasks/{id}/retry
POST /admin/api/provisioning/resources/{id}/upgrade
   Cuerpo: { cpu?, ram?, disk? }
POST /admin/api/provisioning/resources/{id}/destroy   🔒 confirmación de contraseña
GET /admin/api/provisioning/hosts
```

### Gestión de proveedores

```
GET /admin/api/suppliers                  Parámetros: page, status
GET /admin/api/suppliers/export          → Descarga Excel

POST /admin/api/suppliers/{id}/approve    🔒 confirmación de contraseña
POST /admin/api/suppliers/{id}/settle     🔒 confirmación de contraseña
   Cuerpo: { period_start, period_end, confirm_password }

POST /admin/api/suppliers/withdraws/{id}/approve  🔒 confirmación de contraseña
```

### API Key de proveedores

```
GET /admin/api/suppliers/{id}/api-keys
POST /admin/api/suppliers/{id}/api-keys
   Cuerpo: { name }
  ← { api_key: "sk_xxx...", prefix } (se muestra solo una vez)

DELETE /admin/api/suppliers/api-keys/{id}
```

### Gestión de tickets

```
GET /admin/api/tickets                   Parámetros: page, status, priority, assigned_to
POST /admin/api/tickets/{id}/assign      Cuerpo: { user_id }
POST /admin/api/tickets/{id}/close
```

### Gestión de dominios

```
GET /admin/api/domains/tlds
POST /admin/api/domains/tlds
   Cuerpo: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/domains/tlds/{id}
DELETE /admin/api/domains/tlds/{id}
GET /admin/api/domains/zones              Parámetros: page
GET /admin/api/domains/transfers          Parámetros: page
POST /admin/api/domains/transfers/{id}/approve
```

### Gestión de notificaciones

```
GET /admin/api/notifications/templates
PUT /admin/api/notifications/templates/{id}
   Cuerpo: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/notifications/log          Parámetros: page
```

### Cupones

```
GET /admin/api/coupons
POST /admin/api/coupons
   Cuerpo: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/coupons/{id}
```

### Artículos de ayuda

```
GET /admin/api/help
POST /admin/api/help
   Cuerpo: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/help/{id}
DELETE /admin/api/help/{id}              → Eliminación blanda (status=archived)
```

### API de proveedores de nube

```
GET /admin/api/providers
POST /admin/api/providers
   Cuerpo: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/providers/{id}
DELETE /admin/api/providers/{id}         → Deshabilitar (status=disabled)
```

### Gestión de Webhooks

```
GET /admin/api/webhooks
POST /admin/api/webhooks
   Cuerpo: { url }
DELETE /admin/api/webhooks               Cuerpo: { id }
POST /admin/api/webhooks/test            Cuerpo: { url }
```

### Reportes

```
GET /admin/api/reports/revenue            Parámetros: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp (coherente con SUM(DECIMAL) y el agregado con bcmath)
GET /admin/api/reports/supplier           Parámetros: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/reports/region             Parámetros: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### Monitorización

```
GET /admin/api/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### Registros de auditoría

```
GET /admin/api/audit-logs                 Parámetros: page, user_id, action, from, to
  → Registros de auditoría paginados (incluye client_platform)
```

### Feature Flags

```
GET /admin/api/features
  → [{ name, enabled, default, source }]

PUT /admin/api/features/{name}
   Cuerpo: { action: enable/disable/toggle/reset }
```

### Configuración del sistema

```
PUT /admin/api/system/config              🔒 confirmación de contraseña
```

### Importación y exportación de productos

```
GET /admin/api/products/export           → Descarga CSV
POST /admin/api/products/import          → Subida CSV upsert
```

### Exportaciones de proveedores y usuarios

```
GET /admin/api/suppliers/export          → Descarga Excel
GET /admin/api/users/export              → Descarga Excel
GET /admin/api/orders/export             → Descarga Excel
```

---

## 11. Certificados SSL

### Lado del usuario

```
GET /api/ssl/plans
  → Lista de planes SSL (DV/OV/EV, precios con register/renew/transfer)

GET /api/ssl-certs
  → Mi lista de certificados (incluye status: pending/active/expired/revoked)

GET /api/ssl-certs/{id}
  → Detalle del certificado (dominio, entidad emisora, validez, estado de renovación)

GET /api/ssl-certs/{id}/download
  → Descargar archivos del certificado (cadena de certificados + clave privada)

POST /api/ssl-certs/{id}/auto-renew
   Cuerpo: { auto_renew: true/false }
  → Activar/desactivar la renovación automática
```

### Lado de administración

```
GET /admin/api/ssl/plans              → Lista de planes
POST /admin/api/ssl/plans             → Crear plan
PUT /admin/api/ssl/plans/{id}         → Actualizar plan
DELETE /admin/api/ssl/plans/{id}      → Eliminar plan
GET /admin/api/ssl/certs              → Todos los certificados
POST /admin/api/ssl/certs/{id}/revoke → Revocar certificado
```

---

## 12. Almacenamiento de objetos

Almacenamiento de objetos compatible con S3, con subida/descarga mediante URLs prefirmadas; las claves no salen del servidor.

```
GET /api/storage/buckets
  → Mi lista de buckets (uso, estado)

GET /api/storage/buckets/{id}
  → Detalle del bucket

POST /api/storage/buckets/{id}/presign-upload
   Cuerpo: { filename, content_type, size }
  → { upload_url, object_key } URL de subida prefirmada (con caducidad)

POST /api/storage/buckets/{id}/presign-download
   Cuerpo: { object_key }
  → URL de descarga prefirmada (con caducidad)

GET /api/storage/buckets/{id}/credentials
  → Credenciales de acceso temporales (válidas por poco tiempo, para subida directa con SDK)
```

---

## 13. Aceleración CDN

### Lado del usuario

```
GET /api/cdn/domains
  → Mi lista de dominios CDN (origen, estado, plan)

GET /api/cdn/domains/{id}
  → Detalle del dominio CDN

POST /api/cdn/domains/{id}/purge
  → Purgar caché (todo el sitio o lista de URLs concreta)

GET /api/cdn/domains/{id}/stats
   Parámetros: range (day/week/month)
  → Estadísticas de tráfico / peticiones / tasa de aciertos
```

### Lado de administración

```
GET /admin/api/cdn/domains            → Todos los dominios CDN
PUT /admin/api/cdn/domains/{id}       → Actualizar plan/configuración del dominio
```

---

## 14. Facturación por uso

```
GET /admin/api/billing/rates          → Lista de tarifas (por tipo de recurso/especificación)
POST /admin/api/billing/rates         → Crear tarifa
PUT /admin/api/billing/rates/{id}     → Actualizar tarifa
DELETE /admin/api/billing/rates/{id}  → Eliminar tarifa
GET /admin/api/billing/usage          → Resumen de uso (agregado por usuario/recurso)
```

Pipeline de facturación: ResourceMonitor recopila cada 5 minutos → UsageAggregator agrega cada hora → BillingEngine cobra a diario; si el saldo es insuficiente, el recurso se suspende.

---

## 15. Comisiones de afiliados (Affiliate)

### Lado del usuario

```
GET /api/affiliate/summary
  → Resumen de comisiones (acumulado/pendiente de liquidar/retirable, número de enlaces, tasa de conversión)

POST /api/affiliate/links
   Cuerpo: { source? }
  → Generar enlace de recomendación (?ref=CODE)

GET /api/affiliate/earnings
   Parámetros: status, page
  → Detalle de comisiones (pedido de origen, porcentaje, estado: pending/approved/paid)

POST /api/affiliate/payout
   Cuerpo: { amount, method }
  → Solicitar retiro
```

### Lado de administración

```
GET /admin/api/affiliate/plans                → Lista de planes de comisión
POST /admin/api/affiliate/plans               → Crear plan de comisión
GET /admin/api/affiliate/earnings             → Todas las comisiones
POST /admin/api/affiliate/earnings/{id}/approve → Revisar comisión
GET /admin/api/affiliate/payouts              → Lista de solicitudes de retiro
POST /admin/api/affiliate/payouts/{id}/approve → Revisar/pagar retiro
```

---

## 16. GraphQL

```
POST /graphql
  → Consultas públicas (datos de solo lectura: productos, dominios, ayuda, etc.)
   Límites: profundidad de consulta 5 niveles, complejidad 100

POST /api/graphql                          🔒 requiere autenticación
  → Consultas completas (incluye datos del usuario)
```

**Las operaciones sensibles siguen siendo solo REST:** pagos, retiros, reembolsos y revisión de KYC no pasan por GraphQL.

---

## 17. Valoraciones de proveedores y reseñas de productos

### Público

```
GET /api/regions
  → Lista de regiones disponibles (incluye moneda/zona horaria)

GET /api/suppliers/{supplierId}/ratings
  → Lista de valoraciones del proveedor (cuatro dimensiones: calidad/soporte/velocidad de entrega/relación calidad-precio, solo las aprobadas)
```

### Lado del usuario (requiere autenticación)

```
POST /api/products/{productId}/reviews
   Cuerpo: { rating, content, images? }
  → Enviar reseña del producto (una por pedido, se muestra tras la revisión)

POST /api/supplier/ratings
   Cuerpo: { supplier_id, quality, support, delivery_speed, value, comment? }
  → Enviar valoración del proveedor (una por pedido)

GET /api/supplier/ratings/me
  → Mis valoraciones
```

### Lado de administración

```
GET /admin/api/suppliers/{id}/ratings          → Todas las valoraciones (incluye pending)
POST /admin/api/suppliers/ratings/{id}/approve → Aprobar
POST /admin/api/suppliers/ratings/{id}/hide    → Ocultar
```

---

## 18. Webhook de pagos

```
POST /api/payments/webhook/stripe
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
| `Email or phone required` | /api/auth/register |
| `Email already registered` | /api/auth/register |
| `Invalid credentials` | /api/auth/login |
| `Account temporarily locked` | /api/auth/login |
| `You already have a supplier application` | /api/supplier/apply |
| `Insufficient withdrawable balance` | /api/supplier/withdraw |
| `Product already assigned to this supplier` | /api/supplier/products |
| `Invalid or revoked API key` | /api/supplier/external/* |
| `Captcha verification failed` | /api/auth/login, /api/auth/register |
| `Email already verified` | /api/user/resend-verify-email |
| `Password too short` | /api/auth/register |
| `Unknown feature: xxx` | /admin/api/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/orders/{id}/refund |
