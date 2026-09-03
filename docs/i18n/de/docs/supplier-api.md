# Supplier-API-Dokumentation v1

## Übersicht

Die Supplier-Funktion bietet zwei API-Sätze:

| Typ | Authentifizierung | Präfix | Status |
|------|---------|------|------|
| **Interne API** | Benutzer-Bearer-Token | `/api/v1/supplier/` | Verfügbar |
| **Externe API** | API Key (`sk_xxx`) | `/api/v1/supplier/external/` | Verfügbar |

**Base URL**: `https://api.example.com`

**Versionsverwaltung**: Die API-Version ist Teil des URL-Pfads (z. B. `/api/v1/supplier/apply`; Admin-Pfade unter `/admin/api/v1/...`). Nicht unterstützte Versionen liefern `400`; zentral über `VersionMiddleware` verarbeitet.

---

## Interne API (aktuell verfügbar)

Die interne API verwendet dieselbe Benutzer-Bearer-Token-Authentifizierung wie die übrigen Plattform-Schnittstellen und ist für bereits eingeloggte Supplier-Benutzer in Client/Frontend gedacht.

### Authentifizierung

```
Authorization: Bearer <user_access_token>
```

Der Benutzer muss sich zuerst über `/api/v1/auth/login` einloggen, um ein Token zu erhalten; die Konto-Rolle muss `supplier` sein (wird nach Admin-Genehmigung des Supplier-Antrags gesetzt).

---

### Antwortformat

#### Erfolgreiche Antwort

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### Paginierte Antwort

```json
{
  "code": 0,
  "message": "ok",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 45
  }
}
```

#### Fehlerantwort

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | Bedeutung |
|------|------|
| 0 | Erfolg |
| 400 | Parameterfehler / nicht unterstützte API-Version |
| 401 | Nicht eingeloggt oder Token abgelaufen |
| 403 | Keine Zugriffsberechtigung (keine Supplier-Rolle / Passwortbestätigung fehlgeschlagen) |
| 404 | Ressource nicht gefunden |
| 422 | Parametervalidierung fehlgeschlagen |
| 429 | Anforderungsrate überschritten |

---

### Endpunkte

#### 1. Supplier-Onboarding

```
POST /api/v1/supplier/apply
```

Als Supplier bewerben. Jeder Benutzer kann nur einen Antrag einreichen.

**Request-Body**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| Parameter | Typ | Pflicht | Bedeutung |
|------|------|------|------|
| company_name | string | Ja | Firmenname |
| contact_name | string | Ja | Ansprechpartner |
| contact_phone | string | Ja | Kontakttelefon |
| contact_email | string | Ja | Kontakt-E-Mail |
| settlement_method | string | Nein | Abrechnungsmethode, Standard `bank` |

**Antwort**: Supplier-Objekt, Status `pending`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "aBc123XyZ",
    "user_id": "UsEr456AbC",
    "company_name": "示例科技有限公司",
    "contact_name": "张三",
    "contact_phone": "138****8000",
    "contact_email": "zha***@example.com",
    "status": "pending",
    "settlement_method": "bank",
    "created_at": "2026-05-20T10:30:00Z"
  }
}
```

> Sensible Felder (Ansprechpartner, Telefon, E-Mail) werden verschlüsselt in der Datenbank gespeichert und bei der API-Antwort teilweise maskiert.

**Fehler**:

| code | Szenario |
|------|------|
| 422 | Bereits ein Supplier-Antrag eingereicht |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. Produktverwaltung

##### Zugewiesene Produkte abrufen

```
GET /api/v1/supplier/products
```

**Query-Parameter**:

| Parameter | Typ | Pflicht | Bedeutung |
|------|------|------|------|
| page | int | Nein | Seitennummer, Standard 1 |

**Antwort**: Paginierte Liste, jeder Eintrag mit Produktinformationen und Provisionssatz

```json
{
  "code": 0,
  "data": [{
    "id": "SpAbC123",
    "supplier_id": "aBc123XyZ",
    "product_id": "PrOdEfG456",
    "commission_rate": 0.1,
    "approved_at": "2026-05-20T10:30:00Z",
    "product": {
      "id": "PrOdEfG456",
      "name": "高性能云服务器",
      "status": "active"
    }
  }],
  "meta": { "page": 1, "page_size": 20, "total": 5 }
}
```

##### Produkt hinzufügen

```
POST /api/v1/supplier/products
```

Ein vorhandenes Produkt mit dem aktuellen Supplier verknüpfen.

**Request-Body**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| Parameter | Typ | Pflicht | Bedeutung |
|------|------|------|------|
| product_id | string | Ja | Produkt-ID (Hashid) |
| commission_rate | float | Nein | Provisionssatz, Standard 0.1 |

**Antwort**: Erstelltes SupplierProduct-Objekt

**Fehler**:

| code | Szenario |
|------|------|
| 422 | Produkt ist diesem Supplier bereits zugewiesen |

##### Produkt entfernen

```
DELETE /api/v1/supplier/products/{id}
```

Die Verknüpfung zwischen Produkt und Supplier aufheben.

**Antwort**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. Abrechnungsverwaltung

##### Abrechnungsliste abrufen

```
GET /api/v1/supplier/settlements
```

**Antwort**: Alle Abrechnungen des aktuellen Suppliers, absteigend nach Erstellzeit

```json
{
  "code": 0,
  "data": [{
    "id": "SeTtLe123",
    "supplier_id": "aBc123XyZ",
    "period_start": "2026-05-01",
    "period_end": "2026-05-31",
    "total_sales": "15000.00",
    "commission": "1500.0000",
    "payable": "13500.0000",
    "status": "pending",
    "created_at": "2026-06-01T02:17:00Z"
  }]
}
```

| Feld | Bedeutung |
|------|------|
| total_sales | Gesamtumsatz der abgeschlossenen Orders im Zeitraum |
| commission | Plattform-Provision gesamt |
| payable | An den Supplier zahlbarer Betrag (total_sales - commission) |
| status | `pending` / `paid` |

---

#### 4. Auszahlung

##### Auszahlung beantragen

```
POST /api/v1/supplier/withdraw
```

> Diese Operation erfordert Passwort-Zweitbestätigung (Feld `confirm_password`), geprüft über `ConfirmationMiddleware`.
> Nach 5 Fehlversuchen 15 Minuten Sperre.

**Request-Body**:

```json
{
  "amount": "5000.00",
  "confirm_password": "user_password_here",
  "account_info": {
    "method": "bank_transfer",
    "bank_name": "中国工商银行",
    "account_number": "6222021234567890",
    "account_holder": "张三"
  }
}
```

| Parameter | Typ | Pflicht | Bedeutung |
|------|------|------|------|
| amount | string | Ja | Auszahlungsbetrag (String, um Float-Präzisionsprobleme zu vermeiden) |
| confirm_password | string | Ja | Benutzer-Login-Passwort (Zweitbestätigung) |
| account_info | object | Ja | Empfängerkontoinformationen |
| account_info.method | string | Ja | Auszahlungsmethode: `bank_transfer` / `alipay` / `wechat` |

**Berechnung des auszahlbaren Guthabens**: Summe aller `payable` abgeschlossener Abrechnungen - Summe aller `amount` laufender Auszahlungen

**Antwort**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**Fehler**:

| code | Szenario |
|------|------|
| 422 | Auszahlbares Guthaben nicht ausreichend |
| 403 | Passwortbestätigung fehlgeschlagen |

```bash
curl -X POST "https://api.example.com/api/v1/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### Übersicht der internen API-Endpunkte

| Methode | Pfad | Auth | Passwortbestätigung | Bedeutung |
|------|------|------|---------|------|
| POST | `/api/v1/supplier/apply` | Token | - | Als Supplier bewerben |
| GET | `/api/v1/supplier/products` | Token | - | Zugewiesene Produkte ansehen |
| POST | `/api/v1/supplier/products` | Token | - | Produktverknüpfung hinzufügen |
| DELETE | `/api/v1/supplier/products/{id}` | Token | - | Produktverknüpfung entfernen |
| GET | `/api/v1/supplier/settlements` | Token | - | Abrechnungen ansehen |
| POST | `/api/v1/supplier/withdraw` | Token | Ja | Auszahlung beantragen |

---

## Externe API (Designspezifikation, zu implementieren)

Die externe API erlaubt Suppliern, Orders, Ressourcen und Abrechnungen programmatisch zu verwalten. Alle Anfragen benötigen API-Key-Authentifizierung.

**Base URL**: `https://api.example.com/api/v1`

### Authentifizierung

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

API Keys werden vom Plattform-Administrator im Admin-Panel unter `供应商管理 → API Keys` erzeugt.

**Sicherheitsanforderungen**:
- Nur über HTTPS zugreifen
- Der API Key wird nur einmal bei der Erstellung angezeigt — sicher aufbewahren
- Es wird empfohlen, die Server-IP zu whitelisten

---

### Antwortformat

Wie die interne API, zusätzlich mit `request_id` zur Verfolgung:

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### Endpunkte

#### 1. Order-Verwaltung

##### Orderliste abrufen

```
GET /api/v1/supplier/orders
```

**Query-Parameter**:

| Parameter | Typ | Pflicht | Bedeutung |
|------|------|------|------|
| page | int | Nein | Seitennummer, Standard 1 |
| page_size | int | Nein | Einträge pro Seite, Standard 20, max 50 |
| status | string | Nein | Statusfilter: pending/paid/completed/refunded |
| from | date | Nein | Startdatum YYYY-MM-DD |
| to | date | Nein | Enddatum YYYY-MM-DD |

##### Order-Details abrufen

```
GET /api/v1/supplier/orders/{id}
```

---

#### 2. Ressourcenverwaltung

##### Ressourcenliste abrufen

```
GET /api/v1/supplier/resources
```

**Query-Parameter**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### Ressourcenstatus abrufen

```
GET /api/v1/supplier/resources/{id}/status
```

---

#### 3. Abrechnungsverwaltung

##### Abrechnungsliste abrufen

```
GET /api/v1/supplier/settlements
```

##### Abrechnungsdetails abrufen

```
GET /api/v1/supplier/settlements/{id}
```

---

#### 4. Auszahlung

##### Auszahlung beantragen

```
POST /api/v1/supplier/withdraw
```

##### Auszahlungshistorie

```
GET /api/v1/supplier/withdraws
```

---

#### 5. Produktverwaltung

##### Meine Produkte abrufen

```
GET /api/v1/supplier/products
```

##### Produkt-Einstellungsantrag einreichen

```
POST /api/v1/supplier/products
```

---

### Übersicht der externen API-Endpunkte

| Methode | Pfad | Bedeutung |
|------|------|------|
| GET | `/api/v1/supplier/orders` | Orderliste |
| GET | `/api/v1/supplier/orders/{id}` | Order-Details |
| GET | `/api/v1/supplier/resources` | Ressourcenliste |
| GET | `/api/v1/supplier/resources/{id}/status` | Ressourcenstatus |
| GET | `/api/v1/supplier/settlements` | Abrechnungsliste |
| GET | `/api/v1/supplier/settlements/{id}` | Abrechnungsdetails |
| POST | `/api/v1/supplier/withdraw` | Auszahlung beantragen |
| GET | `/api/v1/supplier/withdraws` | Auszahlungshistorie |
| GET | `/api/v1/supplier/products` | Produktliste |
| POST | `/api/v1/supplier/products` | Produkt einreichen |

---

## Webhook (Plattform-Events empfangen)

Supplier können eine Webhook-URL registrieren, um Echtzeit-Events zu empfangen. Konfiguration im Admin-Panel.

### Eventtypen

| Event | Auslösezeitpunkt |
|------|----------|
| `order.paid` | Benutzer hat die Zahlung abgeschlossen |
| `order.refunded` | Order wurde erstattet |
| `resource.provisioned` | Ressourcenbereitstellung abgeschlossen |
| `resource.expiring` | Ressource läuft bald ab (innerhalb von 7 Tagen) |
| `resource.destroyed` | Ressource wurde vernichtet |
| `settlement.created` | Abrechnung erzeugt |
| `withdrawal.approved` | Auszahlung genehmigt |

### Webhook-Requestformat

```json
POST {your_webhook_url}
Content-Type: application/json
X-Webhook-Signature: sha256=abc123...
X-Webhook-Event: order.paid

{
  "event": "order.paid",
  "timestamp": "2026-05-20T14:30:00Z",
  "data": {
    "order_id": "abc123",
    "amount": "49.99",
    "currency": "USD"
  }
}
```

**Signaturprüfung**: `HMAC-SHA256(payload, webhook_secret)`

---

## Rate-Limiting

| Endpunkt | Limit |
|------|------|
| Interne API | 60 req/min pro Benutzer (Standard) |
| Interne API-Login | 5 req/min |
| Externe API | 120 req/min pro API Key (`supplier_api`-Regel, wirksam über `RateLimitMiddleware`) |
| Externe API-Auszahlung | 10 req/min (Empfehlungswert, anpassbar in `config/security.php`) |

> Die Rate-Limit-Regel der externen API ist unter `rate_limits.supplier_api` in `config/security.php` definiert
> und wird von `RateLimitMiddleware` für `/api/v1/supplier/external/*`-Pfade einheitlich ausgeführt (atomare INCR-Zählung,
> bei nicht verfügbarem Redis Durchlass).

Limit-Header:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## SDK-Beispiele

### PHP

```php
$token = 'user_access_token_here';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/v1/',
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'Accept'        => 'application/json',
    ],
]);

// Als Supplier bewerben
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// Abrechnungen abrufen
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// Auszahlung beantragen
$response = $client->post('supplier/withdraw', [
    'json' => [
        'amount'           => '5000.00',
        'confirm_password' => 'mypassword',
        'account_info'     => [
            'method'          => 'bank_transfer',
            'bank_name'       => '中国工商银行',
            'account_number'  => '6222021234567890',
        ],
    ],
]);
```

### Python

```python
import requests

headers = {
    'Authorization': 'Bearer <user_access_token>',
}

# Zugewiesene Produkte abrufen
resp = requests.get('https://api.example.com/api/v1/supplier/products',
                     headers=headers)
products = resp.json()

# Auszahlung beantragen
resp = requests.post('https://api.example.com/api/v1/supplier/withdraw',
                      headers=headers,
                      json={
                          'amount': '5000.00',
                          'confirm_password': 'mypassword',
                          'account_info': {
                              'method': 'bank_transfer',
                              'bank_name': 'ICBC',
                              'account_number': '6222021234567890',
                          },
                      })
print(resp.json())
```

---

## Empfehlungen zur Fehlerbehandlung

1. **429 Rate-Limit**: Nach `Retry-After` Sekunden erneut versuchen
2. **401 Nicht autorisiert**: Prüfen, ob das Token gültig bzw. abgelaufen ist
3. **403 Verboten**: Konto-Rolle auf `supplier` prüfen; bei fehlgeschlagener Passwortbestätigung Sperre abwarten
4. **422 Validierungsfehler**: Request-Parameter gemäß `message`-Feld korrigieren
5. **5xx Serverfehler**: Exponentielles Backoff-Retry (1s -> 5s -> 25s)

---

## Admin-Panel-Endpunktreferenz

Die folgenden Endpunkte dienen der Admin-Verwaltung von Suppliern (nur Backend, erfordert Admin-Rolle):

| Methode | Pfad | Bedeutung |
|------|------|------|
| GET | `/admin/api/v1/suppliers` | Supplierliste (mit status-Filter) |
| GET | `/admin/api/v1/suppliers/export` | Supplier als Excel exportieren |
| POST | `/admin/api/v1/suppliers/{id}/approve` | Supplier genehmigen |
| POST | `/admin/api/v1/suppliers/{id}/settle` | Abrechnung erzeugen |
| POST | `/admin/api/v1/suppliers/withdraws/{id}/approve` | Auszahlung genehmigen |
| GET | `/admin/api/v1/suppliers/{id}/api-keys` | API-Key-Liste des Suppliers ansehen |
| POST | `/admin/api/v1/suppliers/{id}/api-keys` | API Key erstellen (roher Key wird nur einmal zurückgegeben) |
| DELETE | `/admin/api/v1/suppliers/api-keys/{id}` | API Key widerrufen |
