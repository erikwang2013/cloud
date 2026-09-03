# Resumen de la API

> Referencia completa de la API (200+ endpoints, con ejemplos de solicitud/respuesta y códigos de error): [Documentación de la API](api-reference.md)
> Depuración en línea: [documentación de la API de service](http://localhost:8787/apidoc) · [documentación de la API de admin](http://localhost:8788/apidoc)

## Endpoints públicos

| Método | Ruta | Descripción |
|------|------|------|
| GET | `/health` | Comprobación de salud |
| POST | `/api/v1/auth/register` | Registro de usuario (el cuerpo de la solicitud debe ir cifrado con AES-256-GCM) |
| POST | `/api/v1/auth/login` | Inicio de sesión de usuario (el cuerpo de la solicitud debe ir cifrado con AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | Renovar Token (el cuerpo de la solicitud debe ir cifrado con AES-256-GCM) |
| POST | `/api/v1/captcha/create` | Generar CAPTCHA de clic (obtener antes de iniciar sesión/registrarse) |
| GET | `/api/v1/products` | Lista de productos (filtrable por categoría/región/palabra clave) |
| GET | `/api/v1/products/{id}` | Detalle del producto (id es una cadena hashid) |
| GET | `/api/v1/regions` | Regiones disponibles |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Consulta de disponibilidad de dominio |
| GET | `/api/v1/domain/tlds` | Lista de extensiones registrables |
| POST | `/api/v1/payments/webhook/stripe` | Callback de Stripe (verificación de firma, sin cifrado) |

## Endpoints autenticados (Bearer Token)

| Método | Ruta | Descripción |
|------|------|------|
| GET | `/api/v1/user/profile` | Información personal |
| PUT | `/api/v1/user/profile` | Actualizar información |
| POST | `/api/v1/user/kyc` | Enviar verificación de identidad |
| GET | `/api/v1/user/balance` | Saldo de la cuenta |
| GET/POST | `/api/v1/cart` | Carrito de compras |
| POST/GET | `/api/v1/orders` | Pedidos |
| GET | `/api/v1/orders/{id}/payment-methods` | Métodos de pago disponibles |
| POST | `/api/v1/orders/{id}/pay` | Iniciar el pago |
| GET/POST | `/api/v1/resources` | Mis recursos |
| GET | `/api/v1/resources/{id}/status` | Estado del recurso |
| GET | `/api/v1/resources/{id}/console` | Enlace de la consola VNC |
| GET/POST | `/api/v1/cdn/domains` | Lista de dominios CDN / crear (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | Detalle / eliminación de dominio CDN |
| POST | `/api/v1/cdn/domains/{id}/purge` | Purgar caché (idempotente, máx. 100 URLs) |
| GET/POST | `/api/v1/tickets` | Tickets de soporte |
| POST | `/api/v1/tickets/{id}/reply` | Responder al ticket |
| GET/POST | `/api/v1/dns/{domain}` | Gestión de DNS |
| POST | `/api/v1/supplier/apply` | Solicitar ser proveedor |
| GET | `/api/v1/supplier/settlements` | Historial de liquidaciones del proveedor |
| POST | `/api/v1/supplier/withdraw` | Retiro del proveedor |

> **Nota:** La versión de la API va en la ruta URL (p. ej. `/api/v1/...`), validada de forma centralizada por `VersionMiddleware`. Las solicitudes/respuestas de los endpoints autenticados y de administrador pasan por `EncryptionMiddleware`. El cliente establece el encabezado `X-Encrypted: 1` y el cuerpo de la solicitud tiene el formato `{"payload": "<base64(AES-256-GCM)>"}`; el cuerpo de la respuesta también se cifra y se envuelve en el campo `payload`. Todos los IDs enteros se convierten automáticamente a cadenas Hashid de 12 caracteres en las respuestas de la API, y las cadenas Hashid de las solicitudes se decodifican automáticamente de vuelta a IDs enteros por `HashidRequestMiddleware`.

## Endpoints de administrador

| Método | Ruta | Descripción |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | Panel operativo |
| GET/PUT | `/admin/api/v1/users` | Gestión de usuarios |
| GET/POST | `/admin/api/v1/kyc` | Revisión de KYC |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Gestión de productos |
| POST | `/admin/api/v1/products/{productId}/skus` | Crear SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Fijar precio regional |
| GET/POST | `/admin/api/v1/orders` | Gestión de pedidos (incluye reembolsos) |
| GET | `/admin/api/v1/orders/export` | Exportar pedidos (.xlsx) |
| GET | `/admin/api/v1/users/export` | Exportar usuarios (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Exportar proveedores (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Canales de pago / transacciones / conciliación |
| GET/POST | `/admin/api/v1/provisioning/*` | Tareas de entrega / gestión de servidores |
| GET/PUT | `/admin/api/v1/cdn/domains` | Gestión de dominios CDN (cambios de plan) |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | Gestión de credenciales de cuentas de proveedor (compartido CDN/entrega, cifrado Encryptable) |
| GET/POST | `/admin/api/v1/suppliers/*` | Aprobación de proveedores / liquidación / retiros |
| GET/POST | `/admin/api/v1/tickets` | Asignación / cierre de tickets |
| GET | `/admin/api/v1/reports/*` | Reportes de ingresos / regiones / proveedores |
| GET | `/admin/api/v1/monitor/*` | Panel de monitorización / métricas de recursos |
| GET | `/admin/api/v1/audit-logs` | Registros de auditoría |
| PUT | `/admin/api/v1/system/config` | Configuración del sistema |
