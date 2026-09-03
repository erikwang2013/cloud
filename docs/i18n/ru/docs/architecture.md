# Документ архитектурного дизайна CloudPlatform

## 1. Обзор системы

CloudPlatform — глобальная торговая платформа облачных ресурсов, поддерживающая гибридную модель собственных физических машин + сторонних поставщиков. Пользователи могут покупать через Web/мобильные приложения серверы (VM), IP-адреса, облачные диски, домены и другие продукты, система автоматически выполняет обработку платежей и поставку ресурсов.

### 1.1 Ключевые архитектурные решения

| Решение | Выбор | Причина |
|------|------|------|
| Бэкенд-фреймворк | PHP webman (Workerman) | постоянно в памяти, событийный, многопроцессный, отклик в миллисекундах |
| Архитектурный паттерн | Модульный монолит | модули вертикально разделены по бизнесу, внутри слои MVC, между модулями — слабая связность через события |
| Панель управления | Отдельный экземпляр webman (webman-admin + Layui) | изоляция управляющего трафика от пользовательского, разделение зон отказа |
| ORM | Illuminate/Eloquent | зрелая экосистема Laravel: связи, Scope, события, миграции |
| Распределённые первичные ключи | Snowflake 64-bit | без зависимости от автоинкремента, нативно поддерживает шардирование |
| Обефускация ID | Hashids | скрывает реальный масштаб ID, защита от перебора краулерами |
| Аутентификация | JWT HS256 | аутентификация без состояния, Access 15min + Refresh 30d |
| Шифрование транспорта | AES-256-GCM | прозрачное шифрование/дешифрование в промежуточном слое, аутентифицированное шифрование GCM против подделки |
| Шифрование полей | AES-128-ECB | автоматическое шифрование/дешифрование через Eloquent Cast, детерминированное шифрование (равенство по шифротексту при запросах, зависит от входа/проверки уникальности); поддерживается только ECB |
| Очередь сообщений | Redis Queue | асинхронная обработка колбэков платежей, распределения уведомлений, открытия ресурсов |
| Поисковая система | database (по умолчанию) / Elasticsearch 8.x | webman-scout по умолчанию database-драйвер (деградация на SQL LIKE); после настройки ES включается индекс с сегментацией IK |
| Виртуализация | Proxmox VE + kvm-server | собственные VM поставляет Rust kvm-server (gRPC :50051, регистрация/обнаружение e-cat/etcd); на уровне драйверов сейчас имитационный драйвер, реальный драйвер libvirt — Phase 2 |
| Клиент | Flutter | один код-базис для пяти платформ iOS/macOS/Windows/Linux/Web + HarmonyOS |

### 1.2 Границы системы

```
┌──────────────────────────────────────────────────────────────────┐
│                         Клиентская сторона                        │
│  Flutter (iOS/macOS/Windows/Linux/Web) + HarmonyOS (ArkTS)      │
└─────────────────────────┬────────────────────────────────────────┘
                          │ HTTPS + JWT
┌─────────────────────────┼────────────────────────────────────────┐
│                    Обратный прокси Nginx                         │
│  Терминация SSL / gzip-сжатие / ограничение частоты / WebSocket Upgrade │
└─────────────────────────┬────────────────────────────────────────┘
                          │
┌─────────────────────────┼────────────────────────────────────────┐
│              webman-сервер (многопроцессный)                     │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ Цепочка глобальных промежуточных слоёв: Version→CORS→    │     │
│  │ SecurityHeaders→ClientPlatform→GeoBlock→WAF→             │     │
│  │ SecurityPlugin→RateLimit→Locale→Metrics→Hashid→          │     │
│  │ Maintenance→[маршрутные промежуточные слои]               │     │
│  └─────────────────────────────────────────────────────────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌───────┐     │
│  │  User   │ │ Product │ │  Order  │ │ Payment │ │Provision│    │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘ └───────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐               │
│  │ Domain  │ │Supplier │ │ Ticket  │ │  Notif  │  ...           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ WebSocket Server (:8282) — push в реальном времени      │     │
│  └─────────────────────────────────────────────────────────┘     │
└──────────┬──────────────┬────────────────┬───────────────────────┘
           │              │                │
    ┌──────┴──────┐ ┌─────┴─────┐ ┌───────┴────────┐
    │  MySQL 8.0  │ │ Redis 7.x │ │ Elasticsearch  │
    │  (master/  │ │(кэш/очереди)│ │    8.x        │
    │  replica)  │ └───────────┘ └────────────────┘
    └─────────────┘
           │
    ┌──────┴──────────────────────┐
    │  kvm-server (Rust gRPC)     │
    │  регистрация e-cat / etcd   │
    │  имитационный драйвер (libvirt Phase 2) │
    └──────┬──────────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  Proxmox VE API (:8006)     │
    │  виртуализация KVM/QEMU     │
    │  IP-пул / дисковый пул / хосты │
    └─────────────────────────────┘
```

---

## 2. Архитектура компонентов

### 2.1 Конструкция двух экземпляров

Проект содержит два независимых экземпляра webman, разделяющих базу MySQL:

```
                    ┌─────────────────────┐
                    │   admin/ (Layui)     │
  Administrator ───▶│   port: 8788         │
                    │   Промежуточные слои: WAF→ACL     │
                    └──────────┬───────────┘
                               │ SQL
                    ┌──────────┴───────────┐
                    │     MySQL 8.0        │
                    └──────────┬───────────┘
                               │ SQL
  User/API ────────▶│   service/           │
                    │   port: 8787         │
                    │   12 глобальных + 6 маршрутных промежуточных слоёв │
                    └─────────────────────┘
```

| Экземпляр | Порт | Обязанности | Промежуточные слои |
|------|------|------|--------|
| **service** | 8787 | пользовательский API + управляющий API + WebSocket | глобальных 12 + маршрутных 6 + SupplierApiKey |
| **admin** | 8788 | HTML-панель управления (Layui) | WafMiddleware + AccessControl |

### 2.2 Структура слоёв модуля

Каждый бизнес-модуль внутри придерживается единой структуры слоёв:

```
app/{Module}/
├── controller/     # HTTP-слой: проверка параметров, вызов Service, возврат Response
│   └── external/   # контроллеры внешнего API (аутентификация по API Key поставщика)
├── service/        # бизнес-логика: без HTTP-зависимостей, переиспользуется Controller/Queue Worker
├── model/          # модели данных Eloquent: связи, query scopes, Casts
├── event/          # определения доменных событий (OrderPaid, TicketCreated и др.)
├── listener/       # слушатели событий (Provisioning, WebSocket push)
├── provider/       # адаптеры облачных провайдеров (ProxmoxProvider и др.)
├── queue/          # потребители очередей (ProvisionWorker, EmailSender и др.)
└── cron/           # планировщики (ExchangeRateSync, ExpirationCheck и др.)
```

### 2.3 Слои общей библиотеки

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # сервис капчи по клику
├── clientplatform/      # ClientPlatformMiddleware (заголовок X-Client-Platform)
├── confirmation/        # промежуточный слой повторного подтверждения пароля
├── encryption/          # промежуточный слой транспортного шифрования AES-256-GCM
├── feature/             # Feature Flags — функциональные переключатели
├── hashid/              # промежуточный слой декодирования запросов Hashids + сервис кодеков
├── helper/              # форматирование Response + CacheService
├── http/                # HTTP-клиентские утилиты
├── i18n/middleware/     # многоязычный LocaleMiddleware
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # сервис снежинок ID + Eloquent Trait
├── metrics/             # сборщик метрик Prometheus + рендерер + промежуточный слой счётчика HTTP-запросов
├── version/             # VersionMiddleware (проверка версии по пути URL)
└── webhook/             # диспетчер событий Webhook
```

### 2.4 Модуль CDN

Продуктовый модуль CDN (`service/app/cdn/`) через адаптерный шаблон подключает четырёх провайдеров, используя сервер или хранилище (bucket) как origin:

```
CdnAdapterInterface
  ├── CloudflareAdapter   REST v4 (SSL SaaS с автоконфигурацией сертификатов), ICP не нужна
  ├── CloudFrontAdapter   aws-sdk-php (CloudFront + ACM), ICP не нужна
  ├── AliyunCdnAdapter    RPC-подпись, нужна ICP-регистрация
  └── TencentCdnAdapter   TC3-подпись, нужна ICP-регистрация
         ▲
CdnAdapterFactory.resolve(type, accountId, strict)
  ① привязанная запись (provider_account_id) → ② активная запись code=cdn-{type} → ③ env как fallback
  strict=true (удаление/purge): только привязанная запись, при отсутствии — 4003, без тихого переключения
```

**Управление учётными записями:** переиспользуется модель `provider_apis` (учётные данные шифруются через Encryptable), админ-панель `/admin/providers` (CRUD, RbacMiddleware), `code` по соглашению `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, учётные данные env понижены до fallback.

**Модель данных:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; перед записью из cert_config удаляется приватный ключ). Изоляция прав: CDN-ресурсы проходят проверку принадлежности через `resource.user_id`, чужие ресурсы единообразно возвращают 404.

---

## 3. Конвейер выполнения промежуточных слоёв

### 3.1 Цепочка глобальных промежуточных слоёв (все запросы)

```
HTTP-запрос
  │
  ▼
1. VersionMiddleware         ← проверка версии из пути URL, недействительная → 400
  │                            действует только на /api/v1/ и /admin/api/v1/
  ▼
2. CorsMiddleware            ← OPTIONS preflight возвращает CORS-заголовки, отражение Origin
  ▼
3. SecurityHeadersMiddleware ← безопасные заголовки ответа HSTS / X-Frame-Options / CSP / Referrer-Policy
  ▼
4. ClientPlatformMiddleware  ← распознавание заголовка X-Client-Platform (8 платформ), инъекция в properties
  │                            действует только на /api/v1/ и /admin/api/v1/
  ▼
5. GeoBlockMiddleware        ← блокировка стран GEO_BLOCKED_COUNTRIES (MaxMind GeoIP2)
  ▼
6. WafMiddleware             ← сканирование по 8 категориям 45+ правил (JSON body + URL + UA + сырое тело)
  │                          ← белый список Content-Type + лимит тела 10MB + лимит URL 2KB
  │                            совпадение → AuditLogger::threat() → 403
  ▼
7. SecurityPlugin            ← 31 вид обнаружения атак (XSS/SQL-инъекция/SSRF/десериализация и др.), IP-белые/чёрные списки
  ▼
8. RateLimitMiddleware       ← ограничение частоты всех маршрутов (два ведра per-IP + per-token)
  ▼
9. LocaleMiddleware          ← разбор Accept-Language, установка локали
  ▼
10. MetricsMiddleware        ← счётчик HTTP-запросов Prometheus и запись задержек
  ▼
11. HashidRequestMiddleware  ← декодирование hashid-строк параметров запроса → реальные целочисленные ID
  ▼
12. MaintenanceMiddleware    ← проверка MAINTENANCE_MODE, пропуск IP из белого списка
  │
  ▼
[Маршрутные промежуточные слои — прикрепляются по группам маршрутов]
  │
  ├─ /health (внутренний мониторинг) ────────────
  │   InternalTokenMiddleware      ← проверка внутреннего токена /health/live|ready|deps
  │
  ├─ /api/v1/auth ─────────────────────
  │   EncryptionMiddleware          ← шифрование/дешифрование тела запроса/ответа AES-256-GCM
  │
  ├─ /api/v1 ((аутентификация пользователя) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware                ← проверка JWT Bearer Token → $request->userId/role
  │
  ├─ /api/v1 ((чувствительные операции) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← повторное подтверждение пароля, счётчик Redis, 5 попыток → блокировка 15min
  │
  ├─ /api/v1/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← проверка sk_xxx SHA256 → $request->supplierId
  │
  ├─ /admin/api/v1 ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← проверка прав RBAC
  │
  └─ /admin/api/v1 (чувствительные операции) ─────────
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
Controller → Service → Model → DB
```

### 3.2 Детали промежуточных слоёв

| Промежуточный слой | Расположение | Способ регистрации | Обязанности |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | глобальный | проверка версии по пути URL |
| `CorsMiddleware` | common/Security | глобальный | OPTIONS preflight, отражение Origin |
| `SecurityHeadersMiddleware` | common/Security | глобальный | безопасные заголовки ответа HSTS / X-Frame-Options / CSP / Referrer-Policy |
| `ClientPlatformMiddleware` | common/ClientPlatform | глобальный | распознавание 8 платформ `X-Client-Platform` |
| `GeoBlockMiddleware` | common/Security | глобальный | региональная блокировка GEO_BLOCKED_COUNTRIES (MaxMind GeoIP2) |
| `WafMiddleware` | common/Security | глобальный(service)+admin | 8 категорий 45+ правил + ограничения запросов |
| `SecurityPlugin` | Erikwang2013\Security | глобальный | 31 вид обнаружения атак, IP-белые/чёрные списки |
| `RateLimitMiddleware` | common/Security | глобальный | ограничение частоты Redis (два ведра per-IP + per-token) |
| `LocaleMiddleware` | common/I18n | глобальный | разбор Accept-Language |
| `MetricsMiddleware` | common/Metrics | глобальный | счётчик HTTP-запросов Prometheus и задержки |
| `HashidRequestMiddleware` | common/Hashid | глобальный | декодирование hashid в запросах |
| `MaintenanceMiddleware` | common/Security | глобальный | режим обслуживания + IP-белый список |
| `InternalTokenMiddleware` | common/Security | группа маршрутов | проверка внутреннего токена `/health/live|ready|deps` |
| `EncryptionMiddleware` | common/Encryption | группа маршрутов | шифрование/дешифрование AES-256-GCM |
| `AuthMiddleware` | common/Auth | группа маршрутов | проверка JWT Bearer Token |
| `AdminRoleMiddleware` | common/Auth | группа маршрутов | RBAC администратора |
| `ConfirmationMiddleware` | common/Confirmation | группа маршрутов | повторное подтверждение пароля |
| `SupplierApiKeyMiddleware` | common/Auth | группа маршрутов | проверка подписи sk_xxx API Key SHA256 |

---

## 4. Архитектура данных

### 4.1 Распределённые первичные ключи: Snowflake

```
Структура 64-bit Snowflake ID:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  временная метка   дата-центр  рабочий узел  порядковый номер
  в миллисекундах
  Эпоха: 2024-01-01
  Максимальный срок жизни: ~69 лет
```

Все Eloquent Model автоматически генерируют ID через Trait `HasSnowflakeId` в событии `creating`. Тип колонок базы — `bigint unsigned`.

### 4.2 Обефускация ID: Hashids

```
Поток запроса:
  Client: GET /api/v1/products/aB3xK7mQ9w
    → HashidRequestMiddleware декодирует → int(1234567890)
      → Controller/Service работает с целочисленным ID
        → Response::success() / Response::paginated()
          → hashids_encode_ids() рекурсивно кодирует все поля id/*_id
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 Подключения к базе данных

```
┌────────────────────┐     ┌────────────────────┐
│  MySQL master (запись) │     │  MySQL replica (чтение) │
│  DB_HOST           │     │  DB_READ_HOST      │
└────────┬───────────┘     └────────┬───────────┘
         │ запись                   │ чтение (SELECT)
         └──────────┬───────────────┘
                    │
         ┌──────────┴───────────┐
         │  Eloquent Capsule    │
         │  sticky = true       │
         │  постоянные соединения (PDO)│
         └──────────────────────┘
                    │
         ┌──────────┴───────────┐
         │  база audit (отдельное соединение)│
         │  изолированное хранение журнала аудита│
         └──────────────────────┘
```

### 4.4 Слои шифрования

| Слой | Алгоритм | Реализация | Назначение |
|------|------|------|------|
| Транспортный слой | AES-256-GCM | EncryptionMiddleware | шифрование тел API-запросов/ответов, аутентификация GCM |
| Слой полей | AES-128-ECB | Encryptable Cast | автоматическое шифрование/дешифрование чувствительных полей (детерминированное шифрование: одинаковый открытый текст → одинаковый шифротекст, равенство по шифротексту при входе/проверке уникальности; поддерживается только ECB, смена cipher требует миграции с перешифрованием) |
| Слой хеширования | bcrypt + SHA256 | JWT / API Key | необратимое хранение паролей/токенов |
| Слой первичных ключей | Hashids | Response + Middleware | обефускация ID наружу |

### 4.5 Слои кэширования

```
L1: Слой кэша Redis (CacheService)
    список продуктов TTL 5min | детали продукта TTL 10min
    регионы TTL 1h | курсы валют TTL 30min | TLD TTL 1h
    Стратегия инвалидации: активный forget / forgetPattern при изменении данных

L2: Слой запросов MySQL (Eloquent + оптимизация индексов)
    13 составных/покрывающих индексов покрывают частые запросы

L3: Сжатие ответов Nginx (gzip level 6)
    степень сжатия JSON-ответов 70-85%
```

### 4.6 Интернационализация (i18n)

```
Accept-Language: zh-CN,zh;q=0.9
         │
         ▼
  LocaleMiddleware (глобальный промежуточный слой)
         │  разбор основного языка → zh-CN
         │  I18n::setLocale('zh-CN')
         │  загрузка i18n/zh-CN/messages.php
         ▼
  Controller / Service
         │
         ├── I18n::trans('auth.login_success')  →  '登录成功'
         ├── I18n::translateField($jsonField)   →  значение по языку
         └── I18n::getLocale()                  →  'zh-CN'
```

| Возможность | Описание |
|------|------|
| Разбор заголовка | `LocaleMiddleware` автоматически разбирает заголовок `Accept-Language` |
| Возврат языка | неподдерживаемый язык → `fallback_locale` |
| Статические переводы | 120 записей, покрывающих 15 модулей (`i18n/{locale}/messages.php`) |
| Подстановка параметров | `I18n::trans('key', ['field' => 'value'])` |
| JSON-поля | `translateField()` обрабатывает многоязычные JSON-колонки |

---

## 5. Архитектура безопасности

### 5.1 Система правил WAF (8 категорий 45+ правил)

| Категория | Количество правил | Область обнаружения |
|------|--------|---------|
| SQL-инъекция | 9 | символы комментариев, ключевые слова, шестнадцатеричное кодирование, UNION-запросы, вечно истинные условия, time-based blind, стековые запросы |
| XSS | 8 | HTML-теги, варианты Script, 13 обработчиков событий, псевдопротокол JS, кодирование сущностей, Data URI |
| Инъекция команд | 5 | команды после конвейера, команды после точки с запятой, $(cmd), обратные кавычки, ключевые слова отдельных команд |
| Включение файлов | 4 | обход пути, PHP-псевдопротоколы, абсолютные пути, Null byte |
| Инъекция HTTP-заголовков | 2 | CRLF-переводы строк, инъекции Host/Cookie/Set-Cookie |
| SSRF | 6 | внутренние IP, localhost, cloud metadata, протокол file:// |
| NoSQL-инъекция | 3 | операторы MongoDB, опасные команды Redis |
| Открытое перенаправление | 2 | внешние URL в redirect_uri, обход двойным кодированием |

**Область сканирования:** правила инъекции значений (SQLi/XSS/инъекция команд/инъекция заголовков/SSRF/NoSQL/открытое перенаправление) сканируют query string, тело запроса, User-Agent; URL path — только структурная проверка паттернами включения файлов (обход пути). Бизнес-пути содержат обычные слова select/insert/alert (например `/order_item/select`), при сканировании всего пути будут ложно убиты все CRUD-эндпоинты, поэтому path не участвует в сопоставлении инъекций значений.

**Защита на уровне запросов:** белый список Content-Type, лимит тела запроса 10MB, лимит URL 2KB

### 5.2 Система аутентификации

```
┌─────────────────────────────────────────────┐
│               Способы аутентификации         │
├──────────────┬──────────────┬───────────────┤
│  Клиент      │  Панель      │  API поставщика│
│  JWT HS256   │  JWT HS256   │  API Key      │
│  Access 15min│  Access 2h   │  префикс sk_xxx│
│  Refresh 30d │  Refresh 7d  │  хранение SHA256│
│  TOTP опц.   │              │  показ один раз│
│  OAuth опц.  │              │               │
└──────────────┴──────────────┴───────────────┘
```

---

## 6. Архитектура развёртывания

### 6.1 Топология продакшена

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │
               │  DDoS / Bot     │
               └────────┬────────┘
                        │
               ┌────────┴────────┐
               │  Nginx × 2      │
               │  SSL / gzip     │
               │  limit_req      │
               └──┬──────────┬───┘
                  │          │
         ┌────────┴──┐  ┌───┴──────────┐
         │ webman × 2│  │ webman × 2   │
         │ service   │  │ admin        │
         │ :8787     │  │ :8788        │
         │ :8282 WS  │  │              │
         └─────┬─────┘  └──────┬───────┘
               │               │
         ┌─────┴──────┬───────┴───────┐
         │ MySQL master/replica │ Redis Cluster │
         │ 1 master 2 replica  │ кэш+очереди   │
         └─────────────┴───────────────┘
               │
         ┌─────┴──────────────────────┐
         │  kvm-server (Rust gRPC)    │
         │  регистрация e-cat / etcd  │
         │  имитационный драйвер (libvirt Phase 2)│
         └─────┬──────────────────────┘
               │
         ┌─────┴──────────────────────┐
         │  Кластер Proxmox VE        │
         │  физические машины × N     │
         │  виртуализация KVM/QEMU    │
         └────────────────────────────┘
```

### 6.2 Модель процессов

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (по умолчанию 8)
├── Queue Worker: provisioning     (×2)
├── Queue Worker: email            (×5)
├── Queue Worker: sms              (×10)
├── Queue Worker: push             (×20)
├── WebSocket Worker               (×2, порт 8282)
└── Cron Timer                     (×1)

webman admin (8788)
└── HTTP Worker × 4
```

---

## 7. Технические зависимости

### 7.1 Базовые фреймворки

| Пакет | Версия | Назначение |
|----|------|------|
| workerman/webman-framework | ^2.1 | Web-фреймворк (постоянно в памяти, многопроцессный) |
| illuminate/database | ^10.0 | Eloquent ORM |
| illuminate/events | ^10.0 | система событий |
| illuminate/redis | ^10.0 | Redis-клиент |
| webman/redis-queue | ^1.0 | очередь сообщений Redis |

### 7.2 Пакеты экосистемы erikwang2013

| Пакет | Назначение |
|----|------|
| snowflake-php | 64-битные распределённые первичные ключи |
| hashids | обефускация ID API |
| encryptable | шифрование полей базы данных |
| encryption | транспортное шифрование AES-256-GCM |
| jwt-webman | JWT-аутентификация |
| webman-scout | полнотекстовый поиск Elasticsearch |
| season | эмодзи флагов стран |
| poster-php | капча по клику |

### 7.3 Интеграции третьих сторон

| Пакет | Назначение |
|----|------|
| stripe/stripe-php | оплата Stripe |
| twilio/sdk | SMS |
| kreait/firebase-php | push FCM |
| guzzlehttp/guzzle | HTTP-клиент (Proxmox API и др.) |
| sentry/sentry | мониторинг исключений |
| phpoffice/phpspreadsheet | экспорт Excel |
