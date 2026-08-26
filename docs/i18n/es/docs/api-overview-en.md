# Resumen de la API

> Referencia completa de la API (200+ endpoints, ejemplos de solicitud/respuesta y códigos de error): [Documentación de la API](api-reference.md)
> Depuración en línea: [documentación de la API de service](http://localhost:8787/apidoc) · [documentación de la API de admin](http://localhost:8788/apidoc)

## Endpoints públicos

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/health` | Comprobación de salud |
| POST | `/api/auth/register` | Registro (cuerpo cifrado con AES-256-GCM) |
| POST | `/api/auth/login` | Inicio de sesión (cuerpo cifrado con AES-256-GCM) |
| POST | `/api/auth/refresh` | Renovar token (cuerpo cifrado con AES-256-GCM) |
| POST | `/api/captcha/create` | Generar CAPTCHA de clic (obligatorio antes de iniciar sesión/registrarse) |
| GET | `/api/products` | Listado de productos (filtrable por categoría/región/palabra clave) |
| GET | `/api/products/{id}` | Detalle del producto (id es una cadena hashid) |
| GET | `/api/regions` | Regiones disponibles |
| GET | `/api/domain/check/{domain}/{tld}` | Comprobación de disponibilidad de dominio |
| GET | `/api/domain/tlds` | TLD disponibles |
| POST | `/api/payments/webhook/stripe` | Webhook de Stripe (firma verificada, sin cifrado) |

## Endpoints autenticados (Bearer Token)

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/user/profile` | Obtener perfil |
| PUT | `/api/user/profile` | Actualizar perfil |
| POST | `/api/user/kyc` | Enviar KYC |
| GET | `/api/user/balance` | Saldo de la cuenta |
| GET/POST | `/api/cart` | Carrito de compras |
| POST/GET | `/api/orders` | Pedidos |
| GET | `/api/orders/{id}/payment-methods` | Métodos de pago disponibles |
| POST | `/api/orders/{id}/pay` | Iniciar el pago |
| GET/POST | `/api/resources` | Mis recursos |
| GET | `/api/resources/{id}/status` | Estado del recurso |
| GET | `/api/resources/{id}/console` | URL de la consola VNC |
| GET/POST | `/api/tickets` | Tickets de soporte |
| POST | `/api/tickets/{id}/reply` | Responder al ticket |
| GET/POST | `/api/dns/{domain}` | Gestión de DNS |
| POST | `/api/supplier/apply` | Solicitar ser proveedor |
| GET | `/api/supplier/settlements` | Historial de liquidaciones |
| POST | `/api/supplier/withdraw` | Solicitar retiro |

> **Nota:** Todas las solicitudes a la API deben incluir el encabezado `X-Api-Version: v1` (si falta, el valor por defecto es `v1`, validado por `VersionMiddleware`). Los endpoints autenticados y de administrador se procesan mediante `EncryptionMiddleware`. Los clientes establecen el encabezado `X-Encrypted: 1` y envuelven el cuerpo como `{"payload": "<base64(AES-256-GCM)>"}`. Las respuestas también se cifran y se envuelven en un campo `payload`. Los IDs enteros de las respuestas de la API se convierten automáticamente en cadenas Hashid de 12 caracteres; las cadenas Hashid de las solicitudes se decodifican de vuelta a IDs enteros mediante `HashidRequestMiddleware`.

## Endpoints de administrador

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/admin/api/dashboard` | Panel operativo |
| GET/PUT | `/admin/api/users` | Gestión de usuarios |
| GET/POST | `/admin/api/kyc` | Revisión de KYC |
| GET/POST/PUT/DELETE | `/admin/api/products` | Gestión de productos |
| POST | `/admin/api/products/{productId}/skus` | Crear SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | Fijar precio regional |
| GET/POST | `/admin/api/orders` | Gestión de pedidos (incl. reembolsos) |
| GET | `/admin/api/orders/export` | Exportar pedidos (.xlsx) |
| GET | `/admin/api/users/export` | Exportar usuarios (.xlsx) |
| GET | `/admin/api/suppliers/export` | Exportar proveedores (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Canales / transacciones / conciliación |
| GET/POST | `/admin/api/provisioning/*` | Tareas de entrega / gestión de servidores |
| GET/POST | `/admin/api/suppliers/*` | Aprobación de proveedores / liquidación / retiros |
| GET/POST | `/admin/api/tickets` | Asignación / cierre de tickets |
| GET | `/admin/api/reports/*` | Reportes de ingresos / regiones / proveedores |
| GET | `/admin/api/monitor/*` | Panel de monitorización / métricas de recursos |
| GET | `/admin/api/audit-logs` | Registros de auditoría |
| PUT | `/admin/api/system/config` | Actualización de configuración del sistema |
