# CloudPlatform — Gesamt-Audit-Bericht

**Datum**: 2026-08-06
**Prüfumfang**: service vollständig (app / common / config / tests) + Ökosystem-Konfiguration + Sicherheit
**Methodik**: PHPUnit-Testsuite, vollständige PHP-Syntaxprüfung, Routen-/Middleware-Audit, Code-Review der neuen OAuth-Funktion, Konsistenzprüfung von Umgebungsvariablen und Konfiguration, Abhängigkeits-Sicherheitsaudit, Smoke-Tests

---

## I. Gesamtfazit

| Dimension | Fazit |
|------|------|
| Tests | **Alle 314 bestanden** (nach Fix von 2 Bugs, 494 assertions) |
| Syntax | 287 PHP-Dateien, 0 Syntaxfehler |
| Abhängigkeitssicherheit | composer audit ohne bekannte Schwachstellen; 1 veraltetes Paket (doctrine/annotations) |
| Sicherheitsarchitektur | Mehrschichtiger Schutz vollständig (WAF-Doppelengine, CORS-Whitelist, Transportverschlüsselung, Feldverschlüsselung, bcrypt cost=12, JWT-Blacklist, Audit-Protokoll) |
| Schwere Probleme | **1 P0 (Apple id_token ohne Signaturprüfung → Account-Übernahme möglich), 4 P1** |
| Ökosystem-Konfiguration | **.env.example fehlen 31 verwendete Variablen**, einschließlich aller OAuth-Anmeldedaten; Benachrichtigungskanäle sind Platzhalter-Implementierungen |

---

## II. Testergebnisse

```
OK (314 tests, 494 assertions)
```

### Die 2 in dieser Runde behobenen Bugs

| ID | Datei | Problem | Fix |
|----|------|------|------|
| B1 | `service/common/captcha/CaptchaService.php:31` | Liest `$result['extra']['targets']`, aber die Bibliothek liefert `extra.texts` → `target_count` immer 0 | auf `extra.texts` geändert |
| B2 | `vendor/erikwang2013/poster-php/src/Captcha/ClickCaptcha.php:17` | Bibliotheksstandard `targetCount = 5` widerspricht dem eigenen README-Vertrag (medium=3 Ziele) → 3 Captcha-Tests schlugen fehl | Standardwert 5 → 3 |

> B2 ist ein Bug der vendored Bibliothek (vendor/ wird von git verfolgt, der Fix ist dauerhaft). Empfohlen wird, den Fix auch an das Upstream-Repository einzureichen.

---

## III. Schwere Sicherheitsprobleme (P0 / P1)

### P0-1. Apple `id_token` ohne Signaturprüfung — direkte Account-Übernahme möglich
**Datei**: `service/app/user/service/OAuthService.php:180-192` (`appleProfile()`)

```php
$parts  = explode('.', $tokenData['id_token']);
$claims = json_decode(base64_decode($parts[1]), true);   // nur base64-Decodierung, keine Signatur-/iss-/aud-/exp-Prüfung
```

Ein Angreifer kann ein eigenes `id_token` konstruieren und sich mit beliebiger E-Mail-Identität per OAuth einloggen. `resolveUser()` matcht bestehende Nutzer per E-Mail und stellt direkt ein Token aus → **Übernahme beliebiger Konten**.

**Fix**: Apple-JWKS (`https://appleid.apple.com/auth/keys`) + `Firebase\JWT\JWT::decode($idToken, $keys, ['ES256'])` zur Signaturprüfung verwenden und `iss=appleid.apple.com`, `aud=client_id`, `exp`, `nonce` prüfen.

### P1-1. OAuth-Login prüft `email_verified` nicht
**Datei**: `OAuthService.php:163-178, 282-303`

Google/Facebook/Microsoft/LinkedIn liefern alle ein Feld `email_verified`, das der Code komplett ignoriert. Nutzer mit unverifizierter E-Mail beim Provider können damit direkt registrierte Konten binden/übernehmen. Der GitHub-Pfad prüft `verified` (korrekt); die übrigen Provider müssen einheitlich prüfen.

### P1-2. Rate-Limit-Middleware existiert, ist aber nie gemountet — Dokumentation weicht von Implementierung ab
**Datei**: `common/Security/RateLimitMiddleware.php` + `config/security.php` + `config/route.php`

- `security.php` konfiguriert login=5/min, register=3/min usw.
- `RateLimitMiddleware` wird **von keiner Route referenziert** (Grep über die gesamte Codebasis trifft nur die Klasse selbst)
- `docs/features.md` behauptet Login „Rate-Limit 5 req/min", Registrierung „Rate-Limit 3 req/min" — existiert tatsächlich nicht
- Der frühere Audit-Bericht (`security-audit-2026-08-04.md`) markierte diesen Punkt als OK, da nur die Konfiguration, nicht das Mounting geprüft wurde; diese Runde korrigiert das

**Auswirkung**: Öffentliche Endpunkte wie Login/Registrierung/Passwort vergessen/Passwort zurücksetzen/Wiederherstellungscodes/CAPTCHA können ohne Limit gebruteforct werden (Login nur durch per-Account-Sperre geschützt, kein Schutz vor Credential Stuffing und IP-Level-Flut).

**Fix**: `RateLimitMiddleware` auf öffentliche Routen wie `/api/auth/*`, `/api/captcha/*` mounten (auch globale Gruppe `''` möglich, Unterscheidung über den `route`-Parameter).

### P1-3. TOTP-2FA wird im Login-Prozess nicht erzwungen
**Datei**: `AuthService.php:64-97` (`login()`) + `AuthController.php` + `config/features.php`

`user->totp_enabled` wird nur in `totpVerify/totpDisable/totpRecoveryCodes` geprüft, **`login()` prüft es nie**. Nutzer mit aktivierter 2FA erhalten weiterhin allein mit dem Passwort ein gültiges Access-Token — 2FA ist wirkungslos (`FEATURE_TOTP` ist standardmäßig aktiv).

**Fix**: Ist bei Login `totp_enabled` gesetzt, ein temporäres Token ausstellen und erst nach bestandener TOTP-Prüfung das endgültige Token vergeben (oder den Parameter totp code verlangen).

### P1-4. Benachrichtigungskanäle sind Platzhalter — E-Mail-Verifizierung/Passwort-Reset in Produktion unbrauchbar
**Datei**: `app/Notification/Queue/EmailSender.php`, `SmsSender.php`, `PushSender.php`

Alle drei Consumer simulieren das Senden nur mit `error_log()` und markieren `send_status` als `sent`. Folgen:
- **Passwort-vergessen-Kette unterbrochen**: `AuthController::forgotPassword()` erzeugt einen Code und „sendet" eine E-Mail, aber die E-Mail kommt nie an → Nutzer können ihr Passwort nicht selbst zurücksetzen
- Registrierungs-E-Mail-Verifizierung und Login-Alarm bei neuer IP fallen gleichermaßen aus
- Die 7 Variablen `SMTP_*`/`MAIL_FROM_*` in `.env.example` werden von keinem Code gelesen (tote Konfiguration)

**Fix**: Echten E-Mail-Versand anbinden (PHPMailer/SendGrid-SDK), den irreführenden `sent`-Status entfernen; oder die Funktion explizit als unvollständig markieren und die entsprechenden Zusagen aus der Dokumentation entfernen.

---

## IV. Sicherheitsprobleme (P2)

| ID | Datei | Problem |
|----|------|------|
| P2-1 | `app/Controller/UploadController.php:23` | Der Parameter `type` wird ohne Whitelist-Prüfung in den Pfad `uploads/{$type}/...` eingefügt → **Path Traversal** kann aus dem Upload-Verzeichnis hinausschreiben (zufällige Dateinamen, kein Überschreiben, aber Verschmutzung des Dateisystems); empfohlen: type auf Enum-Whitelist beschränken und das Speicherverzeichnis mit `index.php`/`.htaccess` schützen |
| P2-2 | wie oben | Nur Erweiterungsprüfung, kein MIME-Inhalts-Sniffing (Polyglot-Dateien können über Cache/Forwarding ausgenutzt werden); `finfo` zur echten MIME-Prüfung empfohlen |
| P2-3 | `AuthController.php:131-158` | Reset-Code (6 Stellen) 600s gültig, ohne Versuchslimit → innerhalb von 10 Minuten lassen sich 1 Million Kombinationen erraten; `forgotPassword` ohne Frequenzlimit → E-Mail-Flut |
| P2-4 | `AuthController.php:333-348` | `totpRecoveryCodes`: Wiederherstellungscodes erzeugen/einsehen erfordert nur Login, keine Passwortbestätigung; sollte `ConfirmationMiddleware` nutzen |
| P2-5 | `common/Auth/Middleware/AuthMiddleware.php:31` | Manuelle Blacklist-Prüfung mit Key `jwt_blacklist:{sha256(token)}`, abweichend vom Bibliotheksformat `jwt_blacklist:{jti}` → toter Code (tatsächlicher Schutz erfolgt durch `decode()` in der Bibliothek, wirkt aber redundant); löschen oder Bibliotheksschnittstelle verwenden |
| P2-6 | `OAuthService.php:67-94` | Der `redirect`-Parameter von `authorizeUrl` wird im state gespeichert, aber nie verwendet (toter Parameter); state nicht an Provider gebunden; gesamter OAuth-Fluss ohne nonce (OIDC-Provider, fehlende Verteidigungstiefe, zusammen mit P0-1 beheben) |
| P2-7 | `OAuthService.php:31-37, 236-238` | X-(Twitter)-v2-API `userinfo` liefert keine E-Mail → X-Login schlägt zwangsläufig mit „Email not provided" fehl; Funktionsdefekt, Dokumentation nötig oder Umstieg auf `/2/email`-Endpunkt |
| P2-8 | `AuthController.php:436-442` | `deviceFingerprint` schneidet mit `strrpos($ip, '.')` das IPv4-Netzsegment; IPv6-Clients ergeben leere Strings → schwacher Fingerprint; die ersten 64 Bit oder Hash der ganzen IP empfohlen |

---

## V. Vollständigkeit der Ökosystem-Konfiguration

### 5.1 In .env.example fehlende Variablen (im Code per `getenv()` referenziert, aber undefiniert) — 31 Stück

| Kategorie | Variablen |
|------|------|
| **OAuth-Anmeldedaten (neue Funktion, komplett undokumentiert)** | `GOOGLE/APPLE/FACEBOOK/X/MICROSOFT/LINKEDIN/GITHUB_OAUTH_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT_URI` (21 Stück) |
| **Apple-spezifisch** | `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY_PATH` |
| **Kernfunktionen** | `APP_URL` (Verifikations-E-Mail-Links hängen davon ab; fehlt → falsche E-Mail-Links), `APP_ENV`, `APP_VERSION` |
| **Sicherheit** | `INTERNAL_MONITOR_TOKEN` (Schutz der /health/*-Endpunkte), `MAINTENANCE_MODE`, `MAINTENANCE_ALLOWED_IPS`, `WEBHOOK_SECRET`, `JWT_LEEWAY` |
| **Cloud/Speicher** | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `BACKUP_S3_BUCKET`, `BACKUP_S3_REGION`, `DB_READ_HOST` |
| **Feature Flags (8)** | `FEATURE_SSL_PRODUCT`, `FEATURE_OBJECT_STORAGE`, `FEATURE_USAGE_BILLING`, `FEATURE_PROMETHEUS`, `FEATURE_CDN_PRODUCT`, `FEATURE_SUPPLIER_RATING`, `FEATURE_AFFILIATE`, `FEATURE_GRAPHQL` |
| **Sonstiges** | `METRICS_PORT`, `WS_PORT`, `GEOIP_DB_PATH` (in .env.example nur kommentiert), `SSL_STAGING`, `HASHIDS_ALPHABET`, `POSTER_IMAGE_DRIVER`, `EXCHANGE_RATE_API_URL`, `COUNTRY_SEASON_DEFAULT` |

### 5.2 In .env.example definiert, aber vom Code nicht verwendet — 7 Stück

`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` (E-Mail-Versand nicht implementiert, siehe P1-4)

### 5.3 Inkonsistente i18n-Abdeckung

| Sprache | messages.php | billing | health | storage |
|------|:---:|:---:|:---:|:---:|
| en-US / zh-CN | 129 / 129 | 10 / 16 | 8 / 16 | 9 / 16 |
| ja-JP | 63 | 10 | 8 | 9 |
| ko-KR | 51 | 10 | 8 | 9 |
| fr-FR / de-DE / es-ES | 44 | 10 | 8 | 9 |

- Nicht-chinesisch/englische Sprachen fehlen über die Hälfte der Übersetzungsschlüssel; zh-CN hat bei billing/health/storage 6-8 Schlüssel mehr als en-US (Synchronisationsrichtung umgekehrt)
- **OAuth-bezogene Übersetzungsschlüssel fehlen komplett** (Fehlermeldungen sind hartcodiert englisch)

### 5.4 Weitere Ökosystemprobleme

| ID | Problem |
|----|------|
| E1 | `service/composer.lock` wird von `.gitignore` ignoriert und nicht committet — Anwendungsabhängigkeiten ohne Versionslock, Deployment nicht reproduzierbar (Deployment-Risiko) |
| E2 | `service/.phpunit.cache/` erscheint in git status (nicht ignoriert) |
| E3 | Port 8787 kollidiert mit dem lokalen Projekt erp-php; cloud-php lässt sich lokal nicht starten (8787 wird von WorkerMan von erp-php belegt, bestätigt) |
| E4 | Die von `docs/features.md` behaupteten Rate-Limit-/E-Mail-Funktionen entsprechen nicht der Realität (siehe P1-2 / P1-4), Dokumentation muss korrigiert werden |
| E5 | Die Abhängigkeit `doctrine/annotations` ist veraltet (composer-audit-Hinweis), Entfernen erwägen |

---

## VI. Optimierungsempfehlungen (nicht blockierend)

1. **DI-basierte Service-Erstellung**: `AuthController` erstellt im Konstruktor direkt `new AuthService()/OAuthService()`; Anbindung an den Container empfohlen (nativ von webman unterstützt), erleichtert Tests und Austausch.
2. **Upload-Verzeichnis härten**: `index.html` ablegen, PHP-Ausführung deaktivieren (nginx `location ~ \.php { deny all; }`).
3. **WAF-Regex eingrenzen**: `sqli_patterns` in `security.php` enthält breite Muster wie `\b(select|update|delete|...)\b`; bei globalem Rate-Limiting werden Nutzer in Tickets/Bewertungen mit diesen Wörtern fälschlich mit 403 belegt; nur auf sensible Parameter anwenden oder Regex verschärfen.
4. **Log-Audit**: `AuditLogger::record('user_registered', ['user_id' => null])` protokolliert die neue Nutzer-ID nicht; die echte ID eintragen.
5. **OAuth-Testabdeckung**: `OAuthServiceTest` deckt URL-Konstruktion und Code-Austausch ab, aber `resolveUser()` (DB-Pfad) und der Apple-Signaturprüfungspfad sind ungetestet; nach dem P0-Fix müssen Testfälle für fehlgeschlagene Signaturprüfung ergänzt werden.
6. **CI-Anbindung**: Das Projekt hat ein `.github`-Verzeichnis; GitHub Actions empfohlen: `composer install && phpunit` + `composer audit`, um Regressionen zu verhindern.
7. **HTTP-Methodenbeschränkung**: GET/POST-Callback doppelt für OAuth-Routen zu registrieren ist sinnvoll (Apple benötigt es); übrige öffentliche Schreiboperationen sind explizit POST, OK.

---

## VII. Priorisierte Fixliste

| Priorität | Punkt | Aufwand |
|:---:|------|:---:|
| P0 | Apple id_token-Signaturprüfung (JWKS + iss/aud/exp/nonce) | mittel |
| P1 | OAuth: `email_verified` bei allen Providern prüfen | gering |
| P1 | RateLimitMiddleware auf öffentliche Routen mounten | gering |
| P1 | TOTP im Login-Prozess erzwingen | mittel |
| P1 | Echten E-Mail-Versand implementieren (oder als unvollständig markieren) | mittel |
| P1 | .env.example um 31 fehlende Variablen + OAuth-Konfigurationsdokumentation ergänzen | gering |
| P2 | Upload-type-Whitelist + MIME-Prüfung | gering |
| P2 | Rate-Limiting für Reset-Code/Passwort-vergessen | gering |
| P2 | Passwortbestätigung für Recovery-Code-Schnittstelle | gering |
| P2 | composer.lock committen, .phpunit.cache gitignoren | minimal |
| P3 | Blacklist-Totcode bereinigen, WAF-Regex eingrenzen, i18n vervollständigen | mittel |

---

## VIII. Fixstatus (2026-08-06)

| Priorität | Punkt | Status |
|:---:|------|:---:|
| P0 | Apple id_token-Signaturprüfung (JWKS + iss/aud/exp/nonce) | ✅ behoben |
| P1 | OAuth: `email_verified` bei allen Providern prüfen (X mit /2/email-Fallback) | ✅ behoben |
| P1 | RateLimitMiddleware mounten (auth/oauth/password/sms/captcha-Routen + 4 neue Regeln) | ✅ behoben |
| P1 | TOTP im Login erzwingen (5 Fehler sperren 15 Minuten, eigener Zähler gegen DoS) | ✅ behoben |
| P1 | Echter E-Mail-Versand (symfony/mailer SMTP; ohne Konfiguration dev-stub-Status) | ✅ behoben |
| P1 | .env.example um 31 fehlende Variablen + OAuth-Konfigurationsdokumentation ergänzen | ✅ behoben |
| P2 | Upload-type-Whitelist + finfo-MIME-Inhalts-Sniffing | ✅ behoben |
| P2 | Rate-Limiting für Reset-Code/Passwort-vergessen (5 Fehler → 429 für 10 Minuten) | ✅ behoben |
| P2 | Passwortbestätigung für Recovery-Code-Schnittstelle | ✅ behoben |
| P2 | composer.lock entignoren und stagen, .phpunit.cache gitignoren | ✅ behoben |
| P3 | Blacklist-Totcode bereinigt, WAF-Regex eingegrenzt (3 strukturelle), i18n vervollständigt (falsche zh-CN-Inhalte für billing/health/storage neu geschrieben, trans() mit fallback_locale) | ✅ behoben |
| E3 | Port 8787 von erp-php belegt, lokal nicht startbar | ⚠️ Umgebungsproblem, keine Kollision in der Deployment-Umgebung |
| E5 | doctrine/annotations veraltet | ⚠️ Nach Bewertung beibehalten (direkte Abhängigkeit von hg/apidoc; Entfernen würde die API-Dokumentgenerierung brechen) |

Ergänzende Tests: OAuth 12 (inkl. nonce-Parameter, Signaturprüfung, email_verified-Ablehnung, X-E-Mail-Fallback), nach WAF-Verschärfung 2. Gesamtbasis: **319/319 bestanden (505 assertions)**.

*Berichtsmethode: PHPUnit-Gesamttest, `php -l` über 287 Dateien, statisches Routen-/Middleware-Audit, Mengendifferenz von env-Nutzung vs. -Definition, composer audit, Port- und Prozessuntersuchung. Testbasis: 314/314 bestanden.*
