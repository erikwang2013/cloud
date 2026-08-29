# CloudPlatform-Architektur-Design-Dokument

## 1. Systemübersicht

CloudPlatform ist eine globale Handelsplattform für Cloud-Ressourcen mit Hybrid-Modell aus eigenen physischen Servern und Drittanbieter-Lieferanten. Benutzer können über Web/Mobile Server (VM), IP-Adressen, Cloud-Disks, Domains und andere Produkte kaufen; das System übernimmt automatisch Zahlungsabwicklung und Ressourcenauslieferung.

### 1.1 Zentrale Architekturentscheidungen

| Entscheidung | Wahl | Begründung |
|------|------|------|
| Backend-Framework | PHP webman (Workerman) | Arbeitsspeicher-resident, ereignisgetrieben, multiprozess, Millisekunden-Antwortzeiten |
| Architekturmuster | Modulare Monolith | Module nach Geschäftsbereich vertikal geschnitten, internes MVC-Layering, modulübergreifende Entkopplung über Events |
| Admin-Panel | Separate webman-Instanz (webman-admin + Layui) | Admin- und Benutzer-Traffic isoliert, Ausfalldomänen getrennt |
| ORM | Illuminate/Eloquent | Ausgereiftes Laravel-Ökosystem: Relationen, Scopes, Events, Migrationen |
| Verteilte Primärschlüssel | Snowflake 64-bit | Keine Auto-Increment-Abhängigkeit, nativ sharding-fähig |
| ID-Verschleierung | Hashids | Verbirgt die echte ID-Größe nach außen, verhindert Crawler-Traversal |
| Authentifizierung | JWT HS256 | Zustandslose Authentifizierung, Access 15min + Refresh 30d |
| Transportverschlüsselung | AES-256-GCM | Transparente Ver-/Entschlüsselung in der Middleware, GCM-Authenticated-Encryption gegen Manipulation |
| Feldverschlüsselung | AES-128-ECB | Automatische Ver-/Entschlüsselung über Eloquent Cast, deterministische Verschlüsselung (Geheimtext gleichwertig abfragbar, Login/Eindeutigkeitsprüfung hängt davon ab); nur ECB unterstützt |
| Message Queue | Redis Queue | Asynchrone Verarbeitung von Zahlungs-Callbacks, Benachrichtigungsverteilung, Ressourcenbereitstellung |
| Suchmaschine | database (Standard) / Elasticsearch 8.x | webman-scout nutzt standardmäßig den database-Treiber (SQL LIKE-Fallback); nach ES-Konfiguration IK-Tokenizer-Index |
| Virtualisierung | Proxmox VE + kvm-server | Eigene VMs werden von Rust kvm-server (gRPC :50051, e-cat/etcd Service-Discovery) bereitgestellt; Treiberschicht aktuell Mock-Treiber, echter libvirt-Treiber Phase 2 |
| Clients | Flutter | Eine Codebasis für iOS/macOS/Windows/Linux/Web fünf Plattformen + HarmonyOS |

### 1.2 Systemgrenzen

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
│  │全局中间件链: Version→CORS→SecurityHeaders→ClientPlatform │     │
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

## 2. Komponentenarchitektur

### 2.1 Zwei-Instanzen-Design

Das Projekt enthält zwei unabhängige webman-Instanzen, die dieselbe MySQL-Datenbank nutzen:

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

| Instanz | Port | Zuständigkeit | Middleware |
|------|------|------|--------|
| **service** | 8787 | Benutzer-API + Admin-API + WebSocket | Global 12 + Route 6 + SupplierApiKey |
| **admin** | 8788 | Admin-Panel HTML (Layui) | WafMiddleware + AccessControl |

### 2.2 Modul-Schichtstruktur

Jedes Geschäftsmodul folgt intern einheitlicher Schichtung:

```
app/{Module}/
├── controller/     # HTTP-Ebene: Parameterprüfung, Service-Aufruf, Response zurückgeben
│   └── external/   # Externe API-Controller (Supplier-API-Key-Auth)
├── service/        # Geschäftslogik: keine HTTP-Abhängigkeit, von Controller/Queue Worker wiederverwendbar
├── model/          # Eloquent-Datenmodelle: Relationen, Query-Scopes, Casts
├── event/          # Domain-Events (OrderPaid, TicketCreated usw.)
├── listener/       # Event-Listener (Provisioning, WebSocket-Push)
├── provider/       # Cloud-Provider-Adapter (ProxmoxProvider usw.)
├── queue/          # Queue-Consumer (ProvisionWorker, EmailSender usw.)
└── cron/           # Geplante Tasks (ExchangeRateSync, ExpirationCheck usw.)
```

### 2.3 Common-Library-Schichtung

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # Klick-CAPTCHA-Dienst
├── clientplatform/      # ClientPlatformMiddleware (X-Client-Platform-Header)
├── confirmation/        # Passwort-Zweitbestätigungs-Middleware
├── encryption/          # AES-256-GCM-Transportverschlüsselungs-Middleware
├── feature/             # Feature Flags
├── hashid/              # Hashids-Request-Decode-Middleware + Encode/Decode-Dienst
├── helper/              # Response-Formatierung + CacheService
├── http/                # HTTP-Client-Werkzeuge
├── i18n/middleware/     # Mehrsprachige LocaleMiddleware
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # Snowflake-ID-Dienst + Eloquent Trait
├── metrics/             # Prometheus-Metrik-Collector + Renderer + HTTP-Request-Zähl-Middleware
├── version/             # VersionMiddleware (X-Api-Version-Header)
└── webhook/             # Webhook-Event-Dispatcher
```

### 2.4 CDN-Modul

Das produktseitige CDN-Modul (`service/app/cdn/`) bindet über das Adaptermuster vier Anbieter an und nimmt Server oder Storage-Buckets als Origin in das CDN auf:

```
CdnAdapterInterface
  ├── CloudflareAdapter   REST v4 (SSL-SaaS-Autozertifikate), keine ICP-Registrierung
  ├── CloudFrontAdapter   aws-sdk-php (CloudFront + ACM), keine ICP-Registrierung
  ├── AliyunCdnAdapter    RPC-Signatur, ICP-Registrierung erforderlich
  └── TencentCdnAdapter   TC3-Signatur, ICP-Registrierung erforderlich
         ▲
CdnAdapterFactory.resolve(type, accountId, strict)
  ① gebundenes Konto (provider_account_id) → ② aktives Konto mit code=cdn-{type} → ③ env-Fallback
  strict=true (Löschen/Purge): nur gebundenes Konto, fehlt es → 4003, kein stilles Umschalten
```

**Kontoverwaltung:** Wiederverwendung des Modells `provider_apis` (Anmeldedaten mit Encryptable verschlüsselt gespeichert), CRUD über `/admin/providers` (RbacMiddleware), `code`-Konvention `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, env-Anmeldedaten als Fallback.

**Datenmodell:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; cert_config wird vor dem Speichern von privaten Schlüsseln befreit). Zugriffsisolierung: CDN-Ressourcen werden über `resource.user_id` auf den Besitzer geprüft, fremde Ressourcen einheitlich 404.

---

## 3. Middleware-Ausführungspipeline

### 3.1 Globale Middleware-Kette (alle Anfragen)

```
HTTP 请求
  │
  ▼
1. VersionMiddleware         ← X-Api-Version-Header-Validierung, fehlt → Standard v1, ungültig → 400
  │                             nur für /api/ und /admin/api/ wirksam
  ▼
2. CorsMiddleware            ← OPTIONS-Preflight liefert CORS-Header, Origin-Reflexion
  ▼
3. SecurityHeadersMiddleware ← HSTS / X-Frame-Options / CSP / Referrer-Policy Sicherheits-Response-Header
  ▼
4. ClientPlatformMiddleware  ← X-Client-Platform-Header-Erkennung (8 Plattformen), properties injizieren
  │                             nur für /api/ und /admin/api/ wirksam
  ▼
5. GeoBlockMiddleware        ← GEO_BLOCKED_COUNTRIES-Länderblockade (MaxMind GeoIP2)
  ▼
6. WafMiddleware             ← 8 Kategorien, 45+ Regeln (JSON body + URL + UA + Rohbody)
  │                          ← Content-Type-Whitelist + 10MB-Body-Limit + 2KB-URL-Limit
  │                            Treffer → AuditLogger::threat() → 403
  ▼
7. SecurityPlugin            ← 31 Angriffserkennungen (XSS/SQL-Injection/SSRF/Deserialisierung usw.), IP-White/Blacklist
  ▼
8. RateLimitMiddleware       ← Routenweites Rate-Limiting (per-IP + per-Token Doppel-Bucket)
  ▼
9. LocaleMiddleware          ← Accept-Language-Parsing, Locale setzen
  ▼
10. MetricsMiddleware        ← Prometheus-HTTP-Request-Zählung und Latenzaufzeichnung
  ▼
11. HashidRequestMiddleware  ← Request-Parameter hashid-Strings → echte Integer-IDs dekodieren
  ▼
12. MaintenanceMiddleware    ← MAINTENANCE_MODE-Prüfung, Whitelist-IPs passieren
  │
  ▼
[路由中间件 — 按路由组附加]
  │
  ├─ /health (内部监控) ────────────
  │   InternalTokenMiddleware      ← Internes Token-Validierung /health/live|ready|deps
  │
  ├─ /api/auth ─────────────────────
  │   EncryptionMiddleware          ← AES-256-GCM Request/Response-Body-Ver-/Entschlüsselung
  │
  ├─ /api (用户认证) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware                ← JWT-Bearer-Token-Validierung → $request->userId/role
  │
  ├─ /api (敏感操作) ───────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← Passwort-Zweitbestätigung, Redis-Zähler, 5 Fehlversuche → 15min-Sperre
  │
  ├─ /api/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← sk_xxx-SHA256-Validierung → $request->supplierId
  │
  ├─ /admin/api ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← RBAC-Berechtigungsprüfung
  │
  └─ /admin/api (敏感操作) ─────────
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
Controller → Service → Model → DB
```

### 3.2 Middleware-Details

| Middleware | Ort | Registrierung | Zuständigkeit |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | Global | `X-Api-Version` validieren, fehlt → Standard v1 |
| `CorsMiddleware` | common/Security | Global | OPTIONS-Preflight, Origin-Reflexion |
| `SecurityHeadersMiddleware` | common/Security | Global | HSTS / X-Frame-Options / CSP / Referrer-Policy Sicherheits-Response-Header |
| `ClientPlatformMiddleware` | common/ClientPlatform | Global | `X-Client-Platform`-Erkennung, 8 Plattformen |
| `GeoBlockMiddleware` | common/Security | Global | GEO_BLOCKED_COUNTRIES-Länderblockade (MaxMind GeoIP2) |
| `WafMiddleware` | common/Security | Global(service)+admin | 8 Kategorien, 45+ Regeln + Request-Limits |
| `SecurityPlugin` | Erikwang2013\Security | Global | 31 Angriffserkennungen, IP-White/Blacklist |
| `RateLimitMiddleware` | common/Security | Global | Redis-Token-Bucket-Limiting (per-IP + per-Token Doppel-Bucket) |
| `LocaleMiddleware` | common/I18n | Global | Accept-Language-Parsing |
| `MetricsMiddleware` | common/Metrics | Global | Prometheus-HTTP-Request-Zählung und Latenz |
| `HashidRequestMiddleware` | common/Hashid | Global | hashid-Request-Dekodierung |
| `MaintenanceMiddleware` | common/Security | Global | Wartungsmodus + IP-Whitelist |
| `InternalTokenMiddleware` | common/Security | Routengruppe | `/health/live|ready|deps` internes Token |
| `EncryptionMiddleware` | common/Encryption | Routengruppe | AES-256-GCM Ver-/Entschlüsselung |
| `AuthMiddleware` | common/Auth | Routengruppe | JWT-Bearer-Token-Validierung |
| `AdminRoleMiddleware` | common/Auth | Routengruppe | Admin-RBAC |
| `ConfirmationMiddleware` | common/Confirmation | Routengruppe | Passwort-Zweitbestätigung |
| `SupplierApiKeyMiddleware` | common/Auth | Routengruppe | sk_xxx API-Key-SHA256-Signaturprüfung |

---

## 4. Datenarchitektur

### 4.1 Verteilte Primärschlüssel: Snowflake

```
64-bit Snowflake ID 结构:
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  毫秒级时间戳      数据中心   工作节点    序列号
  纪元: 2024-01-01
  最大寿命: ~69 年
```

Alle Eloquent-Modelle generieren im `creating`-Event automatisch über das `HasSnowflakeId`-Trait. Der DB-Spalten-Typ ist `bigint unsigned`.

### 4.2 ID-Verschleierung: Hashids

```
请求流程:
  Client: GET /api/products/aB3xK7mQ9w
    → HashidRequestMiddleware 解码 → int(1234567890)
      → Controller/Service 使用整数 ID 操作
        → Response::success() / Response::paginated()
          → hashids_encode_ids() 递归编码所有 id/*_id 字段
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 Datenbankverbindungen

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

### 4.4 Verschlüsselungsebenen

| Ebene | Algorithmus | Implementierung | Zweck |
|------|------|------|------|
| Transport | AES-256-GCM | EncryptionMiddleware | API-Request/Response-Body-Verschlüsselung, GCM-Authentifizierung |
| Feld | AES-128-ECB | Encryptable Cast | Automatische Ver-/Entschlüsselung sensibler Felder (deterministisch: gleicher Klartext → gleicher Geheimtext, Login/Eindeutigkeitsprüfung per Geheimtext-Gleichabfrage; nur ECB unterstützt, Cipher-Wechsel erfordert Re-Encrypt-Migration) |
| Hash | bcrypt + SHA256 | JWT / API Key | Unumkehrbare Passwort-/Token-Speicherung |
| Primärschlüssel | Hashids | Response + Middleware | ID-Verschleierung nach außen |

### 4.5 Cache-Ebenen

```
L1: Redis-Cache-Ebene (CacheService)
    Produktliste TTL 5min | Produktdetails TTL 10min
    Regionen TTL 1h | Wechselkurse TTL 30min | TLD TTL 1h
    Invalidierungsstrategie: aktives forget / forgetPattern bei Datenänderung

L2: MySQL-Abfrageebene (Eloquent + Indexoptimierung)
    13 Composite-/Covering-Indizes decken Hochfrequenz-Abfragen ab

L3: Nginx-Antwortkompression (gzip level 6)
    JSON-Antworten 70-85 % Kompressionsrate
```

### 4.6 Internationalisierung (i18n)

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

| Fähigkeit | Beschreibung |
|------|------|
| Header-Parsing | `LocaleMiddleware` parst automatisch den `Accept-Language`-Header |
| Sprach-Fallback | Nicht unterstützte Sprache → `fallback_locale` |
| Statische Übersetzungen | 120 Einträge, 15 Module abgedeckt (`i18n/{locale}/messages.php`) |
| Parameterersetzung | `I18n::trans('key', ['field' => 'value'])` |
| JSON-Felder | `translateField()` verarbeitet mehrsprachige JSON-Spalten |

---

## 5. Sicherheitsarchitektur

### 5.1 WAF-Regelsystem (8 Kategorien, 45+ Regeln)

| Kategorie | Regeln | Erkennungsbereich |
|------|--------|---------|
| SQL-Injection | 9 | Kommentarzeichen, Keywords, Hex-Kodierung, UNION-Queries, immer-wahre Bedingungen, Time-based Blind, Stacked Queries |
| XSS | 8 | HTML-Tags, Script-Varianten, 13 Event-Handler, JS-Pseudoprotokolle, Entity-Kodierung, Data-URI |
| Command-Injection | 5 | Befehle nach Pipe, Befehle nach Semikolon, $(cmd), Backticks, eigenständige Befehls-Keywords |
| File Inclusion | 4 | Path Traversal, PHP-Pseudoprotokolle, absolute Pfade, Null-Byte |
| HTTP-Header-Injection | 2 | CRLF-Zeilenumbruch, Host/Cookie/Set-Cookie-Injection |
| SSRF | 6 | Interne IPs, localhost, Cloud-Metadata, file://-Protokoll |
| NoSQL-Injection | 3 | MongoDB-Operatoren, gefährliche Redis-Befehle |
| Open Redirect | 2 | redirect_uri externe URLs, Doppel-Kodierungs-Bypass |

**Scan-Bereich:** Wert-Injektionsregeln (SQLi/XSS/Command-Injection/Header-Injection/SSRF/NoSQL/Open Redirect) scannen Query-String, Request-Body, User-Agent; der URL-Pfad wird nur mit den File-Inclusion-Mustern (Path Traversal) strukturell geprüft. Geschäftspfade enthalten normale Wörter wie select/insert/alert (z. B. `/order_item/select`); ein Ganzpfad-Scan würde alle CRUD-Endpunkte als Fehlalarm blockieren, daher nimmt der Pfad nicht an der Wert-Injektions-Matching teil.

**Request-Ebene-Schutz:** Content-Type-Whitelist, 10MB-Body-Limit, 2KB-URL-Limit

### 5.2 Authentifizierungssystem

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

## 6. Deployment-Architektur

### 6.1 Produktionstopologie

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │  ← Plattform-eigener Edge-Schutz (DDoS/Bot),
               │  DDoS / Bot     │     unabhängig vom CDN-Produktmodul (vier Anbieter,
               └────────┬────────┘     siehe §2.4)
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

### 6.2 Prozessmodell

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (Standard 8)
├── Queue Worker: provisioning     (×2)
├── Queue Worker: email            (×5)
├── Queue Worker: sms              (×10)
├── Queue Worker: push             (×20)
├── WebSocket Worker               (×2, Port 8282)
└── Cron Timer                     (×1)

webman admin (8788)
└── HTTP Worker × 4
```

---

## 7. Technische Abhängigkeiten

### 7.1 Kern-Framework

| Paket | Version | Zweck |
|----|------|------|
| workerman/webman-framework | ^2.1 | Web-Framework (arbeitsspeicher-resident, multiprozess) |
| illuminate/database | ^10.0 | Eloquent ORM |
| illuminate/events | ^10.0 | Event-System |
| illuminate/redis | ^10.0 | Redis-Client |
| webman/redis-queue | ^1.0 | Redis-Message-Queue |

### 7.2 erikwang2013-Ökosystem-Pakete

| Paket | Zweck |
|----|------|
| snowflake-php | 64-Bit verteilte Primärschlüssel |
| hashids | API-ID-Verschleierung |
| encryptable | DB-Feldverschlüsselung |
| encryption | Transportverschlüsselung AES-256-GCM |
| jwt-webman | JWT-Authentifizierung |
| webman-scout | Elasticsearch-Volltextsuche |
| season | Länderflaggen-Emojis |
| poster-php | Klick-CAPTCHA |

### 7.3 Drittanbieter-Integrationen

| Paket | Zweck |
|----|------|
| stripe/stripe-php | Stripe-Zahlungen |
| twilio/sdk | SMS |
| kreait/firebase-php | FCM-Push |
| guzzlehttp/guzzle | HTTP-Client (Proxmox API usw.) |
| sentry/sentry | Exception-Monitoring |
| phpoffice/phpspreadsheet | Excel-Export |
