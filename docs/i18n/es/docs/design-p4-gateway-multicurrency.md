# Diseño P4.1 + P4.2: gateway de API independiente / límite de peticiones unificado + consistencia de extremo a extremo multi-moneda

> Versión: 2026-08-17 v1 | Producido por el arquitecto, para su implementación por gateway-impl / multicurrency-impl y revisión por reviewer-gate
> Base: docs/team-plan.md v2 Fase 4, docs/architecture.md, lectura real del código existente

---

## P4.1 Gateway de API independiente + límite de peticiones unificado

### Estado actual (confirmado por lectura del código)

| Capa | Estado actual |
|----|------|
| Gateway de borde | docker/nginx.conf actúa como gateway L7 del servicio: `limit_req_zone api 10r/s` (límite global), proxy_pass 8787 (service), 8282 (ws). **admin es un contenedor independiente** (Dockerfile target admin, nginx-admin.conf escucha en 8788 proxy 8788), **sin limit_req** |
| Límite a nivel de aplicación | `service/common/security/RateLimitMiddleware.php` ya existe: ventana fija con Redis INCR+expire, **solo per-IP**, selección de reglas según `ROUTE_MAP`, adjuntado a **rutas explícitas** (unas ~12 ubicaciones en route.php) |
| Configuración de reglas | `config/security.php rate_limits`: default/login/register/password_reset/oauth/captcha/sms/pay/upload/supplier_api/graphql, todas con rate/burst/per, pero **el campo burst actualmente no se usa** |
| Middlewares globales | la clave `''` de `config/middleware.php` ya soporta aplicación a todas las rutas (WAF/GeoBlock/Security, 10 elementos) |
| Deficiencias | `/graphql` (rutas pública + autenticada) **sin ningún límite**; no existe límite per-token; la respuesta 429 no incluye cabecera `Retry-After`; los webhooks no tienen reglas de exención/dedicadas |

### Decisiones

**D1: No crear un proceso de gateway independiente.** nginx ya es el gateway (borde de red + límite de peticiones + distribución de rutas); dentro de webman se aplica el límite unificado.
- Razón: un contenedor gateway independiente requeriría nuevas dependencias/nueva topología de despliegue/doble autenticación; en la escala actual de instancia única es sobrediseño.
- Compensación: no se puede aplicar un límite diferenciado por token/por ruta a nivel de gateway (nginx solo tiene segmentos per-IP). La diferenciación la cubre la capa de aplicación; en la capa de nginx se mantiene únicamente el respaldo de IP de grano grueso (el actual 10r/s se sube a 100r/s para no afectar al negocio; al validar con k6 se vuelve al umbral de demostración).
- Ruta de evolución: si en el futuro hay múltiples instancias/servicios, basta con mover el limitador global de `config/middleware.php` a un servicio gateway independiente; el middleware no percibe la forma de despliegue.

**D2: Límite unificado = middleware global + cubos de dos dimensiones (per-IP + per-token).**
- Quitar `RateLimitMiddleware` de las rutas explícitas (en route.php son unas ~12 ubicaciones; comprobar con grep) y montarlo en la lista global `''` de `config/middleware.php` (después de WAF, antes de los middlewares de negocio), **cubriendo de forma natural todas las rutas de la aplicación (incluidas las dos de /graphql)**.
- **Semántica de cubos (explícita, para evitar evasiones)**: los cubos `ratelimit:ip:{realIp}:{rule}` y `ratelimit:tok:{sha256(token)}:{rule}` cuentan de forma independiente; **si cualquiera de los cubos supera el límite, se devuelve 429 (OR)**. Prohibido implementar con AND: con AND, cambiar de IP permite evadir el cubo per-IP y cambiar de token permite evadir el cubo per-token.
- **Lista de exenciones**: `/health*` (sondas de monitorización) y `/api/payments/webhook/stripe` (la verificación de firma es la defensa real + Stripe reintenta automáticamente con backoff en 429 + el respaldo de grano grueso de nginx a 100r/s sigue vigente; el límite de peticiones no aporta seguridad, solo riesgo de perder eventos o retrasar pagos). El resto de rutas deben estar limitadas.
- Respuesta: `HTTP 429` + cabecera `Retry-After` (el máximo **max** de los restantes de la ventana de ambos cubos; para ventana fija usar el `PTTL` exacto de Redis) + body `{code:429, message, retry_after}` (alineado con el `Response::error` existente).
- Ráfagas: activar el campo burst — `rate` es la cuota estable en la ventana, `burst` es el crédito que se puede sobregirar. Se implementa como límite de conteo de la clave Redis de `rate + burst` (sobregiro dentro de la ventana fija), sin necesidad de ventana deslizante (ponytail: la ventana fija tiene una ampliación de 2x la ventana en los bordes; per-IP es suficiente para abuso contra una sola máquina; si se necesita más estricto, cambiar a ventana deslizante).
- Mapeo ruta → regla: mantener el `ROUTE_MAP` existente y añadir `'/graphql' => 'graphql'` (config/security.php:46 ya tiene `{rate:30, burst:5, per:60}`); las rutas desconocidas usan `default` (60/60s).
- Redis no disponible: mantener el fail-open existente (capturar Exception y dejar pasar) — el respaldo de grano grueso de nginx a 100r/s sigue vigente.
- **Alcance**: solo el contenedor service. admin es un contenedor independiente (nginx-admin.conf sin limit_req, actualmente sin límite); los cambios en service/config y los middlewares de service no afectan a admin — el límite de admin no está en el alcance de P4.1, se decide por separado.

**D3: Límite antes de la autenticación.** El middleware global está antes de AuthMiddleware (el orden de middleware.php es el orden de ejecución), por lo que el cubo per-token degenera a cubo per-IP para peticiones sin token; las peticiones con token, incluso en rutas anónimas (p. ej. /api/products), cuentan en el cubo de token — evita el abuso de tokens compartidos.

### Superficie de impacto

| Elemento | Cambio |
|----|------|
| `service/common/security/RateLimitMiddleware.php` | Refactor: cubo per-token, burst, Retry-After, regla graphql |
| `service/config/middleware.php` | Añadir RateLimitMiddleware a la lista `''`; eliminar de todos los puntos de montaje explícitos en route.php |
| `service/config/security.php` | Mantener `default` {60,10,60} sin cambios (umbral de aceptación = rate+burst = 70); `graphql` {30,5,60} ya existe, no hay que añadirlo; el campo burst se mantiene |
| `service/config/route.php` | Eliminar ~12 montajes explícitos de `RateLimitMiddleware::class` (según el grep real, grupos auth/supplier/admin) |
| `docker/nginx.conf` | `limit_req` rate 10r/s → 100r/s (respaldo de grano grueso, evitar limitar el negocio encima del middleware global) |
| Pruebas | Sincronizar los tests del paquete service que dependen del montaje explícito del middleware de límite; añadir pruebas unitarias del middleware |

### Aceptación (k6)

```
# Elegir una ruta anónima cualquiera (p. ej. GET /api/products) y /graphql, y lanzar 200 peticiones/10s a cada una:
# Todas las peticiones por encima del umbral devuelven 429 y la respuesta incluye Retry-After; por debajo del umbral, todo 200.
# Aserción: recuento de 429 == total de peticiones - umbral; /graphql también aplica el límite (deficiencia original).
```

---

## P4.2 Consistencia de extremo a extremo multi-moneda (incluida la estrategia de redondeo de fees)

### Estado actual (confirmado por lectura del código)

- **Almacenamiento**: en `install.sql` todos los importes son DECIMAL — saldo/congelado `(16,4)`, subtotal/descuento/impuesto/total del pedido, unit_price/total_price de líneas `(12,4)`, `exchange_rate DECIMAL(12,6)` ya existe en `orders` y `payment_transactions`; `user_balances` separa por moneda (contabilidad por moneda).
- **Fuente de tipo de cambio**: `service/app/cron/ExchangeRateSync.php` ya está implementado — API externa gratuita (`EXCHANGE_RATE_API_URL` configurable por env, por defecto exchangerate-api.com) sincronizada cada hora a Redis `exchange_rate:{CURRENCY}`; `OrderService::getExchangeRate` lee la instantánea de Redis al hacer el pedido (USD siempre 1.0) y la escribe en el campo `exchange_rate` del pedido. **Ya existe la dependencia externa y el env permite cambiar de fuente, no hace falta añadir nada.**
- **Problema de truncamiento de fee**: `PaymentRouter::calculateFee` = `bcadd(bcmul($amount, $rate, 8), $fixed, 4)` — bcmath **trunca** según scale (no redondea a la mitad), con dirección de **cobrar de menos** <0.0001/pedido; además, `total_amount = amount + fee` con un amount de 5+ decimales (p. ej. 10.12345) puede quedar inconsistente con el total del pedido tras el truncamiento.
- La **comprobación de suspensión** ya evalúa el saldo por moneda (multi-moneda); Billing factura según meters (precios unitarios de usage_rates DECIMAL(12,4)).

### Decisiones

**D4: Invariante de importes unificado — una precisión interna por moneda, el redondeo solo ocurre en un único punto.**
- Cálculo interno unificado en `DECIMAL(12,4)` (granularidad de pedido) y `DECIMAL(16,4)` (granularidad de saldo); toda multiplicación debe pasar por `bcround(x, 4, PHP_ROUND_HALF_UP)`; `bcadd/bcsub` solo para sumas/restas de la misma precisión (exactas por sí mismas).
- Nuevo y único helper de importes `service/common/money/Money.php` (unas 40 líneas):
  - `bcround(string $v, int $scale = 4, int $mode = PHP_ROUND_HALF_UP): string` — idempotente; `round()` tiene riesgo de precisión con flotantes, debe usarse la vía de cadenas: `bcadd($v, '0', $scale+1)` y decidir HALF-UP según el dígito en la posición $scale+1 (en la implementación cuidar los negativos; basta con usar bccomp sobre abs).
  - Todo campo de importe debe pasar por `bcround(…, 4)` antes de escribirse en la base; **prohibido** usar `(float)`/`round()` en mitad de la cadena de cálculo (el `round((float) bcmul(...))` actual de StripeChannel es un riesgo latente).
- El `calculateFee` existente pasa a: `$fee = bcround(bcadd(bcmul(bcround($amount,4), $rate, 8), $fixed, 8), 4)` — primero alinear amount a 4 decimales, luego multiplicar por la tasa, luego HALF_UP a 4 decimales. **Corrección de dirección: cobrar de menos → redondeo estándar a la mitad** (diferencia por pedido ≤0.00005, valor esperado tiende a 0). **Se mantiene la protección de clamp a 0 para fees negativos** (el comportamiento actual de PaymentRouter.php:44 no cambia).

**D5: Identidad de pedido y separación de comisiones de canal (conciliación sin deriva).** Dos hechos independientes:
- **Identidad de líneas de pedido** `total − subtotal − tax + discount == 0` (exacta a 0.0000): en la cadena de creación (OrderService::createFromCart), líneas con `bcround(bcmul(price, qty, 8), 4)` (primero multiplicación de alta precisión y luego redondeo, evitando doble truncamiento) → subtotal = suma de líneas (exacta) → total = subtotal + tax − discount (suma/resta de la misma precisión, exacta). **tax actualmente siempre es 0** (createFromCart no fija tax, install.sql:345 DEFAULT 0.0000) — no se añade cálculo de impuestos (fuera del alcance de P4.2, con implicaciones de cumplimiento); la aserción se implementa sobre el valor actual tax=0 pero la fórmula conserva el término tax.
- **Comisión de canal**: channel_fee con `bcround(…,4)` independiente; el importe del canal de pago = total + channel_fee, igualdad exacta a 4 decimales.
- Validación: `PaymentController::reconcile*` y los informes (Report) toman como base el total almacenado en el pedido, sin recalcular.

**D6: Instantánea de tipo de cambio y punto de conversión.**
- La fuente de tipo de cambio se mantiene en el cron ExchangeRateSync + Redis (ya existe, no se toca). La columna `exchange_rate` ya queda como instantánea con pedido/transacción (DECIMAL(12,6)); **el punto de conversión = liquidación (escritura en base)**, sin conversión en tiempo real al mostrar (el precio en tiempo real en la UI es solo multiplicar por la tasa actual de Redis, no afecta a la contabilidad).
- Regla: **todo lo que afecta a la contabilidad/saldo debe usar la tasa de la instantánea del pedido; todo lo que es precio/mostración puede usar la tasa actual**. Prohibido mezclar dos tasas en la cadena de liquidación.
- La capa de saldos ya es un libro por moneda (user_balances por fila de currency), sin conversión a moneda base unificada; cuando los informes necesiten una moneda base (p. ej. USD), se agrega con la tasa de la instantánea del pedido y el resultado agregado pasa igualmente por `bcround(…,4)` (ponytail: el error de redondeo de la agregación entre monedas queda en la cifra total; si una auditoría futura exige subtotales por moneda, se descompone).

**D7: Lista de cambios (incluye puntos de revisión del código multi-moneda existente).**
- Cambiar: `PaymentRouter::calculateFee`, `StripeChannel` (alinear importes de entrada + eliminar round de flotantes, incluido convertToSmallest con bcround($total,2)), `OrderService::createFromCart` (redondeo en orden de líneas/subtotal/total), **`Order/Model/Coupon.php::calculateDiscount` (:31-44 actualmente float+round, cambiar a vía de cadenas bcround)** , `PaymentController::reconcile*` (aserción de la identidad D5), `Report/*` (agregación unificada con bcround).
- Revisar sin cambiar: Billing meters (precios unitarios ya DECIMAL(12,4), basta alinear la facturación con bcround), comprobación de suspensión (evaluación de saldo por moneda, ya correcta), `Cron/ExchangeRateSync.php` (escribe en Redis conservando 6 decimales originales, no se toca).
- Añadir: `service/common/money/Money.php` + pruebas unitarias (casos límite HALF_UP: 0.00005 → 0.0001, 0.00004 → 0.0000, **-0.00005 → -0.0001 (negativos se alejan de cero)**, idempotencia).
- Migración: `install.sql` sin cambios estructurales (la columna exchange_rate ya existe); si el truncamiento de fees en pedidos históricos generó una cola residual <0.0001, es una diferencia contable irreversible — **solo se registra, no se corrige** (corregir con un asiento cambiaría la conciliación histórica); nueva consulta de auditoría `fee_drift` que liste los pedidos con |total−subtotal−tax+discount|>0 para revisión manual.

### Aceptación

```
# k6 (P4.1): una única IP fija. GET /api/products y /graphql, 200 peticiones/10s cada una:
#   umbral de la regla default = rate+burst = 70/ventana de 60s → 429 esperados ≈ 200−70 = 130 (±1-2 por borde de ventana)
#   umbral de la regla graphql = 35 → 429 esperados ≈ 165; todos con cabecera Retry-After; con tráfico bajo, todo 200
# Pruebas unitarias (P4.2): límites de Money::bcround (0.00005→0.0001, 0.00004→0.0000, -0.00005→-0.0001, idempotencia)
# Test de identidad: construir un pedido de varias líneas (con precios unitarios de 5 decimales + cupón), aserción total−subtotal−tax+discount == 0 siempre
# Regresión: los 491 tests existentes de service en verde (incluidas aserciones de importes)
```

---

## Riesgos y revisión

- **Riesgo del limitador global D2 (medio)**: el montaje global afecta a todos los endpoints de service (**no a admin** — contenedor independiente, los cambios de service/config no lo alcanzan); los webhooks ya están exentos; umbrales inadecuados pueden perjudicar al negocio, se requiere la revisión de security-auditor de los umbrales por defecto y la política fail-open. **El contenedor admin actualmente no tiene límite** (nginx-admin.conf sin limit_req), P4.1 no lo incluye, se decide por separado.
- **Cadena de fondos D4/D5 (alto)**: el cambio de dirección de redondeo afecta al importe de cada pedido (cobrar de menos → redondeo estándar a la mitad); requiere revisión de security-auditor + doble revisión humana; los datos históricos solo se registran, no se corrigen.
- **Dependencias**: sin nuevas dependencias composer; sin tablas nuevas; el cambio de configuración de nginx requiere recarga.

```yaml
design:
  objective: "P4.1 límite unificado efectivo en todas las rutas (incluida graphql) + P4.2 estrategia de redondeo multi-moneda alineada, identidad contable con deriva cero"
  files_affected:
    - service/common/security/RateLimitMiddleware.php
    - service/config/middleware.php
    - service/config/route.php
    - service/config/security.php
    - docker/nginx.conf
    - service/common/money/Money.php (new)
    - service/app/payment/service/PaymentRouter.php
    - service/app/payment/service/channels/StripeChannel.php
    - service/app/order/service/OrderService.php
    - service/app/order/model/Coupon.php
    - service/app/payment/controller/PaymentController.php
    - service/app/report/controller/ReportController.php
    - tests/ (middleware + money + identidad)
  modules_touched: ["Gateway/Route", "Security", "Payment", "Order", "Billing", "Report"]
  api_changes: [{method: "ALL", path: "/graphql", error_codes: ["429"]}, {method: "ALL", path: "ALL", error_codes: ["429 + Retry-After"]}]
  data_changes: []   # sin cambios estructurales; la columna exchange_rate ya existe; tax se mantiene en 0, no se añade
  client_impact: ["flutter", "harmonyos"]  # 429 requiere manejo elegante en el cliente; el contenedor admin no se ve afectado
  risk: "high"       # cadena de fondos D4/D5
  review_needed: ["security-auditor"]
  testing_points: ["429+Retry-After en todas las rutas (k6 IP única, 429≈130/165)", "cierre de la deficiencia del límite en graphql", "webhook exento sin 429", "semántica OR de doble cubo (cambiar token/IP no puede evadir)", "límites HALF_UP de fee con negativos", "Coupon bcround por cadenas", "identidad total−subtotal−tax+discount==0", "consulta de auditoría fee_drift de pedidos históricos"]
  dependencies: []
```
