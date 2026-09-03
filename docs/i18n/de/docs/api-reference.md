# CloudPlatform API-Dokumentation

## Übersicht

**Base URL:** `https://api.example.com`

**Versionierung:** Die API-Version steht im URL-Pfad, z. B. `/api/v1/auth/login`; nicht unterstützte Versionen liefern `400`.

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
| Öffentlich | Globale Middleware-Kette | `/health`, `/api/v1/*` |
| `/health` (intern) | Global + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/v1/auth` | Global + Encryption | `/api/v1/auth/*` |
| `/api/v1` (Benutzer) | Global + Encryption + Auth | `/api/v1/user/*`, `/api/v1/cart`, `/api/v1/orders` |
| `/api/v1` (sensitiv) | Global + Encryption + Auth + Confirmation | `/api/v1/orders/{id}/pay` |
| `/api/v1/supplier/external` | Version + SupplierApiKey | Externe Anbieter-API |
| `/admin/api/v1` | Global + Encryption + Auth + AdminRole | Admin-API |
| `/admin/api/v1` (sensitiv) | Global + Encryption + Auth + AdminRole + Confirmation | Sensitive Admin-Operationen |

---

## 1. Öffentliche Endpunkte

### Health Check

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### Dienststatus

```
GET /api/v1/status
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
GET /api/v1/products
  Parameter: category_id, region_id, keyword, supplier_id, page (Standard 1), page_size (Standard 20, max. 50)
  → Paginierte Produktliste (inkl. category, skus.regionPrices)

GET /api/v1/products/search
  Parameter: q (Pflicht), page
  → Elasticsearch-Volltextsuche

GET /api/v1/products/{id}
  → Produktdetails (inkl. category, skus, images, reviews)

GET /api/v1/products/{productId}/reviews
  → Bewertungsliste + avg_rating + total + distribution
  Status-Enum: pending(ausstehend)/approved(genehmigt)/rejected(abgelehnt), nur approved wird zurückgegeben
```

### Domains

```
GET /api/v1/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/v1/domain/tlds
  → Liste verfügbarer TLDs (Redis-Cache 1h)
```

### Hilfezentrum

```
GET /api/v1/help
  Parameter: category, page
  Header: Accept-Language (en-US / zh-CN)
  → Paginierte Hilfeartikel

GET /api/v1/help/categories
  → Liste der Artikelkategorien

GET /api/v1/help/{slug}
  → Details eines einzelnen Artikels
```

---

## 2. Authentifizierungs-Endpunkte

### Captcha

```
POST /api/v1/captcha/create
  Header: X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### Registrierung

```
POST /api/v1/auth/register
  Header: X-Encrypted: 1
  Body (verschlüsselt): { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Rate-Limit: 3 req/min
```

- `deviceFingerprint` (optional): Der Geräte-Fingerprint wird bei der Registrierung erfasst und bei Login/Refresh geprüft; ohne Angabe wird keine Fingerprint-Bindung vorgenommen
- email/phone werden vor der Speicherung deterministisch über Encryptable verschlüsselt (ECB, äquivalente Abfragen anhand von Ciphertext); Eindeutigkeitsprüfung und Login-Abfrage erfolgen über den Ciphertext

### Login

```
POST /api/v1/auth/login
  Header: X-Encrypted: 1
  Body (verschlüsselt): { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Rate-Limit: 5 req/min, nach 5 Fehlversuchen Sperre für 15 min
```

- `login` wird als äquivalente Abfrage über den Ciphertext durchgeführt (deterministische Verschlüsselung via Encryptable); Klartext-Abfragen treffen verschlüsselte Spalten nicht

### Token-Refresh

```
POST /api/v1/auth/refresh
  Header: X-Encrypted: 1
  Body (verschlüsselt): { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` stimmt nicht mit dem bei der Registrierung erfassten überein → 401 `Device mismatch`; das Refresh-Token wird anhand eines Hash des Ciphertexts gesucht

### OAuth

Unterstützte Anbieter: google, apple, facebook, x, microsoft, linkedin, github
(Die Aktivierung richtet sich nach `{PROVIDER}_OAUTH_CLIENT_ID` etc. in der .env)

```
GET /api/v1/auth/{provider}            → { url }        # Weiterleitung zur Autorisierungsseite (PKCE/nonce gegen Replay)
GET /api/v1/auth/{provider}/callback?code=xxx&state=yyy
POST /api/v1/auth/{provider}/callback  Body: { code, state }
```

- Apple/Microsoft liefern ein id_token; der Server prüft Signatur, iss/aud/exp/nonce über JWKS
- Alle Anbieter verlangen `email_verified=true` für den Login, sonst 422
- `state` fehlt oder stimmt nicht überein → 422 (Schutz vor CSRF, Ablauf nach 5 Minuten)
- Rate-Limit für OAuth-Abläufe: 10 Anfragen pro 60 Sekunden (redirect + callback)

### Passwort-Reset

```
POST /api/v1/auth/forgot-password
  Body: { email }
  → Versendet E-Mail mit Bestätigungscode

POST /api/v1/auth/reset-password
  Body: { email, code, password }
  → Zurücksetzen erfolgreich
  → Nach 5 Fehlversuchen → 429, Rate-Limit für 10 Minuten
```

### E-Mail-Verifizierung

```
GET /api/v1/auth/verify-email?token=xxx
  → Verifizierung erfolgreich
```

### SMS-Verifizierung

```
POST /api/v1/auth/send-sms
  Body: { phone }
  → Versendet SMS-Bestätigungscode (60s Abklingzeit)
```

### TOTP-Zwei-Faktor-Verifizierung

```
POST /api/v1/user/totp/setup        → { secret, qr_url }        # Nicht persistiert; muss innerhalb von 10 Minuten per verify aktiviert werden
POST /api/v1/user/totp/verify       Body: { code } → { verified: true }   # Bei Erstaktivierung wird eine Erfolgsmeldung zurückgegeben
POST /api/v1/user/totp/disable      Body: { password }             # Erfordert Passwortbestätigung, sonst 403
GET /api/v1/user/totp/recovery-codes → { recovery_codes }        # Erzeugt jeweils 8 Einmalcodes, Passwortbestätigung erforderlich, sonst 403
POST /api/v1/auth/login/recovery    Body: { login, password, recovery_code }
```

- Nach Aktivierung von TOTP muss der Login `totp_code` enthalten, sonst 401
- Nach 5 aufeinanderfolgenden TOTP-Fehlern wird der Benutzer für 15 Minuten gesperrt (login_lock)

---

## 3. Benutzer-Endpunkte (authentifiziert)

### Profil

```
GET /api/v1/user/profile
PUT /api/v1/user/profile
  Body: { nickname?, avatar?, country?, language?, timezone? }
```

### KYC-Verifizierung

```
POST /api/v1/user/kyc
  Body: { id_type, id_number, real_name, front_image, back_image }
```

### Guthaben

```
GET /api/v1/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/v1/user/balance/transactions
  Parameter: page
  → Guthabenänderungen
```

### Adressverwaltung

```
GET /api/v1/user/addresses
POST /api/v1/user/addresses
  Body: { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/v1/user/addresses/{id}
DELETE /api/v1/user/addresses/{id}
```

### Sitzungsverwaltung

```
GET /api/v1/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/v1/user/sessions/{id}
  → Widerruft die angegebene Sitzung

DELETE /api/v1/user/account
  Body: { confirm_password }
  → Konto-Löschung nach GDPR
```

### Benachrichtigungen

```
GET /api/v1/user/notifications
  Parameter: page
  → Paginierte Benachrichtigungsliste

POST /api/v1/user/notifications/{id}/read
  → Als gelesen markieren

GET /api/v1/user/notification-prefs
PUT /api/v1/user/notification-prefs
  Body: { email: {order_paid: true, ...}, push: {...} }
```

### E-Mail

```
POST /api/v1/user/resend-verify-email
  → Verifizierungs-E-Mail erneut senden
```

### Datei-Upload

```
POST /api/v1/upload
  Body: multipart/form-data { file, type: avatar/kyc/attach }
  Beschränkungen: avatar 2MB, kyc 5MB, attach 10MB
  Zulässig: jpg, jpeg, png, gif, pdf
  Hinweis: Typ-Whitelist-Prüfung + finfo-Inhaltserkennung (Extension und MIME weichen ab → 422)
```

---

## 4. Warenkorb und Bestellungen

### Warenkorb

```
POST /api/v1/cart
  Body: { sku_id, region_id, quantity, cycle }
GET /api/v1/cart
DELETE /api/v1/cart/{id}
PUT /api/v1/cart/{id}
  Body: { quantity }
```

> Konvention für Betragsfelder (beschlossen in D4/P4.2): Alle Beträge sind Strings mit 4 Nachkommastellen (z. B. "9.9900"), number/float ist verboten —
> konsistent mit der Rohausgabe von MySQL-DECIMAL-Spalten über PDO; die Präzision liegt im 4dp-String selbst. Gilt für alle Endpunkte mit Bestellungen/Guthaben/Berichten.

### Bestellungen

```
POST /api/v1/orders
  → Erstellt Bestellung aus dem Warenkorb
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total: string 4dp

GET /api/v1/orders
  Parameter: page, status (pending/paid/provisioning/completed/refunded, ungültige Werte liefern 400)
  → Meine Bestellungen

GET /api/v1/orders/{id}
  → Bestelldetails (inkl. items, timeline)

GET /api/v1/orders/{id}/payment-methods
  → Verfügbare Zahlungskanäle + tatsächlich zu zahlender Betrag je Kanal

POST /api/v1/orders/{id}/pay    🔒 Passwortbestätigung
  Body: { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### Gutscheine

```
POST /api/v1/coupons/validate
  Body: { code, order_total }
  → { coupon_id, discount, type }   # discount: string 4dp (z. B. "2.0000")

422: ungültig/abgelaufen/Nutzungsbedingungen nicht erfüllt
```

### Rechnungen

```
GET /api/v1/invoices
  Parameter: page
GET /api/v1/invoices/{id}
GET /api/v1/invoices/{id}/download
  → PDF-Download
```

---

## 5. Ressourcenverwaltung

```
GET /api/v1/resources
  Parameter: page, status
  → Meine Ressourcen

GET /api/v1/resources/{id}
  → Ressourcendetails

GET /api/v1/resources/{id}/status
  → Aktueller Ressourcenstatus + Metriken

GET /api/v1/resources/{id}/console
  → VNC/Konsolen-URL

POST /api/v1/resources/batch
  Body: { action: start/stop/restart, resource_ids: [...] }
```

---

## 6. DNS-Verwaltung

```
GET /api/v1/dns/{domain}
  → Liste der DNS-Einträge

POST /api/v1/dns/{domain}/records
  Body: { type, name, value, ttl?, priority? }

DELETE /api/v1/dns/{domain}/records/{id}   🔒 Passwortbestätigung
```

---

## 7. Tickets

```
POST /api/v1/tickets
  Body: { resource_id?, category, priority?, title, content }

GET /api/v1/tickets
  Parameter: page, status

GET /api/v1/tickets/{id}

POST /api/v1/tickets/{id}/reply
  Body: { content }
```

---

## 8. Anbieter (interne API)

```
POST /api/v1/supplier/apply
  Body: { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/v1/supplier/settlements
  → Liste der Abrechnungen

POST /api/v1/supplier/withdraw    🔒 Passwortbestätigung
  Body: { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/v1/supplier/products
POST /api/v1/supplier/products
  Body: { product_id, commission_rate }
DELETE /api/v1/supplier/products/{id}
```

---

## 9. Externe Anbieter-API

**Authentifizierung:** `Authorization: Bearer sk_xxx...` (Signaturprüfung per SHA256)

**Rate-Limit:** 120 req/min (Auszahlung 10 req/min)

```
GET /api/v1/supplier/external/orders
  Parameter: page, page_size, status, from, to

GET /api/v1/supplier/external/orders/{id}
  → Bestelldetails (nur Bestellungen dieses Anbieters)

GET /api/v1/supplier/external/resources
  Parameter: page, status, type

GET /api/v1/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/v1/supplier/external/settlements
  Parameter: page, status

GET /api/v1/supplier/external/settlements/{id}

POST /api/v1/supplier/external/withdraw
  Body: { amount, account_info: { method, ... } }

GET /api/v1/supplier/external/withdraws
  Parameter: page
```

---

## 10. Admin-API

**Authentifizierung:** JWT Bearer Token + Admin-Rolle

### Dashboard

```
GET /admin/api/v1/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### Benutzerverwaltung

```
GET /admin/api/v1/users              Parameter: page, status, keyword
GET /admin/api/v1/users/export       → Excel-Download
GET /admin/api/v1/users/{id}
PUT /admin/api/v1/users/{id}/status  Body: { status }
```

### KYC-Prüfung

```
GET /admin/api/v1/kyc                Parameter: page, status

POST /admin/api/v1/kyc/{id}/approve  🔒 Passwortbestätigung
  Body: { confirm_password }

POST /admin/api/v1/kyc/{id}/reject   🔒 Passwortbestätigung
  Body: { confirm_password, reason }
```

### Produktverwaltung

```
POST /admin/api/v1/products
PUT /admin/api/v1/products/{id}
DELETE /admin/api/v1/products/{id}         🔒 Passwortbestätigung
POST /admin/api/v1/products/{productId}/skus
PUT /admin/api/v1/skus/{id}
POST /admin/api/v1/skus/{skuId}/region-price
GET /admin/api/v1/products/export         → CSV-Download
POST /admin/api/v1/products/import        → CSV-Upload (upsert)
```

### Bestellverwaltung

```
GET /admin/api/v1/orders              Parameter: page, status, keyword
GET /admin/api/v1/orders/export       → Excel-Download
GET /admin/api/v1/orders/{id}

POST /admin/api/v1/orders/{id}/refund 🔒 Passwortbestätigung
  Body: { confirm_password, amount?, reason }
```

### Zahlungsverwaltung

```
GET /admin/api/v1/payments/channels
PUT /admin/api/v1/payments/channels/{id}
GET /admin/api/v1/payments/transactions  Parameter: page, channel, status
GET /admin/api/v1/payments/reconcile     Parameter: date; records.status: verified/mismatch/unverified
POST /admin/api/v1/payments/reconcile/run  Parameter: date; löst täglichen Abgleich aus
```

### Ressourcen und Bereitstellung

```
GET /admin/api/v1/provisioning/tasks              Parameter: page, status
POST /admin/api/v1/provisioning/tasks/{id}/retry
POST /admin/api/v1/provisioning/resources/{id}/upgrade
  Body: { cpu?, ram?, disk? }
POST /admin/api/v1/provisioning/resources/{id}/destroy   🔒 Passwortbestätigung
GET /admin/api/v1/provisioning/hosts
```

### Anbieterverwaltung

```
GET /admin/api/v1/suppliers                 Parameter: page, status
GET /admin/api/v1/suppliers/export          → Excel-Download

POST /admin/api/v1/suppliers/{id}/approve   🔒 Passwortbestätigung
POST /admin/api/v1/suppliers/{id}/settle    🔒 Passwortbestätigung
  Body: { period_start, period_end, confirm_password }

POST /admin/api/v1/suppliers/withdraws/{id}/approve  🔒 Passwortbestätigung
```

### Anbieter-API-Keys

```
GET /admin/api/v1/suppliers/{id}/api-keys
POST /admin/api/v1/suppliers/{id}/api-keys
  Body: { name }
  ← { api_key: "sk_xxx...", prefix } (wird nur einmal angezeigt)

DELETE /admin/api/v1/suppliers/api-keys/{id}
```

### Ticketverwaltung

```
GET /admin/api/v1/tickets                  Parameter: page, status, priority, assigned_to
POST /admin/api/v1/tickets/{id}/assign     Body: { user_id }
POST /admin/api/v1/tickets/{id}/close
```

### Domainverwaltung

```
GET /admin/api/v1/domains/tlds
POST /admin/api/v1/domains/tlds
  Body: { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/v1/domains/tlds/{id}
DELETE /admin/api/v1/domains/tlds/{id}
GET /admin/api/v1/domains/zones             Parameter: page
GET /admin/api/v1/domains/transfers         Parameter: page
POST /admin/api/v1/domains/transfers/{id}/approve
```

### Benachrichtigungsverwaltung

```
GET /admin/api/v1/notifications/templates
PUT /admin/api/v1/notifications/templates/{id}
  Body: { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/v1/notifications/log         Parameter: page
```

### Gutscheine

```
GET /admin/api/v1/coupons
POST /admin/api/v1/coupons
  Body: { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/v1/coupons/{id}
```

### Hilfeartikel

```
GET /admin/api/v1/help
POST /admin/api/v1/help
  Body: { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/v1/help/{id}
DELETE /admin/api/v1/help/{id}              → Soft-Delete (status=archived)
```

### Cloud-Anbieter-API

```
GET /admin/api/v1/providers
POST /admin/api/v1/providers
  Body: { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/v1/providers/{id}
DELETE /admin/api/v1/providers/{id}         → Deaktivieren (status=disabled)
```

### Webhook-Verwaltung

```
GET /admin/api/v1/webhooks
POST /admin/api/v1/webhooks
  Body: { url }
DELETE /admin/api/v1/webhooks              Body: { id }
POST /admin/api/v1/webhooks/test           Body: { url }
```

### Berichte

```
GET /admin/api/v1/reports/revenue           Parameter: from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue: string 4dp (konsistent mit SUM(DECIMAL) und bcmath-Summen)
GET /admin/api/v1/reports/supplier          Parameter: from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid: string 4dp
GET /admin/api/v1/reports/region            Parameter: from, to
  → [{region, orders, revenue}]                  # revenue: string 4dp
```

### Monitoring

```
GET /admin/api/v1/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/v1/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### Audit-Logs

```
GET /admin/api/v1/audit-logs                Parameter: page, user_id, action, from, to
  → Paginierte Audit-Logs (inkl. client_platform)
```

### Feature Flags

```
GET /admin/api/v1/features
  → [{ name, enabled, default, source }]

PUT /admin/api/v1/features/{name}
  Body: { action: enable/disable/toggle/reset }
```

### Systemkonfiguration

```
PUT /admin/api/v1/system/config             🔒 Passwortbestätigung
```

### Produkt-Import/-Export

```
GET /admin/api/v1/products/export           → CSV-Download
POST /admin/api/v1/products/import          → CSV-Upload (upsert)
```

### Anbieter- und Benutzer-Export

```
GET /admin/api/v1/suppliers/export          → Excel-Download
GET /admin/api/v1/users/export              → Excel-Download
GET /admin/api/v1/orders/export             → Excel-Download
```

---

## 11. SSL-Zertifikate

### Benutzerseite

```
GET /api/v1/ssl/plans
  → Liste der SSL-Pakete (DV/OV/EV, Preis inkl. register/renew/transfer)

GET /api/v1/ssl-certs
  → Meine Zertifikate (inkl. status: pending/active/expired/revoked)

GET /api/v1/ssl-certs/{id}
  → Zertifikatsdetails (Domain, Ausstellungsstelle, Gültigkeit, Verlängerungsstatus)

GET /api/v1/ssl-certs/{id}/download
  → Zertifikatsdateien herunterladen (Zertifikatskette + privater Schlüssel)

POST /api/v1/ssl-certs/{id}/auto-renew
  Body: { auto_renew: true/false }
  → Automatische Verlängerung umschalten
```

### Adminseite

```
GET /admin/api/v1/ssl/plans              → Paketliste
POST /admin/api/v1/ssl/plans             → Paket erstellen
PUT /admin/api/v1/ssl/plans/{id}         → Paket aktualisieren
DELETE /admin/api/v1/ssl/plans/{id}      → Paket löschen
GET /admin/api/v1/ssl/certs              → Alle Zertifikate
POST /admin/api/v1/ssl/certs/{id}/revoke → Zertifikat widerrufen
```

---

## 12. Objekt-Speicher

S3-kompatibler Objekt-Speicher; Upload/Download über vorsignierte URLs, Schlüssel werden nie übertragen.

```
GET /api/v1/storage/buckets
  → Meine Speicher-Buckets (Nutzung, Status)

GET /api/v1/storage/buckets/{id}
  → Bucket-Details

POST /api/v1/storage/buckets/{id}/presign-upload
  Body: { filename, content_type, size }
  → { upload_url, object_key } Vorsignierte Upload-URL (zeitlich begrenzt)

POST /api/v1/storage/buckets/{id}/presign-download
  Body: { object_key }
  → Vorsignierte Download-URL (zeitlich begrenzt)

GET /api/v1/storage/buckets/{id}/credentials
  → Temporäre Zugangsdaten (kurzlebig, für Direkt-Upload per SDK)
```

---

## 13. CDN-Beschleunigung

### Benutzerseite

```
GET /api/v1/cdn/domains
  → Meine CDN-Domains (Origin, Status, Paket)

POST /api/v1/cdn/domains
  Body: { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
          origin_type (server|storage), origin_value, cert_config? }
  → CDN-Domain erstellen (wird anbieterseitig angelegt und an den Origin gebunden)
  → Bei provider_type=aliyun|tencent muss die Domain eine ICP-Registrierung abgeschlossen haben (sonst 4002)
  → Antwort enthält das Hinweisfeld requires_icp_registration
  → Anmeldedaten-Auflösung: zuerst gebundenes Konto der Domain (provider_account_id), sonst aktives
    provider_apis-Konto mit code=cdn-{provider_type}, sonst Fallback auf env-Konfiguration

GET /api/v1/cdn/domains/{id}
  → CDN-Domain-Details

DELETE /api/v1/cdn/domains/{id}
  → CDN-Domain löschen (anbieterseitige Domain deaktivieren, idempotent)

POST /api/v1/cdn/domains/{id}/purge
  Body: { urls: ["https://cdn.example.com/path"] }
  → Cache leeren (doppelte URLs werden automatisch dedupliziert, idempotent; max. 100)

GET /api/v1/cdn/domains/{id}/stats
  → Domain-Überblick (cdn_domain / provider_type / plan / status / purged_at)
```

### Adminseite

```
GET /admin/api/v1/cdn/domains            → Alle CDN-Domains (inkl. zugehörigem Benutzer)
PUT /admin/api/v1/cdn/domains/{id}       → Domain-Paket aktualisieren (plan-Whitelist: standard | pro | enterprise)
```

Die Admin-CDN-Routen hängen an `RbacMiddleware('cdn.manage')`; Paketänderungen werden im Audit-Log festgehalten (`admin_cdn_update_plan`). Anbieter-Anmeldedaten werden per CRUD über `/admin/api/v1/providers` gepflegt (RbacMiddleware `provider.config`, `code`-Konvention `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, Anmeldedaten mit Encryptable verschlüsselt gespeichert).

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
GET /admin/api/v1/billing/rates          → Tarife (nach Ressourcentyp/Spezifikation)
POST /admin/api/v1/billing/rates         → Tarif erstellen
PUT /admin/api/v1/billing/rates/{id}     → Tarif aktualisieren
DELETE /admin/api/v1/billing/rates/{id}  → Tarif löschen
GET /admin/api/v1/billing/usage          → Nutzungszusammenfassung (nach Benutzer/Ressource aggregiert)
```

Abrechnungspipeline: ResourceMonitor sammelt alle 5 Minuten → UsageAggregator aggregiert stündlich → BillingEngine bucht täglich ab; bei unzureichendem Guthaben werden Ressourcen ausgesetzt.

---

## 15. Affiliate-Provisionen

### Benutzerseite

```
GET /api/v1/affiliate/summary
  → Provisionsübersicht (kumuliert/ausstehend/auszahlbar, Link-Anzahl, Konversionsrate)

POST /api/v1/affiliate/links
  Body: { source? }
  → Erzeugt Werbelink (?ref=CODE)

GET /api/v1/affiliate/earnings
  Parameter: status, page
  → Provisionsdetails (Bestellzuordnung, Anteil, Status: pending/approved/paid)

POST /api/v1/affiliate/payout
  Body: { amount, method }
  → Auszahlungsantrag stellen
```

### Adminseite

```
GET /admin/api/v1/affiliate/plans                → Provisionspläne
POST /admin/api/v1/affiliate/plans               → Provisionsplan erstellen
GET /admin/api/v1/affiliate/earnings             → Alle Provisionsdatensätze
POST /admin/api/v1/affiliate/earnings/{id}/approve → Provision prüfen
GET /admin/api/v1/affiliate/payouts              → Auszahlungsanträge
POST /admin/api/v1/affiliate/payouts/{id}/approve → Auszahlung prüfen/auszahlen
```

---

## 16. GraphQL

```
POST /graphql
  → Öffentliche Abfragen (Produkte, Domains, Hilfe usw., nur lesend)
  Einschränkungen: Abfragetiefe 5 Ebenen, Komplexität 100

POST /api/v1/graphql                          🔒 Authentifizierung erforderlich
  → Vollständige Abfragen (inkl. Benutzerdaten)
```

**Sensitive Operationen bleiben REST-only:** Zahlungen, Auszahlungen, Rückerstattungen und KYC-Prüfungen laufen nicht über GraphQL.

---

## 17. Anbieter-Bewertungen und Produktbewertungen

### Öffentlich

```
GET /api/v1/regions
  → Liste verfügbarer Regionen (inkl. Währung/Zeitzone)

GET /api/v1/suppliers/{supplierId}/ratings
  → Anbieter-Bewertungen (vier Dimensionen: Qualität/Support/Liefergeschwindigkeit/Preis-Leistung, nur approved)
```

### Benutzerseite (authentifiziert)

```
POST /api/v1/products/{productId}/reviews
  Body: { rating, content, images? }
  → Produktbewertung abgeben (einmal pro Bestellung, Anzeige nach Prüfung)

POST /api/v1/supplier/ratings
  Body: { supplier_id, quality, support, delivery_speed, value, comment? }
  → Anbieter-Bewertung abgeben (einmal pro Bestellung)

GET /api/v1/supplier/ratings/me
  → Meine Bewertungen
```

### Adminseite

```
GET /admin/api/v1/suppliers/{id}/ratings          → Alle Bewertungen (inkl. pending)
POST /admin/api/v1/suppliers/ratings/{id}/approve → Genehmigen
POST /admin/api/v1/suppliers/ratings/{id}/hide    → Ausblenden
```

---

## 18. Zahlungs-Webhook

```
POST /api/v1/payments/webhook/stripe
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
| `Email or phone required` | /api/v1/auth/register |
| `Email already registered` | /api/v1/auth/register |
| `Invalid credentials` | /api/v1/auth/login |
| `Account temporarily locked` | /api/v1/auth/login |
| `You already have a supplier application` | /api/v1/supplier/apply |
| `Insufficient withdrawable balance` | /api/v1/supplier/withdraw |
| `Product already assigned to this supplier` | /api/v1/supplier/products |
| `Invalid or revoked API key` | /api/v1/supplier/external/* |
| `Captcha verification failed` | /api/v1/auth/login, /api/v1/auth/register |
| `Email already verified` | /api/v1/user/resend-verify-email |
| `Password too short` | /api/v1/auth/register |
| `Unknown feature: xxx` | /admin/api/v1/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/v1/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/v1/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/v1/orders/{id}/refund |
