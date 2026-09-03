# Resumen de la API

> Referencia completa de la API (200+ endpoints, ejemplos de solicitud/respuesta y códigos de error): [Documentación de la API](api-reference.md)
> Depuración en línea: [documentación de la API de service](http://localhost:8787/apidoc) · [documentación de la API de admin](http://localhost:8788/apidoc)

## Endpoints públicos

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/health` | Comprobación de salud |
| POST | `/api/v1/auth/register` | Registro (cuerpo cifrado con AES-256-GCM) |
| POST | `/api/v1/auth/login` | Inicio de sesión (cuerpo cifrado con AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | Renovar token (cuerpo cifrado con AES-256-GCM) |
| POST | `/api/v1/captcha/create` | Generar CAPTCHA de clic (obligatorio antes de iniciar sesión/registrarse) |
| GET | `/api/v1/products` | Listado de productos (filtrable por categoría/región/palabra clave) |
| GET | `/api/v1/products/{id}` | Detalle del producto (id es una cadena hashid) |
| GET | `/api/v1/regions` | Regiones disponibles |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Comprobación de disponibilidad de dominio |
| GET | `/api/v1/domain/tlds` | TLD disponibles |
| POST | `/api/v1/payments/webhook/stripe` | Webhook de Stripe (firma verificada, sin cifrado) |

## Endpoints autenticados (Bearer Token)

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | Obtener perfil |
| PUT | `/api/v1/user/profile` | Actualizar perfil |
| POST | `/api/v1/user/kyc` | Enviar KYC |
| GET | `/api/v1/user/balance` | Saldo de la cuenta |
| GET/POST | `/api/v1/cart` | Carrito de compras |
| POST/GET | `/api/v1/orders` | Pedidos |
| GET | `/api/v1/orders/{id}/payment-methods` | Métodos de pago disponibles |
| POST | `/api/v1/orders/{id}/pay` | Iniciar el pago |
| GET/POST | `/api/v1/resources` | Mis recursos |
| GET | `/api/v1/resources/{id}/status` | Estado del recurso |
| GET | `/api/v1/resources/{id}/console` | URL de la consola VNC |
| GET/POST | `/api/v1/tickets` | Tickets de soporte |
| POST | `/api/v1/tickets/{id}/reply` | Responder al ticket |
| GET/POST | `/api/v1/dns/{domain}` | Gestión de DNS |
| POST | `/api/v1/supplier/apply` | Solicitar ser proveedor |
| GET | `/api/v1/supplier/settlements` | Historial de liquidaciones |
| POST | `/api/v1/supplier/withdraw` | Solicitar retiro |

> **Nota:** La versión de la API va en la ruta URL (p. ej. `/api/v1/...`), validada de forma centralizada por `VersionMiddleware`. Los endpoints autenticados y de administrador se procesan mediante `EncryptionMiddleware`. Los clientes establecen el encabezado `X-Encrypted: 1` y envuelven el cuerpo como `{"payload": "<base64(AES-256-GCM)>"}`. Las respuestas también se cifran y se envuelven en un campo `payload`. Los IDs enteros de las respuestas de la API se convierten automáticamente en cadenas Hashid de 12 caracteres; las cadenas Hashid de las solicitudes se decodifican de vuelta a IDs enteros mediante `HashidRequestMiddleware`.

## Endpoints de administrador

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/admin/api/v1/dashboard` | Panel operativo |
| GET/PUT | `/admin/api/v1/users` | Gestión de usuarios |
| GET/POST | `/admin/api/v1/kyc` | Revisión de KYC |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Gestión de productos |
| POST | `/admin/api/v1/products/{productId}/skus` | Crear SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Fijar precio regional |
| GET/POST | `/admin/api/v1/orders` | Gestión de pedidos (incl. reembolsos) |
| GET | `/admin/api/v1/orders/export` | Exportar pedidos (.xlsx) |
| GET | `/admin/api/v1/users/export` | Exportar usuarios (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Exportar proveedores (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Canales / transacciones / conciliación |
| GET/POST | `/admin/api/v1/provisioning/*` | Tareas de entrega / gestión de servidores |
| GET/POST | `/admin/api/v1/suppliers/*` | Aprobación de proveedores / liquidación / retiros |
| GET/POST | `/admin/api/v1/tickets` | Asignación / cierre de tickets |
| GET | `/admin/api/v1/reports/*` | Reportes de ingresos / regiones / proveedores |
| GET | `/admin/api/v1/monitor/*` | Panel de monitorización / métricas de recursos |
| GET | `/admin/api/v1/audit-logs` | Registros de auditoría |
| PUT | `/admin/api/v1/system/config` | Actualización de configuración del sistema |
