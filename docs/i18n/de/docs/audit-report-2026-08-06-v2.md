# CloudPlatform — Audit-Bericht (Runde 2, 2026-08-06)

> Umfang: Re-Prüfung nach Behebung aller Probleme der letzten Runde (audit-report-2026-08-06.md).
> Testbasis: PHPUnit **319/319 bestanden (505 assertions)**; `php -l` über 253 PHP-Dateien **0 Syntaxfehler**.

---

## I. Tests und statische Prüfung

| Punkt | Ergebnis |
|------|------|
| PHPUnit komplett | OK (319 tests, 505 assertions) |
| `php -l` (app/common/config) | Alle 253 Dateien bestanden |
| composer audit | **Keine Sicherheitslücken**; 1 veraltetes Paket doctrine/annotations (direkte Abhängigkeit von hg/apidoc, Beibehaltung bewertet) |
| composer.lock | Unter Versionskontrolle (Staging A) |

---

## II. Ökosystem-Konfigurationsprüfung

### 2.1 env-Nutzung und -Definition — vollständig ✓

- Alle `getenv()`-Schlüssel im Code (inkl. dynamischer `{PROVIDER}_OAUTH_*`-Muster) sind in `.env.example` definiert oder als Kommentar-Option vorhanden (`#HASHIDS_ALPHABET`, `#POSTER_IMAGE_DRIVER`, `#EXCHANGE_RATE_API_URL`, `#COUNTRY_SEASON_DEFAULT`, `#SECURITY_HSTS_VALUE`)
- Redundante Template-Einträge (niedrig): `MAIL_FROM_NAME` hat keine `getenv()`-Referenz im Code, nur im Template belassen

### 2.2 Abhängigkeitslock ✓

- `service/composer.lock` committet; nicht mehr von `.gitignore` ausgeschlossen; `service/.phpunit.cache/` ignoriert

### 2.3 Umgebungshinweise

- Lokaler Port 8787 wird weiterhin von erp-php belegt; cloud-php kann lokal nicht starten (keine Kollision in der Deployment-Umgebung)
- `composer validate` meldet fatal wegen des Installers des vendor-Plugins `erikwang2013/security-php` (eval-Konflikt mit composer selbst; Drittanbieterproblem, nicht Code dieses Projekts)

---

## III. Sicherheitsprüfung

### 3.1 Globale Middleware-Kette (11 Ebenen, deckt alle Routen ab) ✓

```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock
→ WAF (SQLi/XSS) → SecurityPlugin (31 Angriffserkennungen)
→ Locale → Metrics → HashidRequest → Maintenance
```

### 3.2 Rate-Limiting öffentlicher Routen — 1 Stelle in dieser Runde behoben

| Route | Middleware | Limit-Regel |
|------|--------|---------|
| register / login / refresh | RateLimit | register 3/min, login 5/min |
| **forgot-password / reset-password** | **RateLimit (diese Runde nachgemountet)** | password_reset 3/5min |
| oauthRedirect / oauthCallback (GET+POST) | RateLimit | oauth 10/60s |
| login/recovery | RateLimit | login 5/min |
| send-sms | RateLimit | sms 5/h |
| captcha/create | RateLimit | captcha 30/60s |

> **Fix**: Für die zwei Routen `forgot-password`/`reset-password` war die Regel `password_reset` in der letzten Runde definiert, aber das Mounten der Middleware vergessen worden (Angriffsfläche für E-Mail-Flut/Code-Bruteforce); diese Runde nachgemountet.

### 3.3 Exponierte Upload-Dateien — 1 Stelle in dieser Runde behoben (hochriskant)

**Problem**: Die nginx-Konfiguration in `deployment.md` mit `location /storage/ { alias .../service/storage/; }` macht das gesamte storage-Verzeichnis öffentlich:

```
storage/
├── backups/    ← Datenbank-Backups (.sql.gz) öffentlich herunterladbar
├── apple/      ← AuthKey.p8-Privatekey öffentlich herunterladbar (kann Apple-Token ausstellen)
├── firebase/   ← FCM-Dienstkonto-Anmeldedaten (mit Privatekey) öffentlich herunterladbar
├── geoip/      ← GeoLite2-Datenbank
└── uploads/    ← Upload-Dateien (erwartungsgemäß öffentlich)
```

**Fix**: Sowohl deployment.md als auch docker/nginx.conf auf `location ^~ /storage/uploads/` geändert — nur das Unterverzeichnis uploads wird exponiert.

### 3.4 Weitere Prüfungen ✓

- `verify-email`: Einmaliges Zufallstoken (nach Verifikation geleert), keine Brute-Force/Enumeration-Fläche, kein Rate-Limiting nötig
- Upload-Schnittstelle: type-Whitelist + finfo-MIME-Inhalts-Sniffing (letzte Runde behoben); uploads laufen über nginx-statisches alias direkt aus, kein PHP-Ausführung
- JWT: HS256 + Redis-Blacklist (Bibliothek prüft per jti); TOTP-Login erzwungen + 5 Fehlversuche sperren 15 Minuten
- OAuth: JWKS-Signaturprüfung + iss/aud/exp/nonce + email_verified erzwungen (letzte Runde behoben)
- Admin-Routen: AuthMiddleware + AdminRoleMiddleware + ConfirmationMiddleware

---

## IV. Verbleibende Empfehlungen (nicht blockierend)

| Stufe | Punkt | Beschreibung |
|:---:|------|------|
| P3 | `service/service/` redundantes Altverzeichnis (28K) | Enthält veraltete Supplier/WebSocket-Kopien, nicht per PSR-4 geladen, nicht getrackt, leicht versehentlich zu ändern; nach manueller Bestätigung löschen |
| P3 | `MAIL_FROM_NAME` im Template redundant | Wird vom Code nicht verwendet, kann als reservierte Konfiguration für den E-Mail-Absendernamen bleiben |
| P3 | doctrine/annotations veraltet | Direkte Abhängigkeit von hg/apidoc; Entfernen erfordert synchronen Ersatz des API-Dokumentgenerierungsansatzes |
| P3 | Upload-Verzeichnis härten (zweite Empfehlung) | `index.html` im uploads-Verzeichnis platzieren, keine PHP-Ausführung auf Deployment-Ebene sicherstellen (nginx-alias vermeidet es nativ; beim eingebauten webman-Service beachten) |

---

## V. Fazit

Alle 15 Fixes der letzten Runde wurden per Re-Prüfung bestätigt, die Testbasis ist stabil (319/505). Diese Runde wurden 3 neue Stellen gefunden und sofort behoben: **fehlendes Rate-Limiting-Mounting auf forgot/reset-Routen (P1)**, **nginx-Konfiguration in deployment.md exponiert Backups und Privatekeys (P0)**, **docker-nginx ohne uploads-Statikkonfiguration (P2)**. Nach den Fixes lief der komplette Testlauf erneut durch.

*Berichtsmethode: PHPUnit komplett, php -l über 253 Dateien, statisches Routen-/Middleware-Audit, nginx-/docker-Konfigurationsaudit, Mengendifferenz von env-Nutzung vs. -Definition, composer audit.*
