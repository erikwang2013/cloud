# Dokumen Desain Arsitektur CloudPlatform

## 1. Ikhtisar Sistem

CloudPlatform adalah platform perdagangan sumber daya cloud global yang mendukung mode campuran fisik milik sendiri + pemasok pihak ketiga. Pengguna dapat membeli produk seperti server (VM), alamat IP, disk cloud, domain melalui Web/klien seluler, dan sistem secara otomatis menyelesaikan pemrosesan pembayaran dan pengiriman sumber daya.

### 1.1 Keputusan Arsitektur Inti

| Keputusan | Pilihan | Alasan |
|------|------|------|
| Kerangka backend | PHP webman (Workerman) | Memori permanen, berbasis event, multi-proses, respons milidetik |
| Pola arsitektur | Monolit modular | Modul dipisah secara vertikal berdasarkan bisnis, berlapis MVC internal, decoupling antar modul via event |
| Panel admin | Instans webman independen (webman-admin + Layui) | Mengisolasi lalu lintas admin dari lalu lintas pengguna, pemisahan domain kegagalan |
| ORM | Illuminate/Eloquent | Ekosistem Laravel matang, relasi, Scope, event, migrasi |
| Kunci utama terdistribusi | Snowflake 64-bit | Tanpa ketergantungan auto-increment, mendukung sharding database/tabel secara alami |
| Obfuskasi ID | Hashids | Menyembunyikan skala ID asli dari luar, mencegah enumerasi scraper |
| Autentikasi | JWT HS256 | Autentikasi tanpa status, Access 15 menit + Refresh 30 hari |
| Enkripsi transmisi | AES-256-GCM | Enkripsi/dekripsi transparan oleh middleware, GCM authenticated encryption mencegah pemalsuan |
| Enkripsi kolom | AES-128-ECB | Eloquent Cast otomatis enkripsi/dekripsi, enkripsi deterministik (ciphertext dapat di-query untuk kesetaraan, bergantung pada login/validasi unik); hanya mendukung ECB |
| Antrean pesan | Redis Queue | Memproses callback pembayaran, distribusi notifikasi, pengaktifan sumber daya secara asinkron |
| Mesin pencari | database (default) / Elasticsearch 8.x | webman-scout default driver database (fallback SQL LIKE); setelah konfigurasi ES, aktifkan indeks IK tokenizer |
| Virtualisasi | Proxmox VE + kvm-server | VM milik sendiri disediakan oleh Rust kvm-server (gRPC :50051, discovery e-cat/etcd); driver saat ini adalah simulated driver, driver nyata libvirt pada Phase 2 |
| Klien | Flutter | Satu basis kode untuk iOS/macOS/Windows/Linux/Web lima platform + HarmonyOS |

### 1.2 Batas Sistem

```
┌──────────────────────────────────────────────────────────────────┐
│                         用户端                                    │
│  Flutter (iOS/macOS/Windows/Linux/Web) + HarmonyOS (ArkTS)      │
└─────────────────────────┬────────────────────────────────────────┘
                          │ HTTPS + JWT
┌─────────────────────────┼────────────────────────────────────────┐
│                    Nginx 反向代理                                 │
│  SSL 终端 / gzip 压缩 / 限流 / WebSocket Upgrade                 │
└─────────────────────────┬────────────────────────────────────────┘
                          │
┌─────────────────────────┼────────────────────────────────────────┐
│              webman 服务端 (多进程)                               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ 全局中间件链: Version→CORS→SecurityHeaders→ClientPlatform │     │
│  │             →GeoBlock→WAF→SecurityPlugin→RateLimit→Locale │     │
│  │             →Metrics→Hashid→Maintenance→[路由中间件]       │     │
│  └─────────────────────────────────────────────────────────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌───────┐     │
│  │  User   │ │ Product │ │  Order  │ │ Payment │ │Provision│    │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘ └───────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐               │
│  │ Domain  │ │Supplier │ │ Ticket  │ │  Notif  │  ...           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ WebSocket Server (:8282) — 实时推送                      │     │
│  └─────────────────────────────────────────────────────────┘     │
└──────────┬──────────────┬────────────────┬───────────────────────┘
           │              │                │
    ┌──────┴──────┐ ┌─────┴─────┐ ┌───────┴────────┐
    │  MySQL 8.0  │ │ Redis 7.x │ │ Elasticsearch  │
    │  (主从)     │ │(缓存/队列) │ │    8.x        │
    └─────────────┘ └───────────┘ └────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  kvm-server (Rust gRPC)     │
    │  e-cat / etcd 注册发现      │
    │  模拟驱动 (libvirt Phase 2) │
    └──────┬──────────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  Proxmox VE API (:8006)     │
    │  KVM/QEMU 虚拟化             │
    │  IP 池 / 磁盘池 / 宿主机     │
    └─────────────────────────────┘
```

---

## 2. Arsitektur Komponen

### 2.1 Desain Dua Instans

Proyek berisi dua instans webman independen yang berbagi database MySQL:

```
                    ┌─────────────────────┐
                    │   admin/ (Layui)     │
  Administrator ───▶│   port: 8788         │
                    │   中间件: WAF→ACL     │
                    └──────────┬───────────┘
                               │ SQL
                    ┌──────────┴───────────┐
                    │     MySQL 8.0        │
                    └──────────┬───────────┘
                               │ SQL
  User/API ────────▶│   service/           │
                    │   port: 8787         │
                    │   12 全局+6 路由中间件 │
                    └─────────────────────┘
```

| Instans | Port | Tanggung jawab | Middleware |
|------|------|------|--------|
| **service** | 8787 | API pengguna + API admin + WebSocket | Global 12 + Route 6 + SupplierApiKey |
| **admin** | 8788 | Panel HTML panel admin (Layui) | WafMiddleware + AccessControl |

### 2.2 Struktur Layering Modul

Setiap modul bisnis internal mengikuti layering yang seragam:

```
app/{Module}/
├── controller/     # HTTP 层：参数校验、调用 Service、返回 Response
│   └── external/   # 外部 API 控制器（供应商 API Key 认证）
├── service/        # 业务逻辑：无 HTTP 依赖，可被 Controller/Queue Worker 复用
├── model/          # Eloquent 数据模型：关系定义、查询作用域、Casts
├── event/          # 领域事件定义（OrderPaid、TicketCreated 等）
├── listener/       # 事件监听器（Provisioning、WebSocket 推送）
├── provider/       # 云厂商适配器（ProxmoxProvider 等）
├── queue/          # 队列消费者（ProvisionWorker、EmailSender 等）
└── cron/           # 定时任务（ExchangeRateSync、ExpirationCheck 等）
```

### 2.3 Layering Pustaka Umum

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # 点击验证码服务
├── clientplatform/      # ClientPlatformMiddleware（X-Client-Platform 头）
├── confirmation/        # 二次密码确认中间件
├── encryption/          # AES-256-GCM 传输加密中间件
├── feature/             # Feature Flags 功能开关
├── hashid/              # Hashids 请求解码中间件 + 编解码服务
├── helper/              # Response 格式化 + CacheService
├── http/                # HTTP 客户端工具
├── i18n/middleware/     # 多语言 LocaleMiddleware
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # 雪花 ID 服务 + Eloquent Trait
├── metrics/             # Prometheus 指标采集器 + 渲染器 + HTTP 请求计数中间件
├── version/             # VersionMiddleware（X-Api-Version 头）
└── webhook/             # Webhook 事件分发器
```

---

## 3. Pipeline Eksekusi Middleware

### 3.1 Rantai Middleware Global (semua permintaan)

```
HTTP 请求
  │
  ▼
1. VersionMiddleware         ← X-Api-Version 头校验，缺失默认 v1，无效返回 400
  │                            仅对 /api/ 和 /admin/api/ 生效
  ▼
2. CorsMiddleware            ← OPTIONS 预检返回 CORS 头，Origin 反射
  ▼
3. SecurityHeadersMiddleware ← HSTS / X-Frame-Options / CSP / Referrer-Policy 安全响应头
  ▼
4. ClientPlatformMiddleware  ← X-Client-Platform 头识别（8 平台），注入 properties
  │                            仅对 /api/ 和 /admin/api/ 生效
  ▼
5. GeoBlockMiddleware        ← GEO_BLOCKED_COUNTRIES 国家封锁（MaxMind GeoIP2）
  ▼
6. WafMiddleware             ← 8 类 45+ 规则扫描（JSON body + URL + UA + 原始体）
  │                          ← Content-Type 白名单 + 请求体 10MB 限制 + URL 2KB 限制
  │                            命中 → AuditLogger::threat() → 403
  ▼
7. SecurityPlugin            ← 31 种攻击检测（XSS/SQL注入/SSRF/反序列化等），IP 黑白名单
  ▼
8. RateLimitMiddleware       ← 全路由限流（per-IP + per-token 双桶）
  ▼
9. LocaleMiddleware          ← Accept-Language 解析，设置区域
  ▼
10. MetricsMiddleware        ← Prometheus HTTP 请求计数与延迟记录
  ▼
11. HashidRequestMiddleware  ← 请求参数 hashid 字符串 → 真实整数 ID 解码
  ▼
12. MaintenanceMiddleware    ← MAINTENANCE_MODE 检查，白名单 IP 放行
  │
  ▼
[路由中间件 — 按路由组附加]
  │
  ├─ /health (内部监控) ────────────
  │   InternalTokenMiddleware      ← 内部令牌校验 /health/live|ready|deps
  │
  ├─ /api/auth ─────────────────────
  │   EncryptionMiddleware          ← AES-256-GCM 请求/响应体加解密
  │
  ├─ /api (用户认证) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware                ← JWT Bearer Token 验证 → $request->userId/role
  │
  ├─ /api (敏感操作) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← 密码二次确认，Redis 计数器，5 次锁 15min
  │
  ├─ /api/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← sk_xxx SHA256 验证 → $request->supplierId
  │
  ├─ /admin/api ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← RBAC 权限检查
  │
  └─ /admin/api (敏感操作) ─────────
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
控制器 → Service → Model → DB
```

### 3.2 Detail Setiap Middleware

| Middleware | Lokasi | Cara registrasi | Tanggung jawab |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | Global | Memvalidasi `X-Api-Version`, jika tidak ada default v1 |
| `CorsMiddleware` | common/Security | Global | Preflight OPTIONS, refleksi Origin |
| `SecurityHeadersMiddleware` | common/Security | Global | Header respons keamanan HSTS / X-Frame-Options / CSP / Referrer-Policy |
| `ClientPlatformMiddleware` | common/ClientPlatform | Global | Identifikasi `X-Client-Platform` 8 platform |
| `GeoBlockMiddleware` | common/Security | Global | Pemblokiran wilayah GEO_BLOCKED_COUNTRIES (MaxMind GeoIP2) |
| `WafMiddleware` | common/Security | Global (service)+admin | 8 kategori 45+ aturan + batasan permintaan |
| `SecurityPlugin` | Erikwang2013\Security | Global | Deteksi 31 jenis serangan, daftar putih/hitam IP |
| `RateLimitMiddleware` | common/Security | Global | Pembatasan token bucket Redis (per-IP + per-token dua bucket) |
| `LocaleMiddleware` | common/I18n | Global | Parsing Accept-Language |
| `MetricsMiddleware` | common/Metrics | Global | Penghitungan permintaan dan latensi HTTP Prometheus |
| `HashidRequestMiddleware` | common/Hashid | Global | Dekode permintaan hashid |
| `MaintenanceMiddleware` | common/Security | Global | Mode pemeliharaan + daftar putih IP |
| `InternalTokenMiddleware` | common/Security | Grup rute | Validasi token internal `/health/live|ready|deps` |
| `EncryptionMiddleware` | common/Encryption | Grup rute | Enkripsi/dekripsi AES-256-GCM |
| `AuthMiddleware` | common/Auth | Grup rute | Verifikasi JWT Bearer Token |
| `AdminRoleMiddleware` | common/Auth | Grup rute | RBAC admin |
| `ConfirmationMiddleware` | common/Confirmation | Grup rute | Konfirmasi ulang kata sandi |
| `SupplierApiKeyMiddleware` | common/Auth | Grup rute | Verifikasi tanda tangan API Key SHA256 sk_xxx |

---

## 4. Arsitektur Data

### 4.1 Kunci Utama Terdistribusi: Snowflake

```
64-bit Snowflake ID 结构:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  毫秒级时间戳      数据中心   工作节点    序列号
  纪元: 2024-01-01
  最大寿命: ~69 年
```

Semua Eloquent Model secara otomatis menghasilkan ID melalui Trait `HasSnowflakeId` pada event `creating`. Tipe kolom database adalah `bigint unsigned`.

### 4.2 Obfuskasi ID: Hashids

```
请求流程:
  Client: GET /api/products/aB3xK7mQ9w
    → HashidRequestMiddleware 解码 → int(1234567890)
      → Controller/Service 使用整数 ID 操作
        → Response::success() / Response::paginated()
          → hashids_encode_ids() 递归编码所有 id/*_id 字段
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 Koneksi Database

```
┌────────────────────┐     ┌────────────────────┐
│  MySQL 主库 (写)    │     │  MySQL 从库 (读)    │
│  DB_HOST           │     │  DB_READ_HOST      │
└────────┬───────────┘     └────────┬───────────┘
         │ write                    │ read (SELECT)
         └──────────┬───────────────┘
                    │
         ┌──────────┴───────────┐
         │  Eloquent Capsule    │
         │  sticky = true       │
         │  持久连接 (PDO)       │
         └──────────────────────┘
                    │
         ┌──────────┴───────────┐
         │  audit 库 (独立连接)  │
         │  审计日志隔离存储      │
         └──────────────────────┘
```

### 4.4 Lapisan Enkripsi

| Lapisan | Algoritma | Implementasi | Kegunaan |
|------|------|------|------|
| Lapisan transmisi | AES-256-GCM | EncryptionMiddleware | Enkripsi body permintaan/respons API, autentikasi GCM |
| Lapisan kolom | AES-128-ECB | Encryptable Cast | Enkripsi/dekripsi otomatis kolom sensitif (enkripsi deterministik: plaintext sama→ciphertext sama, query kesetaraan ciphertext untuk login/validasi unik; hanya mendukung ECB, ganti cipher perlu migrasi enkripsi ulang) |
| Lapisan hash | bcrypt + SHA256 | JWT / API Key | Penyimpanan kata sandi/Token yang tidak dapat dibalik |
| Lapisan kunci utama | Hashids | Response + Middleware | Obfuskasi ID ke luar |

### 4.5 Lapisan Cache

```
L1: Redis 缓存层（CacheService）
    产品列表 TTL 5min | 产品详情 TTL 10min
    区域 TTL 1h | 汇率 TTL 30min | TLD TTL 1h
    失效策略: 数据变更时主动 forget / forgetPattern

L2: MySQL 查询层（Eloquent + 索引优化）
    13 个复合/覆盖索引覆盖高频查询

L3: Nginx 响应压缩（gzip level 6）
    JSON 响应压缩率 70-85%
```

### 4.6 Internasionalisasi (i18n)

```
Accept-Language: zh-CN,zh;q=0.9
         │
         ▼
  LocaleMiddleware (全局中间件)
         │  解析主语言 → zh-CN
         │  I18n::setLocale('zh-CN')
         │  加载 i18n/zh-CN/messages.php
         ▼
  控制器 / Service
         │
         ├── I18n::trans('auth.login_success')  →  '登录成功'
         ├── I18n::translateField($jsonField)   →  按语言取值
         └── I18n::getLocale()                  →  'zh-CN'
```

| Kemampuan | Keterangan |
|------|------|
| Parsing header | `LocaleMiddleware` secara otomatis memparse header `Accept-Language` |
| Fallback bahasa | Bahasa yang tidak didukung → `fallback_locale` |
| Terjemahan statis | 120 entri, mencakup 15 modul (`i18n/{locale}/messages.php`) |
| Penggantian parameter | `I18n::trans('key', ['field' => 'value'])` |
| Kolom JSON | `translateField()` memproses kolom JSON multi-bahasa |

---

## 5. Arsitektur Keamanan

### 5.1 Sistem Aturan WAF (8 kategori 45+ aturan)

| Kategori | Jumlah aturan | Cakupan deteksi |
|------|--------|---------|
| SQL Injection | 9 | Karakter komentar, kata kunci, encoding hex, UNION query, kondisi selalu benar, time-based blind, stacked query |
| XSS | 8 | Tag HTML, varian Script, 13 event handler, pseudo-protocol JS, encoding entitas, Data URI |
| Command Injection | 5 | Perintah setelah pipe, perintah setelah titik koma, $(cmd), backtick, kata kunci perintah berdiri sendiri |
| File Inclusion | 4 | Path traversal, pseudo-protocol PHP, path absolut, Null byte |
| HTTP Header Injection | 2 | CRLF newline, injeksi Host/Cookie/Set-Cookie |
| SSRF | 6 | IP internal, localhost, metadata cloud, protokol file:// |
| NoSQL Injection | 3 | Operator MongoDB, perintah berbahaya Redis |
| Open Redirect | 2 | URL eksternal redirect_uri, bypass double encoding |

**Cakupan pemindaian:** aturan injeksi nilai (SQLi/XSS/command injection/header injection/SSRF/NoSQL/open redirect) memindai query string, body permintaan, User-Agent; URL path hanya menggunakan pola file inclusion (path traversal) untuk validasi struktur. Path bisnis berisi kata umum seperti select/insert/alert (mis. `/order_item/select`), jika seluruh path dipindai akan memblokir semua endpoint CRUD, oleh karena itu path tidak ikut pencocokan injeksi nilai.

**Perlindungan tingkat permintaan:** whitelist Content-Type, batas body permintaan 10MB, batas URL 2KB

### 5.2 Sistem Autentikasi

```
┌─────────────────────────────────────────────┐
│               认证方式                       │
├──────────────┬──────────────┬───────────────┤
│  用户端       │  管理端       │  供应商 API    │
│  JWT HS256   │  JWT HS256   │  API Key      │
│  Access 15min │  Access 2h   │  sk_xxx 前缀   │
│  Refresh 30d  │  Refresh 7d  │  SHA256 存储   │
│  TOTP 可选    │              │  仅显示一次     │
│  OAuth 可选   │              │               │
└──────────────┴──────────────┴───────────────┘
```

---

## 6. Arsitektur Deployment

### 6.1 Topologi Produksi

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
         │ MySQL 主从  │ Redis Cluster │
         │ 1 主 2 从   │ 缓存+队列     │
         └─────────────┴───────────────┘
               │
         ┌─────┴──────────────────────┐
         │  kvm-server (Rust gRPC)    │
         │  e-cat / etcd 注册         │
         │  模拟驱动 (libvirt Phase 2)│
         └─────┬──────────────────────┘
               │
         ┌─────┴──────────────────────┐
         │  Proxmox VE 集群            │
         │  物理机 × N                 │
         │  KVM/QEMU 虚拟化            │
         └────────────────────────────┘
```

### 6.2 Model Proses

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (默认 8)
├── Queue Worker: provisioning     (×2)
├── Queue Worker: email            (×5)
├── Queue Worker: sms              (×10)
├── Queue Worker: push             (×20)
├── WebSocket Worker               (×2, port 8282)
└── Cron Timer                     (×1)

webman admin (8788)
└── HTTP Worker × 4
```

---

## 7. Dependensi Teknis

### 7.1 Kerangka Inti

| Paket | Versi | Kegunaan |
|----|------|------|
| workerman/webman-framework | ^2.1 | Kerangka web (multi-proses memori permanen) |
| illuminate/database | ^10.0 | Eloquent ORM |
| illuminate/events | ^10.0 | Sistem event |
| illuminate/redis | ^10.0 | Klien Redis |
| webman/redis-queue | ^1.0 | Antrean pesan Redis |

### 7.2 Paket Ekosistem erikwang2013

| Paket | Kegunaan |
|----|------|
| snowflake-php | Kunci utama terdistribusi 64-bit |
| hashids | Obfuskasi ID API |
| encryptable | Enkripsi kolom database |
| encryption | Enkripsi transmisi AES-256-GCM |
| jwt-webman | Autentikasi JWT |
| webman-scout | Pencarian teks lengkap Elasticsearch |
| season | Emoji bendera negara |
| poster-php | CAPTCHA klik |

### 7.3 Integrasi Pihak Ketiga

| Paket | Kegunaan |
|----|------|
| stripe/stripe-php | Pembayaran Stripe |
| twilio/sdk | SMS |
| kreait/firebase-php | Push FCM |
| guzzlehttp/guzzle | Klien HTTP (Proxmox API dll.) |
| sentry/sentry | Pemantauan eksepsi |
| phpoffice/phpspreadsheet | Ekspor Excel |
