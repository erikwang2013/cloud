# Документация API поставщика v1

## Обзор

Функциональность поставщика предоставляет два набора API:

| Тип | Способ аутентификации | Префикс | Статус |
|------|---------|------|------|
| **Внутренний API** | Пользовательский Bearer Token | `/api/supplier/` | Доступен |
| **Внешний API** | API Key (`sk_xxx`) | `/api/supplier/external/` | Доступен |

**Base URL**: `https://api.example.com`

**Версионирование**: задаётся HTTP-заголовком `X-Api-Version: v1`. При отсутствии — по умолчанию `v1`, неподдерживаемые версии возвращают `400`. Действует только для путей `/api/*` и `/admin/api/*`, обрабатывается единым `VersionMiddleware`.

---

## Внутренний API (доступен сейчас)

Внутренний API использует ту же пользовательскую Bearer Token аутентификацию, что и остальные интерфейсы платформы, и предназначен для вызовов со стороны клиента/фронтенда уже вошедших пользователей-поставщиков.

### Аутентификация

```
Authorization: Bearer <user_access_token>
X-Api-Version: v1
```

Пользователю нужно сначала войти через `/api/auth/login`, чтобы получить Token, при этом роль учётной записи должна быть `supplier` (устанавливается администратором после одобрения заявки поставщика).

---

### Формат ответов

#### Успешный ответ

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### Ответ со пагинацией

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

#### Ответ об ошибке

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | Описание |
|------|------|
| 0 | Успех |
| 400 | Ошибка параметров запроса / неподдерживаемая версия API |
| 401 | Не выполнен вход или Token истёк |
| 403 | Нет прав доступа (роль не поставщик / не пройдено подтверждение пароля) |
| 404 | Ресурс не найден |
| 422 | Не пройдена проверка параметров |
| 429 | Превышена частота запросов |

---

### Эндпоинты

#### 1. Вступление в поставщики

```
POST /api/supplier/apply
```

Подать заявку на роль поставщика. Каждый пользователь может подать только одну заявку.

**Тело запроса**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| Параметр | Тип | Обязателен | Описание |
|------|------|------|------|
| company_name | string | да | Название компании |
| contact_name | string | да | Имя контактного лица |
| contact_phone | string | да | Контактный телефон |
| contact_email | string | да | Контактная почта |
| settlement_method | string | нет | Способ расчётов, по умолчанию `bank` |

**Ответ**: объект поставщика со статусом `pending`

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

> Чувствительные поля (имя контактного лица, телефон, почта) хранятся в базе данных в зашифрованном виде, в ответе API частично маскируются.

**Ошибки**:

| code | Сценарий |
|------|------|
| 422 | Заявка поставщика уже подана |

```bash
curl -X POST "https://api.example.com/api/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. Управление товарами

##### Получить назначенные товары

```
GET /api/supplier/products
```

**Query-параметры**:

| Параметр | Тип | Обязателен | Описание |
|------|------|------|------|
| page | int | нет | Номер страницы, по умолчанию 1 |

**Ответ**: список со пагинацией, каждый элемент содержит информацию о товаре и ставку комиссии

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

##### Добавить товар

```
POST /api/supplier/products
```

Привязать существующий товар к текущему поставщику.

**Тело запроса**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| Параметр | Тип | Обязателен | Описание |
|------|------|------|------|
| product_id | string | да | ID товара (Hashid) |
| commission_rate | float | нет | Ставка комиссии, по умолчанию 0.1 |

**Ответ**: созданный объект SupplierProduct

**Ошибки**:

| code | Сценарий |
|------|------|
| 422 | Товар уже назначен этому поставщику |

##### Убрать товар

```
DELETE /api/supplier/products/{id}
```

Отменить привязку товара к поставщику.

**Ответ**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. Управление расчётами

##### Получить список расчётных документов

```
GET /api/supplier/settlements
```

**Ответ**: все расчётные документы текущего поставщика в порядке убывания времени создания

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

| Поле | Описание |
|------|------|
| total_sales | Общий объём продаж по завершённым заказам за период |
| commission | Общая сумма комиссии платформы |
| payable | Сумма к выплате поставщику (total_sales - commission) |
| status | `pending` / `paid` |

---

#### 4. Вывод средств

##### Подать заявку на вывод

```
POST /api/supplier/withdraw
```

> Для этой операции требуется повторное подтверждение пароля (поле `confirm_password`), проверяется `ConfirmationMiddleware`.
> После 5 неудачных попыток блокировка на 15 минут.

**Тело запроса**:

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

| Параметр | Тип | Обязателен | Описание |
|------|------|------|------|
| amount | string | да | Сумма вывода (строка, чтобы избежать проблем точности float) |
| confirm_password | string | да | Пароль входа пользователя (повторное подтверждение) |
| account_info | object | да | Информация о счёте получателя |
| account_info.method | string | да | Способ вывода: `bank_transfer` / `alipay` / `wechat` |

**Расчёт доступного для вывода остатка**: сумма `payable` всех завершённых расчётных документов − сумма `amount` всех обрабатываемых заявок на вывод

**Ответ**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**Ошибки**:

| code | Сценарий |
|------|------|
| 422 | Недостаточно доступного для вывода остатка |
| 403 | Не пройдено подтверждение пароля |

```bash
curl -X POST "https://api.example.com/api/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### Сводка эндпоинтов внутреннего API

| Метод | Путь | Аутентификация | Подтверждение пароля | Описание |
|------|------|------|---------|------|
| POST | `/api/supplier/apply` | Token | - | Подать заявку на роль поставщика |
| GET | `/api/supplier/products` | Token | - | Просмотр назначенных товаров |
| POST | `/api/supplier/products` | Token | - | Добавить привязку товара |
| DELETE | `/api/supplier/products/{id}` | Token | - | Убрать привязку товара |
| GET | `/api/supplier/settlements` | Token | - | Просмотр расчётных документов |
| POST | `/api/supplier/withdraw` | Token | требуется | Подать заявку на вывод средств |

---

## Внешний API (спецификация дизайна, ожидает реализации)

Внешний API позволяет поставщикам программно управлять заказами, ресурсами и расчётами. Все запросы требуют аутентификации по API Key.

**Base URL**: `https://api.example.com/api`

### Аутентификация

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
X-Api-Version: v1
```

API Key генерирует администратор платформы в панели управления: `供应商管理 → API Keys`.

**Требования безопасности**:
- Доступ только по HTTPS
- API Key показывается только один раз при создании, храните его надёжно
- Рекомендуется добавлять IP сервера в белый список

---

### Формат ответов

Как у внутреннего API, дополнительно включается `request_id` для отслеживания:

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### Эндпоинты

#### 1. Управление заказами

##### Получить список заказов

```
GET /api/supplier/orders
```

**Query-параметры**:

| Параметр | Тип | Обязателен | Описание |
|------|------|------|------|
| page | int | нет | Номер страницы, по умолчанию 1 |
| page_size | int | нет | Количество на странице, по умолчанию 20, максимум 50 |
| status | string | нет | Фильтр по статусу: pending/paid/completed/refunded |
| from | date | нет | Начальная дата YYYY-MM-DD |
| to | date | нет | Конечная дата YYYY-MM-DD |

##### Получить детали заказа

```
GET /api/supplier/orders/{id}
```

---

#### 2. Управление ресурсами

##### Получить список ресурсов

```
GET /api/supplier/resources
```

**Query-параметры**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### Получить статус ресурса

```
GET /api/supplier/resources/{id}/status
```

---

#### 3. Управление расчётами

##### Получить список расчётных документов

```
GET /api/supplier/settlements
```

##### Получить детали расчётного документа

```
GET /api/supplier/settlements/{id}
```

---

#### 4. Вывод средств

##### Подать заявку на вывод

```
POST /api/supplier/withdraw
```

##### История выводов

```
GET /api/supplier/withdraws
```

---

#### 5. Управление товарами

##### Получить мои товары

```
GET /api/supplier/products
```

##### Подать заявку на размещение товара

```
POST /api/supplier/products
```

---

### Сводка эндпоинтов внешнего API

| Метод | Путь | Описание |
|------|------|------|
| GET | `/api/supplier/orders` | Список заказов |
| GET | `/api/supplier/orders/{id}` | Детали заказа |
| GET | `/api/supplier/resources` | Список ресурсов |
| GET | `/api/supplier/resources/{id}/status` | Статус ресурса |
| GET | `/api/supplier/settlements` | Список расчётных документов |
| GET | `/api/supplier/settlements/{id}` | Детали расчётного документа |
| POST | `/api/supplier/withdraw` | Подать заявку на вывод |
| GET | `/api/supplier/withdraws` | История выводов |
| GET | `/api/supplier/products` | Список товаров |
| POST | `/api/supplier/products` | Подать товар |

---

## Webhook (приём событий платформы)

Поставщик может зарегистрировать Webhook URL для приёма событий в реальном времени. Настраивается в панели управления.

### Типы событий

| Событие | Момент срабатывания |
|------|----------|
| `order.paid` | Пользователь завершил оплату |
| `order.refunded` | Заказ возвращён |
| `resource.provisioned` | Открытие ресурса завершено |
| `resource.expiring` | Ресурс скоро истекает (в течение 7 дней) |
| `resource.destroyed` | Ресурс уничтожен |
| `settlement.created` | Сформирован расчётный документ |
| `withdrawal.approved` | Вывод одобрен |

### Формат запроса Webhook

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

**Проверка подписи**: `HMAC-SHA256(payload, webhook_secret)`

---

## Ограничение частоты

| Эндпоинт | Лимит |
|------|------|
| Внутренний API | 60 req/min на пользователя (по умолчанию) |
| Вход во внутренний API | 5 req/min |
| Внешний API | 120 req/min на API Key (правило `supplier_api`, действует через `RateLimitMiddleware`) |
| Вывод во внешнем API | 10 req/min (рекомендованное значение, настраивается в `config/security.php`) |

> Правила ограничения частоты внешнего API определены в `rate_limits.supplier_api` файла `config/security.php`,
> `RateLimitMiddleware` единообразно применяет их к путям `/api/supplier/external/*` (атомарный счётчик INCR,
> при недоступности Redis запрос пропускается).

Заголовки ограничения частоты:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## Примеры SDK

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

// Подать заявку на роль поставщика
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// Получить расчётные документы
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// Подать заявку на вывод
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

# Получить назначенные товары
resp = requests.get('https://api.example.com/api/supplier/products',
                     headers=headers)
products = resp.json()

# Подать заявку на вывод
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

## Рекомендации по обработке ошибок

1. **429 ограничение частоты**: подождать `Retry-After` секунд и повторить
2. **401 не авторизован**: проверить, действителен ли Token, не истёк ли он
3. **403 запрещено**: проверить, является ли роль учётной записи `supplier`; при неудачном подтверждении пароля дождаться снятия блокировки
4. **422 не пройдена проверка**: исправить параметры запроса согласно полю `message`
5. **5xx ошибка сервера**: повтор с экспоненциальной задержкой (1s -> 5s -> 25s)

---

## Справочник эндпоинтов панели управления

Ниже приведены эндпоинты, с помощью которых администратор управляет поставщиками (только для внутреннего использования, требуется роль Admin):

| Метод | Путь | Описание |
|------|------|------|
| GET | `/admin/api/suppliers` | Список поставщиков (поддерживается фильтр по status) |
| GET | `/admin/api/suppliers/export` | Экспорт поставщиков в Excel |
| POST | `/admin/api/suppliers/{id}/approve` | Одобрить заявку поставщика |
| POST | `/admin/api/suppliers/{id}/settle` | Сформировать расчётный документ |
| POST | `/admin/api/suppliers/withdraws/{id}/approve` | Одобрить вывод средств |
| GET | `/admin/api/suppliers/{id}/api-keys` | Просмотр списка API Key поставщика |
| POST | `/admin/api/suppliers/{id}/api-keys` | Создать API Key (исходный Key возвращается только один раз) |
| DELETE | `/admin/api/suppliers/api-keys/{id}` | Отозвать API Key |
