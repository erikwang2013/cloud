# Обзор API

> Полный справочник интерфейсов (200+ эндпоинтов, примеры запросов/ответов и коды ошибок): [Справочник API](api-reference.md)
> Онлайн-отладка: [документация API service](http://localhost:8787/apidoc) · [документация API admin](http://localhost:8788/apidoc)

## Публичные эндпоинты

| Метод | Путь | Описание |
|--------|------|-------------|
| GET | `/health` | Проверка работоспособности |
| POST | `/api/v1/auth/register` | Регистрация (тело запроса шифруется AES-256-GCM) |
| POST | `/api/v1/auth/login` | Вход (тело запроса шифруется AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | Обновление токена (тело запроса шифруется AES-256-GCM) |
| POST | `/api/v1/captcha/create` | Генерация капчи по клику (требуется перед входом/регистрацией) |
| GET | `/api/v1/products` | Список товаров (фильтр по категории/региону/ключевому слову) |
| GET | `/api/v1/products/{id}` | Карточка товара (id — строка hashid) |
| GET | `/api/v1/regions` | Доступные регионы |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Проверка доступности домена |
| GET | `/api/v1/domain/tlds` | Доступные доменные зоны |
| POST | `/api/v1/payments/webhook/stripe` | Webhook Stripe (подпись проверяется, шифрование не требуется) |

## Эндпоинты аутентификации (Bearer Token)

| Метод | Путь | Описание |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | Получить профиль |
| PUT | `/api/v1/user/profile` | Обновить профиль |
| POST | `/api/v1/user/kyc` | Отправить KYC |
| GET | `/api/v1/user/balance` | Баланс счёта |
| GET/POST | `/api/v1/cart` | Корзина |
| POST/GET | `/api/v1/orders` | Заказы |
| GET | `/api/v1/orders/{id}/payment-methods` | Доступные способы оплаты |
| POST | `/api/v1/orders/{id}/pay` | Инициировать оплату |
| GET/POST | `/api/v1/resources` | Мои ресурсы |
| GET | `/api/v1/resources/{id}/status` | Статус ресурса |
| GET | `/api/v1/resources/{id}/console` | URL консоли VNC |
| GET/POST | `/api/v1/tickets` | Тикеты поддержки |
| POST | `/api/v1/tickets/{id}/reply` | Ответить на тикет |
| GET/POST | `/api/v1/dns/{domain}` | Управление DNS |
| POST | `/api/v1/supplier/apply` | Подать заявку на статус поставщика |
| GET | `/api/v1/supplier/settlements` | История расчётов |
| POST | `/api/v1/supplier/withdraw` | Запросить вывод средств |

> **Примечание:** все API-запросы указывают версию в пути URL (например, `/api/v1/products`). Эндпоинты аутентификации и администратора обрабатываются `EncryptionMiddleware`. Клиент устанавливает заголовок `X-Encrypted: 1` и оборачивает тело как `{"payload": "<base64(AES-256-GCM)>"}`. Ответы аналогично шифруются и оборачиваются в поле `payload`. Целочисленные ID в ответах API автоматически преобразуются в 12-символьные строки Hashid; строки Hashid в запросах декодируются обратно в целочисленные ID посредством `HashidRequestMiddleware`.

## Административные эндпоинты

| Метод | Путь | Описание |
|--------|------|-------------|
| GET | `/admin/api/v1/dashboard` | Операционный дашборд |
| GET/PUT | `/admin/api/v1/users` | Управление пользователями |
| GET/POST | `/admin/api/v1/kyc` | Проверка KYC |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Управление товарами |
| POST | `/admin/api/v1/products/{productId}/skus` | Создать SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Установить региональную цену |
| GET/POST | `/admin/api/v1/orders` | Управление заказами (включая возвраты) |
| GET | `/admin/api/v1/orders/export` | Экспорт заказов (.xlsx) |
| GET | `/admin/api/v1/users/export` | Экспорт пользователей (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Экспорт поставщиков (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Каналы / транзакции / сверка |
| GET/POST | `/admin/api/v1/provisioning/*` | Задачи поставки / управление хостами |
| GET/POST | `/admin/api/v1/suppliers/*` | Согласование поставщиков / расчёты / вывод средств |
| GET/POST | `/admin/api/v1/tickets` | Назначение / закрытие тикетов |
| GET | `/admin/api/v1/reports/*` | Отчёты о выручке / по регионам / по поставщикам |
| GET | `/admin/api/v1/monitor/*` | Панель мониторинга / метрики ресурсов |
| GET | `/admin/api/v1/audit-logs` | Журналы аудита |
| PUT | `/admin/api/v1/system/config` | Обновление конфигурации системы |
