# Resumen de la API

> Referencia completa de la API (200+ endpoints, con ejemplos de solicitud/respuesta y códigos de error): [Documentación de la API](api-reference.md)
> Depuración en línea: [documentación de la API de service](http://localhost:8787/apidoc) · [documentación de la API de admin](http://localhost:8788/apidoc)

## Endpoints públicos

| Método | Ruta | Descripción |
|------|------|------|
| GET | `/health` | Comprobación de salud |
| POST | `/api/auth/register` | Registro de usuario (el cuerpo de la solicitud debe ir cifrado con AES-256-GCM) |
| POST | `/api/auth/login` | Inicio de sesión de usuario (el cuerpo de la solicitud debe ir cifrado con AES-256-GCM) |
| POST | `/api/auth/refresh` | Renovar Token (el cuerpo de la solicitud debe ir cifrado con AES-256-GCM) |
| POST | `/api/captcha/create` | Generar CAPTCHA de clic (obtener antes de iniciar sesión/registrarse) |
| GET | `/api/products` | Lista de productos (filtrable por categoría/región/palabra clave) |
| GET | `/api/products/{id}` | Detalle del producto (id es una cadena hashid) |
| GET | `/api/regions` | Regiones disponibles |
| GET | `/api/domain/check/{domain}/{tld}` | Consulta de disponibilidad de dominio |
| GET | `/api/domain/tlds` | Lista de extensiones registrables |
| POST | `/api/payments/webhook/stripe` | Callback de Stripe (verificación de firma, sin cifrado) |

## Endpoints autenticados (Bearer Token)

| Método | Ruta | Descripción |
|------|------|------|
| GET | `/api/user/profile` | Información personal |
| PUT | `/api/user/profile` | Actualizar información |
| POST | `/api/user/kyc` | Enviar verificación de identidad |
| GET | `/api/user/balance` | Saldo de la cuenta |
| GET/POST | `/api/cart` | Carrito de compras |
| POST/GET | `/api/orders` | Pedidos |
| GET | `/api/orders/{id}/payment-methods` | Métodos de pago disponibles |
| POST | `/api/orders/{id}/pay` | Iniciar el pago |
| GET/POST | `/api/resources` | Mis recursos |
| GET | `/api/resources/{id}/status` | Estado del recurso |
| GET | `/api/resources/{id}/console` | Enlace de la consola VNC |
| GET/POST | `/api/cdn/domains` | Lista de dominios CDN / crear (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/cdn/domains/{id}` | Detalle / eliminación de dominio CDN |
| POST | `/api/cdn/domains/{id}/purge` | Purgar caché (idempotente, máx. 100 URLs) |
| GET/POST | `/api/tickets` | Tickets de soporte |
| POST | `/api/tickets/{id}/reply` | Responder al ticket |
| GET/POST | `/api/dns/{domain}` | Gestión de DNS |
| POST | `/api/supplier/apply` | Solicitar ser proveedor |
| GET | `/api/supplier/settlements` | Historial de liquidaciones del proveedor |
| POST | `/api/supplier/withdraw` | Retiro del proveedor |

> **Nota:** Todas las solicitudes a la API deben incluir el encabezado `X-Api-Version: v1` (si falta, el valor por defecto es `v1`, validado por `VersionMiddleware`). Las solicitudes/respuestas de los endpoints autenticados y de administrador pasan por `EncryptionMiddleware`. El cliente establece el encabezado `X-Encrypted: 1` y el cuerpo de la solicitud tiene el formato `{"payload": "<base64(AES-256-GCM)>"}`; el cuerpo de la respuesta también se cifra y se envuelve en el campo `payload`. Todos los IDs enteros se convierten automáticamente a cadenas Hashid de 12 caracteres en las respuestas de la API, y las cadenas Hashid de las solicitudes se decodifican automáticamente de vuelta a IDs enteros por `HashidRequestMiddleware`.

## Endpoints de administrador

| Método | Ruta | Descripción |
|------|------|------|
| GET | `/admin/api/dashboard` | Panel operativo |
| GET/PUT | `/admin/api/users` | Gestión de usuarios |
| GET/POST | `/admin/api/kyc` | Revisión de KYC |
| GET/POST/PUT/DELETE | `/admin/api/products` | Gestión de productos |
| POST | `/admin/api/products/{productId}/skus` | Crear SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | Fijar precio regional |
| GET/POST | `/admin/api/orders` | Gestión de pedidos (incluye reembolsos) |
| GET | `/admin/api/orders/export` | Exportar pedidos (.xlsx) |
| GET | `/admin/api/users/export` | Exportar usuarios (.xlsx) |
| GET | `/admin/api/suppliers/export` | Exportar proveedores (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Canales de pago / transacciones / conciliación |
| GET/POST | `/admin/api/provisioning/*` | Tareas de entrega / gestión de servidores |
| GET/PUT | `/admin/api/cdn/domains` | Gestión de dominios CDN (cambios de plan) |
| GET/POST/PUT/DELETE | `/admin/api/providers` | Gestión de credenciales de cuentas de proveedor (compartido CDN/entrega, cifrado Encryptable) |
| GET/POST | `/admin/api/suppliers/*` | Aprobación de proveedores / liquidación / retiros |
| GET/POST | `/admin/api/tickets` | Asignación / cierre de tickets |
| GET | `/admin/api/reports/*` | Reportes de ingresos / regiones / proveedores |
| GET | `/admin/api/monitor/*` | Panel de monitorización / métricas de recursos |
| GET | `/admin/api/audit-logs` | Registros de auditoría |
| PUT | `/admin/api/system/config` | Configuración del sistema |
