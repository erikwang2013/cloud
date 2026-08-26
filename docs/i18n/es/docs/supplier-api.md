# Documentación de la API de proveedores v1

## Visión general

La función de proveedores ofrece dos conjuntos de API:

| Tipo | Autenticación | Prefijo | Estado |
|------|---------|------|------|
| **API interna** | Token Bearer del usuario | `/api/supplier/` | Disponible |
| **API externa** | API Key (`sk_xxx`) | `/api/supplier/external/` | Disponible |

**Base URL**: `https://api.example.com`

**Control de versiones**: se especifica mediante la cabecera HTTP `X-Api-Version: v1`. Si falta, el valor por defecto es `v1`; una versión no soportada devuelve `400`. Solo aplica a las rutas `/api/*` y `/admin/api/*`, gestionadas de forma unificada por `VersionMiddleware`.

---

## API interna (disponible actualmente)

La API interna usa la misma autenticación con Token Bearer de usuario que el resto de interfaces de la plataforma; es adecuada para que los usuarios proveedores ya conectados la llamen desde clientes/frontends.

### Autenticación

```
Authorization: Bearer <user_access_token>
X-Api-Version: v1
```

El usuario debe iniciar sesión primero a través de `/api/auth/login` para obtener el Token, y el rol de su cuenta debe ser `supplier` (se establece cuando el administrador aprueba la solicitud de proveedor).

---

### Formato de respuesta

#### Respuesta de éxito

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### Respuesta paginada

```json
{
  "code": 0,
  "message": "ok",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 45
  }
}
```

#### Respuesta de error

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | Descripción |
|------|------|
| 0 | Éxito |
| 400 | Parámetros de petición incorrectos / versión de API no soportada |
| 401 | Sin sesión o Token caducado |
| 403 | Sin permiso de acceso (rol no proveedor / fallo de confirmación de contraseña) |
| 404 | Recurso inexistente |
| 422 | Fallo de validación de parámetros |
| 429 | Límite de frecuencia de peticiones superado |

---

### Endpoints

#### 1. Registro como proveedor

```
POST /api/supplier/apply
```

Solicitar ser proveedor. Cada usuario solo puede presentar una solicitud.

**Cuerpo de la petición**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| Parámetro | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| company_name | string | Sí | Nombre de la empresa |
| contact_name | string | Sí | Nombre de la persona de contacto |
| contact_phone | string | Sí | Teléfono de contacto |
| contact_email | string | Sí | Correo de contacto |
| settlement_method | string | No | Método de liquidación, por defecto `bank` |

**Respuesta**: objeto de proveedor, con estado `pending`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "aBc123XyZ",
    "user_id": "UsEr456AbC",
    "company_name": "示例科技有限公司",
    "contact_name": "张三",
    "contact_phone": "138****8000",
    "contact_email": "zha***@example.com",
    "status": "pending",
    "settlement_method": "bank",
    "created_at": "2026-05-20T10:30:00Z"
  }
}
```

> Los campos sensibles (nombre de contacto, teléfono, correo) se almacenan cifrados en la base de datos y la API los devuelve parcialmente desenmascarados.

**Errores**:

| code | Escenario |
|------|------|
| 422 | Ya se ha presentado una solicitud de proveedor |

```bash
curl -X POST "https://api.example.com/api/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. Gestión de productos

##### Obtener los productos asignados

```
GET /api/supplier/products
```

**Parámetros Query**:

| Parámetro | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| page | int | No | Página, por defecto 1 |

**Respuesta**: lista paginada; cada elemento incluye la información del producto y el porcentaje de comisión

```json
{
  "code": 0,
  "data": [{
    "id": "SpAbC123",
    "supplier_id": "aBc123XyZ",
    "product_id": "PrOdEfG456",
    "commission_rate": 0.1,
    "approved_at": "2026-05-20T10:30:00Z",
    "product": {
      "id": "PrOdEfG456",
      "name": "高性能云服务器",
      "status": "active"
    }
  }],
  "meta": { "page": 1, "page_size": 20, "total": 5 }
}
```

##### Añadir un producto

```
POST /api/supplier/products
```

Vincular un producto existente al proveedor actual.

**Cuerpo de la petición**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| Parámetro | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| product_id | string | Sí | ID del producto (Hashid) |
| commission_rate | float | No | Porcentaje de comisión, por defecto 0.1 |

**Respuesta**: objeto SupplierProduct creado

**Errores**:

| code | Escenario |
|------|------|
| 422 | El producto ya está asignado a este proveedor |

##### Eliminar un producto

```
DELETE /api/supplier/products/{id}
```

Cancelar la vinculación entre el producto y el proveedor.

**Respuesta**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. Gestión de liquidaciones

##### Obtener la lista de liquidaciones

```
GET /api/supplier/settlements
```

**Respuesta**: todas las liquidaciones del proveedor actual, ordenadas por fecha de creación descendente

```json
{
  "code": 0,
  "data": [{
    "id": "SeTtLe123",
    "supplier_id": "aBc123XyZ",
    "period_start": "2026-05-01",
    "period_end": "2026-05-31",
    "total_sales": "15000.00",
    "commission": "1500.0000",
    "payable": "13500.0000",
    "status": "pending",
    "created_at": "2026-06-01T02:17:00Z"
  }]
}
```

| Campo | Descripción |
|------|------|
| total_sales | Ventas totales de los pedidos completados en el periodo |
| commission | Importe total de la comisión de la plataforma |
| payable | Importe a pagar al proveedor (total_sales - commission) |
| status | `pending` / `paid` |

---

#### 4. Retiros

##### Solicitar un retiro

```
POST /api/supplier/withdraw
```

> Esta operación requiere confirmación de contraseña (campo `confirm_password`), validada por `ConfirmationMiddleware`.
> Tras 5 fallos se bloquea durante 15 minutos.

**Cuerpo de la petición**:

```json
{
  "amount": "5000.00",
  "confirm_password": "user_password_here",
  "account_info": {
    "method": "bank_transfer",
    "bank_name": "中国工商银行",
    "account_number": "6222021234567890",
    "account_holder": "张三"
  }
}
```

| Parámetro | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| amount | string | Sí | Importe del retiro (string para evitar problemas de precisión de coma flotante) |
| confirm_password | string | Sí | Contraseña de inicio de sesión del usuario (segunda confirmación) |
| account_info | object | Sí | Información de la cuenta receptora |
| account_info.method | string | Sí | Método de retiro: `bank_transfer` / `alipay` / `wechat` |

**Cálculo del saldo retirable**: suma de todos los `payable` de liquidaciones completadas - suma de los `amount` de retiros en trámite

**Respuesta**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**Errores**:

| code | Escenario |
|------|------|
| 422 | Saldo retirable insuficiente |
| 403 | Fallo de confirmación de contraseña |

```bash
curl -X POST "https://api.example.com/api/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### Resumen de endpoints de la API interna

| Método | Ruta | Autenticación | Confirmación de contraseña | Descripción |
|------|------|------|---------|------|
| POST | `/api/supplier/apply` | Token | - | Solicitar ser proveedor |
| GET | `/api/supplier/products` | Token | - | Ver los productos asignados |
| POST | `/api/supplier/products` | Token | - | Añadir una vinculación de producto |
| DELETE | `/api/supplier/products/{id}` | Token | - | Eliminar una vinculación de producto |
| GET | `/api/supplier/settlements` | Token | - | Ver liquidaciones |
| POST | `/api/supplier/withdraw` | Token | Requerida | Solicitar un retiro |

---

## API externa (especificación de diseño, pendiente de implementación)

La API externa permite a los proveedores gestionar pedidos, recursos y liquidaciones de forma programática. Todas las peticiones requieren autenticación con API Key.

**Base URL**: `https://api.example.com/api`

### Autenticación

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
X-Api-Version: v1
```

La API Key la genera el administrador de la plataforma en el panel de administración, en `Gestión de proveedores → API Keys`.

**Requisitos de seguridad**:
- Acceso únicamente por HTTPS
- La API Key solo se muestra una vez al crearla; consérvela en lugar seguro
- Se recomienda añadir la IP del servidor a la lista blanca

---

### Formato de respuesta

Idéntico a la API interna, con `request_id` adicional para el seguimiento:

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### Endpoints

#### 1. Gestión de pedidos

##### Obtener la lista de pedidos

```
GET /api/supplier/orders
```

**Parámetros Query**:

| Parámetro | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| page | int | No | Página, por defecto 1 |
| page_size | int | No | Tamaño de página, por defecto 20, máximo 50 |
| status | string | No | Filtro de estado: pending/paid/completed/refunded |
| from | date | No | Fecha de inicio YYYY-MM-DD |
| to | date | No | Fecha de fin YYYY-MM-DD |

##### Obtener el detalle de un pedido

```
GET /api/supplier/orders/{id}
```

---

#### 2. Gestión de recursos

##### Obtener la lista de recursos

```
GET /api/supplier/resources
```

**Parámetros Query**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### Obtener el estado de un recurso

```
GET /api/supplier/resources/{id}/status
```

---

#### 3. Gestión de liquidaciones

##### Obtener la lista de liquidaciones

```
GET /api/supplier/settlements
```

##### Obtener el detalle de una liquidación

```
GET /api/supplier/settlements/{id}
```

---

#### 4. Retiros

##### Solicitar un retiro

```
POST /api/supplier/withdraw
```

##### Registro de retiros

```
GET /api/supplier/withdraws
```

---

#### 5. Gestión de productos

##### Obtener mis productos

```
GET /api/supplier/products
```

##### Enviar una solicitud de publicación de producto

```
POST /api/supplier/products
```

---

### Resumen de endpoints de la API externa

| Método | Ruta | Descripción |
|------|------|------|
| GET | `/api/supplier/orders` | Lista de pedidos |
| GET | `/api/supplier/orders/{id}` | Detalle de pedido |
| GET | `/api/supplier/resources` | Lista de recursos |
| GET | `/api/supplier/resources/{id}/status` | Estado del recurso |
| GET | `/api/supplier/settlements` | Lista de liquidaciones |
| GET | `/api/supplier/settlements/{id}` | Detalle de liquidación |
| POST | `/api/supplier/withdraw` | Solicitar retiro |
| GET | `/api/supplier/withdraws` | Registro de retiros |
| GET | `/api/supplier/products` | Lista de productos |
| POST | `/api/supplier/products` | Enviar producto |

---

## Webhook (recepción de eventos de la plataforma)

Los proveedores pueden registrar una URL de Webhook para recibir eventos en tiempo real. Se configura en el panel de administración.

### Tipos de eventos

| Evento | Momento de disparo |
|------|----------|
| `order.paid` | El usuario completa el pago |
| `order.refunded` | El pedido ha sido reembolsado |
| `resource.provisioned` | El aprovisionamiento del recurso ha terminado |
| `resource.expiring` | El recurso está a punto de expirar (dentro de 7 días) |
| `resource.destroyed` | El recurso ha sido destruido |
| `settlement.created` | Se genera una liquidación |
| `withdrawal.approved` | El retiro ha sido aprobado |

### Formato de la petición Webhook

```json
POST {your_webhook_url}
Content-Type: application/json
X-Webhook-Signature: sha256=abc123...
X-Webhook-Event: order.paid

{
  "event": "order.paid",
  "timestamp": "2026-05-20T14:30:00Z",
  "data": {
    "order_id": "abc123",
    "amount": "49.99",
    "currency": "USD"
  }
}
```

**Verificación de firma**: `HMAC-SHA256(payload, webhook_secret)`

---

## Límite de peticiones

| Endpoint | Límite |
|------|------|
| API interna | 60 req/min por usuario (por defecto) |
| Inicio de sesión en la API interna | 5 req/min |
| API externa | 120 req/min por API Key (regla `supplier_api`, aplicada mediante `RateLimitMiddleware`) |
| Retiros en la API externa | 10 req/min (valor sugerido, ajustable en `config/security.php`) |

> Las reglas de límite de la API externa se definen en `rate_limits.supplier_api` de `config/security.php` y las ejecuta de forma unificada `RateLimitMiddleware` sobre las rutas `/api/supplier/external/*` (conteo atómico INCR; si Redis no está disponible, se deja pasar).

Cabeceras de límite:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## Ejemplos de SDK

### PHP

```php
$token = 'user_access_token_here';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/',
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'X-Api-Version' => 'v1',
        'Accept'        => 'application/json',
    ],
]);

// Solicitar ser proveedor
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// Obtener liquidaciones
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// Solicitar un retiro
$response = $client->post('supplier/withdraw', [
    'json' => [
        'amount'           => '5000.00',
        'confirm_password' => 'mypassword',
        'account_info'     => [
            'method'          => 'bank_transfer',
            'bank_name'       => '中国工商银行',
            'account_number'  => '6222021234567890',
        ],
    ],
]);
```

### Python

```python
import requests

headers = {
    'Authorization': 'Bearer <user_access_token>',
    'X-Api-Version': 'v1',
}

# Obtener los productos asignados
resp = requests.get('https://api.example.com/api/supplier/products',
                     headers=headers)
products = resp.json()

# Solicitar un retiro
resp = requests.post('https://api.example.com/api/supplier/withdraw',
                      headers=headers,
                      json={
                          'amount': '5000.00',
                          'confirm_password': 'mypassword',
                          'account_info': {
                              'method': 'bank_transfer',
                              'bank_name': 'ICBC',
                              'account_number': '6222021234567890',
                          },
                      })
print(resp.json())
```

---

## Recomendaciones de manejo de errores

1. **429 límite superado**: esperar los segundos de `Retry-After` y reintentar
2. **401 no autorizado**: comprobar si el Token es válido y si ha caducado
3. **403 prohibido**: comprobar si el rol de la cuenta es `supplier`; si el fallo es de confirmación de contraseña, esperar a que se levante el bloqueo
4. **422 fallo de validación**: corregir los parámetros de la petición según el campo `message`
5. **5xx error de servidor**: reintentar con backoff exponencial (1s -> 5s -> 25s)

---

## Referencia de endpoints del panel de administración

Los siguientes son los endpoints de administración de proveedores (solo para el backend; requieren rol Admin):

| Método | Ruta | Descripción |
|------|------|------|
| GET | `/admin/api/suppliers` | Lista de proveedores (admite filtro por status) |
| GET | `/admin/api/suppliers/export` | Exportar proveedores a Excel |
| POST | `/admin/api/suppliers/{id}/approve` | Aprobar un proveedor |
| POST | `/admin/api/suppliers/{id}/settle` | Generar una liquidación |
| POST | `/admin/api/suppliers/withdraws/{id}/approve` | Aprobar un retiro |
| GET | `/admin/api/suppliers/{id}/api-keys` | Ver la lista de API Keys del proveedor |
| POST | `/admin/api/suppliers/{id}/api-keys` | Crear una API Key (la Key original solo se devuelve una vez) |
| DELETE | `/admin/api/suppliers/api-keys/{id}` | Revocar una API Key |
