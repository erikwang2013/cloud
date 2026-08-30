# Cloud Platform — Plataforma global de comercio de recursos en la nube

## Idiomas (Languages)

| Language | Docs |
|----------|------|
| 简体中文 | [README.md](../../../README.md) |
| English | [README_EN.md](../../../README_EN.md) |
| English | [en docs](../../en/README.md) |
| 한국어 | [ko docs](../../ko/README.md) |
| Русский | [ru docs](../../ru/README.md) |
| Deutsch | [de docs](../../de/README.md) |
| Français | [fr docs](../../fr/README.md) |
| Español | [es docs](../../es/README.md) |
| Português | [pt docs](../../pt/README.md) |
| हिन्दी | [hi docs](../../hi/README.md) |
| العربية | [ar docs](../../ar/README.md) |
| বাংলা | [bn docs](../../bn/README.md) |
| Bahasa Indonesia | [id docs](../../id/README.md) |
| 日本語 | [ja docs](../../ja/README.md) |

<p align="center">
  <img src="docs/diagrams/c.svg" alt="Mascota del proyecto CloudPlatform" width="220">
</p>

Plataforma de comercio de recursos en la nube orientada a usuarios globales: compra y entrega automática de servidores (VM), direcciones IP, discos en la nube, dominios, certificados SSL, almacenamiento de objetos (S3), aceleración CDN y otros productos. Los servidores físicos propios se virtualizan y entregan mediante Proxmox VE, con soporte además para que proveedores externos se registren y vendan. Ofrece facturación por uso, distribución por recomendación, API GraphQL y observabilidad con Prometheus/Grafana.

## Pila tecnológica

| Capa | Tecnología |
|------|------|
| Framework de backend | PHP 8.2 + [webman](https://github.com/walkor/webman) (workerman) |
| Panel de administración | [webman-admin](https://www.workerman.net/plugin/1) (Layui) |
| ORM | Illuminate/Eloquent 10.x |
| Autenticación | JWT HS256 ([erikwang2013/jwt-webman](https://github.com/erikwang2013/jwt-webman)) |
| Clave primaria distribuida | ID de copo de nieve Snowflake ([erikwang2013/snowflake-php](https://github.com/erikwang2013/snowflake-php)) |
| Ofuscación de ID | Hashids ([erikwang2013/hashids](https://github.com/erikwang2013/hashids)) |
| Cifrado de transporte | AES-256-GCM ([erikwang2013/encryption](https://github.com/erikwang2013/encryption)) |
| Cifrado de campos | AES-256-CBC ([erikwang2013/encryptable](https://github.com/erikwang2013/encryptable)) |
| Búsqueda de texto completo | Elasticsearch ([erikwang2013/webman-scout](https://github.com/erikwang2013/webman-scout)) |
| Banderas de países | Emoji Unicode de banderas ([erikwang2013/season](https://github.com/erikwang2013/season)) |
| CAPTCHA de clic | Click CAPTCHA ([erikwang2013/poster-php](https://github.com/erikwang2013/poster-php)) |
| Protección de seguridad | 31 tipos de detección de ataques ([erikwang2013/security-php](https://github.com/erikwang2013/security-php)) |
| Exportación de tablas | PhpSpreadsheet ^2.0 |
| SDK de pagos | Stripe PHP ^15.0 |
| SDK de SMS | Twilio PHP ^8.0 |
| SDK de notificaciones push | Firebase PHP ^7.0 |
| Colas | webman redis-queue |
| Base de datos | MySQL 8.0 (conexión dual: base principal + base de auditoría) |
| Motor de búsqueda | Elasticsearch 8.x |
| Virtualización | Proxmox VE (canal gRPC de Rust kvm-server, registro con e-cat/etcd) |
| Clientes | Flutter (iOS/Android/Web/Linux/macOS/Windows) + HarmonyOS ArkTS |
| GraphQL | webonyx/graphql-php ^15.0 |
| Almacenamiento de objetos | AWS S3 SDK PHP ^3.300 |
| Observabilidad | Prometheus + Grafana (paneles preconfigurados) |
| Internacionalización | i18n 7 idiomas (chino/inglés/japonés/coreano/alemán/francés/español) |
| Despliegue | Docker Compose, inicio con un solo clic |

## Arquitectura del sistema

![Arquitectura del sistema](docs/diagrams/system-architecture-zh.svg)

## Flujo de negocio principal

Flujo de negocio completo de extremo a extremo, desde el registro del usuario hasta la entrega del recurso, incluyendo selección, pedido, pago, entrega automática, gestión postventa y el ciclo de renovación.

![Flujo de negocio principal](docs/diagrams/business-flowchart-zh.svg)

## Liquidación multimoneda

El sistema soporta de forma nativa precios, pagos y liquidaciones en múltiples monedas, cubriendo todo el recorrido desde la configuración de moneda del usuario, los precios por región, las instantáneas de tipo de cambio, hasta el cobro del pago, el abono en saldo y la liquidación con proveedores.

![Diagrama de flujo de liquidación multimoneda](docs/diagrams/currency-settlement-zh.svg)

**1. Cuentas de saldo multimoneda**

`user_balances` registra por moneda según `(user_id, currency)` (índice único `uk_user_currency`). Al registrarse se crean por defecto cuentas en USD y CNY; el saldo y el saldo congelado se gestionan de forma independiente por moneda, y se pueden ampliar a cualquier moneda soportada por Stripe.

**2. Precios regionales multimoneda**

`product_regions` permite fijar precios de un mismo SKU en múltiples monedas para la misma región (índice único `uk_sku_region_currency`). El frontend muestra el precio según la moneda preferida del usuario; al hacer el pedido, `OrderService` toma el precio exacto según `(sku_id, region_id, currency)`.

**3. Sistema de tipos de cambio**

La tarea programada `ExchangeRateSync` sincroniza los tipos de cambio desde exchangerate-api y los escribe en Redis (caché con TTL de 30 minutos). Cada pedido registra la instantánea `exchange_rate` del momento de la compra, garantizando la trazabilidad de liquidaciones posteriores.

**4. Pagos multimoneda**

`payment_channels.currency_support` declara la lista blanca de monedas que soporta cada canal de pago; `PaymentRouter` filtra dinámicamente los canales disponibles según moneda / rango de importes / regiones visibles. Stripe PaymentIntent cobra directamente en la moneda del pedido, con manejo integrado de decimales para 16 monedas sin decimales (JPY / KRW / VND, etc.), y el callback de Webhook verifica la coherencia entre importe y moneda.

**5. Liquidación y reportes**

Las transacciones de pago (`payment_transactions`), las liquidaciones a proveedores (`supplier_settlements`) y los reportes de ingresos conservan los campos de moneda y tipo de cambio, y se agregan por moneda.

## Resumen de módulos funcionales

El sistema se organiza en una arquitectura de cuatro capas: capa de cliente (acceso desde 6 plataformas), capa de pasarela API (12 middlewares), capa de servicios de negocio (20+ módulos funcionales) y capa de infraestructura (8 componentes principales).

![Resumen de módulos funcionales](docs/diagrams/module-overview-zh.svg)

## Ciclo de vida del recurso

El recurso atraviesa 6 estados desde su creación hasta su terminación, impulsado por 8 eventos del ciclo de vida, con soporte para entrega automática, suspensión y recuperación, avisos de vencimiento y limpieza al destruir.

![Ciclo de vida del recurso](docs/diagrams/resource-lifecycle-zh.svg)

## Navegación de documentación

| Documento | Descripción |
|------|------|
| [Documento de diseño de arquitectura](docs/architecture.md) | Arquitectura del sistema, relaciones entre componentes, pipeline de middlewares, capas de seguridad, arquitectura de datos, topología de despliegue |
| [Documento de diseño de funciones](docs/features.md) | Diseño funcional detallado de 21 módulos, con diagramas de flujo, modelos de datos y explicaciones de interacción |
| [Documentación de la API](docs/api-reference.md) | Referencia completa de 200+ endpoints, agrupados por módulo, con ejemplos de solicitud/respuesta y códigos de error |
| [Documentación de API en línea (service)](http://localhost:8787/apidoc) | Generada automáticamente con hg/apidoc, agrupada por funcionalidad, con depuración en línea |
| [Documentación de API en línea (admin)](http://localhost:8788/apidoc) | Generada automáticamente con hg/apidoc, 54 controladores en 13 grupos funcionales |
| [Diseño del panel de administración](docs/admin-design.md) | Arquitectura del panel Admin, integración de paquetes, permisos ACL, suite de pruebas |
| [Documentación de la API de proveedores](docs/supplier-api.md) | Referencia de la API de proveedores (interna + externa), ejemplos de SDK |
| [Lista de verificación de despliegue](docs/deployment.md) | Configuración del servidor, variables de entorno, Nginx, HTTPS, tareas programadas |
| [Informe de revisión](docs/review-report-2026-08-04.md) | Informe de revisión de expansión del ecosistema, con estadísticas, seguimiento de incidencias y sugerencias de extensión |
| [Comparativa de ediciones](docs/editions.md) | Comparativa de funciones, diseño y arquitectura entre ediciones simplificada/estándar/completa |

## Estructura de directorios

```
cloud-php/
├── .claude/                    # Configuración de Claude Code (settings / skills)
├── .github/workflows/          # Pipeline CI/CD (comprobación de sintaxis + PHPUnit dual)
├── admin/                      # Panel de administración (instancia webman independiente)
│   ├── app/                    # Código fuente de plugins (PSR-4: app\)
│   │   ├── bootstrap/          # Arranque de procesos (Snowflake / Encryptable / Encryption)
│   │   ├── command/            # Comandos de consola (Migrate / Rollback / Status)
│   │   ├── common/             # Clases de utilidad (Auth / Tree / Layui / Util / ExcelExport / Migration)
│   │   ├── controller/         # 54 archivos de controladores (clases base Base / Crud + CRUD de negocio)
│   │   ├── exception/          # Manejo de excepciones
│   │   ├── middleware/          # Middleware de control de acceso (WafMiddleware + AccessControl)
│   │   ├── model/              # 46 modelos Eloquent (clase base Base con PK Snowflake + Encryptable)
│   │   ├── view/               # Plantillas de vistas (panel de administración Layui)
│   │   └── functions.php       # Funciones auxiliares globales (hashids / encrypt / decrypt)
│   ├── api/                    # Interfaz externa (PSR-4: plugin\admin\api)
│   │   ├── Auth.php            # Interfaz de autenticación
│   │   ├── Menu.php            # Interfaz de menú
│   │   ├── Install.php         # Interfaz de instalación
│   │   └── Middleware.php      # Interfaz de middleware
│   ├── config/                 # Configuración de la aplicación
│   │   ├── plugin/erikwang2013/ # Configuración de 6 paquetes erikwang2013
│   │   │   ├── snowflake-php/  # Generación de ID snowflake
│   │   │   ├── hashids/        # Ofuscación de ID
│   │   │   ├── encryptable/    # Cifrado a nivel de campo
│   │   │   ├── encryption/     # Cifrado de transporte
│   │   │   ├── webman-scout/   # Sincronización con Elasticsearch
│   │   │   └── season/         # Banderas de países
│   │   ├── route.php           # Definición de rutas
│   │   ├── middleware.php       # Configuración de middlewares
│   │   ├── database.php        # Conexión a la base de datos
│   │   └── ...                 # 18 archivos de configuración
│   ├── database/migrations/    # Archivos de migración de base de datos
│   ├── tests/                  # Pruebas unitarias (PHPUnit 11, 286 tests / 962 assertions)
│   │   ├── HashidsTest.php     # Codificación/decodificación hashids (21 tests)
│   │   ├── BaseJsonTest.php    # Codificación de ID en Base::json() (13 tests)
│   │   ├── CrudHashidsTest.php # Decodificación de entrada Crud (14 tests)
│   │   ├── TreeTest.php        # Estructura de árbol (19 tests)
│   │   ├── AccessControlMiddlewareTest.php # Control de acceso RBAC
│   │   ├── AdminControllersTest.php        # Regresión de controladores
│   │   └── support/            # Clases auxiliares de prueba
│   ├── public/                 # Raíz de documentos (recursos estáticos)
│   ├── vendor/                 # Dependencias Composer
│   ├── .env.example            # Plantilla de variables de entorno
│   ├── composer.json           # Declaración de dependencias
│   ├── generate.php            # Generador de código
│   ├── phpunit.xml             # Configuración de PHPUnit
│   └── start.php               # Punto de entrada
├── service/                    # Servicio backend (instancia webman independiente)
│   ├── app/                    # Módulos de negocio (PSR-4: App\), cada módulo con capas Controller / Model / Service, etc.
│   │   ├── admin/controller/   # API del panel de administración (15 controladores: Dashboard / User / Product / Order / Payment / Supplier / Coupon / Invoice / Domain / Webhook, etc.)
│   │   ├── affiliate/          # Comisiones de afiliados / comisiones por recomendación (Controller / Listener / Model / Service)
│   │   ├── billing/            # Facturación por uso / facturas (Cron / Service)
│   │   ├── captcha/controller/ # CAPTCHA de clic
│   │   ├── cdn/                # Alojamiento de recursos CDN (Controller / Model / Provider / Service)
│   │   ├── command/            # Comandos de consola (Migrate / Rollback / Status / DbBackup)
│   │   ├── controller/         # Controladores comunes (Health / Status / Help / Upload)
│   │   ├── cron/               # Tareas programadas (planificador CronRunner + ExchangeRateSync / PaymentReconcile / SupplierSettlement / ExpirationCheck / SslCertificateCheck)
│   │   ├── domain/             # Registro de dominios / gestión de DNS (Controller / Model / Service)
│   │   ├── graphql/            # API GraphQL (Mutation / Query / Schema)
│   │   ├── grpc/               # Cliente gRPC de kvm-server + registro etcd (KvmClient / EtcdRegistry)
│   │   ├── model/              # Modelos comunes (HelpArticle / Role / Permission)
│   │   ├── monitor/            # Monitorización de recursos / alertas (Controller / Cron / Model / Service)
│   │   ├── notification/       # Notificaciones de mensajes (Controller / Model / Queue / Service)
│   │   ├── order/              # Carrito / pedidos / cupones / facturas (Controller / Model / Service)
│   │   ├── payment/            # Enrutamiento de pagos / canal Stripe (Controller / Event / Model / Service)
│   │   ├── product/            # Productos / SKU / precios por región / reseñas (Controller / Model / Service)
│   │   ├── provisioning/       # Motor de entrega de recursos (Controller / Event / Listener / Model / Provider / Queue / Service)
│   │   ├── report/             # Reportes de ingresos / proveedores / regiones (Controller / Service)
│   │   ├── ssl/                # Emisión / gestión de certificados SSL (Controller / Model / Service)
│   │   ├── storage/            # Recursos de almacenamiento de objetos (Controller / Model / Provider / Service)
│   │   ├── supplier/           # Registro de proveedores / liquidación / retiros + API externa (Controller / Model / Service)
│   │   ├── ticket/             # Sistema de tickets (Controller / Event / Listener / Model / Service)
│   │   ├── user/               # Usuarios / autenticación / KYC / saldo / direcciones (Controller / Model / Service)
│   │   ├── webhook/            # Cola de mensajes Webhook (Queue)
│   │   └── websocket/          # Servidor WebSocket + listeners de eventos
│   ├── common/                 # Librería común (PSR-4: Common\)
│   │   ├── auth/middleware/     # AuthMiddleware / AdminRoleMiddleware / RbacMiddleware / RoleMiddleware / SupplierApiKeyMiddleware
│   │   ├── captcha/            # Servicio de CAPTCHA de clic
│   │   ├── confirmation/       # Middleware de doble confirmación (revisión de contraseña)
│   │   ├── encryption/middleware/ # Middleware de cifrado de transporte AES-256-GCM
│   │   ├── hashid/middleware/   # Middleware de decodificación automática de solicitudes Hashids + servicio de codificación
│   │   ├── helper/             # Formato de Response (codificación hashid automática)
│   │   ├── http/               # Utilidades de cliente HTTP (ApiRequest)
│   │   ├── i18n/middleware/     # Middleware de idiomas (Locale)
│   │   ├── security/           # CORS / WAF / límite de frecuencia / bloqueo geográfico / modo mantenimiento / registro de auditoría
│   │   ├── snowflake/          # Servicio de generación de ID snowflake / Trait Eloquent HasSnowflakeId
│   │   ├── version/middleware/  # Middleware de versión de API (validación del encabezado X-Api-Version)
│   │   ├── clientplatform/middleware/  # Middleware de plataforma de cliente (identificación del encabezado X-Client-Platform)
│   │   ├── feature/            # Servicio de Feature Flags (interruptores de funciones)
│   │   └── webhook/            # Distribuidor de eventos Webhook
│   ├── config/                 # 17 archivos de configuración (route / middleware / database / redis / cron / auth / security / i18n / ...)
│   │   └── plugin/             # Configuración de plugins
│   │       ├── erikwang2013/   # encryptable / hashids / jwt / poster / season / webman-scout
│   │       └── webman/         # event / redis-queue
│   ├── database/migrations/    # Archivos de migración de base de datos (37 migraciones)
│   ├── i18n/                   # Recursos de idiomas (en-US / zh-CN)
│   ├── support/                # Arranque Bootstrap (Eloquent / Redis / Event / cifrado / snowflake / Hashids / Scout / MigrationRunner)
│   ├── tests/                  # Pruebas unitarias (PHPUnit 10, 672 tests / 1632 assertions)
│   │   ├── admin/              # ImportExport / SupplierWithdrawApprove
│   │   ├── affiliate/          # AffiliateService
│   │   ├── auth/               # JwtAuth / RbacSeed / Rbac
│   │   ├── billing/            # MeterCollector / UsageAggregator / SuspendCheck
│   │   ├── captcha/            # CaptchaService
│   │   ├── cdn/                # ResourceCdn
│   │   ├── clientplatform/     # ClientPlatformMiddleware
│   │   ├── common/             # Response / Hashid / Snowflake / Validator / LogSanitizer / Totp / ApiRequest
│   │   ├── confirmation/       # ConfirmationMiddleware
│   │   ├── cron/               # SupplierSettlement
│   │   ├── domain/             # DomainService / DomainTransfer
│   │   ├── graphql/            # Schema
│   │   ├── grpc/               # KvmClient / EtcdRegistry
│   │   ├── monitor/            # AlertEngine
│   │   ├── notification/       # NotificationDispatcher
│   │   ├── order/              # Coupon / Invoice
│   │   ├── payment/            # StripeChannel / PaymentRouter
│   │   ├── product/            # ProductService / Search / ReviewStatus
│   │   ├── provisioning/       # ProviderFactory / RetryLogic
│   │   ├── report/             # ReportService
│   │   ├── security/           # RateLimit / Maintenance / UploadSecurity
│   │   ├── ssl/                # SslCertificate
│   │   ├── storage/            # StorageBucket
│   │   ├── supplier/           # SupplierService / Settlement / Rating / Webhook
│   │   ├── ticket/             # TicketUpdatedWiring
│   │   ├── user/               # AddressController
│   │   ├── version/            # VersionMiddleware
│   │   ├── webhook/            # WebhookDispatcher / WebhookE2E
│   │   ├── websocket/          # WebSocketAuth / EventsWiring
│   │   ├── support/            # RequestMock
│   │   ├── bootstrap.php       # Arranque de pruebas
│   │   └── TestCase.php        # Clase base de pruebas
│   ├── runtime/                # Archivos de ejecución (logs / caché)
│   ├── vendor/                 # Dependencias Composer
│   ├── .env.example            # Plantilla de variables de entorno
│   ├── .env                    # Variables de entorno locales (gitignore)
│   ├── composer.json           # Declaración de dependencias
│   ├── phpunit.xml             # Configuración de PHPUnit
│   └── start.php               # Punto de entrada
├── apps/
│   ├── flutter/                # Cliente Flutter (iOS / macOS / Windows / Linux / Web)
│   │   ├── lib/                # Código fuente Dart (core / features)
│   │   ├── ios/                # Proyecto iOS
│   │   ├── macos/              # Proyecto macOS
│   │   ├── windows/            # Proyecto Windows
│   │   ├── linux/              # Proyecto Linux
│   │   ├── web/                # Proyecto Web
│   │   ├── test/               # Pruebas Flutter
│   │   ├── pubspec.yaml        # Declaración de dependencias
│   │   └── analysis_options.yaml # Configuración de análisis estático Dart
│   └── harmonyos/              # Esqueleto del cliente HarmonyOS
│       └── entry/src/          # Código fuente ArkTS
├── docker/                     # Despliegue Docker
│   ├── Dockerfile              # Imagen PHP 8.2
│   ├── docker-compose.yml      # Orquestación de servicios
│   ├── nginx.conf              # Configuración de Nginx
│   └── supervisor.conf         # Supervisión de procesos Supervisor
├── infrastructure/             # Infraestructura Rust (workspace e-cat)
│   ├── kvm-server/             # Servicio propio en la nube: servicio gRPC de aprovisionamiento de VM (:50051, registro etcd)
│   │   ├── src/                # main / grpc / driver (driver simulado, libvirt en Phase 2)
│   │   ├── tests/              # Pruebas de integración
│   │   └── Cargo.toml          # Declaración de miembro del workspace e-cat
│   └── ecat-*/                 # Crates de infraestructura e-cat (transport-grpc / registry-etcd / protos / config / data, etc.)
├── docs/                       # Documentación
│   ├── admin-design.md         # Documento de diseño del panel de administración
│   ├── supplier-api.md         # Documentación de la API de proveedores
│   ├── deployment.md           # Lista de verificación de despliegue
│   ├── api-test.sh             # Script de prueba de humo de la API
│   ├── database.sql            # DDL de la base de datos
│   ├── alipay.png / weixinpay.png  # Códigos QR de donación
│   ├── diagrams/               # 18 diagramas de arquitectura SVG (arquitectura del sistema / pipeline de seguridad / diagrama ER / flujo de negocio / liquidación multimoneda, etc.)
│   ├── test-reports/           # Informes de pruebas (PHPUnit / Rust / API / UI + capturas de pantalla)
│   └── superpowers/            # Especificaciones de diseño y planes de implementación
│       ├── specs/              # Documentos de especificación del diseño del sistema
│       └── plans/              # Planes de implementación por fases Phase 0~3
├── scripts/                     # Scripts de operación (push-release.sh reglas de publicación: incremento de versión + tag)
├── tests/k6/                    # Scripts de pruebas de carga k6 (humo/producto/concurrencia)
├── install.php                 # Punto de entrada del asistente de instalación con un clic
├── install/                    # Página del asistente de instalación
│   └── index.php               # Aplicación web del asistente
├── install.sql                 # DDL unificado de la base de datos (46 tablas)
├── .gitignore
├── README.md                   # Descripción del proyecto (chino)
└── README_EN.md                # Descripción del proyecto (inglés)
```

## Inicio rápido

### Requisitos del entorno

- PHP 8.2+ (ext-json, ext-pdo, ext-pdo_mysql, ext-redis, ext-openssl)
- MySQL 8.0
- Redis 7

### Instalación con un clic (recomendada)

El proyecto incluye un asistente de instalación web que permite completar toda la configuración en el navegador:

```bash
# 1. Instalar dependencias
cd service && composer install && cd ../admin && composer install && cd ..

# 2. Iniciar el asistente de instalación
php install.php
# Abrir el navegador y acceder a http://localhost:8888

# 3. Seguir las indicaciones del asistente:
#    - Comprobación del entorno
#    - Configuración de la base de datos (host, puerto, nombre de base, usuario, contraseña)
#    - Configuración de la cuenta de administrador del panel (usuario, contraseña, correo)
#    - Ejecución de la instalación con un clic (creación de tablas + escritura de configuración)
```

Tras la instalación, el asistente automáticamente:
- Crea las 46 tablas de la base de datos (tablas de administración wa_* + tablas de negocio sin prefijo)
- Crea el rol y la cuenta de superadministrador
- Genera los archivos de configuración `service/.env` y `admin/.env` (incluye claves JWT/cifrado generadas automáticamente)

### Instalación manual

```bash
cd service

# 1. Instalar dependencias
composer install

# 2. Configurar las variables de entorno
cp .env.example .env
# Editar .env para completar la contraseña de la base de datos, la clave JWT, la clave de cifrado, etc.
# Generación de ENCRYPTION_MASTER_KEY: openssl rand -base64 32
# Generación de ENCRYPTION_KEY: echo -n "$(openssl rand -base64 16)" | base64 -w0
# Generación de JWT_SECRET_KEY: openssl rand -base64 32

# 3. Crear la base de datos e importar
mysql -u root -p -e "CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p cloud_platform < ../install.sql

# 4. Iniciar el servicio (modo desarrollo)
php start.php start
# Acceder a http://localhost:8787
```

### Despliegue con Docker

```bash
# Desde la raíz del proyecto
cp service/.env.example .env
# Editar .env para completar las claves

docker compose -f docker/docker-compose.yml up -d
# API: http://localhost
```

### Panel de administración

```bash
cd admin

# 1. Instalar dependencias
composer install

# 2. Configurar las variables de entorno
cp .env.example .env
# Si se usó el asistente de instalación con un clic, este archivo ya se generó automáticamente

# 3. Iniciar el servicio (modo desarrollo)
php start.php start
# Acceder a http://localhost:8787/app/admin
```

### Modo proceso daemon

```bash
php start.php start -d          # Iniciar
php start.php status            # Ver estado
php start.php restart           # Reiniciar
php start.php stop              # Detener
```

## Guía de uso

### Iniciar sesión

- **Portal de usuario**: acceda al servicio API (por defecto `http://localhost:8787`), regístrese e inicie sesión. Se admiten OAuth de Google / Apple y autenticación en dos pasos TOTP
- **Panel de administración**: abra `http://localhost:8787/app/admin` en el navegador (el panel es una instancia independiente, puerto 8788) e inicie sesión con la cuenta de administrador creada por el asistente de instalación

### Funciones habituales del panel

- **Panel**: estadísticas de pedidos / ingresos / nuevos usuarios / recursos activos del día, tendencia de ingresos a 30 días, exportación a PDF
- **Centro de informes**: informes de pedidos, ranking de productos, estadísticas por canal, crecimiento de usuarios, exportación a Excel
- **Gestión diaria**: usuarios / productos / pedidos / proveedores / tickets / dominios / CDN, revisión KYC, reembolsos, aprobación de liquidaciones y retiros
- **Configuración del sistema**: canales de pago, cuentas CDN, webhooks, plantillas de notificación, artículos de ayuda, registros de auditoría

### Compilación de clientes

- **Cliente Flutter** (`apps/flutter/`): iOS / Android / Web / Linux / macOS / Windows. `flutter pub get` para dependencias, `flutter run` para depurar, `flutter build apk` / `flutter build ios` / `flutter build web` para empaquetar
- **Cliente HarmonyOS** (`apps/harmonyos/`): aplicación nativa ArkTS — abra el proyecto `entry` con DevEco Studio para compilar y ejecutar

## Resumen de la API

Las interfaces se agrupan por módulo, con ejemplos de solicitud/respuesta y códigos de error: [Resumen de la API](docs/api-overview.md) (selección) · [Documentación de la API](docs/api-reference.md) (referencia completa de 200+ endpoints) · [Depuración en línea](http://localhost:8787/apidoc)

## Arquitectura del panel de administración

### Integración técnica

El panel de administración es una instancia webman independiente que integra 7 paquetes de erikwang2013:

| Paquete | Uso | Implementación |
|---|------|---------|
| snowflake-php | Clave primaria distribuida de 64 bits | Generación automática en el evento `Base::boot()` creating |
| hashids | Ofuscación de ID de API | Codificación de respuestas en `Base::json()`, decodificación de solicitudes en `Crud::selectInput/updateInput/deleteInput` |
| encryptable | Cifrado de campos de base de datos | Cast `Encryptable` de Eloquent, cifrado/descifrado transparente en Admin (password/email/mobile) y User (6 campos) |
| encryption | Cifrado de transporte de API | Funciones auxiliares reservadas `encrypt_data()`/`decrypt_data()` |
| webman-scout | Búsqueda de texto completo en ES | Trait `Searchable` en el modelo User, sincronización automática de índices |
| season | Emoji de banderas de países | Función auxiliar global `country_season_flag()` |
| poster-php | CAPTCHA de clic | Bootstrap `CaptchaPlugin`, funciones globales `captcha_create()`/`captcha_verify()` |

### Capas de seguridad

```
solicitud → Decodificación Hashids (Crud::selectInput/updateInput/deleteInput)
  → Autenticación ACL (api/Auth.php, noNeedLogin/noNeedAuth en controladores)
  → Procesamiento de negocio (CRUD / eventos de modelo)
  → Cifrado de campos Encryptable (casts set de Eloquent)
  → Escritura en base de datos
respuesta ← Codificación Hashids (Base::json → hashids_encode_ids)

Inicio de sesión/registro: verificación Captcha → Auth → procesamiento de negocio
```

### Flujo de datos

- **Ruta de escritura**: ID de solicitud (hashid) → decodificado a int → operación CRUD → Snowflake genera nuevo ID → Encryptable cifra campos sensibles → DB
- **Ruta de lectura**: DB → descifrado Encryptable → codificación Hashids de ID → respuesta JSON

### Cobertura de pruebas

```
phpunit.xml (PHPUnit 11, 286 tests / 962 assertions)
├── HashidsTest              (21 tests) encode/decode/encode_ids
├── BaseJsonTest             (13 tests) codificación Base::json/success/fail
├── CrudHashidsTest          (14 tests) decodificación de entrada Crud (select/update/delete)
├── TreeTest                 (19 tests) estructura de árbol / descendientes / ancestros / nodos huérfanos
├── AccessControlMiddlewareTest (7 tests) 401 sin sesión / página 403 / paso permitido
├── AdminControllersTest     (data provider) ensamblaje de 48 controladores / superficie CRUD / rutas de vista GET
├── UtilTest                 (17 tests) contraseña / tiempo / bytes / filtrado de entrada / atributos de control
├── DictTest                 (5 tests) conversión nombre de diccionario↔option / save/get/delete
├── ExcelExportTest          (4 tests) encabezados / aplanado JSON / números de fila / celdas vacías
└── LayuiTest                (5 tests) input / inputNumber / escape de label / switch / html
```

## Filosofía de diseño

### 1. Monolito modular

Los módulos se dividen verticalmente por dominio de negocio (User / Product / Order / Payment / Provisioning / Ticket / Notification, etc.), y cada módulo sigue la capa MVC internamente:

- **Controller** — capa HTTP: validación de parámetros, llamada a Service, retorno de Response
- **Service** — lógica de negocio, sin dependencias HTTP, reutilizable por Controller y Queue Worker
- **Model** — modelos de datos Eloquent, definen relaciones y ámbitos de consulta

Los módulos se desacoplan mediante **eventos** e **interfaces**, sin llamar directamente a los Service de otros módulos. Por ejemplo: pago completado → evento `OrderPaid` → `ProvisioningService` activa automáticamente el recurso; creación de Ticket → evento `TicketCreated` → asignación automática de atención al cliente.

### 2. Entrega dirigida por eventos

```
El usuario hace el pedido → pago exitoso → evento OrderPaid
  → ProvisioningService.handleOrderPaid()
    → crea un ProvisionTask por cada OrderItem (status=pending)
    → consumidor de Redis Queue: ProvisionWorker
      → ProviderFactory.create(task) resuelve el Provider
      → ProxmoxProvider.create()
        → HostSelector elige el servidor físico más desocupado
        → ProxmoxApi crea la VM / monta discos / asigna IP
          (el servicio gRPC de aprovisionamiento en Rust kvm-server ya está
          integrado: descubrimiento con registro e-cat/etcd,
          KvmClient conectado en PHP; driver simulado, el driver real
          libvirt es Phase 2)
        → crea los registros Resource / Disk
      → actualiza el estado de Order a completed
```

Si la entrega falla, se reintenta automáticamente con una estrategia de retroceso: 1min → 5min → 15min → 1h → 6h → 24h; después de 6 intentos se marca como fallida y se dispara una alerta.

### 3. Arquitectura de plugins Provider

La entrega de recursos se abstrae mediante `ProviderInterface`; diferentes infraestructuras implementan la misma interfaz:

```
ProviderInterface
  ├── ProxmoxProvider    (Proxmox VE propio)
  ├── AliyunProvider     (futuro: Alibaba Cloud)
  ├── AwsProvider        (futuro: AWS EC2)
  └── DomainProvider     (futuro: registradores de dominios)
```

`ProviderFactory` registra funciones de fábrica con la clave `productType:provider` y resuelve dinámicamente según el ProvisionTask en tiempo de ejecución.

### 4. Enrutamiento de múltiples pagos

`PaymentRouter` devuelve dinámicamente los canales de pago disponibles según importe / moneda / región del pedido; el frontend puede cambiar de canal y lanzar el pago. Los canales de pago se configuran mediante la tabla `PaymentChannel` (tarifas, importes mín/máx, regiones visibles), sin necesidad de cambiar código para activarlos o desactivarlos.

### 5. Arquitectura de seguridad

Cadena de middlewares global: `Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → RateLimit → Locale → Metrics → HashidRequest → Maintenance → [ruta: Encryption → Captcha → Auth → Confirmation]`

![Pipeline de middlewares de seguridad](docs/diagrams/security-middleware-zh.svg)

- **CORS** — manejo de encabezados de solicitudes entre dominios (modo lista blanca, soporta comodines *.example.com)
- **SecurityHeaders** — encabezados de respuesta de seguridad (HSTS / X-Frame-Options / X-Content-Type-Options / Referrer-Policy / Permissions-Policy)
- **GeoBlock** — bloqueo geográfico (bloquea países concretos según GEO_BLOCKED_COUNTRIES, basado en GeoIP2)
- **WAF** — 45+ reglas en 8 categorías (inyección SQL/XSS/inyección de comandos/inclusión de archivos/inyección de cabeceras/SSRF/inyección NoSQL/redirección abierta) + límite de tamaño de solicitud + validación de Content-Type (escaneo de valores inyectados en query/body/UA, en path solo se comprueba el path traversal)
- **Security Plugin** — 31 tipos de detección de ataques (XSS/inyección SQL/inyección de comandos/SSRF/deserialización/ataques JWT/ataques de Host header/smuggling de solicitudes/inyección GraphQL/filtración de datos sensibles, etc.), lista blanca de IP + lista negra de IP con bloqueo automático
- **Locale** — analiza Accept-Language y establece el idioma
- **HashidRequest** — decodifica automáticamente las cadenas hashid de las solicitudes a IDs enteros reales
- **Version** — valida el encabezado `X-Api-Version`; si falta, el valor por defecto es `v1`; las versiones no soportadas devuelven `400`
- **ClientPlatform** — valida el encabezado `X-Client-Platform` e identifica la plataforma del sistema operativo del cliente (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web)
- **Encryption** — cifrado de transporte AES-256-GCM (interfaces de autenticación y panel de administración), protege contra escuchas y manipulación en tránsito
- **Captcha** — CAPTCHA de clic, verificación antes de iniciar sesión/registrarse (dibujo con GD + almacenamiento en Redis, clave de un solo uso, validez de 300s, límite de 3 intentos)
- **Auth** — autenticación JWT HS256, Access Token de 15 minutos, Refresh Token de 30 días, lista negra en Redis
- **Confirmation** — las operaciones sensibles (pago/eliminación/reembolso/aprobación, etc.) requieren reintroducir la contraseña; 5 fallos bloquean 15 minutos
- **Límite de frecuencia** — por defecto 60/min, inicio de sesión 5/min, registro 3/min, pago 10/min
- **Registro de auditoría** — todas las operaciones sensibles se escriben en una base de auditoría independiente

### 6. Seguridad de datos

**Estrategia de cifrado por capas:**

| Capa | Tecnología | Descripción |
|------|------|------|
| Transporte | AES-256-GCM | Cifrado del cuerpo de solicitudes/respuestas de la API, cifrado autenticado GCM contra manipulación |
| Campos | AES-256-CBC | Cifrado/descifrado automático de campos sensibles del modelo, IV aleatorio CBC sin filtrar patrones de valores |
| Clave primaria | Hashids | Ofuscación de IDs externos en cadenas de 12 caracteres, oculta el volumen real de datos |

**Cifrado de campos sensibles:** 14 campos de 7 modelos usan `Encryptable::class` para cifrado/descifrado automático — `User(email, phone, password_hash)`, `UserKyc(id_number, real_name)`, `UserAddress(phone, address)`, `Supplier(contact_name, phone, email)`, `HostMachine(api_token)`, `PaymentChannel(api_key, webhook_secret)`, `RefreshToken(token_hash, device_fingerprint)`.

**Gestión de claves:** el cifrado de transporte y el de campos usan claves independientes distintas (`ENCRYPTION_MASTER_KEY` vs `ENCRYPTION_KEY`), con soporte de lista de claves anteriores (`ENCRYPTION_PREVIOUS_KEYS`) para rotación de claves sin tiempo de inactividad.

### 7. Generación de IDs distribuidos

Se usa el algoritmo Snowflake de Twitter para generar IDs únicos globales de 64 bits: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`. Los 46 modelos Eloquent generan automáticamente IDs snowflake en el evento `creating`, sin dependencia de autoincremento en la base de datos, con soporte nativo de sharding.

### 8. Internacionalización (i18n)

**Análisis automático mediante middleware global:**
- `LocaleMiddleware` lee el encabezado `Accept-Language` y establece automáticamente el idioma actual
- Soporte de fallback de idioma: idioma no soportado → `fallback_locale` (en-US)

**Traducción de textos estáticos:**
- `I18n::trans('auth.login_success')` → `登录成功` / `Login successful`
- Archivos de traducción: `i18n/{locale}/messages.php`, 120 entradas que cubren los 15 módulos
- Soporte de sustitución de parámetros: `I18n::trans('validation.required', ['field' => '邮箱'])`

**Campos JSON multilingües:**
- El nombre/descripción del producto se almacena como `{"zh-CN":"云服务器","en-US":"Cloud Server"}`
- `I18n::translateField($json)` selecciona automáticamente el valor según el idioma actual
- Las plantillas de notificación también soportan varios idiomas y se envían según el idioma preferido del usuario

### 9. Búsqueda de texto completo

Los 4 modelos Producto, Usuario, Pedido y Ticket se conectan a la búsqueda mediante el Trait `Erikwang2013\WebmanScout\Searchable`. El driver por defecto es `database` (escritura no-op, la búsqueda recurre a SQL LIKE, sin dependencia de ES); al configurar el driver de Elasticsearch, los índices se sincronizan automáticamente, con soporte de:

- **Segmentación multilingüe** — IK Analyzer (ik_max_word / ik_smart)
- **Búsqueda de texto completo en chino** — nombre y descripción de productos, títulos de tickets
- **Filtrado preciso** — por estado, categoría, rango de precios, rango de tiempo
- **Sincronización por lotes** — `php webman scout:import "App\Product\Model\Product"`
- **Ejemplo de búsqueda** — `Product::search('VPS服务器')->where('status', 'published')->get()`

### 10. Banderas de países

Soporte de emoji de banderas de todos los países mediante `erikwang2013/season`:

- `country_season_flag('CN')` → 🇨🇳, `country_season_flag('JP')` → 🇯🇵
- Reconoce automáticamente los hemisferios norte/sur y devuelve la estación correspondiente (chino e inglés)
- Nombres de estaciones localizados en 30+ idiomas
- Se puede llamar directamente en la selección de región del frontend, la muestra de nacionalidad del usuario, etc.

## Tareas pendientes

- [x] DDL de la base de datos (`install.sql`, 46 tablas, tablas de administración wa_* + tablas de negocio sin prefijo, claves primarias BigInt sin autoincremento)
- [x] Generación de IDs snowflake (`erikwang2013/snowflake-php`)
- [x] Autenticación JWT (`erikwang2013/jwt-webman`, HS256 + lista negra en Redis)
- [x] Ofuscación de ID de API (`erikwang2013/hashids`, decodificación automática de solicitudes + codificación automática de respuestas)
- [x] Cifrado de transporte (`erikwang2013/encryption`, middleware AES-256-GCM)
- [x] Cifrado a nivel de campo (`erikwang2013/encryptable`, cifrado/descifrado automático de campos sensibles)
- [x] Búsqueda de texto completo (`erikwang2013/webman-scout`, driver database por defecto con fallback SQL LIKE, opcional Elasticsearch + segmentación IK)
- [x] Banderas de países (`erikwang2013/season`, emoji Unicode de banderas)
- [x] Panel de administración (`admin/`, webman-admin + integración de 7 paquetes, 286 pruebas unitarias)
- [x] Revisión de código (2 correcciones críticas + 4 correcciones importantes aplicadas)
- [x] Exportación a Excel (PhpSpreadsheet ^2.0, Crud/Table del panel + API de gestión del servidor)
- [x] Visualización del panel (gráficos ECharts + tarjetas estadísticas animadas + panel de información del sistema)
- [x] Exportación a PDF (html2canvas + jsPDF, exportación de capturas del panel)
- [x] Scripts de migración de base de datos (DDL unificado `install.sql`, comando `php webman migrate`)
- [x] Integración real de Stripe (SDK stripe-php, PaymentIntent + verificación de firma de Webhook)
- [x] Integración real de SMS Twilio (twilio/sdk, con manejo de fallos de envío)
- [x] Integración real de push FCM (kreait/firebase-php, con limpieza de tokens no válidos)
- [x] CAPTCHA de clic (erikwang2013/poster-php, verificación de operaciones sensibles de inicio de sesión/registro)
- [x] Doble confirmación (ConfirmationMiddleware, revisión de contraseña en operaciones sensibles, 5 fallos bloquean 15 minutos)
- [x] Pruebas unitarias del servidor (672 tests / 1632 assertions, 15 skipped)
- [x] Identificación de plataforma de cliente (ClientPlatformMiddleware, encabezado X-Client-Platform soporta 8 plataformas)
- [x] Refuerzo de seguridad WAF (45+ reglas en 8 categorías: inyección SQL/XSS/inyección de comandos/inclusión de archivos/inyección de cabeceras/SSRF/inyección NoSQL/redirección abierta + límite de tamaño de solicitud + validación de Content-Type)
- [x] Security Plugin (erikwang2013/security-php, 31 tipos de detección de ataques + bloqueo automático de lista negra de IP + rotación de logs)
- [x] Middleware WAF del panel Admin
- [x] Separación de lectura/escritura en MySQL (conexiones read/write de Eloquent + sticky)
- [x] Capa de caché Redis multinivel (CacheService: productos/regiones/tipos de cambio/TLD/usuarios, TTL + invalidación activa + precarga)
- [x] Compresión de respuestas y optimización de conexiones en Nginx (gzip/proxy_buffering/keep-alive/limit_req+limit_conn)
- [x] Recomendaciones de índices de base de datos (13 índices compuestos/cobertura recomendados)
- [x] Monitorización de excepciones con Sentry (SentryBootstrap + callback before_send con desensibilización)
- [x] Feature Flags (interruptores de funciones, anulación dinámica con Redis + API del panel)
- [x] API externa de proveedores (autenticación por API Key + endpoints de pedidos/recursos/liquidaciones/retiros)
- [x] Push en tiempo real por WebSocket (WebSocket nativo de Workerman + listeners de eventos de pedidos/tickets)
- [x] Scripts de pruebas de carga k6 (humo/producto/pruebas de concurrencia)
- [x] Pipeline CI/CD (GitHub Actions, comprobación de sintaxis + PHPUnit dual + validación de Composer)
- [x] Asistente de instalación con un clic (UI web, comprobación del entorno + configuración de la base de datos + creación del administrador + generación automática de .env)

## El código abierto cuesta; agradecemos tu apoyo

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "WeChat") | ![Alipay](./docs/alipay.png "Alipay") |

### Transferencia global (transferencia bancaria)

**Datos del beneficiario**

- Nombre del beneficiario: WANG KEXUN
- Número de cuenta del beneficiario: 881015918251

**Banco beneficiario (ZA Bank)**

- Código SWIFT: AABLHKHHXXX
- Nombre del banco: ZA Bank Limited
- Código bancario: 387
- Dirección del banco: Core F, Cyberport 3, 100 Cyberport Road, Hong Kong

**Banco corresponsal para transferencias transfronterizas (si es necesario)**

> Tenga en cuenta que esta es la información del banco corresponsal (banco intermediario) para transferencias transfronterizas, no la del banco beneficiario. Consulte con su banco emisor si es necesario facilitar la información del banco corresponsal.

- El banco corresponsal para transferencias en dólares de Hong Kong, yuanes y dólares estadounidenses es **Citibank**:
  - Nombre del banco: Citibank N.A. Hong Kong
  - Código SWIFT: CITIHKHXXXX
  - Código bancario: 006
  - Sucursal: Hong Kong Branch
  - Código de sucursal: 391
  - Dirección del banco: Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong
- El banco corresponsal para transferencias en otras monedas es **BNY Mellon**:
  - Nombre del banco: THE BANK OF NEW YORK MELLON
  - Código SWIFT: IRVTUS3NXXX
  - Dirección del banco: THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States

### Donación en criptomonedas (Crypto Donation)

Si este proyecto te resulta útil, escanea el código QR para donar, ¡gracias!

| <img src="../../coin/1.jpg" width="200" alt="BNB Smart Chain (BEP20)"><br>**BNB Smart Chain (BEP20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/2.jpg" width="200" alt="Tron (TRC20)"><br>**Tron (TRC20)**<br>`TEdDHWLajt1XvqtPDWmQctdrJaC3pzZZzz` |
| <img src="../../coin/3.jpg" width="200" alt="Ethereum (ERC20)"><br>**Ethereum (ERC20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/4.jpg" width="200" alt="Aptos"><br>**Aptos**<br>`0x836e3780edfc3f7b2372b39e2a1a3a5d7adfaccd96c726f21cfde1b50dd68030` |
| <img src="../../coin/5.jpg" width="200" alt="Plasma"><br>**Plasma**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/6.jpg" width="200" alt="Polygon POS"><br>**Polygon POS**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |
| <img src="../../coin/7.jpg" width="200" alt="Solana"><br>**Solana**<br>`2hfhboHdmdrYsY25XfQSsEWxq5ip4EQsR7f4AzSRMUyr` | <img src="../../coin/8.jpg" width="200" alt="The Open Network (TON)"><br>**The Open Network (TON)**<br>`UQB9kFQohzmXUir9QSSZq01iwl9aQZIDdBpNmDklljRtCoGK` |
| <img src="../../coin/9.jpg" width="200" alt="Arbitrum One"><br>**Arbitrum One**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/10.jpg" width="200" alt="AVAX C-Chain"><br>**AVAX C-Chain**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |

---

## Licencia

Edición simplificada — Licencia MIT | Ediciones estándar/completa — Propietaria
