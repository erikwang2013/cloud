# Платформа глобальной торговли облачными ресурсами — проектирование системы

## Обзор проекта

Торговая платформа облачных ресурсов, ориентированная на глобальных пользователей; поддерживает смешанную модель собственных ресурсов и сторонних поставщиков. Пользователи могут приобретать облачные продукты: серверы, IP-адреса, облачные диски, домены и другие. Полностью автоматическое выделение ресурсов, множество платёжных каналов, несколько валют, несколько языков.

### Технологический стек

| Уровень | Технология |
|------|------|
| Клиентское приложение | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| Админ-панель | webman-admin |
| Серверная часть | PHP webman (модульный монолит) |
| База данных | MySQL 8.0 (ведущий/ведомый) |
| Кэш/очереди | Redis (кэш + сессии + очереди) |
| Хранилище | S3/OSS + CDN |
| Мониторинг | Prometheus + Grafana + Sentry + ELK/Loki |

---

## 1. Разделение на модули (12 основных модулей)

| Модуль | Обязанности |
|------|------|
| **User** | Регистрация/вход (OAuth+email+телефон), KYC-верификация личности, уровни участников, счёт баланса |
| **Product** | Определение товаров (SKU), региональное ценообразование, управление запасами, категории, поиск, отзывы |
| **Order** | Корзина, оформление заказа, жизненный цикл заказа (ожидает оплаты → оплачен → открывается → завершён → возврат), продление/апгрейд |
| **Payment** | Маршрутизация платёжных каналов, мультивалютные котировки, курсы валют, возвраты, сверка |
| **Provisioning** | Интеграция с API облачных провайдеров, автоматическое создание/продление/уничтожение ресурсов |
| **Domain** | Проверка доменов, регистрация, перенос, продление, управление DNS |
| **Supplier** | Онбординг поставщиков, утверждение, публикация товаров, расчёты, комиссионные |
| **Monitor** | Проверка состояния ресурсов, сбор метрик использования, правила оповещений |
| **Ticket** | Создание заявок, назначение, отслеживание SLA |
| **Notification** | Email/SMS/App Push/внутренние сообщения, несколько шаблонов и языков |
| **Report** | Отчёты о выручке, отчёты о расчётах с поставщиками, тенденции продаж |
| **I18n** | Многоязычные словари, мультивалютные курсы, несколько часовых поясов |

---

## 2. Основные модели данных

### Центр пользователей (User)

- **users** — главная таблица пользователей (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — профили пользователей (user_id, avatar, nickname, country)
- **user_kyc** — верификация личности (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — счёт баланса (user_id, currency, balance, frozen_balance)
- **user_balance_log** — журнал изменения баланса (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — адреса пользователей (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### Центр товаров (Product)

- **product_categories** — категории товаров (id, parent_id, name, icon, sort)
- **products** — главная таблица товаров (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — региональное ценообразование (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — изображения товаров (product_id, url, sort)
- **product_attributes** — пользовательские атрибуты (product_id, key, value)
- **product_reviews** — отзывы о товарах (user_id, product_id, order_id, rating, content)
- **regions** — таблица регионов (id, name, continent, country, city, data_center, status)

### Центр заказов (Order)

- **carts** — корзина (user_id, sku_id, region_id, quantity, cycle)
- **orders** — главная таблица заказов (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — позиции заказа (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — таймлайн заказа (order_id, status, operator, remark, created_at)
- **order_invoices** — счета-фактуры (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — возвраты (order_id, user_id, amount, reason, status, handled_by)

### Платёжный центр (Payment)

- **payment_channels** — конфигурация платёжных каналов (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — записи транзакций (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — таблица сверки (date, channel_id, channel_total, system_total, diff, status)

### Выделение ресурсов (Provisioning)

- **resources** — главная таблица ресурсов (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — детали серверов (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — детали IP (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — детали облачных дисков (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — детали доменов (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — задачи выделения (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — конфигурация API облачных провайдеров (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### Управление физическими серверами (Host & IP Pool)

Для управления виртуальными машинами на собственных физических серверах используется Proxmox VE (Community Edition, бесплатно); создание/управление VM, выделение IP и подключение дисков выполняются через REST API.

- **host_machines** — хост-машины (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — пулы IP (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — записи о выделении IP (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — детали дисков VM (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — записи о расширении дисков (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### Поставщики (Supplier)

- **suppliers** — главная таблица поставщиков (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — связь товаров с поставщиком (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — расчётные документы (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — записи о выводе средств (supplier_id, amount, method, account_info, status)

### Доменные услуги (Domain)

- **domain_tlds** — поддерживаемые TLD (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — переносы доменов (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — DNS-зоны (domain_name, user_id, zone_id)
- **dns_records** — DNS-записи (zone_id, type, name, value, ttl, priority)

### Заявки и уведомления (Ticket & Notification)

- **tickets** — заявки (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — сообщения заявок (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — записи уведомлений (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — шаблоны уведомлений (code, name, channels, title_template, body_template, variables)

---

## 3. Стандарты проектирования API

### Управление версиями

Версия API задаётся через HTTP-заголовок `X-Api-Version`, а не в URL-пути. Серверная часть через промежуточный слой инжектирует заголовок версии во внутренние маршруты.

```
请求:  GET /api/auth/login
请求头: X-Api-Version: v1

内部路由 → /api/auth/login → 控制器
响应头: X-Api-Version: v1
```

**Поддерживаемые версии**: `v1` (по умолчанию, используется автоматически при отсутствии заголовка)

**Механизм управления версиями**: `VersionMiddleware` проверяет заголовок `X-Api-Version` для всех путей `/api/*` и `/admin/api/*`; при отсутствии по умолчанию используется `v1`, неподдерживаемая версия возвращает `400`. Номер версии больше не включается в URL-путь.

**Добавление новой версии**:
1. Добавить номер версии в массив `VersionMiddleware::SUPPORTED`
2. Зарегистрировать новую группу маршрутов в `route.php`
3. Контроллер получает номер версии через `$request->properties['api_version']` для обработки различий

### RESTful маршрутизация

```
统一前缀: /api
管理后台: /admin/api
```

**Группы маршрутов и матрица промежуточных слоёв:**

| Группа маршрутов | Промежуточные слои | Примеры конечных точек |
|--------|--------|---------|
| Публичная (без префикса) | Глобальная цепочка middleware | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | Глобальные + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (пользователи) | Глобальные + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (чувствительные) | Глобальные + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | Глобальные + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (чувствительные) | Глобальные + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### Единый формат ответа

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 1523
  },
  "request_id": "req_abc123"
}
```

### Схемы аутентификации

| Сторона | Способ |
|----|------|
| Клиенты | JWT (access_token 2ч + refresh_token 30д) + двухфакторная TOTP + коды восстановления |
| Админ-панель | JWT (access_token 2ч + refresh_token 7д) |
| API поставщиков | API Key (префикс sk_, хранится в виде SHA256-хеша, показывается один раз при создании) |
| Callback облачных провайдеров | Проверка подписи (HMAC-SHA256) |

**Реализованные функции аутентификации**:
- Регистрация по email + ссылка подтверждения email
- Регистрация по номеру телефона + SMS-код через Twilio (кулдаун 60 с + IP-лимит 5 раз/час)
- Вход через Google OAuth / Apple Sign In
- Восстановление пароля (код на email + Redis TTL 10 мин)
- Двухфакторная TOTP (настройка по QR-коду, резервные коды восстановления)
- Управление активными сессиями (просмотр/отзыв устройств входа, включая информацию client_platform)
- Удаление аккаунта по GDPR (подтверждение паролем + мягкое удаление + отзыв всех токенов)
- Оповещение о необычном входе (email-уведомление при входе с нового IP)
- Блокировка входа (5 неудачных попыток → блокировка на 15 минут)

**Процесс аутентификации пользователя:**

```
注册流程                             登录流程
────────                             ────────
1. POST /captcha/create              1. POST /captcha/create
   ← {key, image(点击位置)}              ← {key, image}
2. POST /auth/register               2. POST /auth/login
   → {email, password, captcha}         → {login, password, captcha}
   → [WAF 扫描]                         → [WAF 扫描]
   → [限流: 3 req/min]                  → [限流: 5 req/min]
   → [密码 bcrypt(cost=12)]             → [Hash::check()]
   → [设备指纹: sha256(UA+IP)]           → [设备指纹: sha256(UA+IP)]
   → [client_platform 记录]              → [client_platform 记录]
   → User::create()                    → [失败 5 次 → 锁 15min]
   → RefreshToken::create()            → [新 IP 检测 → 邮件告警]
     user_id, token_hash,              → RefreshToken::create()
     device_fingerprint,                   user_id, token_hash,
     client_platform,                      device_fingerprint,
     expires_at                            client_platform,
   → NotificationDispatcher::send()           expires_at
     (验证邮件)                          → AuditLogger::record('user_login')
   → AuditLogger::record               ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}    OAuth (Google/Apple):
                                      ─────────────────────
                                      1. GET /auth/google
                                      2. Google 授权 → code
                                      3. GET /auth/google/callback?code=xxx
                                      4. 验证 Google token
                                      5. 新建或查找用户
                                      6. 签发 token（含 client_platform）
                                      7. AuditLogger::record('user_oauth_login')

TOTP 两步验证                          会话管理
────────────────                      ────────
1. POST /user/totp/setup               GET /user/sessions
   ← {secret, qr_code_url}                ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                      created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}          DELETE /user/sessions/{id}
3. POST /auth/login                      → RefreshToken::update(revoked=true)
   → {login, password, totp_code}        ← 成功
   或 → /auth/login/recovery
   → {login, password, recovery_code}  DELETE /user/account
                                          → 密码确认 + 软删除 + 全部 token 撤销
登录锁定机制
────────────
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### Схема многоязычности

- Заголовок запроса: Accept-Language: zh-CN / en-US / ja-JP
- Многоязычные тексты хранятся в JSON-колонках: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- Файлы i18n управляют статическими текстами; по одному набору для фронтенда и бэкенда

---

## 4. Система защиты безопасности

### Многоуровневая модель защиты

```
┌─────────────────────────────────────────────────────┐
│ 第一层: 网络边界防护                                    │
│   DDoS清洗 / WAF / IP黑白名单 / Geo-Blocking          │
├─────────────────────────────────────────────────────┤
│ 第二层: 传输与应用防护                                  │
│   HTTPS+TLS1.3 / CSP / CORS / JWT鉴权 / 限流          │
├─────────────────────────────────────────────────────┤
│ 第三层: 数据与存储安全                                  │
│   加密存储 / 脱敏 / 审计日志 / 备份                     │
├─────────────────────────────────────────────────────┤
│ 第四层: 虚拟化与资源隔离                                 │
│   Proxmox安全加固 / VM间隔离 / 网络隔离                 │
├─────────────────────────────────────────────────────┤
│ 第五层: 运营与风控                                     │
│   操作审计 / 异常检测 / 告警 / 应急响应                  │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 Защита сетевого периметра

#### Защита от DDoS

```
用户请求 → CDN (Cloudflare / 阿里云CDN)
              │
              ├── JS质询 / 验证码 (可疑流量)
              ├── 速率限制 (每IP每秒请求数)
              ├── 区域封禁 (阻断指定国家/地区)
              │
              ▼
          源站 (Nginx + webman)
```

| Уровень | Мера | Описание |
|------|------|------|
| Уровень CDN | Автоматическая очистка DDoS | Бесплатный план Cloudflare уже поддерживает защиту L3/L4 |
| Уровень CDN | Bot Management | Распознавание и блокировка вредоносных ботов/скриптов накрутки заказов |
| Уровень Nginx | limit_req_zone | 10 req/s на IP, превышение возвращает 429 |
| Уровень Nginx | limit_conn | максимум 20 одновременных соединений на IP |
| Уровень webman | middleware ограничения токен-бакет | Точное ограничение по гранулярности пользователя/интерфейса |

#### Правила WAF (middleware webman)

WAF-промежуточный слой сканирует запросы с помощью 8 групп регулярных правил; правила настраиваются в `config/security.php` и обновляются на лету без перезапуска. Сканирование охватывает JSON-тело запроса, URL-путь + query-строку, User-Agent и исходное тело запроса (защита от обхода через JSON-кодирование).

**8 категорий правил обнаружения (45+ правил):**

| Категория | Охват |
|---------|---------|
| SQL-инъекции | Одинарные кавычки/символы комментариев, ключевые слова SQL, шестнадцатеричное кодирование, вариации UNION-запросов, условия-тавтологии (`' OR '1'='1`), временные слепые инъекции (`sleep`/`benchmark`), составные запросы, обход через многострочные комментарии |
| XSS | HTML-теги (включая кодированные вариации), теги Script и их варианты, 13 JS-обработчиков событий, глобальные JS-объекты/опасные функции, псевдопротокол `javascript:`, HTML-сущности, инъекции через Data URI, инлайн-атрибуты событий |
| Инъекции команд | Команда после конвейера (`\| cat`), команда после точки с запятой (`; whoami`), подстановка `$(cmd)` и обратные кавычки, отдельные ключевые слова команд |
| Включение файлов | Path traversal (с несколькими кодировками), PHP-псевдопротоколы (`php://`/`data://`/`phar://`), зондирование абсолютных путей (`/etc/`/`C:\`), инъекция нулевого байта |
| Инъекции в HTTP-заголовки | CRLF-инъекции (`%0d%0a`/`\r\n`), инъекции в заголовки Host/Cookie/Set-Cookie |
| **SSRF** | Внутренние IPv4-адреса (127.x/10.x/172.16-31.x/192.168.x), алиасы localhost, endpoint облачного metadata (169.254.169.254), протокол file:// |
| **NoSQL-инъекции** | Операторы MongoDB ($where/$gt/$regex/$or и др.), JS-инъекции через $where, опасные команды Redis (FLUSHALL/CONFIG SET/SHUTDOWN) |
| **Открытые редиректы** | Обнаружение внешних URL в параметрах redirect_uri/return_url/next/callback и др., обход через двойное кодирование |

**Защита на уровне запроса:**

| Защищаемый аспект | Мера |
|--------|------|
| Лимит размера тела запроса | максимум 10MB (превышение возвращает 413) |
| Лимит длины URL | максимум 2KB (превышение возвращает 414, защита от ReDoS) |
| Белый список Content-Type | только application/json, multipart/form-data, application/x-www-form-urlencoded |

**Процесс обнаружения WAF:**

```
请求进入
  │
  ▼
1. 获取待扫描文本
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # 请求体
   │     └── false → serialize() 回退
   ├── mb_substr(path + queryString, 0, 2048)                # URL（防 ReDoS 截断）
   ├── User-Agent 头                                          # UA
   └── file_get_contents('php://input')                      # 原始体（防 JSON 编码逃逸）
  │
  ▼
2. 加载规则（从 config/security.php）
   ├── security.waf.sqli_patterns               (9 条)
   ├── security.waf.xss_patterns                (8 条)
   ├── security.waf.cmd_injection_patterns      (5 条)
   ├── security.waf.file_inclusion_patterns     (4 条)
   ├── security.waf.header_injection_patterns   (2 条)
   ├── security.waf.ssrf_patterns               (6 条)
   ├── security.waf.nosql_injection_patterns    (3 条)
   └── security.waf.open_redirect_patterns      (2 条)
   → array_merge() + array_unique()
  │
  ▼
3. 逐条匹配
   foreach patterns as pattern:
     match($pattern, $input) ───→ 命中 → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ 命中 → 返回 403 "Request blocked by WAF"
     match($pattern, $ua)    ───→ 命中 →
     match($pattern, $raw)   ───→ 命中 →
  │
  ▼
4. match() 严格检查
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → 命中 ✓
   ├── $result === 0    → 未命中（安全放行）
   └── $result === false → 模式错误 → error_log() → 作为未命中处理
  │
  ▼
5. 全部未命中 → $next($request) 放行到下一中间件
```

```php
class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        $url = mb_substr($request->path() . '?' . $request->queryString(), 0, 2048);
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

        // 从 config/security.php 加载 8 类规则
        $patterns = array_unique(array_merge(
            config('security.waf.sqli_patterns'),
            config('security.waf.xss_patterns'),
            config('security.waf.cmd_injection_patterns'),
            config('security.waf.file_inclusion_patterns'),
            config('security.waf.header_injection_patterns'),
        ));

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input)
                || $this->match($pattern, $url)
                || $this->match($pattern, $ua)
                || $this->match($pattern, $raw)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }

    private function match(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            error_log("WAF: invalid regex pattern: $pattern");
            return false;
        }
        return $result === 1;
    }
}
```

#### Чёрный и белый списки IP

```
黑名单:
- 已知恶意 IP 库 (定期同步 AbuseIPDB)
- 频繁触发 WAF 规则的 IP (自动加入，Redis TTL 24h)
- 暴力破解登录的 IP (5次失败 → 锁定 30min)

白名单:
- Proxmox 宿主机 IP
- 云厂商回调 IP 段
- 支付网关 webhook IP 段
- 管理员办公网络 IP (可选)
```

#### Geo-блокировка

```php
// GeoIP2 库 (MaxMind)
$country = geoip($request->getRealIp());

// 可配置的阻断列表
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 Безопасность передачи и приложений

#### Цепочка выполнения глобальных middleware

Все HTTP-запросы обрабатываются промежуточными слоями в следующем порядке; каждый middleware независимо тестируем:

```
请求 → VersionMiddleware        # X-Api-Version 校验（缺失默认 v1，无效返回 400）
     → CorsMiddleware            # CORS 跨域响应头
     → ClientPlatformMiddleware  # X-Client-Platform 识别（8 种平台），注入 $request->properties
     → WafMiddleware             # 8 类 45+ 规则安全扫描（SQLi/XSS/命令注入/文件包含/头注入/SSRF/NoSQL/开放重定向）
     → LocaleMiddleware          # Accept-Language 解析，设置区域
     → HashidRequestMiddleware   # 请求参数 hashid → 真实 ID 解码
     → MaintenanceMiddleware     # 维护模式（IP 白名单放行）
     ↓
  [路由中间件—按路由组附加]
     → EncryptionMiddleware      # AES-256-GCM 请求/响应体加密
     → Captcha                   # 点击验证码校验（登录/注册前）
     → AuthMiddleware            # JWT Bearer Token 验证 + 角色注入
     → AdminRoleMiddleware       # 管理员 RBAC 权限检查
     → ConfirmationMiddleware    # 敏感操作二次密码确认（5 次失败锁 15min）
     ↓
     控制器
```

#### Обязанности каждого middleware

| Middleware | Регистрация | Обязанности |
|--------|---------|------|
| `VersionMiddleware` | Глобальный | Проверяет заголовок `X-Api-Version`; по умолчанию `v1`, неподдерживаемая версия возвращает `400` |
| `CorsMiddleware` | Глобальный | Обрабатывает OPTIONS-префлайт, отражает Origin в `Access-Control-Allow-Origin` |
| `ClientPlatformMiddleware` | Глобальный | Проверяет заголовок `X-Client-Platform`, распознаёт платформу клиента (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web), инжектирует `$request->properties['client_platform']` |
| `WafMiddleware` | Глобальный (service) + экземпляр admin | 8 категорий 45+ правил + лимит размера запроса + проверка Content-Type, записывает журнал аудита при блокировке |
| `LocaleMiddleware` | Глобальный | Разбирает заголовок `Accept-Language`, устанавливает языковой регион |
| `HashidRequestMiddleware` | Глобальный | Автоматически декодирует hashid-строки запроса в реальные целочисленные ID |
| `MaintenanceMiddleware` | Глобальный | Проверяет переменную окружения `MAINTENANCE_MODE`, пропускает IP из белого списка |
| `EncryptionMiddleware` | Группы маршрутов (/api/auth, /api, /admin/api) | Шифрование тела запроса/ответа AES-256-GCM, активируется заголовком `X-Encrypted: 1` |
| `AuthMiddleware` | Группы маршрутов (/api, /admin/api) | Проверка JWT HS256 access_token, инжектирует `$request->userId` и `$request->userRole` |
| `AdminRoleMiddleware` | Группа маршрутов (/admin/api) | Проверка RBAC-прав администратора |
| `ConfirmationMiddleware` | Группы маршрутов (чувствительные операции) | Повторное подтверждение паролем, счётчик неудач в Redis, 5 неудач → блокировка 15 минут |

#### Детали middleware ClientPlatform

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // 仅对 API 路由生效
        $path = $request->path();
        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/admin/api/')) {
            return $next($request);
        }

        $platform = strtolower(trim($request->header('X-Client-Platform', '')));

        if ($platform === '') {
            $platform = 'unknown';
        } elseif (!in_array($platform, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported client platform: {$platform}")
            ), 400, ['X-Client-Platform' => $platform]);
        }

        // 注入请求属性供下游使用（审计日志、会话记录）
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**Поток данных**: инжекция middleware → автоматическая запись в `AuditLogger` → `AuthService::issueTokens()` пишет в `refresh_tokens` → `GET /api/user/sessions` возвращает информацию о платформе

#### Принудительный HTTPS

```nginx
# Nginx 配置
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload";
    add_header X-Content-Type-Options "nosniff";
    add_header X-Frame-Options "DENY";
    add_header X-XSS-Protection "1; mode=block";
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
}
```

#### Усиление безопасности JWT

```
- access_token 有效期 2h，refresh_token 有效期 30d
- 密钥使用 RSA256 (非对称)，定期轮换 (90天)
- jti (JWT ID) 存入 Redis 实现主动吊销
- refresh_token 绑定设备指纹 (User-Agent + IP 段)
- 换发 refresh_token 时旧 token 立即失效 (rotation)
- 敏感操作 (支付/销毁资源) 需二次验证

设备指纹:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  refresh_token 表记录此指纹，换发时校验
```

#### Политика паролей

```
- bcrypt 加密，cost factor = 12
- 最小 8 字符，必须包含大小写字母 + 数字
- 注册/登录连续失败 5 次 → 账号锁定 15 分钟
- 密码修改后，所有已签发 token 立即失效
- 支持 TOTP 两步验证 (用户可选开启)
```

#### Политика CORS

```php
// webman 中间件
class CorsMiddleware
{
    public function process(Request $request, callable $next): Response
    {
        $allowedOrigins = config('cors.allowed_origins', []);
        $origin = $request->header('Origin');

        $response = $next($request);

        if (in_array($origin, $allowedOrigins)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type,Authorization,Accept-Language');
            $response->header('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
```

#### Безопасность загрузки файлов

```
- 白名单校验扩展名 (仅允许: jpg, jpeg, png, pdf, gif)
- 校验文件 MIME 类型 (不允许伪造 Content-Type)
- 文件大小限制: 头像 2MB, KYC 证件 5MB, 附件 10MB
- 上传后重命名: {uuid}.{ext}, 不保留原始文件名
- 图片二次处理: GD/Imagick 去除 EXIF + 元数据
- 存储路径在 web 不可访问目录, 通过 PHP 代理读取
- 病毒扫描: ClamAV (KYC 证件/用户上传文件)
```

---

### 4.3 Безопасность данных и хранилища

#### Шифрование чувствительных данных

```
加密算法: AES-256-GCM (带认证的加密，防篡改)
密钥管理: 主密钥存于环境变量，每个字段使用独立派生密钥

需要加密存储的字段:
| 数据类型 | 字段 | 加密方式 |
|----------|------|----------|
| 密码 | users.password_hash | bcrypt (单向) |
| 支付密钥 | payment_channels.api_key | AES-256-GCM |
| 云厂商密钥 | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Proxmox Token | host_machines.api_token_encrypted | AES-256-GCM |
| KYC 证件号 | user_kyc.id_number | AES-256-GCM |
| 支付账号 | 提现账号 | AES-256-GCM |
| 登录密码(VNC) | resource_servers.login_password | AES-256-GCM |

密钥派生:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### Маскирование данных в журналах

```php
class LogSanitizer
{
    // 自动脱敏的字段名模式
    private array $sensitiveFields = [
        'password', 'password_hash', 'secret', 'api_key',
        'token', 'credit_card', 'cvv', 'ssn', 'id_number',
        'login_password', 'private_key',
    ];

    public function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->matchSensitive($key)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }
}

// Monolog Processor 在写入日志前自动调用
```

#### Безопасность базы данных

```
- MySQL 使用 prepared statement (Eloquent 自动处理)
- 数据库访问账号最小权限原则:
  - app_user: SELECT, INSERT, UPDATE, DELETE (无 DDL)
  - migration_user: DDL 权限 (仅迁移时使用，IP 限制)
  - read_user: SELECT 只读 (报表/数据分析使用)
- 连接使用 SSL/TLS (PHP PDO SSL options)
- 数据库端口不对公网开放 (仅内网可访问)
- 定期备份: 全量备份 1天, binlog 实时同步
```

#### Резервное копирование и восстановление данных

```
备份策略:
- MySQL: 每日全量 + binlog 实时增量
- Redis: RDB 每小时 + AOF 实时持久化
- 用户上传文件: S3/OSS 自动多副本 + 跨区域复制
- Proxmox VM 快照: 每周一次 (保留 4 周)
- 备份加密: AES-256 加密后存储

恢复演练:
- 每季度执行一次灾难恢复演练
- 恢复时间目标 (RTO): < 4 小时
- 恢复点目标 (RPO): < 1 小时
```

---

### 4.4 Виртуализация и изоляция ресурсов

#### Усиление безопасности Proxmox

```
1. API 访问控制:
   - Proxmox API 仅监听内网 IP (不绑定公网)
   - Token 权限最小化: 每个 role 仅授予必要权限
   - API 端口 (8006) 仅允许 PHP 应用服务器 IP 访问 (iptables)

2. SSH 加固:
   - 禁用密码登录，仅允许密钥认证
   - 禁用 root 登录，使用专用管理账户
   - SSH 端口改为非标准端口 (减少扫描)
   - Fail2ban: 5 次失败锁定 1 小时

3. 系统更新:
   - Proxmox 订阅安全更新邮件列表
   - 定期 apt update && apt upgrade
   - 内核 livepatch (Canonical Livepatch Service)

4. 防火墙 (iptables/nftables):
   - 默认拒绝所有入站
   - 仅开放: 8006 (仅应用服务器IP), SSH端口 (仅管理IP)
   - VM 网桥与宿主机管理网络的隔离
```

#### Изоляция между VM

```
- 每个 VM 使用独立的虚拟网桥 VLAN
- 禁止 VM 间通信 (Proxmox 防火墙规则 + VLAN 隔离)
- 用户仅能通过公网 IP 访问自己的 VM
- VM 资源限制 (cgroup): 防止单个 VM 耗尽宿主机资源
  - CPU limit: 购买的核数上限
  - RAM limit: 购买的容量上限
  - Disk IOPS limit: 防止磁盘争用
  - Network bandwidth limit: 购买的带宽上限
```

#### Безопасность выделения IP

```
- IP 分配记录完整审计 (谁、何时、分配了什么 IP)
- IP 释放后冷却期 24h (防止 IP 被立即分配给其他人导致的误用)
- IP 黑名单: 被投诉/滥用的 IP 标记为不可分配
- IP 使用监控: 定期检查分配的 IP 是否正常使用中
```

---

### 4.5 Безопасность платежей

```
1. PCI DSS 合规:
   - 信用卡数据不经过自有服务器 (Stripe Elements / Checkout)
   - card_token 由 Stripe 前端直接生成，后端仅接收 token
   - 不在日志/数据库中存储任何 CVV/完整卡号

2. 加密货币:
   - 收款私钥冷存储 (离线签名)
   - 热钱包仅保留日常周转额度
   - 收款地址生成后验证校验和
   - 大额交易 ( > $10000) 人工审核后手动确认

3. 支付防欺诈:
   - 同一用户/IP 短时间内高频支付 → 风控冻结
   - 新注册用户大额支付 → 人工审核
   - 支付金额异常 (与商品价格不匹配) → 阻断
   - 退款率过高的用户 → 标记风控

4. 回调验签:
   - Stripe: 验证 webhook signature (stripe-signature header)
   - Coinbase: 验证 webhook signature (X-CC-Webhook-Signature header)
   - 支付宝: 验证 notify_id 回调支付宝服务器二次确认
   - 所有回调: 验证 IP 是否为已知支付网关 IP 段
```

#### Безопасность возвратов

```
- 退款必须经过二级审批 (客服发起 → 管理员确认)
- 退款前校验: 订单状态、退款时限、退款次数
- 退款金额不能超过原订单实付金额
- 原路退回: 支付通道退款接口 + 余额退回
- 退款互斥锁 (Redis): 防止并发重复退款
```

---

### 4.6 Контроль доступа и права

#### Модель RBAC

```
角色层级:
  super_admin    (超级管理员 — 全部权限)
  admin          (管理员 — 除系统配置外全部)
  finance        (财务 — 支付/对账/退款/结算)
  support        (客服 — 用户/订单/工单管理)
  supplier       (供应商 — 自己的商品/订单/结算)
  user           (普通用户 — 自己的资源/订单/工单)

权限定义:
  {module}.{action}
  例: order.view, order.create, order.refund, resource.destroy

权限检查中间件:
  class RbacMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $user = Auth::user();
          $requiredPermission = $request->route->get('permission');
          
          if (!$user || !$user->hasPermission($requiredPermission)) {
              AuditLog::unauthorized($user, $requiredPermission, $request);
              return errorResponse(403, 'Forbidden');
          }
          return $next($request);
      }
  }
```

#### Ограничение скорости API

```php
// webman 限流中间件 (Redis 令牌桶)
class RateLimitMiddleware
{
    // 默认: 60 req/min 每用户
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // 防暴力破解
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // 防批量注册
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // 支付限速
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // API 调用
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // 上传限速
    ];
    
    public function process(Request $request, callable $next): Response
    {
        $route = $request->route->getName();
        $limit = $this->limits[$route] ?? $this->limits['default'];
        $key = "ratelimit:{$request->getRealIp()}:{$route}";
        
        if (!$this->checkLimit($key, $limit)) {
            return errorResponse(429, 'Too Many Requests', [
                'retry_after' => $limit['per'],
            ]);
        }
        return $next($request);
    }
}
```

#### Изоляция данных поставщиков

```
数据隔离原则:
- 供应商只能查询和操作自己的资源
- 所有涉及 supplier_id 的查询自动追加 WHERE supplier_id = auth()->supplier_id

实现方式:
  // 全局 Scope
  class SupplierScope implements Scope
  {
      public function apply(Builder $builder, Model $model)
      {
          if ($user = Auth::user()) {
              if ($user->role === 'supplier') {
                  $builder->where('supplier_id', $user->supplier_id);
              }
          }
      }
  }
  
  // 在 Product/Order 等 Model 上注册
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 Аудит операций

```
审计日志记录内容:
- 操作者 ID、IP、User-Agent
- 操作时间
- 操作模块 (哪个菜单/接口)
- 操作类型: 创建/修改/删除/导出/审批
- 操作对象: 哪个资源的哪个字段
- 操作前值 / 操作后值 (字段级变更)
- 操作结果: 成功/失败
- 请求 ID (全链路追踪)

记录范围:
- 所有管理端操作 (100% 记录)
- 用户端敏感操作: 支付/销毁资源/KYC提交/修改密码 (100% 记录)
- 登录/登出 (100% 记录)
- API Key 创建/撤销 (100% 记录)

存储与保留:
- 审计日志写入独立数据库 (audit_db)，与应用库分离
- 至少保留 1 年，金融相关保留 3 年
- 支持导出为 CSV/JSON 供合规审查

审计日志中间件:
  class AuditMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $startTime = microtime(true);
          $response = $next($request);
          $duration = microtime(true) - $startTime;
          
          if ($this->shouldAudit($request)) {
              AuditLog::record([
                  'user_id'    => Auth::id(),
                  'ip'         => $request->getRealIp(),
                  'method'     => $request->method(),
                  'path'       => $request->path(),
                  'input'      => LogSanitizer::sanitize($request->all()),
                  'status'     => $response->getStatusCode(),
                  'duration'   => $duration,
                  'request_id' => $request->header('X-Request-Id'),
                  'user_agent' => $request->header('User-Agent'),
              ]);
          }
          return $response;
      }
  }
```

---

### 4.8 Правила контроля рисков

```
实时风控引擎:

规则 1: 新账号异常行为
  条件: 注册时间 < 24h AND (支付总额 > $500 OR 创建工单 > 5)
  动作: 标记账号为"观察中"，通知风控管理员

规则 2: 批量注册检测
  条件: 同一 IP 24h 内注册 > 3 个账号
  动作: 拒绝新注册，冻结该 IP 下新账号

规则 3: 支付异常
  条件: 同一用户 1h 内支付失败 > 5 次
  动作: 冻结支付功能 2h，生成风控工单

规则 4: 退款滥用
  条件: 同一用户 30 天内退款 > 3 笔 OR 退款率 > 20%
  动作: 限制该账号退款权限，新订单标记风控审查

规则 5: API 滥用
  条件: 单 token 1h 内 API 调用 > 10000 次
  动作: 该 token 降级 (降低限流阈值)，通知管理员

规则 6: 资源滥用
  条件: VM 被投诉 spam/DDoS/挖矿 (接收 Abuse 通知)
  动作: 自动关机，冻结资源，生成高优先级工单

风控动作:
- 标记 (flag): 仅记录，不影响使用
- 降级 (throttle): 降低限流阈值
- 冻结 (freeze): 暂时禁用特定功能
- 封禁 (ban): 账号永久封禁
```

---

### 4.9 Реагирование на инциденты

```
安全事件分级:

P0 (紧急) — 数据泄露、资金损失、平台宕机
  → 立即通知 CTO + 安全团队
  → 30 分钟内启动应急响应
  → 下线上游受影响服务，保留证据
  → 修复后 24h 内发布事件报告

P1 (严重) — 单账号被盗、支付欺诈、WAF 触发异常上升
  → 通知安全负责人
  → 2h 内处理
  → 冻结受影响账号/资源

P2 (一般) — 漏洞扫描发现中低危漏洞、异常登录告警
  → 录入工单系统
  → 下一个迭代修复

应急联系:
- 触发 P0/P1 告警后自动通知 (邮件 + 短信 + 电话)
- webman 健康检查端点: GET /health (返回 200 或告警)
- 值班表: 7×24 轮值，至少 2 人备岗
```

---

## 5. Движок выделения ресурсов

### Архитектура плагинов Provider

Каждая комбинация типа облачного продукта × облачного провайдера реализует единый интерфейс:

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // 物理机自营专用
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

ProviderFactory маршрутизирует к конкретной реализации по (product_type, provider):
- ProxmoxProvider (собственные физические машины: серверы/диски данных/IP)
- AwsServerProvider / AliyunServerProvider (сторонние облачные серверы)
- GcpIpProvider (сторонние IP)
- AzureDiskProvider (сторонние облачные диски)
- NamecheapDomainProvider / GoDaddyDomainProvider (домены)

### Гарантии асинхронных задач

- Worker выделения ресурсов опрашивает таблицу provision_tasks
- Управление параллелизмом по группам provider (максимум 5 одновременных на provider)
- Стратегия повтора: 1 мин → 5 мин → 15 мин → 1 ч → 6 ч → 24 ч (максимум 6 раз)
- Невозобновляемые сбои → оповещение + автоматическое создание заявки

### Полная цепочка от заказа до выделения ресурсов

```
用户下单                               支付                             资源开通
────────                               ────                             ────────
1. POST /cart                          5. POST /orders/{id}/pay         9. OrderPaid 事件
   → addToCart(sku, region, qty)          → 密码二次确认 (Confirmation)      → ProvisioningService
                                                                             .handleOrderPaid()
2. POST /orders                           → PaymentRouter::route()
   → createOrder()                          选择支付通道                   10. 每个 OrderItem:
   ← {order, order_items}                                                    → ProvisionTask::create()
                                        6. StripeChannel::                     status=pending
3. 应用优惠券                               createPaymentIntent()
   POST /coupons/validate                   → Stripe API                 11. Redis Queue Worker
   → validate('CODE', order_total)          ← {client_secret}                → ProviderFactory
   ← {discount, coupon_id}                                                     .create(task)
                                        7. 前端 confirmCardPayment()
4. GET /orders/{id}/payment-methods     8. Stripe webhook 回调            12. Provider->create()
   → 获取可用支付通道                       → 验签 + 幂等检查                   ├→ HostSelector::select()
   ← [{channel, fee, total}]               → transaction=success              ├→ ProxmoxApi::create()
                                            → 触发 OrderPaid 事件               │  createVM(CPU,RAM,Disk)
                                                                              │  allocateIP()
                                                                              │  startVM()
                                        重试策略 (失败时)                      ├→ 创建 Resource 记录
                                        ────────────────                     └→ 更新 host_machine
                                        1min → 5min → 15min                      已分配资源量
                                        → 1h → 6h → 24h
                                        (6 次后标记失败 + 告警)           13. Order::status = completed
                                                                           → NotificationDispatcher
                                        退款流程                                ::send('resource_ready')
                                        ────────
                                        用户申请 → 客服审核 → admin 确认
                                        → provider.destroy()
                                        → payment.refund()
                                        → 原路退回
```

### Решение для собственных физических машин: Proxmox VE (Community Edition)

Для собственных серверов используется Proxmox VE (с открытым исходным кодом, бесплатно, AGPL v3); PHP через HTTP вызывает REST API Proxmox для управления жизненным циклом KVM-виртуальных машин и распределения ресурсов.

Архитектура:
```
PHP (webman) ──HTTPS──> Proxmox VE API (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (分配给用户)
```

#### Клиентская обёртка ProxmoxApi

```php
class ProxmoxApi
{
    private string $baseUrl;
    private string $token;

    public function __construct(HostMachine $host)
    {
        $this->baseUrl = "https://{$host->ip_address}:8006/api2/json";
        $this->token   = $host->api_token_encrypted;
    }

    // GET  /api2/json/nodes/{node}/...
    public function get(string $path, array $params = []): array;
    // POST /api2/json/nodes/{node}/...
    public function post(string $path, array $data = []): array;
    // PUT  /api2/json/nodes/{node}/...
    public function put(string $path, array $data = []): array;
    // DELETE /api2/json/nodes/{node}/...
    public function delete(string $path): array;
}
```

#### Операции с ресурсами

**Создание VM (сервера):**
1. HostSelector выбирает хост-машину с достаточными ресурсами (сортировка по запасу cpu/ram/disk + балансировка нагрузки)
2. Выделить IP из ip_pool этой хост-машины
3. Создать VM через ProxmoxApi.post("/nodes/{node}/qemu") (задать vmid, name, cores, memory, net0, ipconfig0)
4. Подключить системный диск через ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") (scsi0: storagePool:sizeG)
5. Запустить VM через ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start")
6. Обновить выделенные объёмы в host_machine.specs (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**Апгрейд CPU (в реальном времени):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // 更新宿主机资源统计
```

**Апгрейд памяти (в реальном времени):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**Расширение системного диска:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**Создание отдельного диска данных:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**Создание отдельного IP:**
Выделить из пула IP → добавить виртуальную сетевую карту и настроить IP через API Proxmox, либо сохранить как отдельный ресурс и назначить дополнительной сетевой карте существующей VM.

**Уничтожение VM:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // 关机
$api->delete("/nodes/{node}/qemu/{vmid}");             // 删除 VM
releaseIp($resourceId);                                // 释放 IP 回池
$host->deallocate($specs);                             // 回收宿主机资源
```

#### Стратегия выбора хост-машины

```php
class HostSelector
{
    public function select(int $regionId, array $specs): HostMachine
    {
        return HostMachine::where('region_id', $regionId)
            ->where('status', 'online')
            ->whereRaw('JSON_EXTRACT(specs, "$.cpu_total") - JSON_EXTRACT(specs, "$.cpu_allocated") >= ?', [$specs['cpu']])
            ->whereRaw('JSON_EXTRACT(specs, "$.ram_total_gb") - JSON_EXTRACT(specs, "$.ram_allocated_gb") >= ?', [$specs['ram']])
            ->whereRaw('JSON_EXTRACT(specs, "$.disk_total_gb") - JSON_EXTRACT(specs, "$.disk_allocated_gb") >= ?', [$specs['system_disk']])
            ->orderByRaw('JSON_EXTRACT(specs, "$.cpu_allocated") / JSON_EXTRACT(specs, "$.cpu_total") ASC')
            ->firstOrFail();
    }
}
```

#### Сводка операций с отдельными ресурсами

| Операция | Реализация | Горячая операция |
|------|----------|--------|
| Создание VM (CPU+RAM+системный диск+IP) | Proxmox create qemu | — |
| Апгрейд CPU | PUT config cores | В реальном времени |
| Апгрейд памяти | PUT config memory | В реальном времени |
| Расширение системного диска | PUT resize disk | В реальном времени (требуется поддержка VM) |
| Создание отдельного диска данных | POST config добавление диска | В реальном времени |
| Создание отдельного IP | Выделение из IP-пула + добавление сетевой карты VM | В реальном времени |

### Жизненный цикл ресурсов

```
pending → active → destroyed (保留 30 天) → purged (不可恢复)
```

Продление: active → (renew) → active (продлевается expired_at)
Апгрейд: active → (upgrade) → upgrading → active

### Источники ресурсов

| Источник | Виртуализация/API | Типы продуктов | Описание |
|------|-----------|----------|------|
| Собственные физические машины | Proxmox VE (Community Edition) | Серверы, диски данных, IP | Размещение в собственном дата-центре, PHP вызывает Proxmox API |
| Сторонние облачные провайдеры | AWS/GCP/Alibaba Cloud/Huawei Cloud/Azure SDK | Серверы, IP, облачные диски | Перепродажа сторонних облачных ресурсов |
| Регистраторы доменов | Namecheap/GoDaddy/Alibaba Cloud Wanwang API | Регистрация/перенос доменов | Доменные услуги |

### Интеграции первой очереди

| Регион | Серверы | IP | Облачные диски | Домены |
|------|--------|----|------|------|
| Азиатско-Тихоокеанский | Alibaba Cloud, Huawei Cloud, AWS | Alibaba Cloud, GCP | Alibaba Cloud, Huawei Cloud | Alibaba Cloud Wanwang, Namecheap |
| Европа | AWS, GCP, Hetzner | GCP, OVH | AWS, GCP | Namecheap, Gandi |
| Северная Америка | AWS, GCP, Azure | AWS, GCP | AWS, Azure | GoDaddy, Namecheap |

---

## 6. Платёжная система

### Маршрутизация по нескольким каналам

PaymentRouter запрашивает доступные каналы с учётом валютных предпочтений пользователя, вычисляет фактическую сумму к оплате по каждому каналу (включая комиссию канала) и возвращает список вариантов оплаты.

### Платёжный процесс (Stripe)

```
用户端 (Flutter)               服务端 (webman)                Stripe API
───────────────               ──────────────                ──────────
1. 选择 Stripe 支付
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. 创建 payment_transaction
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → Stripe SDK ──────────────────────────────────────────→ 6. 用户确认支付
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  验签 (stripe-signature)
                                  幂等检查 (transaction_no)
                               8. 更新 transaction=success
                               9. 触发 OrderPaid 事件
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← 支付成功页面               ← 返回订单状态
```

### Оплата криптовалютой

1. Пользователь выбирает валюту (например, USDT-TRC20)
2. Бэкенд генерирует адрес для получения средств через Coinbase Commerce / BitPay API
3. Worker каждые 30 с проверяет подтверждения в блокчейне (или webhook)
4. Подтверждение поступления → срабатывает событие OrderPaid

### Курсы валют и мультивалютность

- Курсы валют периодически загружаются с exchangerate-api и сохраняются в Redis
- Товары ценообразуются на базе USD, остальные валюты пересчитываются в реальном времени
- Курс фиксируется при оформлении заказа; при возврате средства возвращаются по исходному курсу

### Управление видимостью платёжных каналов

Поля таблицы payment_channels:
- is_visible: показывать ли пользователям
- visible_regions: ограничение видимых регионов, пусто — все
- min_amount / max_amount: ограничение диапазона суммы заказа

### Сверка

Каждую ночь загружаются отчёты по всем каналам и построчно сверяются с системными транзакциями; при расхождении более $0.01 отправляется оповещение.

### Политика возвратов

- Серверы/VPS: полный возврат в течение 72 часов после покупки
- Домены: возврат возможен в течение 5 дней после регистрации (по правилам ICANN)
- IP: возврат невозможен после покупки
- Облачные диски: по правилам серверов
- Товары по специальным акциям: возврат невозможен

Процесс возврата: запрос пользователя → создание заявки → проверка поддержкой → подтверждение админом → provider.destroy() → payment.refund() → возврат тем же способом оплаты

---

## 7. Структура страниц клиента

### Клиент Flutter / HarmonyOS

- **Аутентификация**: вход/регистрация (email+пароль, Google OAuth, Apple ID, телефон), восстановление пароля, двухфакторная проверка
- **Главная**: выбор региона, вход в категории товаров, баннеры/акции, рекомендуемые товары
- **Товары**: список (фильтры по нескольким условиям), детали (конфигурация/регион/калькулятор цены), отзывы
- **Покупки и оплата**: корзина, подтверждение заказа (способ оплаты/адрес выставления счёта/баланс/промокод), касса, результат оплаты
- **Мои ресурсы**: список ресурсов (фильтр по статусу), операции с деталями (перезапуск/выключение/продление/апгрейд/уничтожение), SSO консоли, графики использования
- **Заказы**: список (ожидают оплаты/оплачены/завершены/возвращены), детали, счета-фактуры
- **Заявки**: список, создание, диалог
- **Личный кабинет**: профиль/KYC, баланс и пополнение, уведомления, управление адресами, настройки языка/валюты/безопасности
- **Общее**: центр помощи, условия обслуживания, о нас

### Админ-панель webman-admin

- **Дашборд**: обзор + графики тенденций
- **Управление пользователями**: список/детали/проверка KYC
- **Управление товарами**: категории/список/цены (SKU×регион)/запасы/отзывы
- **Управление заказами**: список/детали/проверка возвратов/счета-фактуры
- **Управление платежами**: настройка каналов/журнал транзакций/отчёты сверки
- **Управление ресурсами**: список/мониторинг задач выделения/конфигурация API провайдеров
- **Управление поставщиками**: проверка онбординга/список/распределение товаров/расчёты/вывод средств
- **Управление заявками**: очередь/мои заявки/мониторинг SLA
- **Управление доменами**: цены TLD/API регистраторов/управление переносами
- **Сообщения и уведомления**: управление шаблонами/журнал отправки
- **Системные настройки**: администраторы и роли/журнал операций/языки/курсы валют/регионы/системные параметры
- **Отчёты**: выручка/расчёты с поставщиками/анализ продаж товаров/региональный анализ

---

## 8. Система уведомлений

### Четыре канала

Email (SMTP/SendGrid) / SMS (Twilio/Alibaba SMS) / Push (FCM/HMS) / внутренние сообщения

### Процесс

Событие → Notification Dispatcher → подбор шаблона (код события + языковые предпочтения) → распределение по каналам согласно предпочтениям пользователя → асинхронная отправка через Redis Queue

### Типы уведомлений

Код подтверждения регистрации, успешная оплата заказа, завершение выделения ресурсов, напоминание об истечении ресурсов (7д/3д/1д), ответ на заявку, завершение возврата, предупреждения безопасности, рекламные акции

### Повтор при сбоях

3 повтора с экспоненциальной задержкой, управляется через webman redis-queue.

---

## 9. Система поставщиков

### Процесс онбординга

Регистрация → подача информации о компании + контактное лицо + способ расчётов → проверка администратором → после одобрения публикация товаров → проверка товаров админом → покупка пользователем → автоматическое распределение средств → запрос вывода средств поставщиком → выплата админом

### Изоляция прав

Поставщик видит только свои товары/заказы/расчётные документы/заявки/записи вывода средств. Ему недоступны выручка платформы, данные других поставщиков и конфигурация платёжных каналов.

### Правила распределения средств

- Собственные товары: commission_rate = 100% (всё платформе)
- Сторонние товары: commission_rate = 5%~20% (комиссия платформы)
- Формула расчёта: сумма товаров в заказе - комиссия платформы - комиссия канала = сумма к выплате поставщику
- Период расчётов: еженедельно / ежемесячно

### Полный бизнес-процесс поставщика

```
供应商入驻                              管理员审批
──────────                             ──────────
POST /supplier/apply                   GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,         → 审核供应商信息
     contact_phone, contact_email,       POST /admin/api/suppliers/{id}/approve
     settlement_method}                    → 确认密码
  → SupplierService::apply()               → SupplierService::approve()
  ← {supplier, status:pending}               → User::role = 'supplier'
                                              ← 成功
商品上架
────────
POST /supplier/products               管理员审核
  → {product_id, commission_rate}        → 关联供应商商品 + 设置佣金比例
  ← {supplier_product}                    → 商品状态: published

用户下单 ──→ 支付完成 ──→ 资源开通 ──→ 订单完成

定时结算 (每周一 04:17)                   提现
───────────────────────                 ──────
Cron: SupplierSettlement               POST /supplier/withdraw
  → 统计周期内已完成订单                    → 密码二次确认 (ConfirmationMiddleware)
  → 计算 total_sales - commission        → SupplierService::requestWithdraw()
  → = payable                              → 检查可提现余额
  → 创建 SupplierSettlement                 → 创建 SupplierWithdraw (status:pending)
  → Webhook: settlement.created          ← 成功

管理员打款                              管理员 API Key 管理
───────────                             ──────────────────
POST /admin/api/suppliers/              POST /admin/api/suppliers/{id}/api-keys
  withdraws/{id}/approve                  → 生成 sk_xxx (SHA256 存储)
  → 确认密码                               ← {api_key} (仅显示一次)
  → SupplierWithdraw: status=completed  DELETE /admin/api/suppliers/api-keys/{id}
  → Webhook: withdrawal.approved           → revoked=true
```

---

## 10. Мониторинг и эксплуатация

### Мониторинг ресурсов

- Собираемые метрики: использование CPU/памяти/диска/полосы пропускания, доступность IP, IOPS облачных дисков, DNS-резолвинг, истечение SSL-сертификатов
- Способы сбора: Agent/SNMP (собственные) + API мониторинга провайдеров (сторонние) + опрос WHOIS/DNS (домены)
- Период сбора: 5 минут, хранение в Prometheus + VictoriaMetrics

### Правила оповещений

| Событие оповещения | Серьёзность | Условие срабатывания |
|----------|--------|----------|
| Сервер недоступен | Критично | 3 последовательных неудачных Ping |
| CPU/память > 90% | Информация | Длится 10 минут |
| Диск > 90% | Предупреждение | Длится 5 минут |
| Полоса > 80% | Информация | Длится 30 минут |
| SSL-сертификат < 30 дней до истечения | Предупреждение | Ежедневная проверка |
| Домен < 30 дней до истечения | Предупреждение | Ежедневная проверка |
| Сбой задачи выделения | Критично | 2 последовательных сбоя |
| Расхождение при сверке платежей | Критично | Единичное расхождение > $0.01 |

---

## 11. Архитектура развёртывания

### Продакшн-среда

- Серверы приложений × 2: webman (многопроцессный) + Nginx + Supervisor
- База данных: MySQL 8.0 master-slave (1 мастер, 2 реплики) + Redis Cluster
- Очереди: webman redis-queue (платёжные callback'и/уведомления/задачи выделения)
- Планировщик: Crontab (сверка/расчёты/проверка доменов/напоминания о продлении)
- Хранилище: S3/OSS + CDN
- Мониторинг журналов: ELK/Loki + Prometheus + Grafana + Sentry

### Структура каталогов

```
cloud-php/
├── apps/
│   ├── flutter/           # Flutter 客户端
│   └── harmonyos/         # HarmonyOS 客户端 (ArkTS)
├── service/               # webman 服务端
│   ├── app/
│   │   ├── controller/    # 控制器 (按模块)
│   │   ├── service/       # 业务逻辑 (按模块)
│   │   ├── model/         # 数据模型
│   │   ├── middleware/     # 中间件
│   │   ├── event/         # 事件定义
│   │   ├── listener/      # 事件监听器
│   │   ├── queue/         # 队列任务
│   │   ├── provider/      # 云厂商适配器
│   │   └── cron/          # 定时任务
│   ├── common/            # 公共库 (auth/payment/i18n/notification/helper)
│   ├── config/            # 配置文件
│   ├── database/
│   │   └── migrations/    # 数据库迁移
│   └── storage/           # 日志/缓存/上传
├── admin/                 # webman-admin
├── docs/                  # 文档
└── docker/                # Docker 配置
```

### Ключевые зависимости Composer

workerman/webman-framework, webman/admin, webman/redis-queue, illuminate/database, firebase/php-jwt, stripe/stripe-php, phpseclib/phpseclib, monolog/monolog

### Оптимизация под высокие нагрузки

#### 1. Разделение чтения и записи MySQL

Eloquent автоматически направляет SELECT на соединение чтения, а INSERT/UPDATE/DELETE — на соединение записи.

```
配置 (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (主库)
  connections.mysql.read  → DB_READ_HOST  (从库，可配置多个实现负载均衡)
  sticky = true           → 同一请求周期内写后读走主库（防主从延迟）

环境变量:
  DB_HOST=10.0.1.1          # 主库（写）
  DB_READ_HOST=10.0.2.1     # 从库（读），可部署多个
```

**Правила маршрутизации чтения/записи:**

| Тип операции | Цель маршрутизации | Пример |
|---------|---------|------|
| SELECT | Соединение read | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | Соединение write | `Order::create(...)` |
| Все операции в транзакции | Соединение write | `DB::transaction(...)` |
| Чтение после записи (sticky) | Соединение write | В пределах одного запроса |

#### 2. Многоуровневая стратегия кэширования Redis

`CacheService` используется для кэширования часто читаемых данных; при недоступности Redis происходит автоматическое понижение до прямого запроса к БД.

```
缓存分层:
  L1: Redis (进程间共享，毫秒级)
  L2: MySQL (持久化，兜底)

缓存策略:
  产品列表        TTL 5min    按 region_id + category_id + keyword 分键
  产品详情        TTL 10min   按 product_id 分键，内容变更时主动失效
  区域列表        TTL 1h      区域数据极少变动
  汇率            TTL 30min   定时任务刷新 + 主动更新
  TLD 定价        TTL 1h      TLD 价格变动频率低
  帮助文章        TTL 10min   发布/修改时主动失效
  商品分类        TTL 10min   分类树变更时主动失效

缓存预热 (部署后):
  CacheService::warmUp(['products:all', 'regions', 'tlds', 'exchange_rates'])

主动失效 (数据变更时):
  ProductController::update() → CacheService::forgetPattern('products:*')
  Crontab::ExchangeRateSync → CacheService::put('exchange_rates', $rates, TTL)
```

```php
// 使用示例
$products = CacheService::remember(
    "products:list:{$regionId}:{$categoryId}",
    CacheService::TTL_PRODUCT_LIST,
    fn() => Product::where('region_id', $regionId)->where('category_id', $categoryId)->get()
);
```

#### 3. Сжатие ответов Nginx + ограничение скорости

```
gzip 压缩:
  gzip on, comp_level=6
  gzip_types: application/json, text/plain, text/javascript, image/svg+xml
  效果: JSON 响应压缩率 70-85%，节省带宽

proxy 优化:
  proxy_buffering on           # 缓冲上游响应，慢客户端不占 worker
  proxy_http_version 1.1       # HTTP/1.1 长连接复用
  keep-alive 到上游             # 减少 TCP 握手

限流:
  limit_req: 10 req/s per IP (burst 20)
  limit_conn: 20 concurrent per IP
  /health 端点不限流（关闭 access_log 减 I/O）
```

#### 4. Рекомендации по индексам БД

На основе анализа паттернов запросов следующие индексы значительно сокращают число сканируемых строк при высоких нагрузках:

| Таблица | Рекомендуемый индекс | Покрываемые запросы |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | Список заказов пользователя + фильтр по статусу |
| `orders` | `(order_no)` (уникальный) | Точный поиск по номеру заказа |
| `products` | `(status, category_id, sort)` | Список товаров на витрине + фильтр по категории + сортировка |
| `product_skus` | `(product_id, status)` | Список SKU + фильтр по статусу |
| `product_regions` | `(sku_id, region_id)` (уникальный) | Поиск региональных цен |
| `resources` | `(user_id, status)` | Список моих ресурсов |
| `resources` | `(expired_at, status)` | Планировщик проверки истечения |
| `provision_tasks` | `(status, next_retry_at)` | Опрос Worker'ом ожидающих задач |
| `refresh_tokens` | `(user_id, revoked)` | Запросы управления сессиями |
| `payment_transactions` | `(order_id)` | Поиск транзакций по заказу |
| `payment_transactions` | `(transaction_no)` (уникальный) | Идемпотентность Webhook |
| `tickets` | `(user_id, status)` | Список заявок пользователя |
| `notifications` | `(user_id, read_at, created_at)` | Список уведомлений пользователя |

#### 5. Оценка одновременных соединений

```
webman 多进程:
  CPU 核数 × 进程数 = worker 数
  例: 4核 × 8 worker = 32 worker 进程
  
MySQL 连接数:
  每个 worker 维持 1 个持久连接
  32 worker × 2 实例 (service + admin) = 64 连接
  主库 32 + 从库 32，保守建议 MySQL max_connections ≥ 200

Nginx 连接数:
  worker_connections 1024 × worker_processes auto
  峰值并发 ≈ worker_connections × worker_processes / 2
  4核服务器 ≈ 2048 并发连接
```

---

## 12. Сводная таблица статуса реализации

### Основные модули

| Модуль | Статус | Описание |
|------|------|------|
| **User** | ✅ Завершён | Регистрация/вход/подтверждение email/OAuth/TOTP/управление сессиями/GDPR-удаление/CRUD адресов |
| **Product** | ✅ Завершён | Цены SKU×регион, категории, поиск (ES), отзывы, атрибуты, массовый импорт/экспорт |
| **Order** | ✅ Завершён | Корзина, оформление, жизненный цикл, возвраты, счета-фактуры (PDF), купоны |
| **Payment** | ✅ Завершён | Канал Stripe, маршрутизация по каналам, проверка подписи webhook, сверка |
| **Provisioning** | ✅ Завершён | Proxmox + AWS EC2 + расширяемая архитектура ProviderFactory |
| **Domain** | ✅ Завершён | Цены TLD, DNS-записи, утверждение переносов доменов |
| **Supplier** | ✅ Завершён | Проверка онбординга, публикация товаров, расчёты, вывод средств, управление API Key |
| **Monitor** | ✅ Завершён | Проверка доступности ресурсов, движок оповещений, мониторинг SSL-сертификатов |
| **Ticket** | ✅ Завершён | Создание/ответ/назначение/закрытие/отслеживание SLA |
| **Notification** | ✅ Завершён | Четыре канала: email/SMS/Push/внутренние сообщения + управление предпочтениями |
| **Report** | ✅ Завершён | Отчёты: выручка/поставщики/регионы |
| **I18n** | ✅ Завершён | Несколько языков, валют, часовых поясов |

### Система безопасности

| Функция | Статус |
|------|------|
| WAF (8 категорий, 45+ правил: SQL-инъекции/XSS/инъекции команд/включение файлов/инъекции в заголовки/SSRF/NoSQL-инъекции/открытые редиректы) | ✅ |
| CORS-промежуточный слой | ✅ |
| ClientPlatform middleware распознавания платформы (8 платформ) | ✅ |
| Ограничение скорости API (Redis-токен-бакет) | ✅ |
| Geo-блокировка (MaxMind GeoIP2) | ✅ |
| Режим обслуживания (переключатель через переменную окружения + белый список IP) | ✅ |
| Шифрование запросов/ответов (AES-256-GCM) | ✅ |
| Журнал аудита (отдельная БД, включая отслеживание client_platform) | ✅ |
| Маскирование данных (автоматически в журналах/ответах) | ✅ |
| Привязка JWT к отпечатку устройства + ротация токенов + запись client_platform | ✅ |
| Пароли bcrypt (cost=12) + повторное шифрование Encryptable | ✅ |
| Повторное подтверждение паролем (ConfirmationMiddleware, 5 неудач → блокировка 15 мин) | ✅ |
| WAF middleware для админ-панели | ✅ |
| Мониторинг исключений Sentry (SentryBootstrap + маскирование в before_send) | ✅ |
| Feature Flags (динамическое переопределение через Redis + API админ-панели) | ✅ |

### Новые функции (2026-05-21)

| Функция | Статус |
|------|------|
| Внешний API поставщиков (аутентификация по API Key + конечные точки заказов/ресурсов/расчётов/вывода средств) | ✅ |
| WebSocket-пуши в реальном времени (нативный WebSocket Workerman + прослушивание событий) | ✅ |
| Скрипты нагрузочного тестирования k6 (smoke/продукты/конкурентность) | ✅ |

### Статистика бэкенда

| Метрика | Количество |
|------|------|
| API-конечные точки | 135 |
| Моделей данных | 50+ |
| Таблиц БД | 50+ |
| Middleware | 15 (глобальных 7 + маршрутных 6 + внешний API 1 + admin WebSocket) |
| Планируемых задач | 7 |
| Миграций | 22 |
| Тесты | 362 tests / 579 assertions (Service 295 + Admin 67) |
| Тестовых файлов | 22 |
| Скриптов нагрузочного теста k6 | 3 (smoke / products / concurrent) |

### Документация

| Документ | Путь |
|------|------|
| Спецификация проектирования системы | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| Дизайн админ-панели | `docs/admin-design.md` |
| Документация API поставщиков | `docs/supplier-api.md` |
| Чек-лист развёртывания | `docs/deployment.md` |
| Скрипт API smoke-теста | `docs/api-test.sh` |

### Статус фронтенда

| Сторона | Статус | Описание |
|----|------|------|
| Flutter | 🟡 В процессе | ApiClient подключён к номеру версии в заголовке + единый слой данных ApiService; вход/список товаров/корзина/список ресурсов уже интегрированы с API; история заказов/центр уведомлений требуют проверки в сборочном окружении |
| HarmonyOS | 🔴 Ранняя стадия | Только страница входа и ApiClient |
| Admin Panel | ✅ Завершён | Дашборд/пользователи/товары/заказы/платежи/ресурсы/поставщики/заявки/домены/уведомления/система/отчёты/Webhook/импорт-экспорт — полный функционал |
