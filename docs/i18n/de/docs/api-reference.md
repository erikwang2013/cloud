# CloudPlatform API-Dokumentation

## Übersicht

**Base URL:** `https://api.example.com`

**Versionierung:** Wird über den HTTP-Header `X-Api-Version: v1` angegeben. Fehlt er, wird standardmäßig `v1` angenommen; nicht unterstützte Versionen liefern `400`. Die Version steht nicht im URL-Pfad.

**Authentifizierung:**

| Endpunkt | Verfahren | Header |
|----|------|--------|
| Benutzer-API | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| Admin-API | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| Externe Anbieter-API | API Key | `Authorization: Bearer sk_xxx...` |
| Stripe Webhook | Signaturprüfung | `Stripe-Signature: ...` |

**Client-Plattform:** Allen API-Anfragen sollte ein `X-Client-Platform`-Header beigefügt werden; unterstützt werden `ios/android/macos/windows/linux/web/harmonyos/ipados`.

**Mehrsprachigkeit:** Allen API-Anfragen sollte ein `Accept-Language`-Header beigefügt werden (`zh-CN` / `en-US`); er beeinflusst übersetzte Texte und die Rückgabewerte mehrsprachiger JSON-Felder. Fehlt er, gilt standardmäßig `en-US`.

---

## Einheitliches Antwortformat

### Erfolg

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### Pagination

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### Fehler

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### HTTP-Statuscodes

| code | Beschreibung |
|------|------|
| 0 | Erfolg |
| 400 | Ungültige Anfrageparameter / nicht unterstützte API-Version / nicht unterstützte Client-Plattform |
| 401 | Nicht authentifiziert |
| 403 | Keine Berechtigung / WAF-Blockierung |
| 404 | Ressource nicht vorhanden (firstOrFail/findOrFail-Treffer werden einheitlich auf 404 gemappt) |
| 413 | Anfragekörper zu groß (>10MB) |
| 414 | URL zu lang (>2KB) |
| 415 | Nicht unterstützter Content-Type |
| 422 | Parametervalidierung fehlgeschlagen |
| 429 | Rate-Limit überschritten |

---

## Routengruppen und Middleware-Matrix

| Routengruppe | Middleware | Präfix |
|--------|--------|------|
| Öffentlich | Globale Middleware-Kette | `/health`, `/api/*` |
| `/health` (intern) | Global + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/auth` | Global + Encryption | `/api/auth/*` |
| `/api` (Benutzer) | Global + Encryption + Auth | `/api/user/*`, `/api/cart`, `/api/orders` |
| `/api` (sensitiv) | Global + Encryption + Auth + Confirmation | `/api/orders/{id}/pay` |
| `/api/supplier/external` | Version + SupplierApiKey | Externe Anbieter-API |
| `/admin/api` | Global + Encryption + Auth + AdminRole | Admin-API |
| `/admin/api` (sensitiv) | Global + Encryption + Auth + AdminRole + Confirmation | Sensitive Admin-Operationen |

---

## 1. Öffentliche Endpunkte

### Health Check

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### Dienststatus

```
GET /api/status
→ {
  "overall": "operational",
  "components": {
    "api": "healthy",
    "database": "healthy",
    "redis": "healthy",
    "payment_gateway": "healthy",
    "provisioning": "healthy"
  }
}
```

### Produkte

```
GET /api/products
  Parameter: category_id, region_id, keyword, supplier_id, page (Standard 1), page_size (Standard 20, max. 50)
  → Paginierte Produktliste (inkl. category, skus.regionPrices)

GET /api/products/search
  Parameter: q (Pflicht), page
  → Elasticsearch-Volltextsuche

GET /api/products/{id}
  → Produktdetails (inkl. category, skus, images, reviews)

GET /api/products/{productId}/reviews
  → Bewertungsliste + avg_rating + total + distribution
  Status-Enum: pending(ausstehend)/approved(genehmigt)/rejected(abgelehnt), nur approved wird zurückgegeben
```

### Domains

```
GET /api/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/domain/tlds
  → Liste verfügbarer TLDs (Redis-Cache 1h)
```

### Hilfezentrum

```
GET /api/help
  Parameter: category, page
  Header: Accept-Language (en-US / zh-CN)
  → Paginierte Hilfeartikel

GET /api/help/categories
  → Liste der Artikelkategorien

GET /api/help/{slug}
  → Details eines einzelnen Artikels
```

---

## 2. Authentifizierungs-Endpunkte

### Captcha

```
POST /api/captcha/create
  Header: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### Registrierung

```
POST /api/auth/register
  Header: X-Encrypted: 1
  Body (verschlüsselt): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Rate-Limit: 3 req/min
```

- `deviceFingerprint` (optional): Der Geräte-Fingerprint wird bei der Registrierung erfasst und bei Login/Refresh geprüft; ohne Angabe wird keine Fingerprint-Bindung vorgenommen
- email/phone werden vor der Speicherung deterministisch über Encryptable verschlüsselt (ECB, äquivalente Abfragen anhand von Ciphertext); Eindeutigkeitsprüfung und Login-Abfrage erfolgen über den Ciphertext

### Login

```
POST /api/auth/login
  Header: X-Encrypted: 1
  Body (verschlüsselt): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Rate-Limit: 5 req/min, nach 5 Fehlversuchen Sperre für 15 min
```

- `login` wird als äquivalente Abfrage über den Ciphertext durchgeführt (deterministische Verschlüsselung via Encryptable); Klartext-Abfragen treffen verschlüsselte Spalten nicht

### Token-Refresh

```
POST /api/auth/refresh
  Header: X-Encrypted: 1
  Body (verschlüsselt): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` stimmt nicht mit dem bei der Registrierung erfassten überein → 401 `Device mismatch`; das Refresh-Token wird anhand eines Hash des Ciphertexts gesucht

### OAuth

Unterstützte Anbieter: google, apple, facebook, x, microsoft, linkedin, github
(Die Aktivierung richtet sich nach `{PROVIDER}_OAUTH_CLIENT_ID` etc. in der .env)

```
GET /api/auth/{provider}            → { url }        # Weiterleitung zur Autorisierungsseite (PKCE/nonce gegen Replay)
GET /api/auth/{provider}/callback?code=xxx&state=yyy
POST /api/auth/{provider}/callback  Body: { code, state }
```

- Apple/Microsoft liefern ein id_token; der Server prüft Signatur, iss/aud/exp/nonce über JWKS
- Alle Anbieter verlangen `email_verified=true` für den Login, sonst 422
- `state` fehlt oder stimmt nicht überein → 422 (Schutz vor CSRF, Ablauf nach 5 Minuten)
- Rate-Limit für OAuth-Abläufe: 10 Anfragen pro 60 Sekunden (redirect + callback)

### Passwort-Reset

```
POST /api/auth/forgot-password
  Body: { email }
  → Versendet E-Mail mit Bestätigungscode

POST /api/auth/reset-password
  Body: { email, code, password }
  → Zurücksetzen erfolgreich
  → Nach 5 Fehlversuchen → 429, Rate-Limit für 10 Minuten
```

### E-Mail-Verifizierung

```
GET /api/auth/verify-email?token=xxx
  → Verifizierung erfolgreich
```

### SMS-Verifizierung

```
POST /api/auth/send-sms
  Body: { phone }
  → Versendet SMS-Bestätigungscode (60s Abklingzeit)
```

### TOTP-Zwei-Faktor-Verifizierung

```
POST /api/user/totp/setup        → { secret, qr_url }        # Nicht persistiert; muss innerhalb von 10 Minuten per verify aktiviert werden
POST /api/user/totp/verify       Body: { code } → { verified: true }   # Bei Erstaktivierung wird eine Erfolgsmeldung zurückgegeben
POST /api/user/totp/disable      Body: { password }             # Erfordert Passwortbestätigung, sonst 403
GET /api/user/totp/recovery-codes → { recovery_codes }        # Erzeugt jeweils 8 Einmalcodes, Passwortbestätigung erforderlich, sonst 403
POST /api/auth/login/recovery    Body: { login, password, recovery_code }
```

- Nach Aktivierung von TOTP muss der Login `totp_code` enthalten, sonst 401
- Nach 5 aufeinanderfolgenden TOTP-Fehlern wird der Benutzer für 15 Minuten gesperrt (login_lock)

---

## 3. Benutzer-Endpunkte (authentifiziert)

### Profil

```
GET /api/user/profile
PUT /api/user/profile
  Body: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC-Verifizierung

```
POST /api/user/kyc
  Body: { id_type, id_number, real_name, front_image, back_image }
```

### Guthaben

```
GET /api/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/user/balance/transactions
  Parameter: page
  → Guthabenänderungen
```

### Adressverwaltung

```
GET /api/user/addresses
POST /api/user/addresses
  Body: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/user/addresses/{id}
DELETE /api/user/addresses/{id}
```

### Sitzungsverwaltung

```
GET /api/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/user/sessions/{id}
  → Widerruft die angegebene Sitzung

DELETE /api/user/account
  Body: { confirm_password }
  → Konto-Löschung nach GDPR
```

### Benachrichtigungen

```
GET /api/user/notifications
  Parameter: page
  → Paginierte Benachrichtigungsliste

POST /api/user/notifications/{id}/read
  → Als gelesen markieren

GET /api/user/notification-prefs
PUT /api/user/notification-prefs
  Body: { email: {order_paid: true, ...}, push: {...} }
```

### E-Mail

```
POST /api/user/resend-verify-email
  → Verifizierungs-E-Mail erneut senden
```

### Datei-Upload

```
POST /api/upload
  Body: multipart/form-data { file, type: avatar/kyc/attach }
  Beschränkungen: avatar 2MB, kyc 5MB, attach 10MB
  Zulässig: jpg, jpeg, png, gif, pdf
  Hinweis: Typ-Whitelist-Prüfung + finfo-Inhaltserkennung (Extension und MIME weichen ab → 422)
```

---

## 4. Warenkorb und Bestellungen

### Warenkorb

```
POST /api/cart
  Body: { sku_id, region_id, quantity, cycle }
GET /api/cart
DELETE /api/cart/{id}
PUT /api/cart/{id}
  Body: { quantity }
```

> Konvention für Betragsfelder (beschlossen in D4/P4.2): Alle Beträge sind Strings mit 4 Nachkommastellen (z. B. "9.9900"), number/float ist verboten —
> konsistent mit der Rohausgabe von MySQL-DECIMAL-Spalten über PDO; die Präzision liegt im 4dp-String selbst. Gilt für alle Endpunkte mit Bestellungen/Guthaben/Berichten.

### Bestellungen

```
POST /api/orders
  → Erstellt Bestellung aus dem Warenkorb
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/orders
  Parameter: page, status (pending/paid/provisioning/completed/refunded, ungültige Werte liefern 400)
  → Meine Bestellungen

GET /api/orders/{id}
  → Bestelldetails (inkl. items, timeline)

GET /api/orders/{id}/payment-methods
  → Verfügbare Zahlungskanäle + tatsächlich zu zahlender Betrag je Kanal

POST /api/orders/{id}/pay    🔒 Passwortbestätigung
  Body: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### Gutscheine

```
POST /api/coupons/validate
  Body: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp (z. B. "2.0000")

422: ungültig/abgelaufen/Nutzungsbedingungen nicht erfüllt
```

### Rechnungen

```
GET /api/invoices
  Parameter: page
GET /api/invoices/{id}
GET /api/invoices/{id}/download
  → PDF-Download
```

---

## 5. Ressourcenverwaltung

```
GET /api/resources
  Parameter: page, status
  → Meine Ressourcen

GET /api/resources/{id}
  → Ressourcendetails

GET /api/resources/{id}/status
  → Aktueller Ressourcenstatus + Metriken

GET /api/resources/{id}/console
  → VNC/Konsolen-URL

POST /api/resources/batch
  Body: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. DNS-Verwaltung

```
GET /api/dns/{domain}
  → Liste der DNS-Einträge

POST /api/dns/{domain}/records
  Body: { type, name, value, ttl?, priority? }

DELETE /api/dns/{domain}/records/{id}   🔒 Passwortbestätigung
```

---

## 7. Tickets

```
POST /api/tickets
  Body: { resource_id?, category, priority?, title, content }

GET /api/tickets
  Parameter: page, status

GET /api/tickets/{id}

POST /api/tickets/{id}/reply
  Body: { content }
```

---

## 8. Anbieter (interne API)

```
POST /api/supplier/apply
  Body: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/supplier/settlements
  → Liste der Abrechnungen

POST /api/supplier/withdraw    🔒 Passwortbestätigung
  Body: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/supplier/products
POST /api/supplier/products
  Body: { product_id, commission_rate }
DELETE /api/supplier/products/{id}
```

---

## 9. Externe Anbieter-API

**Authentifizierung:** `Authorization: Bearer sk_xxx...` (Signaturprüfung per SHA256)

**Rate-Limit:** 120 req/min (Auszahlung 10 req/min)

```
GET /api/supplier/external/orders
  Parameter: page, page_size, status, from, to

GET /api/supplier/external/orders/{id}
  → Bestelldetails (nur Bestellungen dieses Anbieters)

GET /api/supplier/external/resources
  Parameter: page, status, type

GET /api/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/supplier/external/settlements
  Parameter: page, status

GET /api/supplier/external/settlements/{id}

POST /api/supplier/external/withdraw
  Body: { amount, account_info: { method, ... } }

GET /api/supplier/external/withdraws
  Parameter: page
```

---

## 10. Admin-API

**Authentifizierung:** JWT Bearer Token + Admin-Rolle

### Dashboard

```
GET /admin/api/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### Benutzerverwaltung

```
GET /admin/api/users              Parameter: page, status, keyword
GET /admin/api/users/export       → Excel-Download
GET /admin/api/users/{id}
PUT /admin/api/users/{id}/status  Body: { status }
```

### KYC-Prüfung

```
GET /admin/api/kyc                Parameter: page, status

POST /admin/api/kyc/{id}/approve  🔒 Passwortbestätigung
  Body: { confirm_password }

POST /admin/api/kyc/{id}/reject   🔒 Passwortbestätigung
  Body: { confirm_password, reason }
```

### Produktverwaltung

```
POST /admin/api/products
PUT /admin/api/products/{id}
DELETE /admin/api/products/{id}         🔒 Passwortbestätigung
POST /admin/api/products/{productId}/skus
PUT /admin/api/skus/{id}
POST /admin/api/skus/{skuId}/region-price
GET /admin/api/products/export         → CSV-Download
POST /admin/api/products/import        → CSV-Upload (upsert)
```

### Bestellverwaltung

```
GET /admin/api/orders              Parameter: page, status, keyword
GET /admin/api/orders/export       → Excel-Download
GET /admin/api/orders/{id}

POST /admin/api/orders/{id}/refund 🔒 Passwortbestätigung
  Body: { confirm_password, amount?, reason }
```

### Zahlungsverwaltung

```
GET /admin/api/payments/channels
PUT /admin/api/payments/channels/{id}
GET /admin/api/payments/transactions  Parameter: page, channel, status
GET /admin/api/payments/reconcile     Parameter: date; records.status: verified/mismatch/unverified
POST /admin/api/payments/reconcile/run  Parameter: date; löst täglichen Abgleich aus
```

### Ressourcen und Bereitstellung

```
GET /admin/api/provisioning/tasks              Parameter: page, status
POST /admin/api/provisioning/tasks/{id}/retry
POST /admin/api/provisioning/resources/{id}/upgrade
  Body: { cpu?, ram?, disk? }
POST /admin/api/provisioning/resources/{id}/destroy   🔒 Passwortbestätigung
GET /admin/api/provisioning/hosts
```

### Anbieterverwaltung

```
GET /admin/api/suppliers                 Parameter: page, status
GET /admin/api/suppliers/export          → Excel-Download

POST /admin/api/suppliers/{id}/approve   🔒 Passwortbestätigung
POST /admin/api/suppliers/{id}/settle    🔒 Passwortbestätigung
  Body: { period_start, period_end, confirm_password }

POST /admin/api/suppliers/withdraws/{id}/approve  🔒 Passwortbestätigung
```

### Anbieter-API-Keys

```
GET /admin/api/suppliers/{id}/api-keys
POST /admin/api/suppliers/{id}/api-keys
  Body: { name }
  ← { api_key: "sk_xxx...", prefix } (wird nur einmal angezeigt)

DELETE /admin/api/suppliers/api-keys/{id}
```

### Ticketverwaltung

```
GET /admin/api/tickets                  Parameter: page, status, priority, assigned_to
POST /admin/api/tickets/{id}/assign     Body: { user_id }
POST /admin/api/tickets/{id}/close
```

### Domainverwaltung

```
GET /admin/api/domains/tlds
POST /admin/api/domains/tlds
  Body: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/domains/tlds/{id}
DELETE /admin/api/domains/tlds/{id}
GET /admin/api/domains/zones             Parameter: page
GET /admin/api/domains/transfers         Parameter: page
POST /admin/api/domains/transfers/{id}/approve
```

### Benachrichtigungsverwaltung

```
GET /admin/api/notifications/templates
PUT /admin/api/notifications/templates/{id}
  Body: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/notifications/log         Parameter: page
```

### Gutscheine

```
GET /admin/api/coupons
POST /admin/api/coupons
  Body: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/coupons/{id}
```

### Hilfeartikel

```
GET /admin/api/help
POST /admin/api/help
  Body: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/help/{id}
DELETE /admin/api/help/{id}              → Soft-Delete (status=archived)
```

### Cloud-Anbieter-API

```
GET /admin/api/providers
POST /admin/api/providers
  Body: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/providers/{id}
DELETE /admin/api/providers/{id}         → Deaktivieren (status=disabled)
```

### Webhook-Verwaltung

```
GET /admin/api/webhooks
POST /admin/api/webhooks
  Body: { url }
DELETE /admin/api/webhooks              Body: { id }
POST /admin/api/webhooks/test           Body: { url }
```

### Berichte

```
GET /admin/api/reports/revenue           Parameter: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp (konsistent mit SUM(DECIMAL) und bcmath-Summen)
GET /admin/api/reports/supplier          Parameter: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/reports/region            Parameter: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### Monitoring

```
GET /admin/api/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### Audit-Logs

```
GET /admin/api/audit-logs                Parameter: page, user_id, action, from, to
  → Paginierte Audit-Logs (inkl. client_platform)
```

### Feature Flags

```
GET /admin/api/features
  → [{ name, enabled, default, source }]

PUT /admin/api/features/{name}
  Body: { action: enable/disable/toggle/reset }
```

### Systemkonfiguration

```
PUT /admin/api/system/config             🔒 Passwortbestätigung
```

### Produkt-Import/-Export

```
GET /admin/api/products/export           → CSV-Download
POST /admin/api/products/import          → CSV-Upload (upsert)
```

### Anbieter- und Benutzer-Export

```
GET /admin/api/suppliers/export          → Excel-Download
GET /admin/api/users/export              → Excel-Download
GET /admin/api/orders/export             → Excel-Download
```

---

## 11. SSL-Zertifikate

### Benutzerseite

```
GET /api/ssl/plans
  → Liste der SSL-Pakete (DV/OV/EV, Preis inkl. register/renew/transfer)

GET /api/ssl-certs
  → Meine Zertifikate (inkl. status: pending/active/expired/revoked)

GET /api/ssl-certs/{id}
  → Zertifikatsdetails (Domain, Ausstellungsstelle, Gültigkeit, Verlängerungsstatus)

GET /api/ssl-certs/{id}/download
  → Zertifikatsdateien herunterladen (Zertifikatskette + privater Schlüssel)

POST /api/ssl-certs/{id}/auto-renew
  Body: { auto_renew: true/false }
  → Automatische Verlängerung umschalten
```

### Adminseite

```
GET /admin/api/ssl/plans              → Paketliste
POST /admin/api/ssl/plans             → Paket erstellen
PUT /admin/api/ssl/plans/{id}         → Paket aktualisieren
DELETE /admin/api/ssl/plans/{id}      → Paket löschen
GET /admin/api/ssl/certs              → Alle Zertifikate
POST /admin/api/ssl/certs/{id}/revoke → Zertifikat widerrufen
```

---

## 12. Objekt-Speicher

S3-kompatibler Objekt-Speicher; Upload/Download über vorsignierte URLs, Schlüssel werden nie übertragen.

```
GET /api/storage/buckets
  → Meine Speicher-Buckets (Nutzung, Status)

GET /api/storage/buckets/{id}
  → Bucket-Details

POST /api/storage/buckets/{id}/presign-upload
  Body: { filename, content_type, size }
  → { upload_url, object_key } Vorsignierte Upload-URL (zeitlich begrenzt)

POST /api/storage/buckets/{id}/presign-download
  Body: { object_key }
  → Vorsignierte Download-URL (zeitlich begrenzt)

GET /api/storage/buckets/{id}/credentials
  → Temporäre Zugangsdaten (kurzlebig, für Direkt-Upload per SDK)
```

---

## 13. CDN-Beschleunigung

### Benutzerseite

```
GET /api/cdn/domains
  → Meine CDN-Domains (Origin, Status, Paket)

POST /api/cdn/domains
  Body: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
          origin_type (server|storage), origin_value, cert_config? }
  → CDN-Domain erstellen (wird anbieterseitig angelegt und an den Origin gebunden)
  → Bei provider_type=aliyun|tencent muss die Domain eine ICP-Registrierung abgeschlossen haben (sonst 4002)
  → Antwort enthält das Hinweisfeld requires_icp_registration
  → Anmeldedaten-Auflösung: zuerst gebundenes Konto der Domain (provider_account_id), sonst aktives
    provider_apis-Konto mit code=cdn-{provider_type}, sonst Fallback auf env-Konfiguration

GET /api/cdn/domains/{id}
  → CDN-Domain-Details

DELETE /api/cdn/domains/{id}
  → CDN-Domain löschen (anbieterseitige Domain deaktivieren, idempotent)

POST /api/cdn/domains/{id}/purge
  Body: { urls: ["https://cdn.example.com/path"] }
  → Cache leeren (doppelte URLs werden automatisch dedupliziert, idempotent; max. 100)

GET /api/cdn/domains/{id}/stats
  → Domain-Überblick (cdn_domain / provider_type / plan / status / purged_at)
```

### Adminseite

```
GET /admin/api/cdn/domains            → Alle CDN-Domains (inkl. zugehörigem Benutzer)
PUT /admin/api/cdn/domains/{id}       → Domain-Paket aktualisieren (plan-Whitelist: standard | pro | enterprise)
```

Die Admin-CDN-Routen hängen an `RbacMiddleware('cdn.manage')`; Paketänderungen werden im Audit-Log festgehalten (`admin_cdn_update_plan`). Anbieter-Anmeldedaten werden per CRUD über `/admin/api/providers` gepflegt (RbacMiddleware `provider.config`, `code`-Konvention `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, Anmeldedaten mit Encryptable verschlüsselt gespeichert).

### CDN-Fehlercodes

| code | Beschreibung |
|------|--------------|
| 4001 | CDN-Parameter fehlen/ungültig (urls leer, provider_type ungültig, fehlerhaftes Domain-Format) |
| 4002 | Domain hat die ICP-Registrierung nicht abgeschlossen (wird bei Ablehnung durch Aliyun/Tencent-API gemappt) |
| 4003 | CDN-Anbieter-Anmeldedaten nicht konfiguriert (Konto fehlt/deaktiviert, strikte Snapshot ohne stilles Umschalten) |
| 4005 | CDN-Cache-Purge fehlgeschlagen |
| 5001 | CDN-Anbieter-API-Aufruf fehlgeschlagen |

> CDN-Ressourcen, die nicht dem Benutzer gehören (fremde oder nicht vorhandene Ressourcen), geben einheitlich **404** zurück (findOrFail-Mapping, ohne die Existenz der Ressource preiszugeben); kein separater Geschäftscode.

---

## 14. Verbrauchsabhängige Abrechnung

```
GET /admin/api/billing/rates          → Tarife (nach Ressourcentyp/Spezifikation)
POST /admin/api/billing/rates         → Tarif erstellen
PUT /admin/api/billing/rates/{id}     → Tarif aktualisieren
DELETE /admin/api/billing/rates/{id}  → Tarif löschen
GET /admin/api/billing/usage          → Nutzungszusammenfassung (nach Benutzer/Ressource aggregiert)
```

Abrechnungspipeline: ResourceMonitor sammelt alle 5 Minuten → UsageAggregator aggregiert stündlich → BillingEngine bucht täglich ab; bei unzureichendem Guthaben werden Ressourcen ausgesetzt.

---

## 15. Affiliate-Provisionen

### Benutzerseite

```
GET /api/affiliate/summary
  → Provisionsübersicht (kumuliert/ausstehend/auszahlbar, Link-Anzahl, Konversionsrate)

POST /api/affiliate/links
  Body: { source? }
  → Erzeugt Werbelink (?ref=CODE)

GET /api/affiliate/earnings
  Parameter: status, page
  → Provisionsdetails (Bestellzuordnung, Anteil, Status: pending/approved/paid)

POST /api/affiliate/payout
  Body: { amount, method }
  → Auszahlungsantrag stellen
```

### Adminseite

```
GET /admin/api/affiliate/plans                → Provisionspläne
POST /admin/api/affiliate/plans               → Provisionsplan erstellen
GET /admin/api/affiliate/earnings             → Alle Provisionsdatensätze
POST /admin/api/affiliate/earnings/{id}/approve → Provision prüfen
GET /admin/api/affiliate/payouts              → Auszahlungsanträge
POST /admin/api/affiliate/payouts/{id}/approve → Auszahlung prüfen/auszahlen
```

---

## 16. GraphQL

```
POST /graphql
  → Öffentliche Abfragen (Produkte, Domains, Hilfe usw., nur lesend)
  Einschränkungen: Abfragetiefe 5 Ebenen, Komplexität 100

POST /api/graphql                          🔒 Authentifizierung erforderlich
  → Vollständige Abfragen (inkl. Benutzerdaten)
```

**Sensitive Operationen bleiben REST-only:** Zahlungen, Auszahlungen, Rückerstattungen und KYC-Prüfungen laufen nicht über GraphQL.

---

## 17. Anbieter-Bewertungen und Produktbewertungen

### Öffentlich

```
GET /api/regions
  → Liste verfügbarer Regionen (inkl. Währung/Zeitzone)

GET /api/suppliers/{supplierId}/ratings
  → Anbieter-Bewertungen (vier Dimensionen: Qualität/Support/Liefergeschwindigkeit/Preis-Leistung, nur approved)
```

### Benutzerseite (authentifiziert)

```
POST /api/products/{productId}/reviews
  Body: { rating, content, images? }
  → Produktbewertung abgeben (einmal pro Bestellung, Anzeige nach Prüfung)

POST /api/supplier/ratings
  Body: { supplier_id, quality, support, delivery_speed, value, comment? }
  → Anbieter-Bewertung abgeben (einmal pro Bestellung)

GET /api/supplier/ratings/me
  → Meine Bewertungen
```

### Adminseite

```
GET /admin/api/suppliers/{id}/ratings          → Alle Bewertungen (inkl. pending)
POST /admin/api/suppliers/ratings/{id}/approve → Genehmigen
POST /admin/api/suppliers/ratings/{id}/hide    → Ausblenden
```

---

## 18. Zahlungs-Webhook

```
POST /api/payments/webhook/stripe
  Header: Stripe-Signature: ...
  → Stripe-Callback (Zahlungserfolg/Rückerstattung/Dispute), bei fehlgeschlagener Signaturprüfung 400
```

---

## 19. WebSocket-Events

**Verbindung:** `ws://host:8282` (unter Docker läuft WS über den nginx-Reverse-Proxy, Verbindungsadresse `ws://host/ws/`; 8282 ist nur im Container erreichbar)

Die Authentifizierung erfolgt über die erste Nachricht nach dem Verbindungsaufbau (das Token gelangt nicht in URL/Zugriffslogs): Nach Verbindungsaufbau muss zuerst eine `auth`-Nachricht gesendet werden; ohne Authentifizierung innerhalb von 30 Sekunden wird getrennt; bei fehlgeschlagener Authentifizierung wird `error` zurückgegeben und die Verbindung getrennt.

### Client → Server

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### Server → Client

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### Push-Events

| Event | Daten | Auslöser |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | Zahlung erfolgreich |
| `resource.provisioned` | `{resource_id, type, ip_address}` | Ressourcen-Bereitstellung abgeschlossen |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | Ressource läuft bald ab |
| `ticket.updated` | `{ticket_id, title, status}` | Ticketstatus geändert |
| `notification.new` | `{notification_id, title, body}` | Neue Benachrichtigung |

---

## 20. Fehlercode-Referenz

| code | Beschreibung |
|------|------|
| 400 | Parameterfehler / nicht unterstützte API-Version / nicht unterstützte Client-Plattform |
| 401 | Nicht authentifiziert / Token abgelaufen / ungültiger API Key / Geräte-Fingerprint weicht ab (Device mismatch) |
| 403 | Keine Berechtigung / keine Anbieter-Rolle / WAF-Blockierung / Passwortbestätigung fehlgeschlagen |
| 404 | Ressource nicht vorhanden (firstOrFail/findOrFail-Treffer werden einheitlich auf 404 gemappt) |
| 413 | Anfragekörper über 10MB |
| 414 | URL über 2KB |
| 415 | Content-Type nicht in der Whitelist (nur application/json, multipart/form-data, x-www-form-urlencoded erlaubt) |
| 422 | Parametervalidierung fehlgeschlagen (E-Mail bereits registriert / Lagerbestand unzureichend / auszahlbares Guthaben unzureichend / Antrag bereits eingereicht) |
| 429 | Rate-Limit überschritten |
| 500 | Serverfehler |

### Häufige 422-Meldungen

| Meldung | Endpunkt |
|------|------|
| `Email or phone required` | /api/auth/register |
| `Email already registered` | /api/auth/register |
| `Invalid credentials` | /api/auth/login |
| `Account temporarily locked` | /api/auth/login |
| `You already have a supplier application` | /api/supplier/apply |
| `Insufficient withdrawable balance` | /api/supplier/withdraw |
| `Product already assigned to this supplier` | /api/supplier/products |
| `Invalid or revoked API key` | /api/supplier/external/* |
| `Captcha verification failed` | /api/auth/login, /api/auth/register |
| `Email already verified` | /api/user/resend-verify-email |
| `Password too short` | /api/auth/register |
| `Unknown feature: xxx` | /admin/api/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/orders/{id}/refund |
