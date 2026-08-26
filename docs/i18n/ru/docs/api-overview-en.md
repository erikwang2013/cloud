# Обзор API

> Полный справочник интерфейсов (200+ эндпоинтов, примеры запросов/ответов и коды ошибок): [Справочник API](api-reference.md)
> Онлайн-отладка: [документация API service](http://localhost:8787/apidoc) · [документация API admin](http://localhost:8788/apidoc)

## Публичные эндпоинты

| Метод | Путь | Описание |
|--------|------|-------------|
| GET | `/health` | Проверка работоспособности |
| POST | `/api/auth/register` | Регистрация (тело запроса шифруется AES-256-GCM) |
| POST | `/api/auth/login` | Вход (тело запроса шифруется AES-256-GCM) |
| POST | `/api/auth/refresh` | Обновление токена (тело запроса шифруется AES-256-GCM) |
| POST | `/api/captcha/create` | Генерация капчи по клику (требуется перед входом/регистрацией) |
| GET | `/api/products` | Список товаров (фильтр по категории/региону/ключевому слову) |
| GET | `/api/products/{id}` | Карточка товара (id — строка hashid) |
| GET | `/api/regions` | Доступные регионы |
| GET | `/api/domain/check/{domain}/{tld}` | Проверка доступности домена |
| GET | `/api/domain/tlds` | Доступные доменные зоны |
| POST | `/api/payments/webhook/stripe` | Webhook Stripe (подпись проверяется, шифрование не требуется) |

## Эндпоинты аутентификации (Bearer Token)

| Метод | Путь | Описание |
|--------|------|-------------|
| GET | `/api/user/profile` | Получить профиль |
| PUT | `/api/user/profile` | Обновить профиль |
| POST | `/api/user/kyc` | Отправить KYC |
| GET | `/api/user/balance` | Баланс счёта |
| GET/POST | `/api/cart` | Корзина |
| POST/GET | `/api/orders` | Заказы |
| GET | `/api/orders/{id}/payment-methods` | Доступные способы оплаты |
| POST | `/api/orders/{id}/pay` | Инициировать оплату |
| GET/POST | `/api/resources` | Мои ресурсы |
| GET | `/api/resources/{id}/status` | Статус ресурса |
| GET | `/api/resources/{id}/console` | URL консоли VNC |
| GET/POST | `/api/tickets` | Тикеты поддержки |
| POST | `/api/tickets/{id}/reply` | Ответить на тикет |
| GET/POST | `/api/dns/{domain}` | Управление DNS |
| POST | `/api/supplier/apply` | Подать заявку на статус поставщика |
| GET | `/api/supplier/settlements` | История расчётов |
| POST | `/api/supplier/withdraw` | Запросить вывод средств |

> **Примечание:** все API-запросы должны включать заголовок `X-Api-Version: v1` (по умолчанию `v1` при отсутствии, проверяется `VersionMiddleware`). Эндпоинты аутентификации и администратора обрабатываются `EncryptionMiddleware`. Клиент устанавливает заголовок `X-Encrypted: 1` и оборачивает тело как `{"payload": "<base64(AES-256-GCM)>"}`. Ответы аналогично шифруются и оборачиваются в поле `payload`. Целочисленные ID в ответах API автоматически преобразуются в 12-символьные строки Hashid; строки Hashid в запросах декодируются обратно в целочисленные ID посредством `HashidRequestMiddleware`.

## Административные эндпоинты

| Метод | Путь | Описание |
|--------|------|-------------|
| GET | `/admin/api/dashboard` | Операционный дашборд |
| GET/PUT | `/admin/api/users` | Управление пользователями |
| GET/POST | `/admin/api/kyc` | Проверка KYC |
| GET/POST/PUT/DELETE | `/admin/api/products` | Управление товарами |
| POST | `/admin/api/products/{productId}/skus` | Создать SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | Установить региональную цену |
| GET/POST | `/admin/api/orders` | Управление заказами (включая возвраты) |
| GET | `/admin/api/orders/export` | Экспорт заказов (.xlsx) |
| GET | `/admin/api/users/export` | Экспорт пользователей (.xlsx) |
| GET | `/admin/api/suppliers/export` | Экспорт поставщиков (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Каналы / транзакции / сверка |
| GET/POST | `/admin/api/provisioning/*` | Задачи поставки / управление хостами |
| GET/POST | `/admin/api/suppliers/*` | Согласование поставщиков / расчёты / вывод средств |
| GET/POST | `/admin/api/tickets` | Назначение / закрытие тикетов |
| GET | `/admin/api/reports/*` | Отчёты о выручке / по регионам / по поставщикам |
| GET | `/admin/api/monitor/*` | Панель мониторинга / метрики ресурсов |
| GET | `/admin/api/audit-logs` | Журналы аудита |
| PUT | `/admin/api/system/config` | Обновление конфигурации системы |
