# Документ функционального дизайна CloudPlatform

## 1. Аутентификация и авторизация пользователей

### 1.1 Регистрация

```
POST /api/v1/auth/register
  → WAF-сканирование
  → Ограничение частоты 3 req/min
  → Проверка пароля len≥8
  → Проверка уникальности почты/телефона
  → bcrypt(password, cost=12)
  → Snowflake::id() генерация user_id
  → Encryptable::set() шифрование чувствительных полей
  → Создание User + UserProfile + UserBalance
  → NotificationDispatcher::send('email_verify') отправка письма подтверждения
  → AuditLogger::record('user_registered')
  ← {access_token, refresh_token, expires_in, token_type}
```

**Поток данных:**

```
Client                    Middleware Chain           AuthService              DB
  │                           │                        │                     │
  │ POST /api/v1/auth/register   │                        │                     │
  │──────────────────────────▶│ WAF→RateLimit→Encrypt  │                     │
  │                           │───────────────────────▶│                     │
  │                           │                        │ User::create() ────▶│
  │                           │                        │ UserProfile::create │
  │                           │                        │ UserBalance::create │
  │                           │                        │ RefreshToken::create│
  │                           │                        │ (client_platform)   │
  │                           │                        │ AuditLogger::record │
  │◀──────────────────────────│◀───────────────────────│                     │
  │ {access_token, refresh}   │                        │                     │
```

### 1.2 Вход

```
POST /api/v1/auth/login
  → WAF-сканирование
  → Ограничение частоты 5 req/min
  → Проверка капчи (капча по клику, ограничение 3 попытки)
  → Hash::check(password, user->password_hash)
  → 5 неудач → login_lock:{userId} Redis TTL 900s
  → Проверка TOTP (обязательно, если включено у пользователя, поле totp_code обязательно;
      накопление 5 ошибок → totp_fail:{userId} → login_lock TTL 900s)
  → Обнаружение нового IP → почтовое предупреждение
  → deviceFingerprint = sha256(UA + сегмент IP, для IPv6 берётся префикс)
  → clientPlatform = заголовок X-Client-Platform
  → issueTokens(): Access(15min) + Refresh(30d)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth (Google / Apple)

```
GET /api/v1/auth/google → Google OAuth → callback?code=xxx
  1. Проверка ID Token Google/Apple
  2. Поиск или создание пользователя (сопоставление по email)
  3. Выдача токена (включая client_platform)
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 Двухшаговая проверка TOTP

```
1. POST /api/v1/user/totp/setup
     → Генерация secret + QR URL (временное хранение в Redis 10 минут, не персистентно)
     ← {secret, qr_url, manual}
2. POST /api/v1/user/totp/verify
     → Проверка TOTP code (первый раз — включение setup, далее — проверка)
     ← {verified: true}
3. GET /api/v1/user/totp/recovery-codes
     → Генерация 8 одноразовых кодов восстановления (требуется подтверждение пароля)
     ← {recovery_codes: [8 шт.]}
4. При входе: ввести TOTP code или использовать код восстановления
     → POST /api/v1/auth/login/recovery (login, password, recovery_code)
```

### 1.5 Управление сессиями

```
GET /api/v1/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/v1/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/v1/user/account (удаление по GDPR)
  → Повторное подтверждение пароля
  → Мягкое удаление User
  → Все RefreshToken отозваны
```

---

## 2. Управление товарами

### 2.1 Модель продукта

```
Product (1) ────── (N) ProductSku ────── (N) ProductRegion
  │                    │                      │
  │ category_id        │ specs (JSON)         │ region_id
  │ supplier_id        │ cycle (monthly/yr)   │ price
  │ status             │                      │ original_price
  │ name (многоязычный JSON)│                  │ currency
  │ description        │                      │ stock
  └────────────────────┴──────────────────────┘

Product (1) ────── (N) ProductImage
Product (1) ────── (N) ProductReview
Product (1) ────── (N) ProductAttribute
```

### 2.2 Список продуктов (с кэшированием)

```
GET /api/v1/products?category_id=1&region_id=2&keyword=vps&page=1

CacheService::remember('products:list:{hash}', TTL 5min)
  → Product::published()
    → with(category, skus.regionPrices)
    → Фильтрация по category_id/region_id/keyword/supplier_id
    → count + skip/take пагинация
  ← Результат пагинации

Инвалидация кэша:
  Изменение Admin product/SKU/region-price
  → CacheService::forget("products:detail:{$id}")
  → CacheService::forgetPattern('products:list:*')
```

### 2.3 Поиск товаров (Elasticsearch)

```
GET /api/v1/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (IK Analyzer китайская сегментация)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 Отзывы о товарах

```
GET /api/v1/products/{id}/reviews
  → Одобренные отзывы + средняя оценка + распределение оценок
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/v1/products/{id}/reviews (требуется вход)
  → rating (1-5) + content
  → status = pending (отображается после модерации администратором)
```

### 2.5 Массовый импорт/экспорт

```
GET /admin/api/v1/products/export
  → Скачивание CSV (продукты + SKU + региональные цены)

POST /admin/api/v1/products/import
  → Загрузка CSV upsert
  ← {imported: N, errors: [...]}
```

---

## 3. Система заказов

### 3.1 Корзина

```
POST /api/v1/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/v1/cart           → Список корзины (детали SKU + актуальные цены)
DELETE /api/v1/cart/{id}   → removeFromCart
PUT /api/v1/cart/{id}      → updateCartQuantity
```

### 3.2 Процесс оформления заказа

```
1. POST /api/v1/orders                           Создание заказа
     → Проверка остатков, расчёт цены, применение купона
     ← {order_id, order_no, items, total}

2. POST /api/v1/coupons/validate                 Применение купона
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/v1/orders/{id}/payment-methods       Получение доступных платёжных каналов
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/v1/orders/{id}/pay                  Инициация оплаты
     → Повторное подтверждение пароля (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 Жизненный цикл заказа

```
                    ┌─────────┐
                    │ pending  │ ожидает оплаты
                    └────┬─────┘
                         │ Оплата успешна
                    ┌────┴─────┐
                    │  paid    │ оплачен
                    └────┬─────┘
                         │ Событие OrderPaid
                         │ → ProvisioningService
                    ┌────┴─────┐
                    │ completed│ завершён
                    └────┬─────┘
                         │ Пользователь запросил возврат
                    ┌────┴─────┐
                    │ refunded │ возвращён
                    └──────────┘

Условия возврата: сервер в течение 72ч | домен в течение 5 дней | IP невозвратимый | промотовары невозвратимые (для других типов, например disk, ограничения окна нет; неизвестные типы категорий по умолчанию пропускаются)
Процесс возврата: заявка пользователя → создание Ticket → проверка службы поддержки → подтверждение admin → Provider.destroy() → Payment.refund()
```

---

## 4. Платёжная система

### 4.1 Маршрутизация по нескольким каналам

```
PaymentRouter::route(Order $order)
  → Фильтрация доступных каналов (is_visible + visible_regions + min/max_amount)
  → Сопоставление по currency
  → Расчёт фактической суммы оплаты по каждому каналу (с комиссией)
  → Сортировка по возрастанию fee
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Оплата Stripe

```
Client (Flutter)          Server (webman)            Stripe API
─────────────────         ────────────────           ──────────
1. Выбор Stripe
   → POST /orders/{id}/pay
                          2. createPaymentIntent()
                              amount (cents)
                              currency
                              metadata{order_id, order_no}
                                                    3. paymentIntents.create
                                                       ← {id, client_secret}
                          4. Создание transaction
                             (status=pending)
                           ← client_secret
5. confirmCardPayment()
   (Stripe.js SDK)
                                                    6. Пользователь подтверждает оплату
                                                       ← payment_intent.succeeded
                          7. POST /payments/webhook/stripe
                             Webhook::constructEvent()
                             Проверка подписи stripe-signature
                             Проверка идемпотентности transaction_no
                          8. transaction=success
                          9. Триггер события OrderPaid
                             → ProvisioningService
                             → WebSocket push
                             → Уведомления email/SMS/Push
```

### 4.3 Сверка

```
Cron: PaymentReconcile (ежедневно 02:37)
  → Получение отчётов по расчётам всех каналов
  → Построчная сверка с transaction системы
  → Расхождение > $0.01 → предупреждение
```

---

## 5. Движок открытия ресурсов

### 5.1 Плагинная архитектура Provider

```php
interface ProviderInterface {
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}

ProviderFactory:
  (productType, provider) → экземпляр Provider
  'server:proxmox'     → ProxmoxProvider
  'server:aws_ec2'     → AwsProvider (расширяемый)
  'server:aliyun_ecs'  → AliyunProvider (расширяемый)
  'domain:namecheap'   → DomainProvider (расширяемый)
```

### 5.2 Полная цепочка открытия

```
Триггер события OrderPaid
  │
  ▼
ProvisioningService::handleOrderPaid()
  │
  ├→ Создание ProvisionTask для каждого OrderItem
  │   status=pending, next_retry_at=now
  │
  ▼
ProvisionWorker (потребление из Redis Queue)
  │
  ├→ ProviderFactory::create(task)
  │     └→ ProxmoxProvider
  │
  ├→ HostSelector::select(regionId, specs)
  │     Сортировка по остатку cpu/ram/disk + балансировка нагрузки
  │
  ├→ IpPool::allocate(hostId)
  │
  ├→ ProxmoxApi::post('/nodes/{node}/qemu')
  │     Создание VM (vmid, name, cores, memory, net, ipconfig)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/config')
  │     Монтирование системного диска (scsi0: pool:sizeG)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/status/start')
  │     Запуск VM
  │
  ├→ Создание записей Resource + Disk + IpAllocation
  │
  ├→ Обновление выделенного объёма ресурсов host_machine
  │
  └→ Order::status = completed
       → WebSocket push 'resource.provisioned'
       → NotificationDispatcher::send('resource_ready')

Стратегия повтора:
  1min → 5min → 15min → 1h → 6h → 24h (после 6 попыток — пометить как сбой + предупреждение)
```

> **Эволюция канала поставки**: Rust kvm-server (`infrastructure/kvm-server`, workspace e-cat) уже в репозитории —
> gRPC `ping/create_vm/vm_status` (:50051) + обнаружение через etcd, PHP-сторона KvmClient /
> RegistryProcess (`service/app/grpc/`) подключены. На уровне драйверов сейчас **имитационный драйвер** (реальный
> драйвер libvirt — Phase 2), цепочка открытия пока идёт напрямую через ProxmoxProvider; после перехода создания VM
> на kvm-server процесс из этого раздела не меняется, меняется только канал.

### 5.3 Сводка операций Proxmox

| Операция | API | Горячая операция |
|------|-----|--------|
| Создание VM | POST /nodes/{node}/qemu | — |
| Апгрейд CPU | PUT /qemu/{vmid}/config cores | онлайн |
| Апгрейд памяти | PUT /qemu/{vmid}/config memory | онлайн |
| Расширение системного диска | PUT /qemu/{vmid}/resize disk | онлайн |
| Создание диска данных | POST /qemu/{vmid}/config scsi{n} | онлайн |
| Создание отдельного IP | POST /qemu/{vmid}/config net{n} | онлайн |
| Уничтожение VM | POST stop → DELETE qemu | — |
| Запрос статуса | GET /qemu/{vmid}/status/current | — |

---

## 6. Система поставщиков

### 6.1 Процесс вступления

```
POST /api/v1/supplier/apply (требуется вход пользователя)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → Проверка администратором

Одобрение администратором:
  POST /admin/api/v1/suppliers/{id}/approve (подтверждение пароля)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → Пользователь получает права поставщика

Размещение товара:
  POST /api/v1/supplier/products
    → {product_id, commission_rate}
    → Привязка товара к поставщику

Расчёты:
  Cron: SupplierSettlement (каждый понедельник 04:17)
    → Подсчёт завершённых заказов за период
    → total_sales - commission = payable
    → Создание SupplierSettlement

Вывод средств:
  POST /api/v1/supplier/withdraw (подтверждение пароля)
    → Проверка доступного остатка
    → Создание SupplierWithdraw (status=pending)
    → Одобрение администратором и выплата
```

### 6.2 Внешний API

```
POST /admin/api/v1/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → Хранение hash('sha256', rawKey)
  ← {api_key: "sk_xxx..."} (показывается только один раз)

Использование поставщиком:
  GET /api/v1/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → Проверка подписи SupplierApiKeyMiddleware
    → Фильтрация данных по supplierId
```

---

## 7. Домены и DNS

```
GET /api/v1/domain/check/{domain}/{tld}    # доступность домена
GET /api/v1/domain/tlds                     # список регистрируемых TLD (кэш 1h)
GET /api/v1/dns/{domain}                    # список DNS-записей
POST /api/v1/dns/{domain}/records           # добавление DNS-записи
DELETE /api/v1/dns/{domain}/records/{id}    # удаление DNS-записи (подтверждение пароля)
```

---

## 8. Система тикетов

```
POST /api/v1/tickets                    # создание тикета
GET /api/v1/tickets                     # мои тикеты
GET /api/v1/tickets/{id}                # детали тикета
POST /api/v1/tickets/{id}/reply         # ответ по тикету

Администратор:
  GET /admin/api/v1/tickets              # очередь тикетов
  POST /admin/api/v1/tickets/{id}/assign # назначение специалиста поддержки
  POST /admin/api/v1/tickets/{id}/close  # закрытие тикета

Событийная модель:
  Событие TicketCreated
    → AutoAssignListener: назначение специалисту с наименьшей нагрузкой
    → WebSocket push 'ticket.created'
```

---

## 9. Система уведомлений

### 9.1 Распределение по четырём каналам

```
Триггер события → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → прямая запись в таблицу notifications
```

### 9.2 Типы уведомлений

| Событие | Канал | Момент срабатывания |
|------|------|---------|
| Подтверждение регистрации | email | после регистрации почты |
| Предупреждение о необычном входе | email | вход с нового IP |
| Оплата заказа успешна | email/push | оплата завершена |
| Открытие ресурса завершено | email/push/in_app | завершено Provisioning |
| Напоминание об истечении ресурса | email/push | за 7d/3d/1d |
| Ответ по тикету | email/push/in_app | новое сообщение в Ticket |
| Возврат завершён | email/push | возврат обработан |
| Истечение SSL-сертификата | email | за 30d |
| Истечение домена | email | за 30d |

---

## 10. Мониторинг и предупреждения

### 10.1 Мониторинг ресурсов

```
Cron: CollectMetrics (каждые 5 минут)
  → Опрос активных ресурсов
  → ProxmoxApi::status() / Provider API
  → Хранение метрик в Redis hash (TTL 1h)

Администратор:
  GET /admin/api/v1/monitor/dashboard
    → Обзорная статистика + последние предупреждения
  GET /admin/api/v1/monitor/resources/{id}
    → Актуальные метрики (чтение из Redis)
```

### 10.2 Правила предупреждений

| Правило | Серьёзность | Условие срабатывания |
|------|--------|---------|
| server_down | серьёзная | 3 последовательных неудачных Ping |
| cpu_high | предупреждение | CPU > 90% в течение 10min |
| disk_high | предупреждение | диск > 90% в течение 5min |
| ssl_expiring | предупреждение | SSL-сертификат истекает < 30 дней |
| domain_expiring | предупреждение | домен истекает < 30 дней |
| provision_failed | серьёзная | повторные сбои задачи открытия |

---

## 11. Планировщик задач

| Cron-выражение | Задача | Назначение |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | синхронизация курсов каждые 4 часа |
| `37 2 * * *` | PaymentReconcile | ежедневная сверка |
| `17 4 * * 1` | SupplierSettlement | расчёты с поставщиками по понедельникам |
| `23 6 * * *` | ExpirationCheck | проверка истечения + уведомления |
| `43 7 * * *` | SslCertificateCheck | проверка SSL-сертификатов |
| `*/5 * * * *` | CollectMetrics | сбор метрик ресурсов |
| `*/30 * * * *` | CheckExpirations | проверка истечения ресурсов |

---

## 12. Интернационализация (i18n)

### 12.1 Поток запроса

```
Клиент → Accept-Language: zh-CN
  → LocaleMiddleware (глобальный промежуточный слой)
    → I18n::setLocale('zh-CN')
    → Загрузка i18n/zh-CN/messages.php
```

### 12.2 Способы перевода

**Статический текст:** `I18n::trans('auth.login_success')` → `登录成功`
**JSON-поля:** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**Подстановка параметров:** `I18n::trans('validation.required', ['field' => '邮箱'])` → `邮箱 不能为空`

### 12.3 Охват

120 записей, покрывающих все модули: аутентификация/товары/заказы/оплата/ресурсы/KYC/тикеты/уведомления/поставщики/Webhook/система и др. Поддержка возврата языка (неподдерживаемый язык → en-US).

---

## 13. Feature Flags — функциональные переключатели

```
config/features.php (значения по умолчанию)
  ↓ может быть переопределено
.env переменные окружения FEATURE_*
  ↓ может быть переопределено во время выполнения
Redis feature:{name} (TTL 1h, динамическая настройка через API управления)

Управляющий API:
  GET /admin/api/v1/features → список всех Flag со статусом/источником
  PUT /admin/api/v1/features/{name} → enable/disable/toggle/reset

Текущие Flags:
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. SSL-сертификаты

Продукт SSL-сертификатов поддерживает три типа: DV/OV/EV, автоматическая выдача и продление по протоколу ACME (Let's Encrypt) или через API внешнего CA (ZeroSSL/GoGetSSL).

**Ключевой процесс:**

    用户选购 SSL 套餐 → 下单支付 → ProvisionTask 创建
      → SslProvider::create() → CertificateAuthority::issue()
      → ACME HTTP-01/DNS-01 验证 → 证书签发
      → 每天检查 expires_at → 到期前 14 天自动续期
      → 到期 → status=expired → 通知用户

**Модели данных:** `ssl_plans` (пакеты), `resource_ssl_certs` (экземпляры сертификатов)

## 15. Объектное хранилище (S3)

Объектное хранилище, совместимое с API S3, поддерживает AWS S3 и собственное MinIO. Пользователи загружают/скачивают файлы по предподписанным URL.

**Модель данных:** `resource_storage_buckets`

## 16. Ускорение CDN

Продукт CDN поддерживает четырёх провайдеров (Cloudflare / AWS CloudFront / Aliyun CDN / Tencent Cloud CDN): сервер или хранилище (bucket) можно подключить как origin к CDN, поддерживаются очистка кэша и опциональная настройка HTTPS-сертификатов.

**Адаптерная архитектура:** в `service/app/cdn/provider/` по одному адаптеру на провайдера, все реализуют общий `CdnAdapterInterface` (createDomain / configureDomain / purgeCache / disableDomain / requiresIcpRegistration), распределение выполняет `CdnAdapterFactory` по `provider_type`:

| provider_type | Адаптер | Протокол подключения | Нужна ICP-регистрация |
|---------------|---------|----------------------|-----------------------|
| `cloudflare` | CloudflareAdapter | REST v4 API (включая SSL SaaS с автоконфигурацией сертификатов) | Нет |
| `cloudfront` | CloudFrontAdapter | aws-sdk-php (CloudFront + ACM) | Нет |
| `aliyun` | AliyunCdnAdapter | RPC-подпись | Да |
| `tencent` | TencentCdnAdapter | TC3-подпись | Да |

**Настройка учётных записей провайдеров:** в админ-панели через `/admin/providers` (CRUD) ведутся записи `provider_apis` (учётные данные шифруются через Encryptable, `code` по соглашению `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`). Приоритет разрешения учётных данных на стороне пользователя: привязанная запись (provider_account_id) → активная запись по code → конфигурация env как запасной вариант.

**Строгая привязка (strict snapshot):** `provider_account_id` фиксируется при создании домена; последующие удаление/очистка кэша используют только эту привязанную запись; при её отсутствии или отключении возвращается 4003 без тихого переключения. Для Aliyun/Tencent требуется ICP-регистрация домена; при её отсутствии возвращается 4002 (с подсказкой `requires_icp_registration`).

**Очистка кэша:** `POST /api/v1/cdn/domains/{id}/purge` — URL автоматически дедуплицируются и очищаются от пробелов (не более 100), допускаются только сам домен и поддомены, подстановочные знаки и внешние URL отклоняются, операция идемпотентна.

**Интерфейсы:** CdnAdapterInterface + CdnProvider (переиспользует канал апгрейда ProvisionProvider, поддерживается повышение plan)

**Модель данных:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; из cert_config перед записью удаляется приватный ключ, хранятся только нечувствительные данные сертификата)

## 17. Поминутная тарификация

Полный конвейер: сбор использования ресурсов → агрегация → расчёт → списание:

    ResourceMonitor 每 5 分钟采集指标 → resource_metrics
      → UsageAggregator 每小时聚合 → usage_events
      → BillingEngine 每日扣减余额 → 余额不足 → 挂起资源
      → SuspendCheck 每 30 分钟检查 → 余额恢复 → 解挂

**Модели данных:** `resource_metrics`, `usage_events`, `usage_rates`, `usage_invoice_items`

## 18. Рейтинг поставщиков

Купившие пользователи могут оценивать поставщиков по четырём измерениям (качество/поддержка/скорость поставки/соотношение цены и качества), один раз на заказ. Управляющая сторона может модерировать (approve/hide).

**Модели данных:** `supplier_ratings`, `suppliers.rating_avg/rating_count`

## 19. Рекомендательная партнёрка

Пользователь генерирует референс-ссылку (?ref=CODE), при регистрации нового пользователя привязывается affiliate_code, после оплаты заказа комиссия начисляется автоматически.

**Событийная модель:** OrderPaid → Affiliate OrderPaidListener → attributeOrder()

**Модели данных:** `affiliate_plans`, `affiliate_links`, `affiliate_earnings`, `affiliate_payouts`

## 20. GraphQL API

Предоставляются два эндпоинта: POST /graphql (публичные запросы) и POST /api/v1/graphql (аутентифицированные запросы). На базе webonyx/graphql-php, лимит глубины запроса 5 уровней, лимит сложности 100.

**Чувствительные операции остаются REST-only:** оплата, вывод средств, возвраты, проверка KYC.

## 21. Наблюдаемость

Эндпоинт метрик Prometheus — отдельный процесс 127.0.0.1:9100, не подвержен влиянию WAF/ограничения частоты. MetricsMiddleware записывает количество HTTP-запросов и задержки. Docker Compose предустановлен с Prometheus + Grafana + правила предупреждений + дашборды.

**Проверки здоровья:** /health (публичный), /health/live, /health/ready (5 проверок зависимостей), /health/deps (детальные задержки)
