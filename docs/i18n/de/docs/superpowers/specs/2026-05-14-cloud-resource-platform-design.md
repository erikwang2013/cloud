# Globale Cloud-Ressourcen-Handelsplattform — Systemdesign

## Projektübersicht

Cloud-Ressourcen-Handelsplattform für globale Nutzer mit gemischtem Modell (Eigenbetrieb + Drittanbieter). Nutzer können Server, IPs, Cloud-Disks, Domains und andere Cloud-Produkte kaufen. Vollautomatische Ressourcenbereitstellung, mehrere Zahlungskanäle, mehrere Währungen, mehrere Sprachen.

### Technologie-Stack

| Ebene | Technologie |
|------|------|
| Benutzer-App | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| Verwaltungsbackend | webman-admin |
| Server | PHP webman (modularer Monolith) |
| Datenbank | MySQL 8.0 (Master/Slave) |
| Cache/Queue | Redis (Cache + Session + Queue) |
| Speicher | S3/OSS + CDN |
| Monitoring | Prometheus + Grafana + Sentry + ELK/Loki |

---

## 1. Modulaufteilung (12 Kernmodule)

| Modul | Verantwortlichkeit |
|------|------|
| **User** | Registrierung/Login (OAuth+E-Mail+Telefon), KYC-Verifizierung, Mitgliedsstufen, Guthabenkonto |
| **Product** | Produktdefinition (SKU), regionale Preisgestaltung, Lagerverwaltung, Kategorien, Suche, Bewertungen |
| **Order** | Warenkorb, Bestellung, Bestelllebenszyklus (ausstehend→bezahlt→bereitstellend→abgeschlossen→erstattet), Verlängerung/Upgrade |
| **Payment** | Zahlungskanal-Routing, Mehrwährungsangebote, Wechselkurse, Rückerstattung, Abgleich |
| **Provisioning** | Anbindung der Cloud-Anbieter-APIs, automatische Erstellung/Verlängerung/Vernichtung von Ressourcen |
| **Domain** | Domainabfrage, Registrierung, Transfer, Verlängerung, DNS-Verwaltung |
| **Supplier** | Anbieter-Onboarding, Genehmigung, Produktveröffentlichung, Abrechnung, Umsatzbeteiligung |
| **Monitor** | Ressourcen-Liveness-Checks, Nutzungserfassung, Alarmregeln |
| **Ticket** | Ticket-Einreichung, Zuweisung, SLA-Verfolgung |
| **Notification** | E-Mail/SMS/App-Push/In-App-Nachrichten, mehrere Vorlagen und Sprachen |
| **Report** | Umsatzberichte, Anbieterabrechnungsberichte, Verkaufstrends |
| **I18n** | Mehrsprachige Begriffe, Mehrwährungs-Wechselkurse, mehrere Zeitzonen |

---

## 2. Kern-Datenmodelle

### Benutzerzentrum (User)

- **users** — Benutzerhaupttabelle (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — Benutzerprofil (user_id, avatar, nickname, country)
- **user_kyc** — Verifizierung (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — Guthabenkonto (user_id, currency, balance, frozen_balance)
- **user_balance_log** — Guthabenänderungsprotokoll (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — Benutzeradressen (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### Produktzentrum (Product)

- **product_categories** — Produktkategorien (id, parent_id, name, icon, sort)
- **products** — Produkthaupttabelle (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — Regionale Preise (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — Produktbilder (product_id, url, sort)
- **product_attributes** — Benutzerdefinierte Attribute (product_id, key, value)
- **product_reviews** — Produktbewertungen (user_id, product_id, order_id, rating, content)
- **regions** — Regionentabelle (id, name, continent, country, city, data_center, status)

### Bestellzentrum (Order)

- **carts** — Warenkorb (user_id, sku_id, region_id, quantity, cycle)
- **orders** — Bestellhaupttabelle (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — Bestellpositionen (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — Bestellzeitlinie (order_id, status, operator, remark, created_at)
- **order_invoices** — Rechnungen (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — Rückerstattungen (order_id, user_id, amount, reason, status, handled_by)

### Zahlungszentrum (Payment)

- **payment_channels** — Zahlungskanal-Konfiguration (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — Transaktionsprotokoll (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — Abgleichstabelle (date, channel_id, channel_total, system_total, diff, status)

### Ressourcenbereitstellung (Provisioning)

- **resources** — Ressourcenhaupttabelle (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — Serverdetails (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — IP-Details (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — Cloud-Disk-Details (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — Domaindetails (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — Bereitstellungsaufgaben (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — Cloud-Anbieter-API-Konfiguration (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### Verwaltung physischer Maschinen (Host & IP Pool)

Eigenbetriebene physische Server verwenden Proxmox VE (Community-Edition, kostenlos) zur Verwaltung von VMs; über die REST-API werden VMs erstellt/verwaltet, IPs zugewiesen und Disks angehängt.

- **host_machines** — Hostmaschinen (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — IP-Pools (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — IP-Zuweisungsprotokoll (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — VM-Disk-Details (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — Disk-Erweiterungsprotokoll (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### Anbieter (Supplier)

- **suppliers** — Anbieterhaupttabelle (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — Anbieter-Produktverknüpfung (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — Abrechnungen (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — Auszahlungsprotokoll (supplier_id, amount, method, account_info, status)

### Domain-Dienste (Domain)

- **domain_tlds** — Unterstützte TLDs (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — Domain-Transfers (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — DNS-Zonen (domain_name, user_id, zone_id)
- **dns_records** — DNS-Einträge (zone_id, type, name, value, ttl, priority)

### Tickets und Benachrichtigungen (Ticket & Notification)

- **tickets** — Tickets (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — Ticket-Nachrichten (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — Benachrichtigungsprotokoll (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — Benachrichtigungsvorlagen (code, name, channels, title_template, body_template, variables)

---

## 3. API-Design-Spezifikation

### Versionsverwaltung

Die API-Version wird über den HTTP-Header `X-Api-Version` angegeben, nicht im URL-Pfad. Der Server injiziert den Versions-Header über Middleware in das interne Routing.

```
Anfrage:  GET /api/auth/login
Header: X-Api-Version: v1

Internes Routing → /api/auth/login → Controller
Antwort-Header: X-Api-Version: v1
```

**Unterstützte Versionen**: `v1` (Standard, automatisch verwendet, wenn der Header fehlt)

**Versionskontrollmechanismus**: `VersionMiddleware` validiert den `X-Api-Version`-Header für alle `/api/*`- und `/admin/api/*`-Pfade; fehlt er, gilt `v1`, nicht unterstützte Versionen liefern `400`. Die Versionsnummer ist nicht mehr Teil des URL-Pfads.

**Schritte zum Hinzufügen einer Version**:
1. Versionsnummer zum Array `VersionMiddleware::SUPPORTED` hinzufügen
2. Neue Versions-Routengruppe in `route.php` registrieren
3. Controller verwenden `$request->properties['api_version']` für versionsabhängige Verarbeitung

### RESTful-Routen

```
Einheitliches Präfix: /api
Verwaltungsbackend: /admin/api
```

**Routengruppen- und Middleware-Matrix:**

| Routengruppe | Middleware | Endpunktbeispiele |
|--------|--------|---------|
| Öffentlich (ohne Präfix) | Globale Middleware-Kette | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | Global + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (Benutzer) | Global + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (sensitiv) | Global + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | Global + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (sensitiv) | Global + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### Einheitliches Antwortformat

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

### Authentifizierungskonzept

| Endpunkt | Verfahren |
|----|------|
| Benutzerseite | JWT (access_token 2h + refresh_token 30d) + TOTP-Zwei-Faktor + Wiederherstellungscodes |
| Adminseite | JWT (access_token 2h + refresh_token 7d) |
| Anbieter-API | API Key (sk_-Präfix, SHA256-gehasht gespeichert, nur bei Erstellung einmalig angezeigt) |
| Cloud-Anbieter-Callback | Signaturprüfung (HMAC-SHA256) |

**Bereits implementierte Authentifizierungsfunktionen**:
- E-Mail-Registrierung + E-Mail-Verifizierungslink
- Telefonnummern-Registrierung + Twilio-SMS-Verifizierungscode (60s Abklingzeit + IP-Rate-Limit 5x/Stunde)
- Google-OAuth-Login / Apple Sign In
- Passwort vergessen (E-Mail-Code + Redis 10min TTL)
- TOTP-Zwei-Faktor-Verifizierung (QR-Code-Scan-Einrichtung, Wiederherstellungscodes als Absicherung)
- Aktive Sitzungsverwaltung (eingeloggte Geräte ansehen/widerrufen, inkl. client_platform-Informationen)
- Kontolöschung nach GDPR (Passwortbestätigung + Soft-Delete + alle Tokens widerrufen)
- Login-Anomaliewarnungen (E-Mail-Benachrichtigung bei neuem IP-Login)
- Login-Sperre (nach 5 Fehlversuchen 15 Minuten gesperrt)

**Benutzerauthentifizierungsablauf:**

```
Registrierungsablauf                     Login-Ablauf
────────                             ────────
1. POST /captcha/create              1. POST /captcha/create
   ← {key, image(Klickposition)}        ← {key, image}
2. POST /auth/register               2. POST /auth/login
   → {email, password, captcha}         → {login, password, captcha}
   → [WAF-Scan]                         → [WAF-Scan]
   → [Rate-Limit: 3 req/min]            → [Rate-Limit: 5 req/min]
   → [Passwort bcrypt(cost=12)]         → [Hash::check()]
   → [Geräte-Fingerprint: sha256(UA+IP)] → [Geräte-Fingerprint: sha256(UA+IP)]
   → [client_platform-Protokoll]        → [client_platform-Protokoll]
   → User::create()                     → [5 Fehlversuche → 15min Sperre]
   → RefreshToken::create()             → [Neue-IP-Erkennung → E-Mail-Warnung]
     user_id, token_hash,               → RefreshToken::create()
     device_fingerprint,                   user_id, token_hash,
     client_platform,                      device_fingerprint,
     expires_at                            client_platform,
   → NotificationDispatcher::send()           expires_at
     (Verifizierungs-E-Mail)            → AuditLogger::record('user_login')
   → AuditLogger::record               ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}    OAuth (Google/Apple):
                                      ─────────────────────
                                      1. GET /auth/google
                                      2. Google-Autorisierung → code
                                      3. GET /auth/google/callback?code=xxx
                                      4. Google-Token verifizieren
                                      5. Benutzer neu erstellen oder finden
                                      6. Token ausstellen (inkl. client_platform)
                                      7. AuditLogger::record('user_oauth_login')

TOTP-Zwei-Faktor                      Sitzungsverwaltung
────────────────                      ────────
1. POST /user/totp/setup              GET /user/sessions
   ← {secret, qr_code_url}               ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                      created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}          DELETE /user/sessions/{id}
3. POST /auth/login                      → RefreshToken::update(revoked=true)
   → {login, password, totp_code}        ← Erfolg
   oder → /auth/login/recovery
   → {login, password, recovery_code}  DELETE /user/account
                                          → Passwortbestätigung + Soft-Delete + alle Tokens widerrufen
Login-Sperrmechanismus
────────────
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### Mehrsprachigkeitskonzept

- Anfrage-Header: Accept-Language: zh-CN / en-US / ja-JP
- JSON-Spalten speichern mehrsprachige Texte: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- i18n-Dateien verwalten statische Texte, je ein Satz für Frontend und Backend

---

## 4. Sicherheitsschutzsystem

### Mehrebenen-Schutzmodell

```
┌─────────────────────────────────────────────────────┐
│ Ebene 1: Netzwerkgrenze                               │
│   DDoS-Reinigung / WAF / IP-Schwarzweißlisten / Geo-Blocking │
├─────────────────────────────────────────────────────┤
│ Ebene 2: Transport- und Anwendungsschutz             │
│   HTTPS+TLS1.3 / CSP / CORS / JWT-Auth / Rate-Limit  │
├─────────────────────────────────────────────────────┤
│ Ebene 3: Daten- und Speichersicherheit               │
│   Verschlüsselte Speicherung / Maskierung / Audit-Logs / Backups │
├─────────────────────────────────────────────────────┤
│ Ebene 4: Virtualisierung und Ressourcenisolierung    │
│   Proxmox-Härtung / VM-Isolierung / Netzwerkisolierung │
├─────────────────────────────────────────────────────┤
│ Ebene 5: Betrieb und Risikokontrolle                 │
│   Betriebsaudit / Anomalieerkennung / Alarme / Notfallreaktion │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 Netzwerkgrenzschutz

#### DDoS-Schutz

```
Benutzeranfrage → CDN (Cloudflare / Alibaba Cloud CDN)
              │
              ├── JS-Challenge / Captcha (verdächtiger Traffic)
              ├── Rate-Limit (Anfragen pro IP pro Sekunde)
              ├── Regionssperre (bestimmte Länder/Regionen blockieren)
              │
              ▼
          Origin (Nginx + webman)
```

| Ebene | Maßnahme | Beschreibung |
|------|------|------|
| CDN-Ebene | Automatische DDoS-Reinigung | Cloudflare-Kostenlosplan unterstützt bereits L3/L4-Schutz |
| CDN-Ebene | Bot-Management | Bösartige Crawler/Abuse-Skripte erkennen und blockieren |
| Nginx-Ebene | limit_req_zone | 10 req/s pro IP, bei Überschreitung 429 |
| Nginx-Ebene | limit_conn | Max. 20 gleichzeitige Verbindungen pro IP |
| webman-Ebene | Token-Bucket-Rate-Limit-Middleware | Präzises Rate-Limit nach Benutzer/Endpunkt-Granularität |

#### WAF-Regeln (webman-Middleware)

Die WAF-Middleware scannt Anfragen mit 8 Kategorien von Regex-Regeln; die Regeln sind in `config/security.php` konfiguriert und werden ohne Neustart heiß aktualisiert. Der Scanbereich umfasst den JSON-Anfragekörper, URL-Pfad + Query-String, User-Agent und den rohen Anfragekörper (Schutz vor JSON-Kodierungs-Bypass).

**8 Kategorien von Erkennungsregeln (45+):**

| Kategorie | Abdeckung |
|------|---------|
| SQL-Injection | Einzelne Anführungszeichen/Kommentarzeichen, SQL-Schlüsselwörter, Hex-Kodierung, UNION-Variationen, Always-true-Bedingungen (`' OR '1'='1`), Zeit-Blind-Injection (`sleep`/`benchmark`), gestapelte Abfragen, Mehrzeilenkommentar-Bypass |
| XSS | HTML-Tags (inkl. kodierter Variationen), Script-Tags und Varianten, 13 JS-Event-Handler, JS-Globalobjekte/gefährliche Funktionen, `javascript:`-Pseudo-Protokoll, HTML-Entity-Kodierung, Data-URI-Injection, Inline-Event-Attribute |
| Command-Injection | Pipe gefolgt von Befehl (`\| cat`), Semikolon gefolgt von Befehl (`; whoami`), `$(cmd)`- und Backtick-Befehlssubstitution, eigenständige Befehlsschlüsselwörter |
| File-Inclusion | Pfad-Traversal (mehrfach kodiert), PHP-Pseudo-Protokolle (`php://`/`data://`/`phar://`), absolute Pfad-Sondierung (`/etc/`/`C:\`), Null-Byte-Injection |
| HTTP-Header-Injection | CRLF-Zeilenumbruch-Injection (`%0d%0a`/`\r\n`), Host/Cookie/Set-Cookie-Header-Injection |
| **SSRF** | Interne IPv4-Adressen (127.x/10.x/172.16-31.x/192.168.x), localhost-Aliasse, Cloud-Metadata-Endpunkte (169.254.169.254), file://-Protokoll |
| **NoSQL-Injection** | MongoDB-Operatoren ($where/$gt/$regex/$or usw.), $where-JS-Injection, gefährliche Redis-Befehle (FLUSHALL/CONFIG SET/SHUTDOWN) |
| **Open Redirect** | Externe URL-Erkennung in Parametern wie redirect_uri/return_url/next/callback, Doppelkodierungs-Bypass |

**Schutz auf Anfrageebene:**

| Schutzpunkt | Maßnahme |
|--------|------|
| Anfragekörper-Größenbegrenzung | Max. 10MB (darüber 413) |
| URL-Längenbegrenzung | Max. 2KB (darüber 414, Schutz vor ReDoS) |
| Content-Type-Whitelist | Nur application/json, multipart/form-data, application/x-www-form-urlencoded |

**WAF-Erkennungsablauf:**

```
Anfrage eintreffend
  │
  ▼
1. Zu scannenden Text holen
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # Anfragekörper
   │     └── false → serialize()-Fallback
   ├── mb_substr(path + queryString, 0, 2048)                # URL (ReDoS-Schutz durch Kürzung)
   ├── User-Agent-Header                                      # UA
   └── file_get_contents('php://input')                      # Roher Körper (Schutz vor JSON-Kodierungs-Bypass)
  │
  ▼
2. Regeln laden (aus config/security.php)
   ├── security.waf.sqli_patterns               (9 Regeln)
   ├── security.waf.xss_patterns                (8 Regeln)
   ├── security.waf.cmd_injection_patterns      (5 Regeln)
   ├── security.waf.file_inclusion_patterns     (4 Regeln)
   ├── security.waf.header_injection_patterns   (2 Regeln)
   ├── security.waf.ssrf_patterns               (6 Regeln)
   ├── security.waf.nosql_injection_patterns    (3 Regeln)
   └── security.waf.open_redirect_patterns      (2 Regeln)
   → array_merge() + array_unique()
  │
  ▼
3. Regeln einzeln abgleichen
   foreach patterns as pattern:
     match($pattern, $input) ───→ Treffer → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ Treffer → 403 "Request blocked by WAF" zurückgeben
     match($pattern, $ua)    ───→ Treffer →
     match($pattern, $raw)   ───→ Treffer →
  │
  ▼
4. match()-Strict-Check
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → Treffer ✓
   ├── $result === 0    → kein Treffer (sicher durchlassen)
   └── $result === false → Musterfehler → error_log() → als kein Treffer behandeln
  │
  ▼
5. Keine Treffer → $next($request) an nächste Middleware weiterreichen
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

        // 8 Regelkategorien aus config/security.php laden
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

#### IP-Schwarzweißlisten

```
Schwarze Liste:
- Bekannte bösartige IP-Datenbank (regelmäßige Synchronisierung mit AbuseIPDB)
- IPs, die häufig WAF-Regeln auslösen (automatisch hinzugefügt, Redis TTL 24h)
- IPs mit Brute-Force-Logins (5 Fehlversuche → 30min Sperre)

Weiße Liste:
- Proxmox-Hostmaschinen-IPs
- IP-Bereiche der Cloud-Anbieter-Callbacks
- IP-Bereiche der Zahlungs-Gateway-Webhooks
- Admin-Büronetz-IPs (optional)
```

#### Geo-Blocking

```php
// GeoIP2-Bibliothek (MaxMind)
$country = geoip($request->getRealIp());

// Konfigurierbare Blocklist
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 Transport- und Anwendungssicherheit

#### Globale Middleware-Ausführungskette

Alle HTTP-Anfragen durchlaufen die Middleware in der folgenden Reihenfolge, jede Middleware ist unabhängig testbar:

```
Anfrage → VersionMiddleware        # X-Api-Version-Prüfung (fehlt → v1, ungültig → 400)
     → CorsMiddleware            # CORS-Cross-Origin-Antwort-Header
     → ClientPlatformMiddleware  # X-Client-Platform-Erkennung (8 Plattformen), Injektion in $request->properties
     → WafMiddleware             # 8 Kategorien mit 45+ Regeln (SQLi/XSS/Command-Injection/File-Inclusion/Header-Injection/SSRF/NoSQL/Open Redirect)
     → LocaleMiddleware          # Accept-Language-Parsing, Region setzen
     → HashidRequestMiddleware   # Anfrageparameter hashid → echte ID-Dekodierung
     → MaintenanceMiddleware     # Wartungsmodus (IP-Whitelist-Durchlass)
     ↓
  [Routen-Middleware — nach Routengruppe angehängt]
     → EncryptionMiddleware      # AES-256-GCM-Verschlüsselung von Anfrage-/Antwortkörper
     → Captcha                   # Klick-Captcha-Prüfung (vor Login/Registrierung)
     → AuthMiddleware            # JWT-Bearer-Token-Verifizierung + Rolleninjektion
     → AdminRoleMiddleware       # RBAC-Berechtigungsprüfung des Admins
     → ConfirmationMiddleware    # Zweite Passwortbestätigung für sensitive Operationen (5 Fehlversuche → 15min Sperre)
     ↓
     Controller
```

#### Aufgaben der einzelnen Middlewares

| Middleware | Registrierung | Aufgabe |
|--------|---------|------|
| `VersionMiddleware` | Global | Validiert den `X-Api-Version`-Header, fehlt er → `v1`, nicht unterstützte Versionen → `400` |
| `CorsMiddleware` | Global | Behandelt OPTIONS-Preflight, spiegelt Origin in `Access-Control-Allow-Origin` |
| `ClientPlatformMiddleware` | Global | Validiert den `X-Client-Platform`-Header, erkennt die Client-OS-Plattform (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web), injiziert in `$request->properties['client_platform']` |
| `WafMiddleware` | Global (service) + Admin-Instanz | 8 Kategorien mit 45+ Regeln + Anfragegrößenbegrenzung + Content-Type-Prüfung, blockierte Anfragen werden im Audit-Log protokolliert |
| `LocaleMiddleware` | Global | Parst den `Accept-Language`-Header, setzt die Mehrsprachigkeitsregion |
| `HashidRequestMiddleware` | Global | Dekodiert Hashid-Strings in Anfragen automatisch in echte Integer-IDs |
| `MaintenanceMiddleware` | Global | Prüft die Umgebungsvariable `MAINTENANCE_MODE`, Whitelist-IPs werden durchgelassen |
| `EncryptionMiddleware` | Routengruppe (/api/auth, /api, /admin/api) | AES-256-GCM-Verschlüsselung von Anfrage-/Antwortkörper, ausgelöst durch `X-Encrypted: 1`-Header |
| `AuthMiddleware` | Routengruppe (/api, /admin/api) | JWT-HS256-access_token-Verifizierung, injiziert `$request->userId` und `$request->userRole` |
| `AdminRoleMiddleware` | Routengruppe (/admin/api) | RBAC-Berechtigungsprüfung des Admins |
| `ConfirmationMiddleware` | Routengruppe (sensitive Operationen) | Zweite Passwortbestätigung, Redis-Fehlerzähler, 5 Fehlversuche → 15 Minuten Sperre |

#### Details zur ClientPlatform-Middleware

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // Wirkt nur auf API-Routen
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

        // Anfrageeigenschaften für Downstream-Nutzung injizieren (Audit-Logs, Sitzungsprotokoll)
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**Datenfluss**: Middleware-Injektion → `AuditLogger` protokolliert automatisch → `AuthService::issueTokens()` schreibt in `refresh_tokens` → `GET /api/user/sessions` gibt Plattforminformationen zurück

#### HTTPS-Erzwingung

```nginx
# Nginx-Konfiguration
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

#### JWT-Sicherheitshärtung

```
- access_token-Gültigkeit 2h, refresh_token-Gültigkeit 30d
- Schlüssel mit RSA256 (asymmetrisch), regelmäßige Rotation (90 Tage)
- jti (JWT ID) in Redis gespeichert für aktiven Widerruf
- refresh_token an Geräte-Fingerprint gebunden (User-Agent + IP-Bereich)
- Beim Ausstellen eines neuen refresh_token wird das alte sofort ungültig (Rotation)
- Sensitive Operationen (Zahlung/Ressourcenvernichtung) erfordern zweite Verifizierung

Geräte-Fingerprint:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  Die refresh_token-Tabelle protokolliert diesen Fingerprint und prüft ihn bei Rotation
```

#### Passwortrichtlinie

```
- bcrypt-Verschlüsselung, cost factor = 12
- Mindestens 8 Zeichen, Groß-/Kleinbuchstaben + Ziffern erforderlich
- 5 aufeinanderfolgende Fehlversuche bei Registrierung/Login → 15 Minuten Kontosperre
- Nach Passwortänderung werden alle ausgestellten Tokens sofort ungültig
- TOTP-Zwei-Faktor-Verifizierung unterstützt (vom Benutzer optional aktivierbar)
```

#### CORS-Strategie

```php
// webman-Middleware
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

#### Sicherheit beim Datei-Upload

```
- Whitelist-Prüfung der Dateierweiterungen (nur: jpg, jpeg, png, pdf, gif)
- MIME-Typ der Datei prüfen (gefälschter Content-Type wird nicht akzeptiert)
- Dateigrößenbegrenzung: Avatar 2MB, KYC-Ausweisdokument 5MB, Anhänge 10MB
- Nach Upload umbenennen: {uuid}.{ext}, Originaldateiname wird nicht behalten
- Zweite Bildverarbeitung: GD/Imagick entfernt EXIF + Metadaten
- Speicherpfad in einem im Web nicht zugänglichen Verzeichnis, Auslesen über PHP-Proxy
- Virenscan: ClamAV (KYC-Ausweisdokumente/vom Benutzer hochgeladene Dateien)
```

---

### 4.3 Daten- und Speichersicherheit

#### Verschlüsselung sensibler Daten

```
Verschlüsselungsalgorithmus: AES-256-GCM (authentifizierte Verschlüsselung, manipulationssicher)
Schlüsselverwaltung: Master-Key in Umgebungsvariablen, jedes Feld nutzt einen unabhängig abgeleiteten Schlüssel

Felder, die verschlüsselt gespeichert werden müssen:
| Datentyp | Feld | Verschlüsselungsart |
|----------|------|----------|
| Passwort | users.password_hash | bcrypt (einseitig) |
| Zahlungsschlüssel | payment_channels.api_key | AES-256-GCM |
| Cloud-Anbieter-Schlüssel | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Proxmox-Token | host_machines.api_token_encrypted | AES-256-GCM |
| KYC-Ausweisnummer | user_kyc.id_number | AES-256-GCM |
| Zahlungskonto | Auszahlungskonto | AES-256-GCM |
| Login-Passwort (VNC) | resource_servers.login_password | AES-256-GCM |

Schlüsselableitung:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### Log-Maskierung

```php
class LogSanitizer
{
    // Automatisch zu maskierende Feldnamensmuster
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

// Monolog-Processor ruft dies automatisch vor dem Schreiben der Logs auf
```

#### Datenbanksicherheit

```
- MySQL verwendet Prepared Statements (von Eloquent automatisch behandelt)
- Minimalprinzip für Datenbankzugriffskonten:
  - app_user: SELECT, INSERT, UPDATE, DELETE (kein DDL)
  - migration_user: DDL-Berechtigung (nur bei Migrationen, IP-beschränkt)
  - read_user: SELECT nur lesen (für Berichte/Datenanalysen)
- Verbindungen mit SSL/TLS (PHP-PDO-SSL-Optionen)
- Datenbankport nicht öffentlich erreichbar (nur intern zugänglich)
- Regelmäßige Backups: Vollbackup 1 Tag, binlog-Echtzeitsynchronisierung
```

#### Datensicherung und Wiederherstellung

```
Backup-Strategie:
- MySQL: täglich vollständig + binlog-Echtzeit-Inkremente
- Redis: RDB stündlich + AOF-Echtzeit-Persistenz
- Vom Benutzer hochgeladene Dateien: S3/OSS mit automatischen Mehrfachkopien + Cross-Region-Replikation
- Proxmox-VM-Snapshots: wöchentlich (4 Wochen aufbewahrt)
- Backup-Verschlüsselung: AES-256-verschlüsselt gespeichert

Wiederherstellungsübung:
- Vierteljährliche Notfall-Wiederherstellungsübung
- Wiederherstellungszeit-Ziel (RTO): < 4 Stunden
- Wiederherstellungspunkt-Ziel (RPO): < 1 Stunde
```

---

### 4.4 Virtualisierung und Ressourcenisolierung

#### Proxmox-Sicherheitshärtung

```
1. API-Zugriffskontrolle:
   - Proxmox-API hört nur auf interne IPs (nicht an öffentlichen gebunden)
   - Token-Minimalprinzip: jede Rolle erhält nur die nötigen Berechtigungen
   - API-Port (8006) nur für die IP des PHP-Anwendungsservers erreichbar (iptables)

2. SSH-Härtung:
   - Passwort-Login deaktiviert, nur Schlüsselauthentifizierung
   - Root-Login deaktiviert, dediziertes Verwaltungskonto verwenden
   - SSH-Port auf nicht standardmäßigen Port geändert (weniger Scans)
   - Fail2ban: 5 Fehlversuche → 1 Stunde Sperre

3. Systemupdates:
   - Proxmox-Sicherheitsupdate-E-Mail-Abonnement
   - Regelmäßig apt update && apt upgrade
   - Kernel-Livepatch (Canonical Livepatch Service)

4. Firewall (iptables/nftables):
   - Standardmäßig alle eingehenden Verbindungen ablehnen
   - Nur öffnen: 8006 (nur App-Server-IP), SSH-Port (nur Verwaltungs-IP)
   - Isolierung des VM-Brücken- vom Host-Verwaltungsnetzwerk
```

#### VM-Isolierung

```
- Jede VM verwendet ein eigenes VLAN auf dem virtuellen Brücken-Netzwerk
- Kommunikation zwischen VMs verboten (Proxmox-Firewallregeln + VLAN-Isolierung)
- Benutzer können nur über die öffentliche IP auf ihre eigene VM zugreifen
- VM-Ressourcenbegrenzung (cgroup): verhindert, dass eine einzelne VM die Host-Ressourcen ausschöpft
  - CPU-Limit: Obergrenze der gekauften Kerne
  - RAM-Limit: Obergrenze der gekauften Kapazität
  - Disk-IOPS-Limit: verhindert Disk-Konkurrenz
  - Netzwerkbandbreiten-Limit: Obergrenze der gekauften Bandbreite
```

#### Sicherheit der IP-Zuweisung

```
- Vollständiges Audit der IP-Zuweisungen (wer, wann, welche IP zugewiesen wurde)
- Abkühlzeit von 24h nach IP-Freigabe (verhindert Missbrauch durch sofortige Neuvergabe an andere)
- IP-Schwarze Liste: beschwerde-/missbrauchsbehaftete IPs als nicht zuweisbar markiert
- IP-Nutzungsüberwachung: regelmäßige Prüfung, ob zugewiesene IPs ordnungsgemäß genutzt werden
```

---

### 4.5 Zahlungssicherheit

```
1. PCI-DSS-Compliance:
   - Kreditkartendaten passieren keine eigenen Server (Stripe Elements / Checkout)
   - card_token wird direkt vom Stripe-Frontend erzeugt, das Backend empfängt nur das Token
   - Keine CVV/vollständige Kartennummern in Logs/Datenbanken speichern

2. Kryptowährungen:
   - Empfangsschlüssel kalt gespeichert (Offline-Signatur)
   - Heiße Wallet hält nur das Tages-Umsatzlimit
   - Prüfsummenverifizierung nach Generierung der Empfangsadresse
   - Große Transaktionen (> $10000) erst nach manueller Prüfung bestätigen

3. Zahlungsbetrugsprävention:
   - Häufige Zahlungen desselben Benutzers/IP in kurzer Zeit → Risikokontroll-Einfrieren
   - Große Zahlungen neu registrierter Benutzer → manuelle Prüfung
   - Anomaler Zahlungsbetrag (stimmt nicht mit Produktpreis überein) → blockieren
   - Benutzer mit übermäßig hoher Rückerstattungsquote → als Risiko markieren

4. Callback-Signaturprüfung:
   - Stripe: Webhook-Signatur prüfen (stripe-signature header)
   - Coinbase: Webhook-Signatur prüfen (X-CC-Webhook-Signature header)
   - Alipay: notify_id prüfen, zweite Bestätigung durch Alipay-Server
   - Alle Callbacks: IP prüfen, ob sie zu bekannten Zahlungs-Gateway-IP-Bereichen gehört
```

#### Rückerstattungssicherheit

```
- Rückerstattungen erfordern eine zweistufige Genehmigung (Support initiiert → Admin bestätigt)
- Vor der Rückerstattung prüfen: Bestellstatus, Rückerstattungszeitraum, Anzahl der Rückerstattungen
- Rückerstattungsbetrag darf den tatsächlich gezahlten Betrag der Originalbestellung nicht überschreiten
- Rückweg auf Originalweg: Zahlungskanal-Rückerstattungsschnittstelle + Guthaben-Rückerstattung
- Rückerstattungs-Mutex-Sperre (Redis): verhindert parallele doppelte Rückerstattungen
```

---

### 4.6 Zugriffskontrolle und Berechtigungen

#### RBAC-Modell

```
Rollenhierarchie:
  super_admin    (Super-Administrator — alle Berechtigungen)
  admin          (Administrator — alles außer Systemkonfiguration)
  finance        (Finanzen — Zahlungen/Abgleich/Rückerstattungen/Abrechnungen)
  support        (Support — Benutzer/Bestellungen/Tickets)
  supplier       (Anbieter — eigene Produkte/Bestellungen/Abrechnungen)
  user           (Normalbenutzer — eigene Ressourcen/Bestellungen/Tickets)

Berechtigungsdefinition:
  {module}.{action}
  Beispiel: order.view, order.create, order.refund, resource.destroy

Berechtigungsprüfungs-Middleware:
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

#### API-Rate-Limit

```php
// webman-Rate-Limit-Middleware (Redis-Token-Bucket)
class RateLimitMiddleware
{
    // Standard: 60 req/min pro Benutzer
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // Schutz vor Brute-Force
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // Schutz vor Massenregistrierung
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // Zahlungs-Rate-Limit
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // API-Aufrufe
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // Upload-Rate-Limit
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

#### Datentrennung für Anbieter

```
Datentrennungsprinzip:
- Anbieter können nur ihre eigenen Ressourcen abfragen und verwalten
- Allen Abfragen mit supplier_id wird automatisch WHERE supplier_id = auth()->supplier_id angehängt

Umsetzung:
  // Globaler Scope
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
  
  // Auf Product/Order-Modellen usw. registrieren
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 Betriebsaudit

```
Im Audit-Log protokollierte Inhalte:
- Operator-ID, IP, User-Agent
- Betriebszeitpunkt
- Betriebsmodul (welches Menü/Interface)
- Betriebsart: Erstellen/Ändern/Löschen/Exportieren/Genehmigen
- Betriebsobjekt: welches Feld welcher Ressource
- Wert vor der Operation / Wert nach der Operation (feldgenaue Änderungen)
- Betriebsergebnis: Erfolg/Fehlschlag
- Request-ID (durchgängige Verfolgung)

Protokollumfang:
- Alle Admin-Operationen (100% protokolliert)
- Sensitive Benutzeroperationen: Zahlung/Ressourcenvernichtung/KYC-Einreichung/Passwortänderung (100% protokolliert)
- Login/Logout (100% protokolliert)
- API-Key-Erstellung/-Widerruf (100% protokolliert)

Speicherung und Aufbewahrung:
- Audit-Logs in separater Datenbank (audit_db), getrennt von der Anwendungsdatenbank
- Mindestens 1 Jahr aufbewahren, finanzbezogene 3 Jahre
- Export als CSV/JSON für Compliance-Prüfungen unterstützt

Audit-Log-Middleware:
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

### 4.8 Risikokontrollregeln

```
Echtzeit-Risikokontroll-Engine:

Regel 1: Anomalies Verhalten neuer Konten
  Bedingung: Registrierungszeit < 24h UND (Gesamtzahlungen > $500 ODER mehr als 5 Tickets erstellt)
  Aktion: Konto als "unter Beobachtung" markieren, Risikokontroll-Admin benachrichtigen

Regel 2: Erkennung von Massenregistrierungen
  Bedingung: Mehr als 3 Konten von derselben IP innerhalb von 24h registriert
  Aktion: Neue Registrierungen ablehnen, neue Konten unter dieser IP einfrieren

Regel 3: Zahlungsanomalien
  Bedingung: Mehr als 5 fehlgeschlagene Zahlungen desselben Benutzers innerhalb von 1h
  Aktion: Zahlungsfunktion für 2h einfrieren, Risikokontroll-Ticket erstellen

Regel 4: Rückerstattungsmissbrauch
  Bedingung: Mehr als 3 Rückerstattungen desselben Benutzers in 30 Tagen ODER Rückerstattungsquote > 20%
  Aktion: Rückerstattungsberechtigung des Kontos einschränken, neue Bestellungen als Risikoprüfung markieren

Regel 5: API-Missbrauch
  Bedingung: Mehr als 10000 API-Aufrufe eines einzelnen Tokens innerhalb von 1h
  Aktion: Token herabstufen (Rate-Limit-Schwelle senken), Admin benachrichtigen

Regel 6: Ressourcenmissbrauch
  Bedingung: VM wegen Spam/DDoS/Mining beschwert (Abuse-Benachrichtigung erhalten)
  Aktion: Automatisches Herunterfahren, Ressource einfrieren, hochpriorisiertes Ticket erstellen

Risikokontroll-Aktionen:
- Markieren (flag): nur protokollieren, keine Nutzungsbeeinträchtigung
- Herabstufen (throttle): Rate-Limit-Schwelle senken
- Einfrieren (freeze): bestimmte Funktionen vorübergehend deaktivieren
- Sperren (ban): Konto dauerhaft sperren
```

---

### 4.9 Notfallreaktion

```
Einstufung von Sicherheitsvorfällen:

P0 (kritisch) — Datenleck, Geldverlust, Plattformausfall
  → CTO + Sicherheitsteam sofort benachrichtigen
  → Notfallreaktion innerhalb von 30 Minuten starten
  → Betroffene Upstream-Dienste offline nehmen, Beweise sichern
  → Vorfallsbericht innerhalb von 24h nach Behebung veröffentlichen

P1 (schwerwiegend) — Einzelkontodiebstahl, Zahlungsbetrug, anormaler WAF-Anstieg
  → Sicherheitsverantwortlichen benachrichtigen
  → Innerhalb von 2h behandeln
  → Betroffene Konten/Ressourcen einfrieren

P2 (normal) — Schwachstellenscan findet mittlere/niedrige Schwachstellen, anormale Login-Warnungen
  → Ins Ticketsystem aufnehmen
  → Im nächsten Iterationszyklus beheben

Notfallkontakte:
- Automatische Benachrichtigung nach P0/P1-Alarm (E-Mail + SMS + Telefon)
- webman-Health-Check-Endpunkt: GET /health (gibt 200 oder Alarm zurück)
- Dienstplan: 7×24-Schichtdienst, mindestens 2 Personen als Backup
```

---

## 5. Ressourcenbereitstellungs-Engine

### Provider-Plugin-Architektur

Jede Kombination aus Cloud-Produkttyp × Cloud-Anbieter implementiert eine einheitliche Schnittstelle:

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // Speziell für eigenbetriebene physische Maschinen
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

ProviderFactory routet anhand von (product_type, provider) zu den konkreten Implementierungen:
- ProxmoxProvider (eigenbetriebene physische Maschinen: Server/Datendisk/IP)
- AwsServerProvider / AliyunServerProvider (Cloud-Server von Drittanbietern)
- GcpIpProvider (IP von Drittanbietern)
- AzureDiskProvider (Cloud-Disk von Drittanbietern)
- NamecheapDomainProvider / GoDaddyDomainProvider (Domains)

### Garantien für asynchrone Aufgaben

- Der Provisioning-Worker pollt die provision_tasks-Tabelle
- Gleichzeitigkeitskontrolle nach Provider gruppiert (max. 5 gleichzeitige je Provider)
- Wiederholungsstrategie: 1min → 5min → 15min → 1h → 6h → 24h (max. 6 Wiederholungen)
- Nicht wiederholbarer Fehlschlag → Alarm + automatische Ticket-Erstellung

### Vollständige Kette von der Bestellung zur Ressourcenbereitstellung

```
Benutzer bestellt                        Zahlung                             Ressourcenbereitstellung
────────                               ────                             ────────
1. POST /cart                          5. POST /orders/{id}/pay         9. OrderPaid-Ereignis
   → addToCart(sku, region, qty)          → Zweite Passwortbestätigung (Confirmation) → ProvisioningService
                                                                             .handleOrderPaid()
2. POST /orders                           → PaymentRouter::route()
   → createOrder()                          Zahlungskanal wählen                   10. Je OrderItem:
   ← {order, order_items}                                                    → ProvisionTask::create()
                                        6. StripeChannel::                     status=pending
3. Gutschein anwenden                      createPaymentIntent()
   POST /coupons/validate                   → Stripe-API                 11. Redis-Queue-Worker
   → validate('CODE', order_total)          ← {client_secret}                → ProviderFactory
   ← {discount, coupon_id}                                                     .create(task)
                                        7. Frontend confirmCardPayment()
4. GET /orders/{id}/payment-methods     8. Stripe-Webhook-Callback            12. Provider->create()
   → Verfügbare Zahlungskanäle abrufen       → Signaturprüfung + Idempotenz-Check  ├→ HostSelector::select()
   ← [{channel, fee, total}]               → transaction=success              ├→ ProxmoxApi::create()
                                            → OrderPaid-Ereignis auslösen        │  createVM(CPU,RAM,Disk)
                                                                              │  allocateIP()
                                        Wiederholungsstrategie (bei Fehlschlag)  │  startVM()
                                        ────────────────                     ├→ Resource-Eintrag erstellen
                                        1min → 5min → 15min                  └→ host_machine aktualisieren
                                        → 1h → 6h → 24h                          (zugewiesene Ressourcenmenge)
                                        (nach 6 Versuchen als fehlgeschlagen markieren + Alarm)
                                                                           13. Order::status = completed
                                        Rückerstattungsablauf                   → NotificationDispatcher
                                        ────────                                ::send('resource_ready')
                                        Benutzerantrag → Support-Prüfung → Admin bestätigt
                                        → provider.destroy()
                                        → payment.refund()
                                        → Rückweg auf Originalweg
```

### Eigenbetriebene physische Maschinen: Proxmox VE (Community-Edition)

Eigenbetriebene Server verwenden Proxmox VE (Open Source, kostenlos, AGPL v3); PHP verwaltet KVM-VM-Lebenszyklen und Ressourcenzuweisungen über HTTP-Aufrufe der Proxmox-REST-API.

Architektur:
```
PHP (webman) ──HTTPS──> Proxmox-VE-API (Port 8006)
                               │
                               └──> KVM/QEMU ──> VM (dem Benutzer zugewiesen)
```

#### ProxmoxApi-Client-Wrapper

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

#### Ressourcenoperationen

**VM erstellen (Server):**
1. HostSelector wählt eine Hostmaschine mit ausreichend Ressourcen (sortiert nach cpu/ram/disk-Restkapazität + Lastausgleich)
2. Eine IP aus dem ip_pool dieser Hostmaschine zuweisen
3. ProxmoxApi.post("/nodes/{node}/qemu") erstellt die VM (vmid, name, cores, memory, net0, ipconfig0 setzen)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") hängt die Systemdisk an (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") startet die VM
6. host_machine.specs zugewiesene Mengen aktualisieren (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**CPU-Upgrade (online):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // Host-Ressourcenstatistiken aktualisieren
```

**RAM-Upgrade (online):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**Systemdisk erweitern:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**Separate Datendisk erstellen:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**Separate IP erstellen:**
Aus dem IP-Pool zuweisen → über die Proxmox-API eine virtuelle Netzwerkkarte hinzufügen + IP konfigurieren, oder als eigenständige Ressource für eine zusätzliche Karte einer bestehenden VM behalten.

**VM vernichten:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // Herunterfahren
$api->delete("/nodes/{node}/qemu/{vmid}");             // VM löschen
releaseIp($resourceId);                                // IP zurück in den Pool
$host->deallocate($specs);                             // Host-Ressourcen zurückfordern
```

#### Host-Auswahlstrategie

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

#### Zusammenfassung der Ressourcenoperationen

| Operation | Umsetzung | Hot-Operation |
|------|----------|--------|
| VM erstellen (CPU+RAM+Systemdisk+IP) | Proxmox create qemu | — |
| Nur CPU-Upgrade | PUT config cores | online |
| Nur RAM-Upgrade | PUT config memory | online |
| Systemdisk erweitern | PUT resize disk | online (erfordert VM-Unterstützung) |
| Separate Datendisk erstellen | POST config Disk hinzufügen | online |
| Separate IP erstellen | Aus IP-Pool zuweisen + Netzwerkkarte an VM | online |

### Ressourcen-Lebenszyklus

```
pending → active → destroyed (30 Tage aufbewahrt) → purged (nicht wiederherstellbar)
```

Verlängerung: active → (renew) → active (expired_at verlängern)
Upgrade: active → (upgrade) → upgrading → active

### Ressourcenquellen

| Quelle | Virtualisierung/API | Produkttypen | Beschreibung |
|------|-----------|----------|------|
| Eigenbetriebene physische Maschinen | Proxmox VE (Community-Edition) | Server, Datendisk, IP | Im eigenen Rechenzentrum gehostet, PHP ruft die Proxmox-API auf |
| Cloud-Anbieter von Drittanbietern | AWS/GCP/Aliyun/Huawei Cloud/Azure SDK | Server, IP, Cloud-Disk | Weiterverkauf von Cloud-Ressourcen Dritter |
| Domain-Registrare | Namecheap/GoDaddy/Aliyun-Wanwang-API | Domain-Registrierung/Transfer | Domain-Dienste |

### Erste Anbindung

| Region | Server | IP | Cloud-Disk | Domains |
|------|--------|----|------|------|
| Asien-Pazifik | Aliyun, Huawei Cloud, AWS | Aliyun, GCP | Aliyun, Huawei Cloud | Aliyun-Wanwang, Namecheap |
| Europa | AWS, GCP, Hetzner | GCP, OVH | AWS, GCP | Namecheap, Gandi |
| Nordamerika | AWS, GCP, Azure | AWS, GCP | AWS, Azure | GoDaddy, Namecheap |

---

## 6. Zahlungssystem

### Multi-Kanal-Routing

PaymentRouter fragt anhand der Währungspräferenz des Benutzers verfügbare Kanäle ab, berechnet den tatsächlich zu zahlenden Betrag je Kanal (inkl. Kanalgebühren) und gibt die Zahlungsoptionen zurück.

### Zahlungsablauf (Stripe)

```
Benutzerseite (Flutter)               Server (webman)                Stripe-API
───────────────               ──────────────                ──────────
1. Stripe-Zahlung wählen
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. payment_transaction erstellen
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → Stripe-SDK ──────────────────────────────────────────→ 6. Benutzer bestätigt Zahlung
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  Signaturprüfung (stripe-signature)
                                  Idempotenz-Check (transaction_no)
                               8. transaction=success aktualisieren
                               9. OrderPaid-Ereignis auslösen
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← Zahlungserfolgsseite               ← Bestellstatus zurückgeben
```

### Kryptowährungszahlung

1. Benutzer wählt Währung (z. B. USDT-TRC20)
2. Backend erzeugt Empfangsadresse über Coinbase Commerce / BitPay-API
3. Worker prüft alle 30s die Blockchain-Bestätigung (oder Webhook)
4. Eingang bestätigt → OrderPaid-Ereignis auslösen

### Wechselkurse und Mehrwährungen

- Wechselkursquelle wird regelmäßig von exchangerate-api abgerufen und in Redis gespeichert
- Produktpreise basieren auf USD, andere Währungen werden in Echtzeit umgerechnet
- Wechselkurs wird bei Bestellung gesperrt, Rückerstattung erfolgt zum Originalkurs

### Sichtbarkeitssteuerung der Zahlungskanäle

Felder der payment_channels-Tabelle:
- is_visible: ob dem Benutzer angezeigt wird
- visible_regions: eingeschränkte sichtbare Regionen, leer bedeutet alle
- min_amount / max_amount: Bestellbetrags-Bereichsbegrenzung

### Abgleich

Täglich in den frühen Morgenstunden werden die Abrechnungsberichte jedes Kanals abgerufen und Transaktion für Transaktion mit den Systemtransaktionen abgeglichen; Abweichungen > $0.01 lösen einen Alarm aus.

### Rückerstattungsrichtlinie

- Server/VPS: volle Rückerstattung innerhalb von 72h nach Kauf
- Domains: Rückerstattung innerhalb von 5 Tagen nach Registrierung (ICANN-Regeln)
- IP: nach Kauf nicht erstattungsfähig
- Cloud-Disk: gleiche Richtlinie wie Server
- Sonderangebotsprodukte: nicht erstattungsfähig

Rückerstattungsablauf: Benutzerantrag → Ticket-Erstellung → Support-Prüfung → Admin bestätigt → provider.destroy() → payment.refund() → Rückweg auf Originalweg

---

## 7. Client-Seitenstruktur

### Flutter / HarmonyOS-Benutzerseite

- **Authentifizierung**: Login/Registrierung (E-Mail+Passwort, Google OAuth, Apple ID, Telefonnummer), Passwort vergessen, Zwei-Faktor-Verifizierung
- **Startseite**: Regionsauswahl, Produktkategorie-Einstiege, Banner/Werbeaktionen, empfohlene Produkte
- **Produkte**: Liste (Multi-Kriterien-Filter), Details (Konfiguration/Region/Preisrechner), Bewertungen
- **Einkaufen & Zahlung**: Warenkorb, Bestellbestätigung (Zahlungsart/Rechnungsadresse/Guthaben/Gutscheincode), Kasse, Zahlungsergebnis
- **Meine Ressourcen**: Ressourcenliste (nach Status gefiltert), Detailaktionen (Neustart/Ausschalten/Verlängern/Upgraden/Vernichten), Konsolen-SSO, Nutzungsdiagramme
- **Bestellungen**: Liste (ausstehend/bezahlt/abgeschlossen/erstattet), Details, Rechnungen
- **Tickets**: Liste, neu erstellen, Konversation
- **Persönliches Zentrum**: Profil/KYC, Guthaben & Aufladen, Benachrichtigungen, Adressverwaltung, Sprach-/Währungs-/Sicherheitseinstellungen
- **Allgemein**: Hilfezentrum, Nutzungsbedingungen, Über uns

### webman-admin-Verwaltungsbackend

- **Dashboard**: Übersicht + Trenddiagramme
- **Benutzerverwaltung**: Liste/Details/KYC-Prüfung
- **Produktverwaltung**: Kategorien/Liste/Preisgestaltung (SKU×Region)/Lager/Bewertungen
- **Bestellverwaltung**: Liste/Details/Rückerstattungsprüfung/Rechnungen
- **Zahlungsverwaltung**: Kanal-Konfiguration/Transaktionsprotokoll/Abgleichsberichte
- **Ressourcenverwaltung**: Liste/Bereitstellungsaufgaben-Überwachung/Cloud-Anbieter-API-Konfiguration
- **Anbieterverwaltung**: Onboarding-Prüfung/Liste/Produktzuweisung/Abrechnung/Auszahlung
- **Ticketverwaltung**: Warteschlange/Meine Tickets/SLA-Überwachung
- **Domainverwaltung**: TLD-Preise/Registrar-API/Transferverwaltung
- **Nachrichtenbenachrichtigungen**: Vorlagenverwaltung/Sendehistorie
- **Systemeinstellungen**: Admins & Rollen/Operationsprotokolle/Mehrsprachigkeit/Wechselkurse/Regionen/Systemparameter
- **Berichte**: Umsatz/Anbieterabrechnungen/Produktverkaufsanalyse/Regionsanalyse

---

## 8. Benachrichtigungssystem

### Vier Kanäle

E-Mail (SMTP/SendGrid) / SMS (Twilio/Aliyun-SMS) / Push (FCM/HMS) / In-App-Nachrichten

### Ablauf

Ereignisauslöser → Notification Dispatcher → Vorlagenabgleich (Ereigniscode+Sprachpräferenz) → Verteilung auf Kanäle nach Benutzerpräferenz → Asynchroner Versand über Redis-Queue

### Benachrichtigungstypen

Registrierungsverifizierungscode, Bestellzahlungserfolg, Ressourcenbereitstellung abgeschlossen, Ressourcenablauf-Erinnerung (7d/3d/1d), Ticket-Antwort, Rückerstattung abgeschlossen, Sicherheitswarnung, Werbeaktionen

### Fehlgeschlagene Wiederholung

3 Versuche mit Backoff, verwaltet über webman redis-queue.

---

## 9. Anbietersystem

### Onboarding-Ablauf

Registrierung → Firmeninformationen+Kontakt+Abrechnungsart einreichen → Admin-Prüfung → nach Genehmigung Produkte veröffentlichen → Admin prüft Produkte → Benutzer kauft → automatische Umsatzaufteilung → Anbieter beantragt Auszahlung → Admin zahlt aus

### Berechtigungstrennung

Anbieter können nur ihre eigenen Produkte/Bestellungen/Abrechnungen/Tickets/Auszahlungsprotokolle sehen. Sie können keine Plattformumsätze, Daten anderer Anbieter oder Zahlungskanal-Konfigurationen sehen.

### Aufteilungsregeln

- Eigenbetriebene Produkte: commission_rate = 100% (gehört ganz der Plattform)
- Produkte von Drittanbietern: commission_rate = 5%~20% (Plattformprovision)
- Abrechnungsformel: Bestellproduktbetrag - Plattformprovision - Kanalgebühren = Anbieterforderung
- Abrechnungszeitraum: wöchentlich / monatlich

### Vollständiger Geschäftsablauf für Anbieter

```
Anbieter-Onboarding                         Admin-Genehmigung
──────────                             ──────────
POST /supplier/apply                   GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,         → Anbieterinformationen prüfen
     contact_phone, contact_email,       POST /admin/api/suppliers/{id}/approve
     settlement_method}                    → Passwort bestätigen
  → SupplierService::apply()               → SupplierService::approve()
  ← {supplier, status:pending}               → User::role = 'supplier'
                                              ← Erfolg
Produktveröffentlichung
────────
POST /supplier/products                Admin-Prüfung
  → {product_id, commission_rate}        → Anbieterprodukt verknüpfen + Provisionssatz setzen
  ← {supplier_product}                    → Produktstatus: published

Benutzer bestellt ──→ Zahlung abgeschlossen ──→ Ressourcenbereitstellung ──→ Bestellung abgeschlossen

Zeitgesteuerte Abrechnung (montags 04:17)    Auszahlung
───────────────────────                 ──────
Cron: SupplierSettlement               POST /supplier/withdraw
  → Abgeschlossene Bestellungen des Zeitraums zählen → Zweite Passwortbestätigung (ConfirmationMiddleware)
  → total_sales - commission berechnen  → SupplierService::requestWithdraw()
  → = payable                            → Auszahlbares Guthaben prüfen
  → SupplierSettlement erstellen          → SupplierWithdraw erstellen (status:pending)
  → Webhook: settlement.created          ← Erfolg

Admin zahlt aus                           Admin-API-Key-Verwaltung
───────────                             ──────────────────
POST /admin/api/suppliers/              POST /admin/api/suppliers/{id}/api-keys
  withdraws/{id}/approve                  → sk_xxx erzeugen (SHA256 gespeichert)
  → Passwort bestätigen                   ← {api_key} (nur einmal angezeigt)
  → SupplierWithdraw: status=completed  DELETE /admin/api/suppliers/api-keys/{id}
  → Webhook: withdrawal.approved           → revoked=true
```

---

## 10. Monitoring und Betrieb

### Ressourcenüberwachung

- Erfasste Metriken: CPU/RAM/Disk/Bandbreitenauslastung, IP-Konnektivität, Disk-IOPS, DNS-Auflösung, SSL-Zertifikatsablauf
- Erfassungsmethode: Agent-Reporting / SNMP (eigen) + Cloud-Anbieter-Monitoring-API (Dritte) + WHOIS/DNS-Polling (Domains)
- Erfassungszyklus: 5 Minuten, gespeichert in Prometheus + VictoriaMetrics

### Alarmregeln

| Alarmereignis | Schweregrad | Auslösebedingung |
|----------|--------|----------|
| Serverausfall | Schwerwiegend | 3 aufeinanderfolgende nicht erreichbare Pings |
| CPU/RAM > 90% | Hinweis | 10 Minuten anhaltend |
| Disk > 90% | Warnung | 5 Minuten anhaltend |
| Bandbreite > 80% | Hinweis | 30 Minuten anhaltend |
| SSL-Zertifikat < 30 Tage bis Ablauf | Warnung | Tägliche Prüfung |
| Domain < 30 Tage bis Ablauf | Warnung | Tägliche Prüfung |
| Bereitstellungsaufgabe fehlgeschlagen | Schwerwiegend | 2 aufeinanderfolgende Fehlschläge |
| Zahlungsabgleichs-Abweichung | Schwerwiegend | Einzelposten > $0.01 |

---

## 11. Bereitstellungsarchitektur

### Produktionsumgebung

- Anwendungsserver × 2: webman (Multi-Prozess) + Nginx + Supervisor
- Datenbank: MySQL 8.0 Master/Slave (1 Master, 2 Slaves) + Redis Cluster
- Queue: webman redis-queue (Zahlungs-Callbacks/Benachrichtigungen/Bereitstellungsaufgaben)
- Geplante Aufgaben: Crontab (Abgleich/Abrechnung/Domainprüfung/Verlängerungserinnerungen)
- Speicher: S3/OSS + CDN
- Log-Monitoring: ELK/Loki + Prometheus + Grafana + Sentry

### Verzeichnisstruktur

```
cloud-php/
├── apps/
│   ├── flutter/           # Flutter-Client
│   └── harmonyos/         # HarmonyOS-Client (ArkTS)
├── service/               # webman-Server
│   ├── app/
│   │   ├── controller/    # Controller (nach Modul)
│   │   ├── service/       # Geschäftslogik (nach Modul)
│   │   ├── model/         # Datenmodelle
│   │   ├── middleware/     # Middleware
│   │   ├── event/         # Ereignisdefinitionen
│   │   ├── listener/      # Ereignis-Listener
│   │   ├── queue/         # Queue-Aufgaben
│   │   ├── provider/      # Cloud-Anbieter-Adapter
│   │   └── cron/          # Geplante Aufgaben
│   ├── common/            # Gemeinsame Bibliothek (auth/payment/i18n/notification/helper)
│   ├── config/            # Konfigurationsdateien
│   ├── database/
│   │   └── migrations/    # Datenbankmigrationen
│   └── storage/           # Logs/Cache/Uploads
├── admin/                 # webman-admin
├── docs/                  # Dokumentation
└── docker/                # Docker-Konfiguration
```

### Wichtige Composer-Abhängigkeiten

workerman/webman-framework, webman/admin, webman/redis-queue, illuminate/database, firebase/php-jwt, stripe/stripe-php, phpseclib/phpseclib, monolog/monolog

### Hochgleichzeitigkeits-Optimierung

#### 1. MySQL-Lese-/Schreibtrennung

Eloquent routet SELECT automatisch zur read-Verbindung, INSERT/UPDATE/DELETE zur write-Verbindung.

```
Konfiguration (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (Master)
  connections.mysql.read  → DB_READ_HOST  (Slave, mehrere konfigurierbar für Lastausgleich)
  sticky = true           → Lesevorgänge nach Schreibvorgängen im selben Request laufen über den Master (Schutz vor Master-Slave-Verzögerung)

Umgebungsvariablen:
  DB_HOST=10.0.1.1          # Master (schreiben)
  DB_READ_HOST=10.0.2.1     # Slave (lesen), mehrere deploybar
```

**Lese-/Schreibrouting-Regeln:**

| Operationstyp | Routing-Ziel | Beispiel |
|---------|---------|------|
| SELECT | read-Verbindung | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | write-Verbindung | `Order::create(...)` |
| Alle Operationen in Transaktionen | write-Verbindung | `DB::transaction(...)` |
| Schreiben dann Lesen (sticky) | write-Verbindung | innerhalb desselben Request-Zyklus |

#### 2. Mehrstufige Redis-Cache-Strategie

Der `CacheService` cached häufig gelesene Daten; wenn Redis nicht verfügbar ist, wird automatisch auf direkte Datenbankabfragen degradiert.

```
Cache-Ebenen:
  L1: Redis (prozessübergreifend geteilt, Millisekunden)
  L2: MySQL (persistent, Absicherung)

Cache-Strategie:
  Produktliste        TTL 5min    Schlüssel nach region_id + category_id + keyword
  Produktdetails      TTL 10min   Schlüssel nach product_id, aktive Invalidierung bei Inhaltsänderung
  Regionsliste        TTL 1h      Regionsdaten ändern sich selten
  Wechselkurse        TTL 30min   Aktualisierung per geplanter Aufgabe + aktives Update
  TLD-Preise          TTL 1h      TLD-Preisänderungen sind selten
  Hilfeartikel        TTL 10min   Aktive Invalidierung bei Veröffentlichung/Änderung
  Produktkategorien   TTL 10min   Aktive Invalidierung bei Kategoriebaum-Änderung

Cache-Vorwärmung (nach Deployment):
  CacheService::warmUp(['products:all', 'regions', 'tlds', 'exchange_rates'])

Aktive Invalidierung (bei Datenänderung):
  ProductController::update() → CacheService::forgetPattern('products:*')
  Crontab::ExchangeRateSync → CacheService::put('exchange_rates', $rates, TTL)
```

```php
// Verwendungsbeispiel
$products = CacheService::remember(
    "products:list:{$regionId}:{$categoryId}",
    CacheService::TTL_PRODUCT_LIST,
    fn() => Product::where('region_id', $regionId)->where('category_id', $categoryId)->get()
);
```

#### 3. Nginx-Antwortkomprimierung + Rate-Limit

```
gzip-Komprimierung:
  gzip on, comp_level=6
  gzip_types: application/json, text/plain, text/javascript, image/svg+xml
  Wirkung: JSON-Antworten 70-85% Komprimierung, spart Bandbreite

proxy-Optimierung:
  proxy_buffering on           # Upstream-Antworten puffern, langsame Clients blockieren keine Worker
  proxy_http_version 1.1       # HTTP/1.1-Keep-Alive-Wiederverwendung
  keep-alive zum Upstream      # Weniger TCP-Handshakes

Rate-Limit:
  limit_req: 10 req/s pro IP (burst 20)
  limit_conn: 20 gleichzeitig pro IP
  /health-Endpunkt ohne Rate-Limit (access_log deaktivieren, um I/O zu reduzieren)
```

#### 4. Datenbankindex-Empfehlungen

Basierend auf der Analyse der Abfragemuster reduzieren die folgenden Indizes in Hochlastszenarien deutlich die gescannten Zeilen:

| Tabelle | Empfohlener Index | Abgedeckte Abfragen |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | Benutzerbestellliste + Statusfilter |
| `orders` | `(order_no)` (eindeutig) | Exakte Bestellnummern-Abfrage |
| `products` | `(status, category_id, sort)` | Frontend-Produktliste + Kategoriefilter + Sortierung |
| `product_skus` | `(product_id, status)` | SKU-Liste + Statusfilter |
| `product_regions` | `(sku_id, region_id)` (eindeutig) | Regionale Preissuche |
| `resources` | `(user_id, status)` | Meine Ressourcenliste |
| `resources` | `(expired_at, status)` | Geplante Ablaufprüfungsaufgabe |
| `provision_tasks` | `(status, next_retry_at)` | Worker-Polling ausstehender Aufgaben |
| `refresh_tokens` | `(user_id, revoked)` | Sitzungsverwaltungsabfragen |
| `payment_transactions` | `(order_id)` | Transaktionen nach Bestellung |
| `payment_transactions` | `(transaction_no)` (eindeutig) | Webhook-Idempotenz-Check |
| `tickets` | `(user_id, status)` | Benutzerticketliste |
| `notifications` | `(user_id, read_at, created_at)` | Benutzerbenachrichtigungsliste |

#### 5. Schätzung der gleichzeitigen Verbindungen

```
webman-Multi-Prozess:
  CPU-Kerne × Prozessanzahl = Worker-Anzahl
  Beispiel: 4 Kerne × 8 Worker = 32 Worker-Prozesse
  
MySQL-Verbindungen:
  Jeder Worker hält 1 persistente Verbindung
  32 Worker × 2 Instanzen (service + admin) = 64 Verbindungen
  Master 32 + Slave 32, konservative Empfehlung MySQL max_connections ≥ 200

Nginx-Verbindungen:
  worker_connections 1024 × worker_processes auto
  Spitzenwert gleichzeitiger Verbindungen ≈ worker_connections × worker_processes / 2
  4-Kern-Server ≈ 2048 gleichzeitige Verbindungen
```

---

## 12. Umsetzungsstatus-Übersicht

### Kernmodule

| Modul | Status | Beschreibung |
|------|------|------|
| **User** | ✅ Fertig | Registrierung/Login/E-Mail-Verifizierung/OAuth/TOTP/Sitzungsverwaltung/GDPR-Löschung/Adress-CRUD |
| **Product** | ✅ Fertig | SKU×Region-Preise, Kategorien, Suche (ES), Bewertungen, Attribute, Batch-Import/-Export |
| **Order** | ✅ Fertig | Warenkorb, Bestellung, Lebenszyklus, Rückerstattung, Rechnungen (PDF), Gutscheine |
| **Payment** | ✅ Fertig | Stripe-Kanal, Multi-Kanal-Routing, Webhook-Signaturprüfung, Abgleich |
| **Provisioning** | ✅ Fertig | Proxmox + AWS EC2 + erweiterbare ProviderFactory-Architektur |
| **Domain** | ✅ Fertig | TLD-Preise, DNS-Einträge, Domain-Transfer-Genehmigung |
| **Supplier** | ✅ Fertig | Onboarding-Genehmigung, Produktveröffentlichung, Abrechnung, Auszahlung, API-Key-Verwaltung |
| **Monitor** | ✅ Fertig | Ressourcen-Liveness-Checks, Alarm-Engine, SSL-Zertifikatsüberwachung |
| **Ticket** | ✅ Fertig | Erstellen/Antworten/Zuweisen/Schließen/SLA-Verfolgung |
| **Notification** | ✅ Fertig | E-Mail/SMS/Push/In-App-Vierkanal + Benutzerpräferenzverwaltung |
| **Report** | ✅ Fertig | Umsatz-/Anbieter-/Regionsberichte |
| **I18n** | ✅ Fertig | Mehrsprachigkeit, Mehrwährungen, mehrere Zeitzonen |

### Sicherheitssystem

| Funktion | Status |
|------|------|
| WAF (8 Kategorien mit 45+ Regeln: SQL-Injection/XSS/Command-Injection/File-Inclusion/Header-Injection/SSRF/NoSQL-Injection/Open Redirect) | ✅ |
| CORS-Middleware | ✅ |
| ClientPlatform-Erkennungsmiddleware (8 Plattformen) | ✅ |
| API-Rate-Limit (Redis-Token-Bucket) | ✅ |
| Geo-Blocking (MaxMind GeoIP2) | ✅ |
| Wartungsmodus (Umgebungsvariablen-Schalter + IP-Whitelist) | ✅ |
| Anfrage-/Antwortverschlüsselung (AES-256-GCM) | ✅ |
| Audit-Logs (separate Datenbank, inkl. client_platform-Verfolgung) | ✅ |
| Datenmaskierung (automatische Behandlung von Logs/Antworten) | ✅ |
| JWT-Geräte-Fingerprint-Bindung + Token-Rotation + client_platform-Protokoll | ✅ |
| bcrypt-Passwörter (cost=12) + doppelte Encryptable-Verschlüsselung | ✅ |
| Zweite Passwortbestätigung (ConfirmationMiddleware, 5 Fehlversuche → 15min Sperre) | ✅ |
| Admin-Panel-WAF-Middleware | ✅ |
| Sentry-Ausnahmeüberwachung (SentryBootstrap + before_send-Maskierung) | ✅ |
| Feature Flags (Redis-Dynamik-Override + Admin-API) | ✅ |

### Neue Funktionen (2026-05-21)

| Funktion | Status |
|------|------|
| Externe Anbieter-API (API-Key-Authentifizierung + Bestell-/Ressourcen-/Abrechnungs-/Auszahlungsendpunkte) | ✅ |
| Echtzeit-WebSocket-Push (Workerman-natives WebSocket + Ereignis-Listener) | ✅ |
| k6-Lasttestskripte (Smoke/Produkte/gleichzeitig) | ✅ |

### Backend-Statistiken

| Kennzahl | Anzahl |
|------|------|
| API-Endpunkte | 135 |
| Datenmodelle | 50+ |
| Datenbanktabellen | 50+ |
| Middlewares | 15 (global 7 + Route 6 + externe API 1 + admin WebSocket) |
| Geplante Aufgaben | 7 |
| Migrationsdateien | 22 |
| Tests | 362 Tests / 579 Assertions (Service 295 + Admin 67) |
| Testdateien | 22 |
| k6-Lasttestskripte | 3 (smoke / products / concurrent) |

### Dokumentation

| Dokument | Pfad |
|------|------|
| Systemdesign-Spezifikation | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| Admin-Panel-Entwurf | `docs/admin-design.md` |
| Anbieter-API-Dokumentation | `docs/supplier-api.md` |
| Bereitstellungscheckliste | `docs/deployment.md` |
| API-Smoke-Testskript | `docs/api-test.sh` |

### Frontend-Status

| Endpunkt | Status | Beschreibung |
|----|------|------|
| Flutter | 🟡 In Arbeit | ApiClient mit Header-Versionsnummer + einheitliche ApiService-Datenschicht angebunden; Login/Produktliste/Warenkorb/Ressourcenliste an API angebunden; Bestellhistorie/Benachrichtigungszentrum benötigen Compile-Umgebungsprüfung |
| HarmonyOS | 🔴 Anfangsphase | Nur Login-Seite und ApiClient |
| Admin Panel | ✅ Fertig | Dashboard/Benutzer/Produkte/Bestellungen/Zahlungen/Ressourcen/Anbieter/Tickets/Domains/Benachrichtigungen/System/Berichte/Webhook/Import-Export in voller Funktion |
