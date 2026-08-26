# Отчёт об аудите безопасности — cloud-php

**Дата**: 2026-08-04
**Область**: весь проект (service + admin)
**Методология**: анализ конфигураций, аудит промежуточных слоёв, инспекция кода

---

## Итоговая оценка: **B+ (хорошо, 4 пробела к устранению)**

Проект имеет надёжную многоуровневую архитектуру безопасности. Плагин erikwang2013/security-php с 31 детектором — выдающаяся особенность. Ниже — детальная разбивка.

---

## 1. Внедрённые защиты (проверено)

### Транспорт и шифрование
| Механизм | Реализация | Статус |
|-----------|---------------|--------|
| Транспортное шифрование API | AES-256-GCM через erikwang2013/encryption | OK |
| Шифрование полей БД | AES-128-ECB через erikwang2013/encryptable (детерминированное, запрашиваемое) | OK |
| Ротация ключей | ENCRYPTION_PREVIOUS_KEYS — старые ключи через запятую | OK |
| Запутывание ID | Hashids с настраиваемой солью и минимальной длиной 12 | OK |
| Хеширование паролей | bcrypt cost=12, мин. длина 8 | OK |

### Аутентификация и контроль доступа
| Механизм | Реализация | Статус |
|-----------|---------------|--------|
| Аутентификация JWT | erikwang2013/jwt-webman, HS256, access TTL 900с + refresh 30 дней | OK |
| Чёрный список JWT | Отзыв токенов через Redis | OK |
| MFA/TOTP | 6 цифр, период 30 с, совместим с Google/MS Authenticator | OK |
| RBAC | Промежуточный слой AccessControl admin + plugin\admin\api\Auth::canAccess() | OK |
| Хранилище сессий | Redis (db2) | OK |
| Капча | erikwang2013/poster-php — капча по клику для входа/регистрации | OK |

### Обнаружение атак (WAF — двойной слой)
| Слой | Покрытие | Статус |
|-------|----------|--------|
| Собственный WafMiddleware | SQLi, XSS, CMDi, обход пути, инъекции в заголовки, SSRF, NoSQLi, открытое перенаправление | OK |
| Security Plugin (31 детектор) | Всё вышеперечисленное + XXE, десериализация, LDAP, почтовые заголовки, SSTI, атаки на JWT, Host-заголовок, протаскивание запросов, GraphQL, XPATH, JNDI/Log4Shell, SSI, инъекции CSV, утечка данных, prototype pollution, WebSocket, обход CORS, DNS rebinding | OK |

### Ограничение частоты (только service)
| Маршрут | Rate | Burst | Период | Статус |
|-------|------|-------|-----|--------|
| default | 60 | 10 | 60 с | OK |
| login | 5 | 2 | 60 с | OK |
| register | 3 | 0 | 60 с | OK |
| pay | 10 | 3 | 60 с | OK |
| upload | 10 | 2 | 60 с | OK |
| supplier_api | 120 | 20 | 60 с | OK |
| supplier_withdraw | 10 | 3 | 60 с | OK |

### Прочие защиты
| Механизм | Реализация | Статус |
|-----------|---------------|--------|
| Ограничения размера запроса | 10MB body, 2KB URL | OK |
| Валидация Content-Type | Белый список: JSON, multipart, form-urlencoded | OK |
| Подготовленные операторы БД | PDO::ATTR_EMULATE_PREPARES = false | OK |
| Разделение чтения/записи БД | Запись на master, чтение с реплики, sticky-сессии | OK |
| Журналирование аудита | Отдельная БД аудита, LogSanitizer деидентифицирует чувствительные поля | OK |
| Режим обслуживания | IP из белого списка проходят, остальные получают 503 + Retry-After | OK |
| Автоблокировка IP | 5 нарушений за 60 с → бан на 15 минут | OK |
| Строгий режим SQL | Предотвращает усечение данных и неявное преобразование типов | OK |

---

## 2. Пробелы и рекомендации

### Пробел 1 (средний): CORS повторяет любой origin
**Файл**: `service/common/security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

Зеркалируется любой Origin, присланный клиентом, — фактически любой сайт может делать аутентифицированные кросс-доменные запросы. Детектор cors плагина безопасности может отлавливать некоторые инъекции в заголовки, но сам промежуточный слой не имеет белого списка origin.

**Исправление**: добавить проверку белого списка. Если origin не в списке разрешённых — отвечать `Access-Control-Allow-Origin: null` или не отдавать заголовок вовсе.

### Пробел 2 (средний): отсутствуют заголовки безопасности ответа
Ни service, ни admin не устанавливают критические HTTP-заголовки безопасности:

| Заголовок | Рекомендуется | Сейчас |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | отсутствует |
| X-Content-Type-Options | nosniff | отсутствует |
| X-Frame-Options | DENY или SAMEORIGIN | отсутствует |
| Content-Security-Policy | Политика с nonce/hash | отсутствует |
| X-XSS-Protection | 1; mode=block | отсутствует |
| Referrer-Policy | strict-origin-when-cross-origin | отсутствует |
| Permissions-Policy | Ограничить camera/mic/geolocation | отсутствует |

**Рекомендация**: добавить SecurityHeadersMiddleware в стеки промежуточных слоёв и service, и admin. Высокий эффект при малых усилиях.

### Пробел 3 (низкий): в admin/config/security.php нет ограничения частоты
**Файл**: `admin/config/security.php`

В панели управления нет конфигурации rate_limits. Промежуточный слой WAF admin проверяет только размер запроса/лимиты Content-Type. Брутфорс-атака на вход в admin не ограничивается на уровне приложения.

**Рекомендация**: либо добавить rate_limits в admin/config/security.php, либо применить RateLimitMiddleware к маршрутам admin.

### Пробел 4 (низкий): GeoBlockMiddleware определён, но не активирован
**Файл**: `service/common/security/GeoBlockMiddleware.php`

Промежуточный слой существует и функционален, но не зарегистрирован в `service/config/middleware.php`. Если нужна геоблокировка — добавить его в стек.

### Пробел 5 (инфо): накладные расходы двойного WAF
И WafMiddleware (собственный, 40+ regex-паттернов), и SecurityMiddleware (плагин, 31 детектор) выполняются на каждом запросе. Их покрытие паттернами существенно пересекается по SQLi, XSS, инъекциям команд, обходу пути, инъекциям в заголовки, SSRF, NoSQLi и открытому перенаправлению.

**Рекомендация**: плагин безопасности более всеобъемлющ (31 детектор против 8 категорий) и имеет блокировку по IP, белые списки полей и дедупликацию логов. Рассмотреть удаление собственного WafMiddleware с опорой только на плагин, или как минимум удалить пересекающиеся паттерны из WafMiddleware.

### Пробел 6 (инфо): класс Validator минимален
**Файл**: `service/common/helper/Validator.php`

Есть только required(), email(), minLength(). Отсутствуют: максимальная длина, числовая валидация, санитизация строк, валидация URL, сопоставление паттернов. Контроллеры, не использующие валидацию уровня фреймворка, рискуют принимать некорректный ввод.

---

## 3. Security Plugin — статус 31 детектора

| # | Детектор | Режим | Примечания |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF совпадает с содержимым textarea, должен оставаться в log |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }} совпадает с шаблонами Vue/Angular |
| 13 | nosql_injection | **log** | $ne/$gt совпадает с переменными shell/LaTeX |
| 14 | open_redirect | block | |
| 15 | jwt_attack | block | |
| 16 | host_header | block | |
| 17 | request_smuggling | block | |
| 18 | graphql_injection | block | |
| 19 | xpath_injection | block | |
| 20 | jndi_injection | block | |
| 21 | ssi_injection | block | |
| 22 | csv_injection | block | |
| 23 | data_leak | block | |
| 24 | prototype_pollution | block | |
| 25 | websocket | block | |
| 26 | cors | block | |
| 27 | dns_rebinding | **log** | loopback Host（127.0.0.1/localhost）不再 403（开发/测试常态，仅记录） |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

Все 31 детектор включены. 4 в режиме только-запись (документированный риск ложных срабатываний). Конфигурация корректна.

---

## 4. Порядок выполнения промежуточных слоёв (service)

```
1. VersionMiddleware          — разбор заголовка версии API
2. CorsMiddleware              — заголовки CORS (слишком разрешающие, см. Пробел 1)
3. ClientPlatformMiddleware    — определение ОС/платформы
4. WafMiddleware               — собственный WAF (40+ regex-паттернов)
5. SecurityMiddleware           — WAF плагина (31 детектор)
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — декодирование ID
8. MaintenanceMiddleware       — проверка режима обслуживания
```

---

## 5. Сводка

| Категория | Оценка | Ключевые проблемы |
|----------|-------|------------|
| Обнаружение атак | **A** | 31 детектор, двойной слой WAF (избыточно, но тщательно) |
| Аутентификация | **A-** | bcrypt+MFA+чёрный список JWT, у admin нет ограничения частоты |
| Безопасность транспорта | **B+** | AES-256-GCM отлично, отсутствуют заголовки HSTS/CSP |
| Валидация входных данных | **B** | WAF ловит атаки, валидация уровня приложения тонкая |
| Контроль доступа | **A-** | RBAC + проверка сессий, CORS слишком разрешающий |
| Аудит/журналирование | **A** | Отдельная БД аудита, деидентификация чувствительных полей |
| Ограничение частоты | **B+** | Хорошо настроено для service, отсутствует для admin |

**Приоритетный порядок исправления:**
1. Добавить заголовки безопасности ответа (HSTS, CSP, X-Frame-Options и др.)
2. Ограничить CORS белым списком вместо зеркалирования любого origin
3. Добавить ограничение частоты в панель управления
4. Активировать GeoBlockMiddleware, если нужна геоблокировка
5. Рассмотреть консолидацию слоёв WAF для снижения накладных расходов regex на запрос

---

## 6. Применённые меры (2026-08-04)

### Исправлено
| Пробел | Исправление | Изменённые файлы |
|-----|-----|---------------|
| CORS зеркалирует любой origin | Режим белого списка с переменной окружения `CORS_ALLOWED_ORIGINS`, поддержка подстановочных `*.example.com` и `*` для всех | `service/common/security/CorsMiddleware.php` |
| Отсутствуют заголовки безопасности | Новый `SecurityHeadersMiddleware` добавлен в стеки service и admin: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection, Permissions-Policy, HSTS (опционально через env) | `service/common/security/SecurityHeadersMiddleware.php`, `admin/app/middleware/SecurityHeadersMiddleware.php` |
| У admin нет ограничения частоты | Добавлены конфигурация `rate_limits` + `RateLimitMiddleware` в панель управления (по умолчанию 60/мин, вход 5/мин) | `admin/config/security.php`, `admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock не активирован | `GeoBlockMiddleware` зарегистрирован в стеке промежуточных слоёв service | `service/config/middleware.php` |

### Новые переменные окружения
| Переменная | Назначение | По умолчанию |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | Разрешённые origins через запятую | (пусто = запретить все) |
| `SECURITY_HSTS_ENABLE` | Включить заголовок HSTS | false |
| `SECURITY_HSTS_VALUE` | Значение заголовка HSTS | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | Значение X-Frame-Options | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | Коды блокируемых стран (ISO 3166-1) | (пусто = отключено) |
| `GEOIP_DB_PATH` | Путь к .mmdb GeoLite2 | storage_path('geoip/GeoLite2-Country.mmdb') |

### Обновлённый конвейер промежуточных слоёв

**Service:**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin:**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
