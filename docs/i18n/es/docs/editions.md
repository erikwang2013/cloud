# Cloud Platform — Plataforma global de comercio de recursos en la nube

Plataforma de comercio de recursos en la nube orientada a usuarios globales, que admite la compra en línea y la entrega automática de servidores (VM), direcciones IP, discos en la nube, dominios y otros productos. Los servidores físicos propios se entregan mediante virtualización Proxmox VE, y también se admite el registro de proveedores externos para vender.

## Resumen de versiones

| | Lite (Simplificada) | Standard (Estándar) | Full (Completa) |
|---|:---:|:---:|:---:|
| **Licencia** | Código abierto (MIT) | Licencia comercial | Licencia comercial |
| **Contacto** | GitHub | erik@erik.xyz | erik@erik.xyz |
| **Escenario de uso** | Proyectos personales/estudio/tienda pequeña | Proveedores de nube medianos y pequeños | Plataforma de nube grande/multi-proveedor |

---

## 1. Comparativa de funciones

### 1.1 Sistema de usuarios

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Registro/inicio de sesión con correo o teléfono | ✅ | ✅ | ✅ |
| Autenticación JWT (Access + Refresh) | ✅ | ✅ | ✅ |
| Restablecimiento de contraseña | ✅ | ✅ | ✅ |
| Vinculación de huella del dispositivo + rotación de tokens | ❌ | ✅ | ✅ |
| Bloqueo de inicio de sesión (bloqueo de 15 min tras 5 intentos fallidos) | ❌ | ✅ | ✅ |
| Inicio de sesión con Google OAuth | ❌ | ✅ | ✅ |
| Apple Sign In | ❌ | ✅ | ✅ |
| Verificación en dos pasos TOTP + códigos de recuperación | ❌ | ✅ | ✅ |
| Verificación de correo electrónico | ❌ | ✅ | ✅ |
| Código de verificación por SMS | ❌ | ✅ | ✅ |
| Gestión de sesiones (ver/revocar) | ✅ | ✅ | ✅ |
| Cancelación de cuenta por GDPR | ✅ | ✅ | ✅ |
| Gestión de perfil | ✅ | ✅ | ✅ |
| Verificación de identidad KYC | ❌ | ✅ | ✅ |
| Gestión de direcciones | ❌ | ✅ | ✅ |
| Cuenta de saldo | ❌ | ✅ | ✅ |
| Alerta de inicio de sesión desde IP nueva | ❌ | ✅ | ✅ |
| Identificación de plataforma del cliente | ❌ | ✅ | ✅ |
| Internacionalización multilingüe (i18n, 120 entradas) | ✅ | ✅ | ✅ |

### 1.2 Sistema de productos

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Lista de productos (filtro por categoría/región) | ✅ | ✅ | ✅ |
| Detalle de producto (con SKU + precios por región) | ✅ | ✅ | ✅ |
| Búsqueda de texto completo con Elasticsearch | ✅ | ✅ | ✅ |
| Reseñas de productos (puntuación + contenido) | ✅ | ✅ | ✅ |
| Atributos de producto | ❌ | ✅ | ✅ |
| Captcha de clic | ❌ | ✅ | ✅ |
| Importación/exportación masiva (CSV) | ❌ | ✅ | ✅ |

### 1.3 Sistema de pedidos

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Carrito de compra (alta/baja/modificación/consulta) | ✅ | ✅ | ✅ |
| Realizar pedido | ✅ | ✅ | ✅ |
| Lista de pedidos + detalle | ✅ | ✅ | ✅ |
| Cupones | ❌ | ✅ | ✅ |
| Facturas (generación + descarga PDF) | ❌ | ✅ | ✅ |
| Reembolsos | ❌ | ✅ | ✅ |

### 1.4 Sistema de pagos

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Pago con Stripe | ❌ | ✅ | ✅ |
| Enrutamiento multi-canal | ❌ | ✅ | ✅ |
| Verificación de firma Webhook | ❌ | ✅ | ✅ |
| Conciliación diaria | ❌ | ✅ | ✅ |
| Tipo de cambio multi-moneda | ❌ | ✅ | ✅ |
| Reembolso por el mismo canal | ❌ | ✅ | ✅ |

### 1.5 Entrega de recursos

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Virtualización Proxmox VE | ❌ | ✅ | ✅ |
| Ciclo de vida completo del servidor (VM) | ❌ | ✅ | ✅ |
| Disco en la nube (creación/ampliación) | ❌ | ✅ | ✅ |
| Gestión y asignación del pool de IP | ❌ | ✅ | ✅ |
| Estrategia de selección de host (equilibrio de carga) | ❌ | ✅ | ✅ |
| Mejora en línea de CPU/memoria/disco | ❌ | ✅ | ✅ |
| Consola VNC | ❌ | ✅ | ✅ |
| Cola de aprovisionamiento asíncrono | ❌ | ✅ | ✅ |
| Estrategia de reintentos (6 intentos con backoff) | ❌ | ✅ | ✅ |
| Arquitectura de plugins Provider | ❌ | ✅ | ✅ |
| Monitorización de expiración de recursos | ❌ | ✅ | ✅ |

### 1.6 Dominios y DNS

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Consulta de disponibilidad de dominios | ❌ | ✅ | ✅ |
| Gestión de precios TLD | ❌ | ✅ | ✅ |
| Gestión de registros DNS | ❌ | ✅ | ✅ |
| Aprobación de transferencia de dominios | ❌ | ✅ | ✅ |

### 1.7 Sistema de tickets

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Crear/responder tickets | ❌ | ✅ | ✅ |
| Lista de tickets + detalle | ❌ | ✅ | ✅ |
| Asignación de atención al cliente | ❌ | ✅ | ✅ |
| Seguimiento SLA | ❌ | ✅ | ✅ |
| Asignación automática (equilibrio de carga) | ❌ | ✅ | ✅ |

### 1.8 Sistema de notificaciones

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Notificaciones por correo | ❌ | ✅ | ✅ |
| Notificaciones por SMS (Twilio) | ❌ | ✅ | ✅ |
| Push de la app (FCM) | ❌ | ✅ | ✅ |
| Mensajería interna | ❌ | ✅ | ✅ |
| Gestión de plantillas de notificaciones | ❌ | ✅ | ✅ |
| Preferencias de notificación del usuario | ❌ | ✅ | ✅ |

### 1.9 Panel de administración

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Panel de control | ✅ | ✅ | ✅ |
| Gestión de usuarios (lista/detalle/estado) | ✅ | ✅ | ✅ |
| Gestión de productos (CRUD) | ✅ | ✅ | ✅ |
| Gestión de pedidos (lista/detalle) | ✅ | ✅ | ✅ |
| Registros de auditoría | ✅ | ✅ | ✅ |
| Revisión KYC | ❌ | ✅ | ✅ |
| Gestión de SKU + precios por región | ❌ | ✅ | ✅ |
| Gestión de canales de pago + registros de transacciones | ❌ | ✅ | ✅ |
| Monitorización de tareas de aprovisionamiento de recursos | ❌ | ✅ | ✅ |
| Gestión de hosts | ❌ | ✅ | ✅ |
| Asignación/cierre de tickets | ❌ | ✅ | ✅ |
| Gestión de TLD de dominios + zonas DNS | ❌ | ✅ | ✅ |
| Gestión de plantillas de notificaciones | ❌ | ✅ | ✅ |
| Gestión de cupones | ❌ | ✅ | ✅ |
| Gestión de artículos de ayuda | ❌ | ✅ | ✅ |
| Gestión de Webhooks | ❌ | ✅ | ✅ |
| Gestión de API de proveedores de nube | ❌ | ✅ | ✅ |
| Importación/exportación de productos | ❌ | ✅ | ✅ |
| Exportación de usuarios/pedidos/proveedores | ❌ | ✅ | ✅ |
| Informes (ingresos/regiones) | ❌ | ✅ | ✅ |
| Panel de monitorización + métricas de recursos | ❌ | ✅ | ✅ |
| Gestión de proveedores | ❌ | ❌ | ✅ |
| Gestión de API Keys de proveedores | ❌ | ❌ | ✅ |
| Interruptores dinámicos de Feature Flags | ❌ | ❌ | ✅ |

### 1.10 Sistema de proveedores

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Registro de proveedores + aprobación | ❌ | ❌ | ✅ |
| Publicación de productos + comisiones | ❌ | ❌ | ✅ |
| Liquidación (semanal/mensual) | ❌ | ❌ | ✅ |
| Solicitud de retiro + aprobación | ❌ | ❌ | ✅ |
| API externa (autenticación por API Key) | ❌ | ❌ | ✅ |
| Aislamiento de datos de proveedores | ❌ | ❌ | ✅ |

### 1.11 Comunicación en tiempo real

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Push en tiempo real por WebSocket | ❌ | ❌ | ✅ |
| Monitorización de excepciones con Sentry | ❌ | ❌ | ✅ |
| Scripts de pruebas de carga k6 | ❌ | ✅ | ✅ |

### 1.12 Certificados SSL

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Compra de certificados SSL (DV/OV/EV) | ❌ | ❌ | ✅ |
| Emisión automática con Let's Encrypt | ❌ | ❌ | ✅ |
| Renovación automática (14 días antes de la expiración) | ❌ | ❌ | ✅ |
| Descarga de certificados (PEM/KEY) | ❌ | ❌ | ✅ |
| Gestión de planes SSL (lado de administración) | ❌ | ❌ | ✅ |

### 1.13 Almacenamiento de objetos

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Almacenamiento de objetos compatible con S3 | ❌ | ❌ | ✅ |
| Almacenamiento propio con MinIO | ❌ | ❌ | ✅ |
| URLs pre-firmadas de carga/descarga | ❌ | ❌ | ✅ |
| Gestión de cuotas de almacenamiento | ❌ | ❌ | ✅ |

### 1.14 Aceleración CDN

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Gestión de dominios CDN | ❌ | ❌ | ✅ |
| Purga de caché (Purge) | ❌ | ❌ | ✅ |
| Tipos de origen (servidor/almacenamiento) | ❌ | ❌ | ✅ |
| Integración con Cloudflare | ❌ | ❌ | ✅ |

### 1.15 Facturación por uso

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Facturación por hora/tráfico | ❌ | ❌ | ✅ |
| Recopilación y agregación de uso | ❌ | ❌ | ✅ |
| Débito automático del saldo | ❌ | ❌ | ✅ |
| Suspensión/restauración de recursos por impago | ❌ | ❌ | ✅ |

### 1.16 Puntuación de proveedores

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Puntuación en cuatro dimensiones (calidad/soporte/entrega/valor) | ❌ | ❌ | ✅ |
| Restricción a usuarios que han comprado | ❌ | ❌ | ✅ |
| Revisión de puntuaciones (lado de administración) | ❌ | ❌ | ✅ |
| Visualización de la media de puntuación del proveedor | ❌ | ❌ | ✅ |

### 1.17 Distribución por referidos

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Generación de enlaces de referido | ❌ | ❌ | ✅ |
| Atribución de pedidos (parámetro ref) | ❌ | ❌ | ✅ |
| Cálculo de comisiones y retiro | ❌ | ❌ | ✅ |
| Gestión de planes de afiliación (lado de administración) | ❌ | ❌ | ✅ |

### 1.18 GraphQL

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Endpoint GraphQL (público + autenticado) | ❌ | ❌ | ✅ |
| Consultas de productos/pedidos/recursos | ❌ | ❌ | ✅ |
| Límite de profundidad de consultas | ❌ | ❌ | ✅ |

### 1.19 Observabilidad

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Exportación de métricas Prometheus | ❌ | ❌ | ✅ |
| Panel de control preconfigurado de Grafana | ❌ | ❌ | ✅ |
| Reglas de alerta (colas/tasa de errores/latencia) | ❌ | ❌ | ✅ |
| Chequeos de salud (live/ready/deps) | ❌ | ❌ | ✅ |
| i18n en 7 idiomas (más de 550 entradas) | ❌ | ❌ | ✅ |

### 1.12 Clientes

| Función | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Cliente Flutter | ❌ | ❌ | ✅ |
| Cliente HarmonyOS | ❌ | ❌ | ✅ |

---

## 2. Comparativa de diseño de arquitectura

### 2.1 Middlewares

| Middleware | Lite | Standard | Full |
|--------|:---:|:---:|:---:|
| CorsMiddleware (CORS) | ✅ | ✅ | ✅ |
| LocaleMiddleware (multi-idioma) | ✅ | ✅ | ✅ |
| HashidRequestMiddleware (decodificación de IDs) | ✅ | ✅ | ✅ |
| AuthMiddleware (autenticación JWT) | ✅ | ✅ | ✅ |
| RateLimitMiddleware (límite de peticiones) | ✅ | ✅ | ✅ |
| WafMiddleware básico (SQLi/XSS) | ✅ | ✅ | ✅ |
| WafMiddleware completo (8 categorías, 45+ reglas) | ❌ | ✅ | ✅ |
| AdminRoleMiddleware (RBAC) | ❌ | ✅ | ✅ |
| EncryptionMiddleware (AES-256-GCM) | ❌ | ✅ | ✅ |
| VersionMiddleware (versión de API) | ❌ | ✅ | ✅ |
| ClientPlatformMiddleware (identificación de plataforma) | ❌ | ✅ | ✅ |
| ConfirmationMiddleware (confirmación de contraseña) | ❌ | ✅ | ✅ |
| GeoBlockMiddleware (bloqueo geográfico) | ❌ | ✅ | ✅ |
| MaintenanceMiddleware (modo de mantenimiento) | ❌ | ✅ | ✅ |
| SupplierApiKeyMiddleware | ❌ | ❌ | ✅ |
| FeatureFlags | ❌ | ❌ | ✅ |
| RbacMiddleware | ❌ | ✅ | ✅ |

### 2.2 Arquitectura de datos

| Característica | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Clave primaria distribuida Snowflake | ✅ | ✅ | ✅ |
| Ofuscación de IDs con Hashids | ✅ | ✅ | ✅ |
| MySQL de base única | ✅ | ❌ | ❌ |
| MySQL maestro-esclavo con separación de lectura/escritura | ❌ | ✅ | ✅ |
| Base de auditoría independiente | ❌ | ✅ | ✅ |
| Cifrado de transporte AES-256-GCM | ❌ | ✅ | ✅ |
| Cifrado de campos AES-128-ECB | ❌ | ✅ | ✅ |
| Caché multinivel con Redis | ❌ | ✅ | ✅ |
| Búsqueda de texto completo con Elasticsearch | ✅ | ✅ | ✅ |
| Optimización de índices de base de datos (13) | ❌ | ✅ | ✅ |

### 2.3 Protección de seguridad

| Característica | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Detección de inyección SQL (2 reglas) | ✅ | ✅ | ✅ |
| Detección de XSS (3 reglas) | ✅ | ✅ | ✅ |
| Detección de inyección de comandos | ❌ | ✅ | ✅ |
| Detección de inclusión de archivos | ❌ | ✅ | ✅ |
| Detección de inyección en cabeceras HTTP | ❌ | ✅ | ✅ |
| Detección de SSRF | ❌ | ✅ | ✅ |
| Detección de inyección NoSQL | ❌ | ✅ | ✅ |
| Detección de redirección abierta | ❌ | ✅ | ✅ |
| Límite de tamaño del cuerpo de petición | ❌ | ✅ | ✅ |
| Lista blanca de Content-Type | ❌ | ✅ | ✅ |

### 2.4 Alta concurrencia

| Característica | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Multiproceso de webman | ✅ | ✅ | ✅ |
| Compresión gzip de Nginx | ❌ | ✅ | ✅ |
| Proxy buffering de Nginx | ❌ | ✅ | ✅ |
| limit_req/limit_conn de Nginx | ❌ | ✅ | ✅ |
| Capa de caché Redis | ❌ | ✅ | ✅ |
| Invalidación activa de caché | ❌ | ✅ | ✅ |
| Separación lectura/escritura de MySQL | ❌ | ✅ | ✅ |
| Índices compuestos de base de datos | ❌ | ✅ | ✅ |
| Push WebSocket | ❌ | ❌ | ✅ |

---

## 3. Despliegue y operación

| Característica | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Despliegue con Docker Compose | ✅ | ✅ | ✅ |
| Proxy inverso Nginx | ✅ | ✅ | ✅ |
| CI/CD (GitHub Actions) | ✅ | ✅ | ✅ |
| Pruebas PHPUnit | 95 tests | 295 tests | 295 tests |
| Tareas programadas (7) | ❌ | ✅ | ✅ |
| Procesamiento asíncrono con Redis Queue | ❌ | ✅ | ✅ |
| Comando de migraciones de base de datos | ✅ | ✅ | ✅ |
| Comando de copia de seguridad de base de datos | ❌ | ✅ | ✅ |
| Endpoint de chequeo de salud | ✅ | ✅ | ✅ |
| Endpoint de estado del servicio | ✅ | ✅ | ✅ |
| Monitorización de excepciones con Sentry | ❌ | ❌ | ✅ |
| Lanzamiento gradual con Feature Flags | ❌ | ❌ | ✅ |
| Pruebas de carga k6 | ❌ | ❌ | ✅ |

---

## 4. Cifras

| Métrica | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Endpoints de API | ~35 | ~130 | 200+ |
| Modelos de datos | 15 | 50+ | 70+ |
| Tablas de base de datos | 15 | 50+ | 60+ |
| Middlewares globales | 3 | 7 | 9 |
| Middlewares de ruta | 1 | 5 | 6 |
| Tareas programadas | 0 | 7 | 10 |
| Archivos de migración | 5 | 20 | 27 |
| Número de tests | 95 | 295 | 295 |
| Reglas WAF | 5 | 45+ | 45+ |
| Documentos | 2 | 6 | 8 |
| Documentación en línea hg/apidoc | ✅ | ✅ | ✅ |
| Endpoints de API GraphQL | ❌ | ❌ | ✅ |
| Métricas Prometheus | ❌ | ❌ | ✅ |
| Sistema de puntuación de proveedores | ❌ | ❌ | ✅ |
| Sistema de referidos Affiliate | ❌ | ❌ | ✅ |

---

## 5. Ruta de actualización

```
Lite (Simplificada)
  │
  │  + pagos + entrega + dominios + tickets + notificaciones
  │  + panel de administración completo + seguridad integral + optimización de alta concurrencia
  ▼
Standard (Estándar)
  │
  │  + sistema de proveedores + API externa + WebSocket
  │  + Sentry + Feature Flags + cliente Flutter
  ▼
Full (Completa)
```

**Compatibilidad de datos:** la estructura de base de datos de la versión Lite es compatible con las tablas principales de la versión Standard y se puede migrar y actualizar directamente. De Standard a Full el cambio es puramente incremental (se añaden tablas relacionadas con proveedores), sin necesidad de migración de datos.

---

## 6. Cómo obtenerla

| Versión | Forma de obtención |
|------|---------|
| **Lite (Simplificada)** | Código abierto en GitHub, licencia MIT |
| **Standard (Estándar)** | Licencia comercial, contactar con **erik@erik.xyz** |
| **Full (Completa)** | Licencia comercial, contactar con **erik@erik.xyz** |
