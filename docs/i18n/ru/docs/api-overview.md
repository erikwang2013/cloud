# Обзор API

> Полный справочник интерфейсов (200+ эндпоинтов, примеры запросов/ответов и коды ошибок): [Справочник API](api-reference.md)
> Онлайн-отладка: [документация API service](http://localhost:8787/apidoc) · [документация API admin](http://localhost:8788/apidoc)

## Публичные интерфейсы

| Метод | Путь | Описание |
|------|------|------|
| GET | `/health` | Проверка работоспособности |
| POST | `/api/v1/auth/register` | Регистрация пользователя (тело запроса шифруется AES-256-GCM) |
| POST | `/api/v1/auth/login` | Вход пользователя (тело запроса шифруется AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | Обновление токена (тело запроса шифруется AES-256-GCM) |
| POST | `/api/v1/captcha/create` | Генерация капчи по клику (получается перед входом/регистрацией) |
| GET | `/api/v1/products` | Список товаров (фильтр по категории/региону/ключевому слову) |
| GET | `/api/v1/products/{id}` | Карточка товара (id — строка hashid) |
| GET | `/api/v1/regions` | Доступные регионы |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Проверка доступности домена |
| GET | `/api/v1/domain/tlds` | Список регистрируемых доменных зон |
| POST | `/api/v1/payments/webhook/stripe` | Callback Stripe (проверка подписи, без шифрования) |

## Интерфейсы аутентификации (требуется Bearer Token)

| Метод | Путь | Описание |
|------|------|------|
| GET | `/api/v1/user/profile` | Личная информация |
| PUT | `/api/v1/user/profile` | Обновление информации |
| POST | `/api/v1/user/kyc` | Подача заявки на верификацию личности |
| GET | `/api/v1/user/balance` | Баланс счёта |
| GET/POST | `/api/v1/cart` | Корзина |
| POST/GET | `/api/v1/orders` | Заказы |
| GET | `/api/v1/orders/{id}/payment-methods` | Доступные способы оплаты |
| POST | `/api/v1/orders/{id}/pay` | Инициирование оплаты |
| GET/POST | `/api/v1/resources` | Мои ресурсы |
| GET | `/api/v1/resources/{id}/status` | Статус ресурса |
| GET | `/api/v1/resources/{id}/console` | Ссылка на консоль VNC |
| GET/POST | `/api/v1/cdn/domains` | Список CDN-доменов / создание (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | Детали CDN-домена / удаление |
| POST | `/api/v1/cdn/domains/{id}/purge` | Очистка кэша (идемпотентно, не более 100 URL) |
| GET/POST | `/api/v1/tickets` | Тикеты |
| POST | `/api/v1/tickets/{id}/reply` | Ответ на тикет |
| GET/POST | `/api/v1/dns/{domain}` | Управление DNS |
| POST | `/api/v1/supplier/apply` | Заявка на статус поставщика |
| GET | `/api/v1/supplier/settlements` | История расчётов поставщика |
| POST | `/api/v1/supplier/withdraw` | Вывод средств поставщика |

> **Примечание:** все API-запросы указывают версию в пути URL (например, `/api/v1/products`). Запросы/ответы интерфейсов аутентификации и администратора обрабатываются `EncryptionMiddleware`. Клиент устанавливает заголовок `X-Encrypted: 1`, формат тела запроса — `{"payload": "<base64(AES-256-GCM)>"}`, тело ответа также шифруется и оборачивается в поле `payload`. Все целочисленные ID в ответах API автоматически преобразуются в 12-символьные строки Hashid; строки Hashid в запросах автоматически декодируются обратно в целочисленные ID посредством `HashidRequestMiddleware`.

## Административные интерфейсы

| Метод | Путь | Описание |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | Операционный дашборд |
| GET/PUT | `/admin/api/v1/users` | Управление пользователями |
| GET/POST | `/admin/api/v1/kyc` | Проверка KYC |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Управление товарами |
| POST | `/admin/api/v1/products/{productId}/skus` | Создание SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Установка региональной цены |
| GET/POST | `/admin/api/v1/orders` | Управление заказами (включая возвраты) |
| GET | `/admin/api/v1/orders/export` | Экспорт заказов (.xlsx) |
| GET | `/admin/api/v1/users/export` | Экспорт пользователей (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Экспорт поставщиков (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Платёжные каналы / транзакции / сверка |
| GET/POST | `/admin/api/v1/provisioning/*` | Задачи поставки / управление хостами |
| GET/PUT | `/admin/api/v1/cdn/domains` | Управление CDN-доменами (смена тарифа) |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | Управление учётными данными провайдеров (общие для CDN/доставки, шифрование Encryptable) |
| GET/POST | `/admin/api/v1/suppliers/*` | Согласование поставщиков / расчёты / вывод средств |
| GET/POST | `/admin/api/v1/tickets` | Назначение / закрытие тикетов |
| GET | `/admin/api/v1/reports/*` | Отчёты о выручке / по регионам / по поставщикам |
| GET | `/admin/api/v1/monitor/*` | Панель мониторинга / метрики ресурсов |
| GET | `/admin/api/v1/audit-logs` | Журналы аудита |
| PUT | `/admin/api/v1/system/config` | Конфигурация системы |
