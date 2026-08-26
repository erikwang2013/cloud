# Security Audit Report — cloud-php

**Datum**: 2026-08-04
**Umfang**: Gesamtes Projekt (service + admin)
**Methodik**: Konfigurations-Review, Middleware-Audit, Code-Inspektion

---

## Gesamtbewertung: **B+ (gut, 4 Lücken zu beheben)**

Das Projekt hat eine solide mehrschichtige Sicherheitsarchitektur. Das Plug-in erikwang2013/security-php mit 31 Detektoren ist das herausragende Merkmal. Nachfolgend die detaillierte Aufschlüsselung.

---

## 1. Vorhandene Verteidigungsmaßnahmen (verifiziert)

### Transport und Verschlüsselung
| Mechanismus | Implementierung | Status |
|-----------|---------------|--------|
| API-Transportverschlüsselung | AES-256-GCM über erikwang2013/encryption | OK |
| DB-Feldverschlüsselung | AES-128-ECB über erikwang2013/encryptable (deterministisch, abfragbar) | OK |
| Schlüsselrotation | ENCRYPTION_PREVIOUS_KEYS kommagetrennte Altschlüssel | OK |
| ID-Verschleierung | Hashids mit konfigurierbarem Salt und Mindestlänge 12 | OK |
| Passwort-Hashing | bcrypt cost=12, Mindestlänge 8 | OK |

### Authentifizierung und Zugriffskontrolle
| Mechanismus | Implementierung | Status |
|-----------|---------------|--------|
| JWT-Authentifizierung | erikwang2013/jwt-webman, HS256, Access-TTL 900s + Refresh 30d | OK |
| JWT-Blacklist | Redis-gestützte Token-Widerrufung | OK |
| MFA/TOTP | 6-stellig, 30s-Periode, Google/MS-Authenticator-kompatibel | OK |
| RBAC | Admin-AccessControl-Middleware + plugin\admin\api\Auth::canAccess() | OK |
| Session-Speicherung | Redis (db2) | OK |
| Captcha | erikwang2013/poster-php Klick-Text-CAPTCHA für Login/Registrierung | OK |

### Angriffserkennung (WAF — zweilagig)
| Schicht | Abdeckung | Status |
|-------|----------|--------|
| Eigene WafMiddleware | SQLi, XSS, CMDi, Path Traversal, Header-Injection, SSRF, NoSQLi, Open Redirect | OK |
| Security Plugin (31 Detektoren) | Alles oben + XXE, Deserialisierung, LDAP, Mail-Header, SSTI, JWT-Angriff, Host-Header, Request-Smuggling, GraphQL, XPATH, JNDI/Log4Shell, SSI, CSV-Injection, Daten-Leak, Prototype Pollution, WebSocket, CORS-Bypass, DNS-Rebinding | OK |

### Rate-Limiting (nur service)
| Route | Rate | Burst | Pro | Status |
|-------|------|-------|-----|--------|
| default | 60 | 10 | 60s | OK |
| login | 5 | 2 | 60s | OK |
| register | 3 | 0 | 60s | OK |
| pay | 10 | 3 | 60s | OK |
| upload | 10 | 2 | 60s | OK |
| supplier_api | 120 | 20 | 60s | OK |
| supplier_withdraw | 10 | 3 | 60s | OK |

### Weitere Schutzmaßnahmen
| Mechanismus | Implementierung | Status |
|-----------|---------------|--------|
| Anfragegrößenbegrenzung | 10MB Body, 2KB URL | OK |
| Content-Type-Validierung | Whitelist: JSON, multipart, form-urlencoded | OK |
| Prepared Statements | PDO::ATTR_EMULATE_PREPARES = false | OK |
| Lese-/Schreibtrennung | Schreiben auf Master, Lesen auf Replica, sticky Sessions | OK |
| Audit-Protokollierung | Separate Audit-DB, LogSanitizer redigiert sensible Felder | OK |
| Wartungsmodus | Whitelist-IPs passieren, andere erhalten 503 + Retry-After | OK |
| Automatischer IP-Bann | 5 Verstöße in 60s, dann 15min Bann | OK |
| SQL-Strict-Modus | Verhindert Datentrunkation und implizite Typkonvertierung | OK |

---

## 2. Lücken und Empfehlungen

### Lücke 1 (Mittel): CORS spiegelt jeden Origin
**Datei**: `service/common/security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

Das gibt jeden vom Client gesendeten Origin zurück und erlaubt damit praktisch jeder Website authentifizierte Cross-Origin-Anfragen. Der cors-Detektor des Security-Plugins kann einige Header-Injection abfangen, aber die Middleware selbst hat keine Origin-Whitelist.

**Fix**: Whitelist-Prüfung hinzufügen. Wenn der Origin nicht in der erlaubten Liste steht, mit `Access-Control-Allow-Origin: null` antworten oder den Header ganz weglassen.

### Lücke 2 (Mittel): Fehlende Security-Response-Header
Weder service noch admin setzt kritische HTTP-Sicherheitsheader:

| Header | Empfohlen | Aktuell |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | Fehlt |
| X-Content-Type-Options | nosniff | Fehlt |
| X-Frame-Options | DENY oder SAMEORIGIN | Fehlt |
| Content-Security-Policy | Policy mit nonce/hash | Fehlt |
| X-XSS-Protection | 1; mode=block | Fehlt |
| Referrer-Policy | strict-origin-when-cross-origin | Fehlt |
| Permissions-Policy | Kamera/Mikro/Geolokation einschränken | Fehlt |

**Empfehlung**: Eine SecurityHeadersMiddleware in beide Middleware-Stacks (service und admin) aufnehmen. Hohe Wirkung, geringer Aufwand.

### Lücke 3 (Niedrig): admin/config/security.php ohne Rate-Limiting
**Datei**: `admin/config/security.php`

Das Admin-Panel hat keine rate_limits-Konfiguration. Die Admin-WAF-Middleware prüft nur Anfragegrößen-/Content-Type-Limits. Ein Brute-Force-Angriff auf den Admin-Login ist auf Anwendungsebene nicht rate-limited.

**Empfehlung**: Entweder rate_limits zu admin/config/security.php hinzufügen oder die RateLimitMiddleware auf Admin-Routen anwenden.

### Lücke 4 (Niedrig): GeoBlockMiddleware definiert, aber nicht aktiviert
**Datei**: `service/common/security/GeoBlockMiddleware.php`

Die Middleware existiert und funktioniert, ist aber nicht in `service/config/middleware.php` registriert. Falls Geo-Blocking benötigt wird, muss sie in den Stack aufgenommen werden.

### Lücke 5 (Info): Doppelter WAF-Overhead
Sowohl WafMiddleware (eigene, 40+ Regex-Muster) als auch SecurityMiddleware (Plugin, 31 Detektoren) laufen bei jeder Anfrage. Ihre Musterabdeckung überschneidet sich erheblich bei SQLi, XSS, Command-Injection, Path Traversal, Header-Injection, SSRF, NoSQLi und Open Redirect.

**Empfehlung**: Das Security-Plugin ist umfassender (31 Detektoren vs. 8 Kategorien) und hat IP-Blacklisting, Feld-Whitelisting und Log-Deduplizierung. Erwägen, die eigene WafMiddleware zu entfernen und sich allein auf das Plugin zu verlassen, oder zumindest die überlappenden Muster aus der WafMiddleware zu entfernen.

### Lücke 6 (Info): Validator-Klasse minimal
**Datei**: `service/common/helper/Validator.php`

Enthält nur required(), email(), minLength(). Es fehlen: Max-Länge, numerische Validierung, String-Bereinigung, URL-Validierung, Musterabgleich. Controller ohne Framework-Validierung riskieren die Annahme fehlerhafter Eingaben.

---

## 3. Security Plugin — Status der 31 Detektoren

| # | Detektor | Modus | Anmerkungen |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF matcht Textarea-Inhalte, muss log bleiben |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }} matcht Vue/Angular-Vorlagen |
| 13 | nosql_injection | **log** | $ne/$gt matcht Shell-Variablen/LaTeX |
| 14 | open_redirect | block | |
| 15 | jwt_attack | block | |
| 16 | host_header | block | |
| 17 | request_smuggling | block | |
| 18 | graphql_injection | block | |
| 19 | xpath_injection | block | |
| 20 | jndi_injection | block | |
| 21 | ssi_injection | block | |
| 22 | csv_injection | block | |
| 23 | data_leak | block | |
| 24 | prototype_pollution | block | |
| 25 | websocket | block | |
| 26 | cors | block | |
| 27 | dns_rebinding | **log** | Loopback-Hosts (127.0.0.1/localhost) liefern nicht mehr 403 (üblich in Entwicklung/Test, nur protokollieren) |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

Alle 31 Detektoren aktiviert. 4 nur im Log-Modus (dokumentiertes Falschpositive-Risiko). Korrekte Konfiguration.

---

## 4. Middleware-Ausführungsreihenfolge (service)

```
1. VersionMiddleware          — API-Versionsheader-Parsing
2. CorsMiddleware              — CORS-Header (zu permissiv, siehe Lücke 1)
3. ClientPlatformMiddleware    — OS/Plattformererkennung
4. WafMiddleware               — Eigene WAF (40+ Regex-Muster)
5. SecurityMiddleware           — Plugin-WAF (31 Detektoren)
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — ID-Decodierung
8. MaintenanceMiddleware       — Wartungsmodus-Prüfung
```

---

## 5. Zusammenfassung

| Kategorie | Note | Kernprobleme |
|----------|-------|------------|
| Angriffserkennung | **A** | 31 Detektoren, zweilagige WAF (redundant, aber gründlich) |
| Authentifizierung | **A-** | bcrypt+MFA+JWT-Blacklist, Admin-Rate-Limit fehlt |
| Transportsicherheit | **B+** | AES-256-GCM gut, HSTS/CSP-Header fehlen |
| Eingabevalidierung | **B** | WAF fängt Angriffe ab, App-Level-Validierung dünn |
| Zugriffskontrolle | **A-** | RBAC + Session-Prüfung, CORS zu permissiv |
| Audit/Logging | **A** | Separate Audit-DB, Redaktion sensibler Felder |
| Rate-Limiting | **B+** | Für service gut konfiguriert, für admin fehlend |

**Priorisierte Fix-Reihenfolge:**
1. Sicherheits-Response-Header hinzufügen (HSTS, CSP, X-Frame-Options usw.)
2. CORS auf Whitelist beschränken statt jeden Origin zu spiegeln
3. Rate-Limiting für das Admin-Panel hinzufügen
4. GeoBlockMiddleware aktivieren, falls Geo-Blocking benötigt wird
5. WAF-Ebenen konsolidieren, um den Regex-Overhead pro Anfrage zu reduzieren

---

## 6. Umgesetzte Abhilfe (2026-08-04)

### Behoben
| Lücke | Fix | Geänderte Dateien |
|-----|-----|---------------|
| CORS spiegelt jeden Origin | Whitelist-Modus mit `CORS_ALLOWED_ORIGINS`-env-Variable, unterstützt `*.example.com`-Wildcards und `*` für alle | `service/common/security/CorsMiddleware.php` |
| Fehlende Sicherheitsheader | Neue `SecurityHeadersMiddleware` in beiden Stacks (service und admin): X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection, Permissions-Policy, HSTS (per env opt-in) | `service/common/security/SecurityHeadersMiddleware.php`, `admin/app/middleware/SecurityHeadersMiddleware.php` |
| Admin ohne Rate-Limiting | `rate_limits`-Konfiguration + `RateLimitMiddleware` für das Admin-Panel (default 60/min, login 5/min) | `admin/config/security.php`, `admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock nicht aktiviert | `GeoBlockMiddleware` im service-Middleware-Stack registriert | `service/config/middleware.php` |

### Neue Env-Variablen
| Variable | Zweck | Standard |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | Kommagetrennte erlaubte Origins | (leer = alles verweigern) |
| `SECURITY_HSTS_ENABLE` | HSTS-Header aktivieren | false |
| `SECURITY_HSTS_VALUE` | HSTS-Header-Wert | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | X-Frame-Options-Wert | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | Gesperrte Länder-Codes (ISO 3166-1) | (leer = deaktiviert) |
| `GEOIP_DB_PATH` | GeoLite2-.mmdb-Pfad | storage_path('geoip/GeoLite2-Country.mmdb') |

### Aktualisierte Middleware-Pipeline

**Service:**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin:**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
