# Обзор API

> Полный справочник интерфейсов (200+ эндпоинтов, примеры запросов/ответов и коды ошибок): [Справочник API](api-reference.md)
> Онлайн-отладка: [документация API service](http://localhost:8787/apidoc) · [документация API admin](http://localhost:8788/apidoc)

## Публичные интерфейсы

| Метод | Путь | Описание |
|------|------|------|
| GET | `/health` | Проверка работоспособности |
| POST | `/api/auth/register` | Регистрация пользователя (тело запроса шифруется AES-256-GCM) |
| POST | `/api/auth/login` | Вход пользователя (тело запроса шифруется AES-256-GCM) |
| POST | `/api/auth/refresh` | Обновление токена (тело запроса шифруется AES-256-GCM) |
| POST | `/api/captcha/create` | Генерация капчи по клику (получается перед входом/регистрацией) |
| GET | `/api/products` | Список товаров (фильтр по категории/региону/ключевому слову) |
| GET | `/api/products/{id}` | Карточка товара (id — строка hashid) |
| GET | `/api/regions` | Доступные регионы |
| GET | `/api/domain/check/{domain}/{tld}` | Проверка доступности домена |
| GET | `/api/domain/tlds` | Список регистрируемых доменных зон |
| POST | `/api/payments/webhook/stripe` | Callback Stripe (проверка подписи, без шифрования) |

## Интерфейсы аутентификации (требуется Bearer Token)

| Метод | Путь | Описание |
|------|------|------|
| GET | `/api/user/profile` | Личная информация |
| PUT | `/api/user/profile` | Обновление информации |
| POST | `/api/user/kyc` | Подача заявки на верификацию личности |
| GET | `/api/user/balance` | Баланс счёта |
| GET/POST | `/api/cart` | Корзина |
| POST/GET | `/api/orders` | Заказы |
| GET | `/api/orders/{id}/payment-methods` | Доступные способы оплаты |
| POST | `/api/orders/{id}/pay` | Инициирование оплаты |
| GET/POST | `/api/resources` | Мои ресурсы |
| GET | `/api/resources/{id}/status` | Статус ресурса |
| GET | `/api/resources/{id}/console` | Ссылка на консоль VNC |
| GET/POST | `/api/cdn/domains` | Список CDN-доменов / создание (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/cdn/domains/{id}` | Детали CDN-домена / удаление |
| POST | `/api/cdn/domains/{id}/purge` | Очистка кэша (идемпотентно, не более 100 URL) |
| GET/POST | `/api/tickets` | Тикеты |
| POST | `/api/tickets/{id}/reply` | Ответ на тикет |
| GET/POST | `/api/dns/{domain}` | Управление DNS |
| POST | `/api/supplier/apply` | Заявка на статус поставщика |
| GET | `/api/supplier/settlements` | История расчётов поставщика |
| POST | `/api/supplier/withdraw` | Вывод средств поставщика |

> **Примечание:** все API-запросы должны содержать заголовок `X-Api-Version: v1` (при отсутствии по умолчанию `v1`, проверяется `VersionMiddleware`). Запросы/ответы интерфейсов аутентификации и администратора обрабатываются `EncryptionMiddleware`. Клиент устанавливает заголовок `X-Encrypted: 1`, формат тела запроса — `{"payload": "<base64(AES-256-GCM)>"}`, тело ответа также шифруется и оборачивается в поле `payload`. Все целочисленные ID в ответах API автоматически преобразуются в 12-символьные строки Hashid; строки Hashid в запросах автоматически декодируются обратно в целочисленные ID посредством `HashidRequestMiddleware`.

## Административные интерфейсы

| Метод | Путь | Описание |
|------|------|------|
| GET | `/admin/api/dashboard` | Операционный дашборд |
| GET/PUT | `/admin/api/users` | Управление пользователями |
| GET/POST | `/admin/api/kyc` | Проверка KYC |
| GET/POST/PUT/DELETE | `/admin/api/products` | Управление товарами |
| POST | `/admin/api/products/{productId}/skus` | Создание SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | Установка региональной цены |
| GET/POST | `/admin/api/orders` | Управление заказами (включая возвраты) |
| GET | `/admin/api/orders/export` | Экспорт заказов (.xlsx) |
| GET | `/admin/api/users/export` | Экспорт пользователей (.xlsx) |
| GET | `/admin/api/suppliers/export` | Экспорт поставщиков (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Платёжные каналы / транзакции / сверка |
| GET/POST | `/admin/api/provisioning/*` | Задачи поставки / управление хостами |
| GET/PUT | `/admin/api/cdn/domains` | Управление CDN-доменами (смена тарифа) |
| GET/POST/PUT/DELETE | `/admin/api/providers` | Управление учётными данными провайдеров (общие для CDN/доставки, шифрование Encryptable) |
| GET/POST | `/admin/api/suppliers/*` | Согласование поставщиков / расчёты / вывод средств |
| GET/POST | `/admin/api/tickets` | Назначение / закрытие тикетов |
| GET | `/admin/api/reports/*` | Отчёты о выручке / по регионам / по поставщикам |
| GET | `/admin/api/monitor/*` | Панель мониторинга / метрики ресурсов |
| GET | `/admin/api/audit-logs` | Журналы аудита |
| PUT | `/admin/api/system/config` | Конфигурация системы |
