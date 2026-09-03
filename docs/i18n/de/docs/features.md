# CloudPlatform-Funktionsdesign-Dokument

## 1. Benutzerauthentifizierung und -autorisierung

### 1.1 Registrierung

```
POST /api/v1/auth/register
  → WAF-Scan
  → Rate-Limit 3 req/min
  → Passwortprüfung len≥8
  → E-Mail/Handynummer-Eindeutigkeitsprüfung
  → bcrypt(password, cost=12)
  → Snowflake::id() erzeugt user_id
  → Encryptable::set() verschlüsselt sensible Felder
  → User + UserProfile + UserBalance erstellen
  → NotificationDispatcher::send('email_verify') Verifikations-E-Mail senden
  → AuditLogger::record('user_registered')
  ← {access_token, refresh_token, expires_in, token_type}
```

**Datenfluss:**

```
Client                    Middleware Chain           AuthService              DB
  │                           │                        │                     │
  │ POST /api/v1/auth/register   │                        │                     │
  │──────────────────────────▶│ WAF→RateLimit→Encrypt  │                     │
  │                           │───────────────────────▶│                     │
  │                           │                        │ User::create() ────▶│
  │                           │                        │ UserProfile::create │
  │                           │                        │ UserBalance::create │
  │                           │                        │ RefreshToken::create│
  │                           │                        │ (client_platform)   │
  │                           │                        │ AuditLogger::record │
  │◀──────────────────────────│◀───────────────────────│                     │
  │ {access_token, refresh}   │                        │                     │
```

### 1.2 Login

```
POST /api/v1/auth/login
  → WAF-Scan
  → Rate-Limit 5 req/min
  → Captcha-Prüfung (Klick-CAPTCHA, 3-Versuche-Limit)
  → Hash::check(password, user->password_hash)
  → 5 Fehlversuche → login_lock:{userId} Redis TTL 900s
  → TOTP-Prüfung (bei aktiviertem TOTP des Benutzers erzwungen, totp_code Pflicht;
      5 Fehlversuche → totp_fail:{userId} → login_lock TTL 900s)
  → Neue-IP-Erkennung → E-Mail-Alarm
  → deviceFingerprint = sha256(UA + IP-Segment, IPv6 mit Präfix)
  → clientPlatform = X-Client-Platform-Header
  → issueTokens(): Access(15min) + Refresh(30d)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth (Google / Apple)

```
GET /api/v1/auth/google → Google OAuth → callback?code=xxx
  1. Google/Apple-ID-Token validieren
  2. Benutzer suchen oder erstellen (E-Mail-Abgleich)
  3. Token ausstellen (inkl. client_platform)
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 TOTP-Zwei-Faktor

```
1. POST /api/v1/user/totp/setup
     → Secret + QR-URL erzeugen (Redis 10 Minuten zwischengespeichert, nicht persistiert)
     ← {secret, qr_url, manual}
2. POST /api/v1/user/totp/verify
     → TOTP-Code prüfen (erstes Mal = Aktivierung, danach Validierung)
     ← {verified: true}
3. GET /api/v1/user/totp/recovery-codes
     → 8 Einmal-Wiederherstellungscodes erzeugen (Passwortbestätigung nötig)
     ← {recovery_codes: [8 Stück]}
4. Beim Login: TOTP-Code oder Wiederherstellungscode eingeben
     → POST /api/v1/auth/login/recovery (login, password, recovery_code)
```

### 1.5 Sitzungsverwaltung

```
GET /api/v1/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/v1/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/v1/user/account (GDPR-Löschung)
  → Passwort-Zweitbestätigung
  → Soft-Delete User
  → alle RefreshTokens revoked
```

---

## 2. Produktverwaltung

### 2.1 Produktmodell

```
Product (1) ────── (N) ProductSku ────── (N) ProductRegion
  │                    │                      │
  │ category_id        │ specs (JSON)         │ region_id
  │ supplier_id        │ cycle (monthly/yr)   │ price
  │ status             │                      │ original_price
  │ name (多语言JSON)   │                      │ currency
  │ description        │                      │ stock
  └────────────────────┴──────────────────────┘

Product (1) ────── (N) ProductImage
Product (1) ────── (N) ProductReview
Product (1) ────── (N) ProductAttribute
```

### 2.2 Produktliste (mit Cache)

```
GET /api/v1/products?category_id=1&region_id=2&keyword=vps&page=1

CacheService::remember('products:list:{hash}', TTL 5min)
  → Product::published()
    → with(category, skus.regionPrices)
    → nach category_id/region_id/keyword/supplier_id filtern
    → count + skip/take Paging
  ← Pagination-Ergebnis

Cache-Invalidierung:
  Admin product/SKU/region-price-Änderung
  → CacheService::forget("products:detail:{$id}")
  → CacheService::forgetPattern('products:list:*')
```

### 2.3 Produktsuche (Elasticsearch)

```
GET /api/v1/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (IK-Analyzer chinesische Tokenisierung)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 Produktbewertungen

```
GET /api/v1/products/{id}/reviews
  → Geprüfte Bewertungen + Durchschnitt + Verteilung
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/v1/products/{id}/reviews (Login nötig)
  → rating (1-5) + content
  → status = pending (nach Admin-Prüfung sichtbar)
```

### 2.5 Batch-Import/Export

```
GET /admin/api/v1/products/export
  → CSV-Download (Produkt + SKU + Regionalpreise)

POST /admin/api/v1/products/import
  → CSV-Upload upsert
  ← {imported: N, errors: [...]}
```

---

## 3. Bestellsystem

### 3.1 Warenkorb

```
POST /api/v1/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/v1/cart           → Warenkorbliste (inkl. SKU-Details + Echtzeitpreise)
DELETE /api/v1/cart/{id}   → removeFromCart
PUT /api/v1/cart/{id}      → updateCartQuantity
```

### 3.2 Bestellablauf

```
1. POST /api/v1/orders                            Bestellung erstellen
     → Bestand prüfen, Preis berechnen, Coupon anwenden
     ← {order_id, order_no, items, total}

2. POST /api/v1/coupons/validate                 Coupon anwenden
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/v1/orders/{id}/payment-methods        Verfügbare Zahlungskanäle abrufen
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/v1/orders/{id}/pay                  Zahlung starten
     → Passwort-Zweitbestätigung (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 Bestell-Lebenszyklus

```
                    ┌─────────┐
                    │ pending  │ 待支付
                    └────┬─────┘
                         │ 支付成功
                    ┌────┴─────┐
                    │  paid    │ 已支付
                    └────┬─────┘
                         │ OrderPaid 事件
                         │ → ProvisioningService
                    ┌────┴─────┐
                    │ completed│ 已完成
                    └────┬─────┘
                         │ 用户申请退款
                    ┌────┴─────┐
                    │ refunded │ 已退款
                    └──────────┘

Rückbedingungen: Server innerhalb 72h | Domain innerhalb 5 Tagen | IP nicht erstattbar | Aktion-Waren nicht erstattbar (andere Typen wie disk ohne Fensterlimit; unbekannte Kategorien standardmäßig erlaubt)
Rückzahlungsablauf: Benutzerantrag → Ticket erstellt → Support-Prüfung → admin-Bestätigung → Provider.destroy() → Payment.refund()
```

---

## 4. Zahlungssystem

### 4.1 Multi-Channel-Routing

```
PaymentRouter::route(Order $order)
  → Verfügbare Kanäle filtern (is_visible + visible_regions + min/max_amount)
  → nach currency abgleichen
  → Tatsächlichen Betrag je Kanal berechnen (inkl. Gebühr)
  → nach fee aufsteigend sortieren
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Stripe-Zahlung

```
Client (Flutter)          Server (webman)            Stripe API
─────────────────         ────────────────           ──────────
1. Stripe wählen
   → POST /orders/{id}/pay
                          2. createPaymentIntent()
                              amount (cents)
                              currency
                              metadata{order_id, order_no}
                                                    3. paymentIntents.create
                                                       ← {id, client_secret}
                          4. transaction erstellen
                             (status=pending)
                           ← client_secret
5. confirmCardPayment()
   (Stripe.js SDK)
                                                    6. Benutzer bestätigt Zahlung
                                                       ← payment_intent.succeeded
                          7. POST /payments/webhook/stripe
                             Webhook::constructEvent()
                             Signaturprüfung stripe-signature
                             Idempotenzprüfung transaction_no
                          8. transaction=success
                          9. OrderPaid-Event auslösen
                             → ProvisioningService
                             → WebSocket-Push
                             → E-Mail/SMS/Push-Benachrichtigung
```

### 4.3 Abgleich

```
Cron: PaymentReconcile (täglich 02:37)
  → Abrechnungsberichte der Kanäle abrufen
  → Einzeln mit System-transactions abgleichen
  → Differenz > $0.01 → Alarm
```

---

## 5. Ressourcen-Bereitstellungs-Engine

### 5.1 Provider-Plug-in-Architektur

```php
interface ProviderInterface {
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}

ProviderFactory:
  (productType, provider) → Provider-Instanz
  'server:proxmox'     → ProxmoxProvider
  'server:aws_ec2'     → AwsProvider (erweiterbar)
  'server:aliyun_ecs'  → AliyunProvider (erweiterbar)
  'domain:namecheap'   → DomainProvider (erweiterbar)
```

### 5.2 Kompletter Bereitstellungspfad

```
OrderPaid-Event ausgelöst
  │
  ▼
ProvisioningService::handleOrderPaid()
  │
  ├→ Für jedes OrderItem eine ProvisionTask erstellen
  │   status=pending, next_retry_at=now
  │
  ▼
ProvisionWorker (Redis-Queue-Consumer)
  │
  ├→ ProviderFactory::create(task)
  │     └→ ProxmoxProvider
  │
  ├→ HostSelector::select(regionId, specs)
  │     nach cpu/ram/disk-Rest + Lastverteilung sortieren
  │
  ├→ IpPool::allocate(hostId)
  │
  ├→ ProxmoxApi::post('/nodes/{node}/qemu')
  │     VM erstellen (vmid, name, cores, memory, net, ipconfig)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/config')
  │     Systemdisk montieren (scsi0: pool:sizeG)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/status/start')
  │     VM starten
  │
  ├→ Resource + Disk + IpAllocation-Einträge erstellen
  │
  ├→ Zugewiesene Ressourcenmenge von host_machine aktualisieren
  │
  └→ Order::status = completed
       → WebSocket-Push 'resource.provisioned'
       → NotificationDispatcher::send('resource_ready')

Wiederholungsstrategie:
  1min → 5min → 15min → 1h → 6h → 24h (nach 6 Versuchen als fehlgeschlagen markieren + Alarm)
```

> **Bereitstellungskanal-Evolution**: Rust kvm-server (`infrastructure/kvm-server`, e-cat workspace) ist eingecheckt —
> gRPC `ping/create_vm/vm_status` (:50051) + etcd Service-Discovery, PHP-seitig KvmClient /
> RegistryProcess (`service/app/grpc/`) sind angeschlossen. Die Treiberschicht ist aktuell ein **Mock-Treiber** (echter libvirt-
> Treiber ist Phase 2), der Bereitstellungspfad läuft vorerst weiter über direkte ProxmoxProvider-Verbindung; nach Übernahme der VM-Erstellung durch kvm-server bleibt dieser Ablauf unverändert, nur der Kanal wechselt.

### 5.3 Proxmox-Operationen

| Operation | API | Heiß-Operation |
|------|-----|--------|
| VM erstellen | POST /nodes/{node}/qemu | — |
| CPU upgraden | PUT /qemu/{vmid}/config cores | Online |
| RAM upgraden | PUT /qemu/{vmid}/config memory | Online |
| Systemdisk erweitern | PUT /qemu/{vmid}/resize disk | Online |
| Datendisk erstellen | POST /qemu/{vmid}/config scsi{n} | Online |
| Separate IP erstellen | POST /qemu/{vmid}/config net{n} | Online |
| VM vernichten | POST stop → DELETE qemu | — |
| Status abfragen | GET /qemu/{vmid}/status/current | — |

---

## 6. Supplier-System

### 6.1 Onboarding-Ablauf

```
POST /api/v1/supplier/apply (Benutzer-Login nötig)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → Admin-Prüfung

Admin-Genehmigung:
  POST /admin/api/v1/suppliers/{id}/approve (Passwortbestätigung)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → Benutzer erhält Supplier-Berechtigungen

Produkt einstellen:
  POST /api/v1/supplier/products
    → {product_id, commission_rate}
    → Supplier-Produkt verknüpfen

Abrechnung:
  Cron: SupplierSettlement (jeden Montag 04:17)
    → Abgeschlossene Orders im Zeitraum summieren
    → total_sales - commission = payable
    → SupplierSettlement erstellen

Auszahlung:
  POST /api/v1/supplier/withdraw (Passwortbestätigung)
    → Auszahlbares Guthaben prüfen
    → SupplierWithdraw erstellen (status=pending)
    → Admin-Genehmigung und Überweisung
```

### 6.2 Externe API

```
POST /admin/api/v1/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → hash('sha256', rawKey) speichern
  ← {api_key: "sk_xxx..."} (nur einmal angezeigt)

Supplier-Nutzung:
  GET /api/v1/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → SupplierApiKeyMiddleware-Signaturprüfung
    → Daten nach supplierId filtern
```

---

## 7. Domain und DNS

```
GET /api/v1/domain/check/{domain}/{tld}    # Domain-Verfügbarkeit
GET /api/v1/domain/tlds                     # Registrierbare TLD-Liste (Cache 1h)
GET /api/v1/dns/{domain}                    # DNS-Eintragsliste
POST /api/v1/dns/{domain}/records           # DNS-Eintrag hinzufügen
DELETE /api/v1/dns/{domain}/records/{id}    # DNS-Eintrag löschen (Passwortbestätigung)
```

---

## 8. Ticket-System

```
POST /api/v1/tickets                    # Ticket erstellen
GET /api/v1/tickets                     # Meine Tickets
GET /api/v1/tickets/{id}                # Ticket-Details
POST /api/v1/tickets/{id}/reply         # Ticket beantworten

Administrator:
  GET /admin/api/v1/tickets              # Ticket-Warteschlange
  POST /admin/api/v1/tickets/{id}/assign # Support-Mitarbeiter zuweisen
  POST /admin/api/v1/tickets/{id}/close  # Ticket schließen

Ereignisgesteuert:
  TicketCreated-Event
    → AutoAssignListener: dem am wenigsten ausgelasteten Support zuweisen
    → WebSocket-Push 'ticket.created'
```

---

## 9. Benachrichtigungssystem

### 9.1 Vier-Kanal-Verteilung

```
Event ausgelöst → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → Direkt in notifications-Tabelle schreiben
```

### 9.2 Benachrichtigungstypen

| Event | Kanal | Auslösezeitpunkt |
|------|------|---------|
| Registrierungsverifikation | email | Nach E-Mail-Registrierung |
| Login-Anomalie-Alarm | email | Login von neuer IP |
| Bestellung bezahlt | email/push | Zahlung abgeschlossen |
| Ressource bereitgestellt | email/push/in_app | Provisioning abgeschlossen |
| Ressourcen-Ablauferinnerung | email/push | 7d/3d/1d vorher |
| Ticket-Antwort | email/push/in_app | Neue Ticket-Nachricht |
| Rückerstattung abgeschlossen | email/push | Rückerstattung bearbeitet |
| SSL-Zertifikat läuft ab | email | 30d vorher |
| Domain läuft ab | email | 30d vorher |

---

## 10. Monitoring und Alarmierung

### 10.1 Ressourcen-Monitoring

```
Cron: CollectMetrics (alle 5 Minuten)
  → Aktive Ressourcen pollen
  → ProxmoxApi::status() / Provider API
  → Metriken in Redis-Hash speichern (TTL 1h)

Administrator:
  GET /admin/api/v1/monitor/dashboard
    → Übersichtsstatistiken + letzte Alarme
  GET /admin/api/v1/monitor/resources/{id}
    → Echtzeit-Metriken (aus Redis lesen)
```

### 10.2 Alarmregeln

| Regel | Schweregrad | Auslösebedingung |
|------|--------|---------|
| server_down | Schwer | 3-maliges Ping-Timeout in Folge |
| cpu_high | Warnung | CPU > 90 % über 10min |
| disk_high | Warnung | Disk > 90 % über 5min |
| ssl_expiring | Warnung | SSL-Zertifikat läuft in < 30 Tagen ab |
| domain_expiring | Warnung | Domain läuft in < 30 Tagen ab |
| provision_failed | Schwer | Bereitstellungsaufgabe mehrfach fehlgeschlagen |

---

## 11. Geplante Tasks

| Cron-Ausdruck | Task | Zweck |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | Wechselkurse alle 4 Stunden synchronisieren |
| `37 2 * * *` | PaymentReconcile | Täglicher Abgleich |
| `17 4 * * 1` | SupplierSettlement | Montags Supplier abrechnen |
| `23 6 * * *` | ExpirationCheck | Ablaufprüfung + Benachrichtigung |
| `43 7 * * *` | SslCertificateCheck | SSL-Zertifikatsprüfung |
| `*/5 * * * *` | CollectMetrics | Ressourcen-Metrik-Erfassung |
| `*/30 * * * *` | CheckExpirations | Ressourcen-Ablaufprüfung |

---

## 12. Internationalisierung (i18n)

### 12.1 Request-Fluss

```
Client → Accept-Language: zh-CN
  → LocaleMiddleware (globale Middleware)
    → I18n::setLocale('zh-CN')
    → i18n/zh-CN/messages.php laden
```

### 12.2 Übersetzungsarten

**Statischer Text:** `I18n::trans('auth.login_success')` → `登录成功`
**JSON-Felder:** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**Parameterersetzung:** `I18n::trans('validation.required', ['field' => '邮箱'])` → `邮箱 不能为空`

### 12.3 Abdeckung

120 Einträge, deckt alle Module ab: Authentifizierung/Produkt/Order/Zahlung/Ressource/KYC/Ticket/Benachrichtigung/Supplier/Webhook/System. Sprach-Fallback unterstützt (nicht unterstützte Sprache → en-US).

---

## 13. Feature Flags

```
config/features.php (Standardwerte)
  ↓ kann überschrieben werden
.env FEATURE_*-Umgebungsvariablen
  ↓ können zur Laufzeit überschrieben werden
Redis feature:{name} (TTL 1h, dynamisch über Admin-API anpassbar)

Admin-API:
  GET /admin/api/v1/features → Alle Flags mit Status/Quelle auflisten
  PUT /admin/api/v1/features/{name} → enable/disable/toggle/reset

Aktuelle Flags:
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. SSL-Zertifikate

SSL-Zertifikatsprodukt unterstützt die Typen DV/OV/EV, automatische Ausstellung und Verlängerung über das ACME-Protokoll (Let's Encrypt) oder externe CA-APIs (ZeroSSL/GoGetSSL).

**Kernablauf:**

    Benutzer wählt SSL-Paket → Bestellung und Zahlung → ProvisionTask erstellen
      → SslProvider::create() → CertificateAuthority::issue()
      → ACME HTTP-01/DNS-01-Validierung → Zertifikatsausstellung
      → Tägliche Prüfung von expires_at → automatische Verlängerung 14 Tage vor Ablauf
      → Ablauf → status=expired → Benutzer benachrichtigen

**Datenmodelle:** `ssl_plans` (Pakete), `resource_ssl_certs` (Zertifikatsinstanzen)

## 15. Objektspeicher (S3)

S3-API-kompatibler Objektspeicher, unterstützt AWS S3 und MinIO-Selbstbau-Speicher. Benutzer laden Dateien über Presigned-URLs hoch/herunter.

**Datenmodell:** `resource_storage_buckets`

## 16. CDN-Beschleunigung

Das CDN-Produkt unterstützt vier Anbieter (Cloudflare / AWS CloudFront / Aliyun CDN / Tencent CDN). Server oder Storage-Buckets können als Origin in das CDN aufgenommen werden; Cache-Purge und optionale HTTPS-Zertifikatskonfiguration werden unterstützt.

**Adapterarchitektur:** Ein Adapter pro Anbieter unter `service/app/cdn/provider/`, alle implementieren `CdnAdapterInterface` (createDomain / configureDomain / purgeCache / disableDomain / requiresIcpRegistration); `CdnAdapterFactory` verteilt anhand von `provider_type`:

| provider_type | Adapter | Anschlussprotokoll | ICP-Registrierung erforderlich |
|---------------|---------|--------------------|--------------------------------|
| `cloudflare` | CloudflareAdapter | REST-v4-API (inkl. SSL-SaaS-Autozertifikate) | Nein |
| `cloudfront` | CloudFrontAdapter | aws-sdk-php (CloudFront + ACM) | Nein |
| `aliyun` | AliyunCdnAdapter | RPC-Signatur | Ja |
| `tencent` | TencentCdnAdapter | TC3-Signatur | Ja |

**Anbieter-Kontokonfiguration:** Die Admin-Oberfläche pflegt `provider_apis`-Konten per CRUD über `/admin/providers` (Anmeldedaten mit Encryptable verschlüsselt gespeichert, `code`-Konvention `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`). Auflösungspriorität auf Nutzerseite: gebundenes Konto (provider_account_id) → aktives Konto mit passendem code → env-Konfiguration als Fallback.

**Strikte Snapshot-Bindung:** `provider_account_id` wird bei der Domänenerstellung festgelegt; spätere Löschungen/Cache-Purges verwenden ausschließlich das gebundene Konto. Fehlt das Konto oder ist es deaktiviert, wird 4003 zurückgegeben, ohne stilles Umschalten. Aliyun/Tencent-Domains erfordern eine ICP-Registrierung; ohne Registrierung wird 4002 zurückgegeben (inkl. `requires_icp_registration`-Hinweis).

**Cache-Purge:** `POST /api/v1/cdn/domains/{id}/purge`; URLs werden automatisch dedupliziert und von Leerzeichen befreit (max. 100), nur die eigene Domain oder Subdomains sind erlaubt, Wildcards und externe URLs werden abgelehnt; idempotent.

**Schnittstellen:** CdnAdapterInterface + CdnProvider (nutzt den ProvisionProvider-Upgrade-Kanal, unterstützt Plan-Upgrades)

**Datenmodell:** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config; cert_config wird vor dem Speichern von privaten Schlüsseln befreit, nur nicht-sensitive Zertifikatsinformationen bleiben erhalten)

## 17. Nutzungsbasierte Abrechnung

Vollständige Pipeline: Erfassung der Ressourcennutzung → Aggregation → Abrechnung → Belastung:

    ResourceMonitor erfasst alle 5 Minuten Metriken → resource_metrics
      → UsageAggregator aggregiert stündlich → usage_events
      → BillingEngine belastet täglich das Guthaben → Guthaben unzureichend → Ressource suspendieren
      → SuspendCheck prüft alle 30 Minuten → Guthaben wiederhergestellt → Entsperren

**Datenmodelle:** `resource_metrics`, `usage_events`, `usage_rates`, `usage_invoice_items`

## 18. Supplier-Bewertung

Käufer können Supplier in vier Dimensionen bewerten (Qualität/Support/Liefergeschwindigkeit/Preis-Leistung), einmal pro Bestellung. Admin kann prüfen (approve/hide).

**Datenmodelle:** `supplier_ratings`, `suppliers.rating_avg/rating_count`

## 19. Empfehlungsprogramm

Benutzer erzeugen Empfehlungslinks (?ref=CODE); bei der Registrierung neuer Benutzer wird affiliate_code gebunden; nach Zahlungseingang der Bestellung wird die Provision automatisch zugeordnet.

**Ereignisgesteuert:** OrderPaid → Affiliate OrderPaidListener → attributeOrder()

**Datenmodelle:** `affiliate_plans`, `affiliate_links`, `affiliate_earnings`, `affiliate_payouts`

## 20. GraphQL-API

Bietet POST /graphql (öffentliche Abfragen) und POST /api/v1/graphql (authentifizierte Abfragen). Basiert auf webonyx/graphql-php, Abfragtiefe auf 5 Ebenen begrenzt, Komplexitätslimit 100.

**Sensible Operationen bleiben REST-only:** Zahlung, Auszahlung, Rückerstattung, KYC-Prüfung.

## 21. Beobachtbarkeit

Prometheus-Metrik-Endpunkt als separater Prozess 127.0.0.1:9100, unbeeinflusst von WAF/Rate-Limit. MetricsMiddleware zeichnet HTTP-Request-Zählung und Latenz auf. Docker Compose enthält Prometheus + Grafana + Alarmregeln + Dashboards.

**Health-Checks:** /health (öffentlich), /health/live, /health/ready (5 Abhängigkeitsprüfungen), /health/deps (Latenzdetails)
