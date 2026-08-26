# Documento de diseño de funciones de CloudPlatform

## 1. Autenticación y autorización de usuarios

### 1.1 Registro

```
POST /api/auth/register
  → Escaneo WAF
  → Límite de frecuencia 3 req/min
  → Validación de contraseña len≥8
  → Comprobación de unicidad de correo/teléfono
  → bcrypt(password, cost=12)
  → Snowflake::id() genera user_id
  → Encryptable::set() cifra los campos sensibles
  → Creación de User + UserProfile + UserBalance
  → NotificationDispatcher::send('email_verify') envía el correo de verificación
  → AuditLogger::record('user_registered')
  ← {access_token, refresh_token, expires_in, token_type}
```

**Flujo de datos:**

```
Client                    Middleware Chain           AuthService              DB
  │                           │                        │                     │
  │ POST /api/auth/register   │                        │                     │
  │──────────────────────────▶│ WAF→RateLimit→Encrypt  │                     │
  │                           │───────────────────────▶│                     │
  │                           │                        │ User::create() ────▶│
  │                           │                        │ UserProfile::create │
  │                           │                        │ UserBalance::create │
  │                           │                        │ RefreshToken::create│
  │                           │                        │ (client_platform)   │
  │                           │                        │ AuditLogger::record │
  │◀──────────────────────────│◀───────────────────────│                     │
  │ {access_token, refresh}   │                        │                     │
```

### 1.2 Inicio de sesión

```
POST /api/auth/login
  → Escaneo WAF
  → Límite de frecuencia 5 req/min
  → Verificación Captcha (CAPTCHA de clic, límite de 3 intentos)
  → Hash::check(password, user->password_hash)
  → 5 fallos → login_lock:{userId} Redis TTL 900s
  → Verificación TOTP (obligatoria si el usuario la tiene activada; totp_code es
      obligatorio; 5 errores acumulados → totp_fail:{userId} → login_lock TTL 900s)
  → Detección de IP nueva → alerta por correo
  → deviceFingerprint = sha256(UA + segmento de IP, prefijo para IPv6)
  → clientPlatform = encabezado X-Client-Platform
  → issueTokens(): Access(15min) + Refresh(30d)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth (Google / Apple)

```
GET /api/auth/google → Google OAuth → callback?code=xxx
  1. Verificar el ID Token de Google/Apple
  2. Buscar o crear usuario (coincidencia por email)
  3. Emitir token (incluye client_platform)
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 Verificación en dos pasos TOTP

```
1. POST /api/user/totp/setup
     → Genera secret + URL de QR (guardado temporal en Redis 10 minutos, sin persistir)
     ← {secret, qr_url, manual}
2. POST /api/user/totp/verify
     → Verifica el código TOTP (la primera vez activa el setup, después valida)
     ← {verified: true}
3. GET /api/user/totp/recovery-codes
     → Genera 8 códigos de recuperación de un solo uso (requiere contraseña)
     ← {recovery_codes: [8 códigos]}
4. Al iniciar sesión: introducir el código TOTP o usar un código de recuperación
     → POST /api/auth/login/recovery (login, password, recovery_code)
```

### 1.5 Gestión de sesiones

```
GET /api/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/user/account (eliminación RGPD)
  → Doble confirmación de contraseña
  → Eliminación blanda de User
  → Todos los RefreshToken revocados
```

---

## 2. Gestión de productos

### 2.1 Modelo de producto

```
Product (1) ────── (N) ProductSku ────── (N) ProductRegion
  │                    │                      │
  │ category_id        │ specs (JSON)         │ region_id
  │ supplier_id        │ cycle (monthly/yr)   │ price
  │ status             │                      │ original_price
  │ name (JSON i18n)   │                      │ currency
  │ description        │                      │ stock
  └────────────────────┴──────────────────────┘

Product (1) ────── (N) ProductImage
Product (1) ────── (N) ProductReview
Product (1) ────── (N) ProductAttribute
```

### 2.2 Lista de productos (con caché)

```
GET /api/products?category_id=1&region_id=2&keyword=vps&page=1

CacheService::remember('products:list:{hash}', TTL 5min)
  → Product::published()
    → with(category, skus.regionPrices)
    → Filtro por category_id/region_id/keyword/supplier_id
    → count + skip/take paginación
  ← Resultado paginado

Invalidación de caché:
  Cambios de producto/SKU/precio regional en Admin
  → CacheService::forget("products:detail:{$id}")
  → CacheService::forgetPattern('products:list:*')
```

### 2.3 Búsqueda de productos (Elasticsearch)

```
GET /api/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (segmentación china IK Analyzer)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 Reseñas de productos

```
GET /api/products/{id}/reviews
  → Reseñas aprobadas + valoración media + distribución de puntuaciones
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/products/{id}/reviews (requiere inicio de sesión)
  → rating (1-5) + content
  → status = pending (se muestra tras la revisión del administrador)
```

### 2.5 Importación y exportación masiva

```
GET /admin/api/products/export
  → Descarga CSV (producto + SKU + precio regional)

POST /admin/api/products/import
  → Subida CSV upsert
  ← {imported: N, errors: [...]}
```

---

## 3. Sistema de pedidos

### 3.1 Carrito

```
POST /api/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/cart           → Lista del carrito (incluye detalle de SKU + precio en tiempo real)
DELETE /api/cart/{id}   → removeFromCart
PUT /api/cart/{id}      → updateCartQuantity
```

### 3.2 Flujo de pedido

```
1. POST /api/orders                           Crear pedido
     → Validar stock, calcular precio, aplicar cupón
     ← {order_id, order_no, items, total}

2. POST /api/coupons/validate                 Aplicar cupón
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/orders/{id}/payment-methods       Obtener canales de pago disponibles
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/orders/{id}/pay                  Iniciar el pago
     → Doble confirmación de contraseña (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 Ciclo de vida del pedido

```
                    ┌─────────┐
                    │ pending  │ pendiente de pago
                    └────┬─────┘
                         │ pago exitoso
                    ┌────┴─────┐
                    │  paid    │ pagado
                    └────┬─────┘
                         │ evento OrderPaid
                         │ → ProvisioningService
                    ┌────┴─────┐
                    │ completed│ completado
                    └────┬─────┘
                         │ el usuario solicita reembolso
                    ┌────┴─────┐
                    │ refunded │ reembolsado
                    └──────────┘

Condiciones de reembolso: servidores dentro de 72h | dominios dentro de 5 días | IP no reembolsable | productos promocionales no reembolsables (otros tipos como disk sin límite de ventana; los tipos de categoría desconocidos pasan por defecto)
Flujo de reembolso: solicitud del usuario → creación de Ticket → revisión de atención al cliente → confirmación del admin → Provider.destroy() → Payment.refund()
```

---

## 4. Sistema de pagos

### 4.1 Enrutamiento multicanal

```
PaymentRouter::route(Order $order)
  → Filtrar canales disponibles (is_visible + visible_regions + min/max_amount)
  → Coincidir por currency
  → Calcular el importe real a pagar por canal (incluye comisión)
  → Ordenar por fee ascendente
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Pago con Stripe

```
Client (Flutter)          Server (webman)            Stripe API
─────────────────         ────────────────           ──────────
1. Seleccionar Stripe
   → POST /orders/{id}/pay
                          2. createPaymentIntent()
                              amount (cents)
                              currency
                              metadata{order_id, order_no}
                                                    3. paymentIntents.create
                                                       ← {id, client_secret}
                          4. Crear transaction
                             (status=pending)
                           ← client_secret
5. confirmCardPayment()
   (SDK de Stripe.js)
                                                    6. El usuario confirma el pago
                                                       ← payment_intent.succeeded
                          7. POST /payments/webhook/stripe
                             Webhook::constructEvent()
                             Verificación de firma stripe-signature
                             Comprobación de idempotencia transaction_no
                          8. transaction=success
                          9. Disparar el evento OrderPaid
                             → ProvisioningService
                             → push WebSocket
                             → notificaciones correo/SMS/Push
```

### 4.3 Conciliación

```
Cron: PaymentReconcile (diario a las 02:37)
  → Obtener los informes de liquidación de cada canal
  → Conciliar una a una con las transactions del sistema
  → Diferencia > $0.01 → alerta
```

---

## 5. Motor de aprovisionamiento de recursos

### 5.1 Arquitectura de plugins Provider

```php
interface ProviderInterface {
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}

ProviderFactory:
  (productType, provider) → instancia de Provider
  'server:proxmox'     → ProxmoxProvider
  'server:aws_ec2'     → AwsProvider (extensible)
  'server:aliyun_ecs'  → AliyunProvider (extensible)
  'domain:namecheap'   → DomainProvider (extensible)
```

### 5.2 Cadena de aprovisionamiento completa

```
Disparado por el evento OrderPaid
  │
  ▼
ProvisioningService::handleOrderPaid()
  │
  ├→ Crear un ProvisionTask por cada OrderItem
  │   status=pending, next_retry_at=now
  │
  ▼
ProvisionWorker (consumidor de Redis Queue)
  │
  ├→ ProviderFactory::create(task)
  │     └→ ProxmoxProvider
  │
  ├→ HostSelector::select(regionId, specs)
  │     Ordena por margen de cpu/ram/disk + equilibrio de carga
  │
  ├→ IpPool::allocate(hostId)
  │
  ├→ ProxmoxApi::post('/nodes/{node}/qemu')
  │     Crear VM (vmid, name, cores, memory, net, ipconfig)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/config')
  │     Montar disco de sistema (scsi0: pool:sizeG)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/status/start')
  │     Arrancar la VM
  │
  ├→ Crear registros Resource + Disk + IpAllocation
  │
  ├→ Actualizar los recursos asignados de host_machine
  │
  └→ Order::status = completed
       → push WebSocket 'resource.provisioned'
       → NotificationDispatcher::send('resource_ready')

Estrategia de reintento:
  1min → 5min → 15min → 1h → 6h → 24h (tras 6 intentos se marca fallido + alerta)
```

> **Evolución del canal de aprovisionamiento:** el kvm-server en Rust (`infrastructure/kvm-server`, workspace e-cat) ya está integrado —
> gRPC `ping/create_vm/vm_status` (:50051) + registro y descubrimiento etcd, con KvmClient /
> RegistryProcess en el lado PHP (`service/app/grpc/`) conectados. La capa de driver es actualmente un **driver simulado** (el driver real
> libvirt es Phase 2); la cadena de aprovisionamiento sigue pasando temporalmente por ProxmoxProvider en conexión directa; cuando kvm-server
> asuma la creación de VM, el flujo de esta sección no cambia, solo cambia el canal.

### 5.3 Resumen de operaciones Proxmox

| Operación | API | Operación en caliente |
|------|-----|--------|
| Crear VM | POST /nodes/{node}/qemu | — |
| Aumentar CPU | PUT /qemu/{vmid}/config cores | En línea |
| Aumentar memoria | PUT /qemu/{vmid}/config memory | En línea |
| Ampliar disco de sistema | PUT /qemu/{vmid}/resize disk | En línea |
| Crear disco de datos | POST /qemu/{vmid}/config scsi{n} | En línea |
| Crear IP independiente | POST /qemu/{vmid}/config net{n} | En línea |
| Destruir VM | POST stop → DELETE qemu | — |
| Consultar estado | GET /qemu/{vmid}/status/current | — |

---

## 6. Sistema de proveedores

### 6.1 Proceso de registro

```
POST /api/supplier/apply (requiere inicio de sesión de usuario)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → Revisión del administrador

Aprobación del administrador:
  POST /admin/api/suppliers/{id}/approve (confirmación de contraseña)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → El usuario obtiene permisos de proveedor

Publicar productos:
  POST /api/supplier/products
    → {product_id, commission_rate}
    → Asocia los productos del proveedor

Liquidación:
  Cron: SupplierSettlement (lunes a las 04:17)
    → Cuenta los pedidos completados del periodo
    → total_sales - commission = payable
    → Crea SupplierSettlement

Retiro:
  POST /api/supplier/withdraw (confirmación de contraseña)
    → Comprueba el saldo retirable
    → Crea SupplierWithdraw (status=pending)
    → Aprobación y pago del administrador
```

### 6.2 API externa

```
POST /admin/api/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → Almacena hash('sha256', rawKey)
  ← {api_key: "sk_xxx..."} (se muestra solo una vez)

Uso del proveedor:
  GET /api/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → SupplierApiKeyMiddleware verifica la firma
    → Filtra los datos por supplierId
```

---

## 7. Dominios y DNS

```
GET /api/domain/check/{domain}/{tld}    # Disponibilidad del dominio
GET /api/domain/tlds                     # Lista de TLD registrables (caché 1h)
GET /api/dns/{domain}                    # Lista de registros DNS
POST /api/dns/{domain}/records           # Añadir registro DNS
DELETE /api/dns/{domain}/records/{id}    # Eliminar registro DNS (confirmación de contraseña)
```

---

## 8. Sistema de tickets

```
POST /api/tickets                    # Crear ticket
GET /api/tickets                     # Mis tickets
GET /api/tickets/{id}                # Detalle del ticket
POST /api/tickets/{id}/reply         # Responder al ticket

Administrador:
  GET /admin/api/tickets              # Cola de tickets
  POST /admin/api/tickets/{id}/assign # Asignar agente de soporte
  POST /admin/api/tickets/{id}/close  # Cerrar ticket

Dirigido por eventos:
  Evento TicketCreated
    → AutoAssignListener: asigna al agente con menos carga
    → push WebSocket 'ticket.created'
```

---

## 9. Sistema de notificaciones

### 9.1 Distribución por cuatro canales

```
Disparo de evento → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → Escritura directa en la tabla notifications
```

### 9.2 Tipos de notificación

| Evento | Canal | Cuándo se dispara |
|------|------|---------|
| Verificación de registro | email | Tras registrarse con correo |
| Alerta de inicio de sesión anómalo | email | Inicio de sesión desde IP nueva |
| Pago de pedido exitoso | email/push | Pago completado |
| Aprovisionamiento de recurso completado | email/push/in_app | Provisioning completado |
| Aviso de vencimiento de recurso | email/push | 7d/3d/1d antes |
| Respuesta en ticket | email/push/in_app | Nuevo mensaje en el Ticket |
| Reembolso completado | email/push | Reembolso procesado |
| Vencimiento de certificado SSL | email | 30d antes |
| Vencimiento de dominio | email | 30d antes |

---

## 10. Monitorización y alertas

### 10.1 Monitorización de recursos

```
Cron: CollectMetrics (cada 5 minutos)
  → Recorre los recursos activos
  → ProxmoxApi::status() / API de Provider
  → Almacena las métricas en hash de Redis (TTL 1h)

Administrador:
  GET /admin/api/monitor/dashboard
    → Estadísticas generales + alertas recientes
  GET /admin/api/monitor/resources/{id}
    → Métricas en tiempo real (lectura desde Redis)
```

### 10.2 Reglas de alerta

| Regla | Gravedad | Condición de disparo |
|------|--------|---------|
| server_down | Grave | 3 pings consecutivos inalcanzables |
| cpu_high | Aviso | CPU > 90% durante 10min |
| disk_high | Aviso | Disco > 90% durante 5min |
| ssl_expiring | Aviso | Certificado SSL a menos de 30 días |
| domain_expiring | Aviso | Dominio a menos de 30 días |
| provision_failed | Grave | Fallos consecutivos de tareas de aprovisionamiento |

---

## 11. Tareas programadas

| Expresión cron | Tarea | Uso |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | Sincroniza tipos de cambio cada 4 horas |
| `37 2 * * *` | PaymentReconcile | Conciliación diaria |
| `17 4 * * 1` | SupplierSettlement | Liquidación de proveedores los lunes |
| `23 6 * * *` | ExpirationCheck | Comprobación de vencimientos + notificaciones |
| `43 7 * * *` | SslCertificateCheck | Comprobación de certificados SSL |
| `*/5 * * * *` | CollectMetrics | Recopilación de métricas de recursos |
| `*/30 * * * *` | CheckExpirations | Comprobación de vencimientos de recursos |

---

## 12. Internacionalización (i18n)

### 12.1 Flujo de solicitud

```
Cliente → Accept-Language: zh-CN
  → LocaleMiddleware (middleware global)
    → I18n::setLocale('zh-CN')
    → Carga i18n/zh-CN/messages.php
```

### 12.2 Métodos de traducción

**Texto estático:** `I18n::trans('auth.login_success')` → `登录成功`
**Campos JSON:** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**Sustitución de parámetros:** `I18n::trans('validation.required', ['field' => '邮箱'])` → `邮箱 不能为空`

### 12.3 Cobertura

120 entradas que cubren todos los módulos: autenticación/productos/pedidos/pagos/recursos/KYC/tickets/notificaciones/proveedores/Webhook/sistema, etc. Con fallback de idioma (idioma no soportado → en-US).

---

## 13. Feature Flags (interruptores de funciones)

```
config/features.php (valores por defecto)
  ↓ pueden sobrescribirse
.env variables de entorno FEATURE_*
  ↓ pueden sobrescribirse en runtime
Redis feature:{name} (TTL 1h, ajustable dinámicamente mediante API de administración)

API de administración:
  GET /admin/api/features → Lista todos los Flags con estado/origen
  PUT /admin/api/features/{name} → enable/disable/toggle/reset

Flags actuales:
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. Certificados SSL

El producto de certificados SSL soporta tres tipos: DV/OV/EV, con emisión y renovación automáticas mediante el protocolo ACME (Let's Encrypt) o API de CA externas (ZeroSSL/GoGetSSL).

**Flujo clave:**

    El usuario elige un plan SSL → hace el pedido y paga → se crea ProvisionTask
      → SslProvider::create() → CertificateAuthority::issue()
      → Validación ACME HTTP-01/DNS-01 → emisión del certificado
      → Comprobación diaria de expires_at → renovación automática 14 días antes del vencimiento
      → Vencimiento → status=expired → notificación al usuario

**Modelo de datos:** `ssl_plans` (planes), `resource_ssl_certs` (instancias de certificados)

## 15. Almacenamiento de objetos (S3)

Almacenamiento de objetos compatible con la API S3, con soporte de AWS S3 y MinIO autogestionado. Los usuarios suben/descargan archivos mediante URLs prefirmadas.

**Modelo de datos:** `resource_storage_buckets`

## 16. Aceleración CDN

El producto CDN soporta integración con Cloudflare: se pueden conectar servidores o buckets como origen al CDN, con soporte de purga de caché.

**Interfaces:** ProviderInterface + CachePurgeInterface (interfaz de capacidad opcional)

**Modelo de datos:** `resource_cdn`

## 17. Facturación por uso

Pipeline completo: recopilación de uso → agregación → facturación → cobro:

    ResourceMonitor recopila métricas cada 5 minutos → resource_metrics
      → UsageAggregator agrega cada hora → usage_events
      → BillingEngine descuenta el saldo a diario → saldo insuficiente → suspende el recurso
      → SuspendCheck comprueba cada 30 minutos → saldo recuperado → desuspende

**Modelos de datos:** `resource_metrics`, `usage_events`, `usage_rates`, `usage_invoice_items`

## 18. Valoración de proveedores

Los usuarios que han comprado pueden valorar al proveedor en cuatro dimensiones (calidad/soporte/velocidad de entrega/relación calidad-precio), una vez por pedido. El panel de administración puede revisar (approve/hide).

**Modelos de datos:** `supplier_ratings`, `suppliers.rating_avg/rating_count`

## 19. Distribución por recomendación

Los usuarios generan enlaces de recomendación (?ref=CODE); al registrarse un nuevo usuario, se vincula el affiliate_code y, tras el pago del pedido, la comisión se atribuye automáticamente.

**Dirigido por eventos:** OrderPaid → Affiliate OrderPaidListener → attributeOrder()

**Modelos de datos:** `affiliate_plans`, `affiliate_links`, `affiliate_earnings`, `affiliate_payouts`

## 20. API GraphQL

Proporciona dos endpoints: POST /graphql (consultas públicas) y POST /api/graphql (consultas autenticadas). Basado en webonyx/graphql-php, con profundidad de consulta limitada a 5 niveles y complejidad limitada a 100.

**Las operaciones sensibles siguen siendo solo REST:** pagos, retiros, reembolsos y revisión de KYC.

## 21. Observabilidad

El endpoint de métricas de Prometheus es un proceso independiente en 127.0.0.1:9100, no afectado por WAF/límite de frecuencia. MetricsMiddleware registra el conteo y la latencia de las solicitudes HTTP. Docker Compose incluye Prometheus + Grafana + reglas de alerta + paneles preconfigurados.

**Comprobaciones de salud:** /health (público), /health/live, /health/ready (comprobación de 5 dependencias), /health/deps (detalle de latencias)
