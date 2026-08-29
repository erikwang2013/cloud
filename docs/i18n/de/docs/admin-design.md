# Entwurfsdokument Admin-Panel

## Übersicht

`admin/` ist eine eigenständige webman-v2.1-Instanz, die ein auf Layui basierendes Verwaltungs-Dashboard bereitstellt. Sie läuft unabhängig vom `service/`-Backend und teilt sich lediglich die MySQL-Datenbank und die 7 erikwang2013-Pakete.

## Architektur

```
┌─────────────────────────────────────────────────┐
│                  Admin Panel                     │
│  ┌──────────┐  ┌──────────┐  ┌───────────────┐ │
│  │ Controller│  │  Model   │  │   Bootstrap   │ │
│  │ (Layui)  │  │(Eloquent)│  │(worker start) │ │
│  └────┬─────┘  └────┬─────┘  └───────┬───────┘ │
│       │             │               │          │
│  ┌────┴─────────────┴───────────────┴─────────┐ │
│  │         7 erikwang2013 Packages             │ │
│  │  Snowflake │ Hashids │ Encryptable          │ │
│  │  Encryption│ Scout   │ Season │ Poster     │ │
│  └────────────────────┬───────────────────────┘ │
└───────────────────────┼─────────────────────────┘
                        │
              ┌─────────┴─────────┐
              │   MySQL 8.0       │
              │   Elasticsearch   │
              └───────────────────┘
```

### Modul-Abhängigkeitsübersicht

![Modul-Abhängigkeitsübersicht](diagrams/module-dependency.svg)

## Verzeichnisstruktur

```
admin/
├── app/
│   ├── bootstrap/       # Pro-Prozess-Startinitialisierung
│   │   ├── SnowflakeBootstrap.php
│   │   ├── EncryptableBootstrap.php
│   │   └── EncryptionBootstrap.php
│   ├── controller/       # 54 Controller-Dateien (Base/Crud + CRUD je Entität)
│   │   ├── Base.php     # json() mit hashids_encode_ids
│   │   ├── Crud.php     # Select/Insert/Update/Delete/Export mit Hashids-Dekodierung
│   │   ├── DashboardController.php  # Dashboard-Daten-API (Benutzerstatistiken + Trends)
│   │   ├── AccountController.php    # Login/Logout/Profil/Passwort
│   │   ├── AdminController.php      # Admin-CRUD + Rollen
│   │   ├── RoleController.php       # Rollen-CRUD + Regelbaum
│   │   └── ...
│   ├── model/            # 44 Eloquent-Modelle (36 mappen die Geschäftstabellen ohne Präfix des service + alerts (in install.sql definiert) + 7 wa_*-Verwaltungstabellen)
│   │   ├── Base.php     # Snowflake-PK + Encryptable-Unterstützung
│   │   ├── Admin.php    # Encryptable: password, email, mobile
│   │   ├── User.php     # Encryptable: 6 Felder + Searchable-Trait
│   │   └── ...
│   ├── common/           # Auth, Tree, Layui, Util, ExcelExport
│   ├── middleware/        # WafMiddleware + AccessControl
│   ├── exception/        # Handler
│   └── functions.php     # hashids_encode/decode, encrypt_data/decrypt_data
├── api/                  # Öffentliche API (plugin\admin\api)
│   └── Auth.php          # canAccess() ACL
├── config/
│   ├── plugin/erikwang2013/  # 7 Plugin-Konfigurationen
│   ├── hashids.php       # Hashids-Verbindungen (main + alternative)
│   └── encryption.php    # Verschlüsselungskonfiguration (Master-Key, Cipher)
├── tests/                # PHPUnit-11-Testsuite (286 Tests, 962 Assertions)
│   ├── HashidsTest.php   # 21 Tests
│   ├── BaseJsonTest.php  # 13 Tests
│   ├── CrudHashidsTest.php # 14 Tests
│   ├── TreeTest.php      # 19 Tests
│   ├── AccessControlMiddlewareTest.php # 7 Tests (401/403/Durchlass)
│   ├── AdminControllersTest.php        # 48 Controller-Reflexions-Regressionstests
│   ├── UtilTest.php      # 17 Tests
│   ├── DictTest.php      # 5 Tests
│   ├── ExcelExportTest.php # 4 Tests
│   ├── LayuiTest.php     # 5 Tests
│   └── support/          # RequestMock, TestableCrud
├── install.sql           # DDL (bigint unsigned PKs, kein Auto-Increment)
└── phpunit.xml
```

## Paket-Integrationsdetails

### 1. Snowflake (verteilte Primärschlüssel)

**Konfiguration**: `config/plugin/erikwang2013/snowflake-php/app.php`
**Bootstrap**: `app/bootstrap/SnowflakeBootstrap.php`

```php
// Model Base::boot() — creating-Ereignis
static::creating(function (Model $model) {
    if (empty($model->getKey())) {
        $snowflake = Container::instance()->get(Snowflake::class);
        $model->setAttribute($model->getKeyName(), $snowflake->id());
    }
});
```

- 64-Bit-IDs: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`
- Epoche: 2024-01-01 (maximale Lebensdauer ~69 Jahre)
- `$incrementing = false`, `$keyType = 'int'` im Base-Modell
- Alle PK- und FK-Spalten: `bigint unsigned NOT NULL`

### 2. Hashids (ID-Verschleierung)

**Konfiguration**: `config/hashids.php` + `config/plugin/erikwang2013/hashids/app.php`

**Kodierungspfad** (Antwort):
- `Base::json()` ruft rekursiv `hashids_encode_ids($data)` auf
- Felder mit den Namen `id`, `*_id`, `*_ids` mit positiven Ganzzahlen → Hashid-Strings
- `Crud::formatNormal()` wendet die Kodierung ebenfalls an (im Code-Review behoben)

**Dekodierungspfad** (Anfrage):
- `Crud::selectInput()`: dekodiert Hashid-Strings von `id`/`*_id` in der WHERE-Klausel
- `Crud::updateInput()`: dekodiert den Primärschlüssel aus `$request->post()`
- `Crud::deleteInput()`: dekodiert ein Array von Primärschlüsseln aus `$request->post()`
- `AdminController::update()`: verwendet den Rückgabewert von `updateInput()` direkt (dedupliziert)
- `RoleController::select()`/`rules()`: dekodieren `$request->get('id')`

**Hilfsfunktionen** (in `app/functions.php`):
- `hashids_encode(int $id): string`
- `hashids_decode(string $hash): int` — gibt bei Fehlern 0 zurück
- `hashids_encode_ids(array $data): array` — rekursiv, behandelt `is_numeric()`-Strings

### 3. Encryptable (Verschlüsselung von Datenbankfeldern)

**Konfiguration**: `config/plugin/erikwang2013/encryptable/app.php`
**Bootstrap**: `app/bootstrap/EncryptableBootstrap.php`

Verwendet das Eloquent-Interface `CastsAttributes`:
- `get()`: AES-Entschlüsselung beim Lesen aus der DB
- `set()`: AES-Verschlüsselung beim Schreiben in die DB

**Verschlüsselte Felder**:
| Modell | Felder |
|-------|--------|
| Admin | password, email, mobile |
| User | password, email, mobile, token, last_ip, join_ip |

**Kritische Regel**: Immer `save()` auf der Modellinstanz verwenden, niemals `update()` über den Query Builder. `Admin::where(...)->update(...)` umgeht die Eloquent-Casts und speichert Rohwerte. Dies wurde während des Code-Reviews in `AccountController` behoben.

**Passwort-Schichtung**: Passwörter werden zuerst bcrypt-gehasht (in `insertInput`/`updateInput`), danach wird der Hash beim `save()` durch den Encryptable-Cast AES-verschlüsselt. Beim Lesen: AES-Entschlüsselung → bcrypt-Hash → `password_verify()`.

### 4. Encryption (API-Transport)

**Konfiguration**: `config/encryption.php`
**Bootstrap**: `app/bootstrap/EncryptionBootstrap.php`

Reserviert für die Verschlüsselung von API-Anfragen/-Antworten (AES-256-GCM). Stellt bereit:
- `encrypt_data(string $plaintext): string`
- `decrypt_data(string $ciphertext): string`

Wirft `RuntimeException` mit klarer Meldung, wenn `ENCRYPTION_MASTER_KEY` nicht konfiguriert ist.

### 5. Webman-Scout (Elasticsearch)

**Konfiguration**: `config/plugin/erikwang2013/webman-scout/app.php` + `command.php`

Das User-Modell verwendet den `Searchable`-Trait:
```php
class User extends Base
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'mobile' => $this->mobile,
        ];
    }
}
```

### 6. Season (Länderflaggen)

**Konfiguration**: `config/plugin/erikwang2013/season/app.php`

Globale Hilfsfunktion: `country_season_flag(string $code): string`
- `country_season_flag('CN')` → 🇨🇳
- `country_season_flag('US')` → 🇺🇸

Stellt außerdem lokalisierte Saisonnamen über die Klasse `CountrySeason` bereit.

### 7. Poster-PHP (Klick-CAPTCHA)

**Konfiguration**: `config/poster.php` + `config/plugin/erikwang2013/poster/app.php`
**Bootstrap**: `config/plugin/erikwang2013/poster/bootstrap.php` → `CaptchaPlugin`

Stellt die klickbasierte CAPTCHA-Verifizierung für Login und Registrierung bereit:

```
Client                         Server
──────                         ──────
POST /api/captcha/create
  Header: X-Api-Version: v1
  → CaptchaService::create()
    → captcha_create('click')
      → ClickCaptcha::generate()
        → GD rendert Bild mit n zufällig platzierten chinesischen Zeichen
        → Speichert Ziele + Schlüssel in Redis/Datei-Storage
      ← {key, image (base64), target_count, expires_in}

POST /api/auth/login
  Header: X-Api-Version: v1
  (mit captcha_key + captcha_points)
  → AuthController::verifyCaptcha()
    → CaptchaService::verify(key, [[x1,y1], [x2,y2], ...])
      → captcha_verify(key, 'click', points)
        → CaptchaManager prüft euklidische Distanz ≤ 18px Toleranz
      ← true/false
```

**Sicherheitsfunktionen**:
- Einmalschlüssel: werden nach erfolgreicher Verifizierung gelöscht
- Brute-Force-Schutz: max. 3 Fehlversuche pro Schlüssel, danach wird er gelöscht
- 300-Sekunden-TTL (konfigurierbar über `CAPTCHA_TTL`)
- Klick-Toleranz: 18px Radius (konfigurierbar)
- Schwierigkeitsgrade: leicht (2 Ziele), mittel (3), schwer (4)
- Storage: Auto-Erkennung Redis → Datei-Fallback, konfigurierbar über `CAPTCHA_STORAGE`

**Wrapper**: `Common\Captcha\CaptchaService` lädt die benutzerdefinierte Konfiguration aus `config/poster.php` und stellt die Methoden `create()` (entfernt Ziele aus der Antwort aus Sicherheitsgründen) und `verify()` bereit. Wird von `AuthController::register()` und `AuthController::login()` verwendet.

### 8. ConfirmationMiddleware (erneute Passwortprüfung)

**Konfiguration**: Routengruppen-Middleware in `config/route.php`

Schützt destruktive und sensitive Operationen, indem der Benutzer sein Passwort erneut eingeben muss. Als Middleware auf 12 sensitive Routen-Endpunkte angewendet:

```
Client                              Server
──────                              ──────
POST /api/orders/{id}/pay
  Header: X-Api-Version: v1
  (mit confirm_password-Feld)
    → ConfirmationMiddleware::process()
      → Prüft userId vorhanden (401 wenn fehlt)
      → Prüft Redis-Lock-Schlüssel (429 bei Sperre)
      → Validiert Passwort nicht leer (422 wenn fehlt)
      → User::find() + Hash::check() verifiziert bcrypt
      → Bei Fehlschlag:
        → Redis INCR Zähler confirm_failed:{userId}
        → Bei Anzahl ≥ 5, SETEX confirm_lock:{userId} für 900s
        → AuditLogger::record('confirm_failed', ...)
        → Gibt 403 zurück
      → Bei Erfolg:
        → DEL Zähler confirm_failed:{userId}
        → AuditLogger::record('confirm_success', ...)
        → Ruft $next($request)
```

**Sensitive Benutzer-Endpunkte** (Auth + Confirmation):
| Methode | Pfad | Operation |
|--------|------|-----------|
| POST | `/api/orders/{id}/pay` | Zahlung einleiten |
| POST | `/api/supplier/withdraw` | Auszahlung beantragen |
| DELETE | `/api/dns/{domain}/records/{id}` | DNS-Eintrag löschen |

**Sensitive Admin-Endpunkte** (Auth + AdminRole + Confirmation):
| Methode | Pfad | Operation |
|--------|------|-----------|
| DELETE | `/admin/api/products/{id}` | Produkt löschen |
| POST | `/admin/api/orders/{id}/refund` | Bestellung erstatten |
| POST | `/admin/api/provisioning/resources/{id}/destroy` | Ressource zerstören |
| POST | `/admin/api/kyc/{id}/approve` | KYC genehmigen |
| POST | `/admin/api/kyc/{id}/reject` | KYC ablehnen |
| POST | `/admin/api/suppliers/{id}/approve` | Anbieter genehmigen |
| POST | `/admin/api/suppliers/{id}/settle` | Abrechnung erzeugen |
| POST | `/admin/api/suppliers/withdraws/{id}/approve` | Auszahlung genehmigen |
| PUT | `/admin/api/system/config` | Systemkonfiguration aktualisieren |

Die API-Version wird im Header `X-Api-Version` übertragen (Standard: `v1`), nicht im URL-Pfad.

**Sicherheitsfunktionen**:
- bcrypt-Passwortverifizierung über `Hash::check()`
- Rate-Limit: 5 Fehlversuche lösen eine 15-minütige Sperre aus (900s TTL)
- Die Sperre gilt pro Benutzer über Redis-Schlüssel (`confirm_lock:{userId}`, `confirm_failed:{userId}`)
- Erfolg setzt den Fehlerzähler zurück
- Alle Versuche werden in der Audit-Datenbank protokolliert (Erfolg, Fehlschlag, Sperre)
- `verifyPassword()` ist eine protected Methode, was Testbarkeit über eine anonyme Unterklassen-Überschreibung ermöglicht

**Testbarkeit**: `ConfirmationMiddlewareTest` (11 Tests) verwendet eine anonyme Unterklasse, die `verifyPassword()` überschreibt und einen festen booleschen Wert zurückgibt, wodurch die Abhängigkeit von Eloquent/DB entfällt. Die Tests decken ab: 401 nicht authentifiziert, 422 fehlendes/leeres Passwort, 403 falsches Passwort, erfolgreicher Durchlass, Format des Rate-Limit-Schlüssels, Format des Sperr-Schlüssels und die Grenzwerte der maximalen Fehlversuche (4→keine Sperre, 5→gesperrt, 6→gesperrt).

## ACL-System

### Controller-Ebene

```php
protected $noNeedLogin = ['login', 'logout', 'captcha'];  // Login überspringen
protected $noNeedAuth = ['select'];                         // Auth überspringen
```

Wird von `api/Auth::canAccess()` über `ReflectionClass` geprüft.

**Antwort der AccessControlMiddleware** (`middleware/AccessControl.php`):
- Nicht eingeloggt (außerhalb von `noNeedLogin`) → **HTTP 401**, Body ist ein Skript zur Weiterleitung auf die Login-Seite
- Eingeloggt, aber unzureichende Berechtigung → **HTTP 403** Fehlerseite (Statuscode 403, nicht mehr 500)
- In der Durchlass-Liste (Login-Seite/CAPTCHA usw.) → normaler Durchlass

### Rollenbasiert

- Rollen haben `rules` (kommagetrennte Regel-IDs oder `*` für Super-Admin)
- Regeln sind in `wa_rules` als `{Controller}@{action}`-Schlüssel gespeichert
- `api/Auth::canAccess()` löst den Schlüssel `$controller@$action` gegen die Regeln der Rolle auf
- Super-Admin (`rules = '*'`) umgeht alle Prüfungen

### Datenbegrenzungen

```php
protected $dataLimit = null;     // Keine Begrenzung
protected $dataLimit = 'auth';   // Admin sieht eigene + Daten der Untergeordneten
protected $dataLimit = 'personal'; // Admin sieht nur eigene Daten
protected $dataLimitField = 'admin_id';
```

## Code-Review-Ergebnisse (behoben)

Bei der Prüfung des ersten Commits wurden folgende Punkte gefunden und behoben:

### Kritisch
1. **AccountController umgeht Encryptable**: `password()` und `update()` verwendeten `Admin::where()->update()`, das die Eloquent-Casts umgeht → Rohwerte wurden in verschlüsselte Spalten geschrieben. Behoben durch `Admin::find()->save()`.
2. **Crud::formatNormal() kodiert IDs nicht**: rief globales `json()` auf statt `hashids_encode_ids()` anzuwenden. Behoben.

### Wichtig
3. **hashids_encode_ids mit striktem `is_int`**: Große bigint-Werte von PDO kommen als PHP-Strings an. Geändert zu `is_numeric()` mit Ganzzahl-Prüfung.
4. **Doppelte ID-Dekodierung im AdminController**: `update()` dekodierte denselben Primärschlüssel zweimal. Dedupliziert; zusätzlich Variablen-Schattenbildung in der Schleife von `insert()` behoben.
5. **Toter Passwort-Code in AccountController::update()**: Passwortfeld nicht in der Zulassungsliste. Entfernt.
6. **Fest codierter MySQL-Treiber**: Geändert zu `config('database.default')`.

## Excel-Export

### Architektur

Der Excel-Export verwendet PhpSpreadsheet ^2.0, um .xlsx-Dateien serverseitig zu erzeugen. Das Admin-Panel hat zwei getrennte Exportpfade, weil es zwei CRUD-Mechanismen gibt:

```
Export-Anfrage (mit aktuellen Tabellenfiltern)
  ├── Crud-basierte Controller (User, Admin, Role usw.)
  │     → Crud::export()
  │       → selectInput() nutzt die Abfrageparsung wiederverwendet (Hashids-Dekodierung, WHERE, ORDER)
  │       → doSelect() baut die Eloquent-Abfrage auf
  │       → Obergrenze 10.000 Zeilen
  │       → hashids_encode_ids() auf die Ergebnisdaten angewendet
  │       → ExcelExport::export() erzeugt .xlsx
  │
  └── TableController (generische Tabellen wie wa_dict, wa_rules)
        → TableController::export()
          → Baut Abfrage aus Tabellenschema + Anfrageparametern
          → hashids_encode_ids() angewendet
          → ExcelExport::export() erzeugt .xlsx
```

### ExcelExport-Utility (`app/common/ExcelExport.php`)

Fluent-Wrapper um PhpSpreadsheet:

- `setColumns(array $columns)` — Spaltenreihenfolge definieren
- `setLabels(array $labels)` — menschenlesbare Spaltenüberschriften setzen
- `addRow(array $row)` / `addRows(array $rows)` — Daten befüllen
- `save(string $title): string` — .xlsx nach `runtime/exports/` schreiben, Dateipfad zurückgeben
- Statische Hilfsfunktion: `ExcelExport::export($title, $columns, $data, $labels)` — einmaliger Export
- Spalten werden automatisch über `Worksheet::getColumnDimension()` dimensioniert

### Crud::export()

```php
public function export(Request $request): Response
{
    [$where, $format, $limit, $field, $order] = $this->selectInput($request);
    $query = $this->doSelect($where, $field, $order);
    $maxRows = 10000;
    $total = min($query->count(), $maxRows);
    $items = $query->limit($maxRows)->get();
    if (method_exists($this, 'afterQuery')) {
        $items = call_user_func([$this, 'afterQuery'], $items);
    }
    $data = array_map(fn($item) => ...toArray(), $items->toArray());
    $data = hashids_encode_ids($data);
    // Spaltenbeschriftungen aus den Schemakommentaren ableiten
    $path = ExcelExport::export($table, $columns, $data, $labels);
    return response()->download($path, $table . '_' . date('YmdHis') . '.xlsx');
}
```

Alle Crud-basierten Controller (Admin, User, Role usw.) erben `export()` automatisch.

### Frontend-Anbindung

- Layuis eingebautes Toolbar-Element `"exports"` (clientseitiges CSV) wird durch einen eigenen Button `{title: "导出", layEvent: "export"}` ersetzt
- Der `export`-Event-Handler ruft `window.exportExcel()` auf, das die aktuellen Tabellenfilter-Parameter sammelt und die Download-URL öffnet
- `Layui::buildTable()` erzeugt die Toolbar mit dem benutzerdefinierten Export-Button für alle CRUD-Seiten

### Export der Service-Admin-API

Auch das Service-Backend (`service/`) hat einen Excel-Export über seinen eigenen `Common\ExcelExport`-Wrapper:

| Endpunkt | Controller | Exportierte Daten |
|----------|-----------|---------------|
| `GET /admin/api/orders/export` | OrderController | id, order_no, user_id, type, status, total, currency, created_at, paid_at |
| `GET /admin/api/users/export` | UserController | id, email, phone, role, status, created_at, last_login_at |
| `GET /admin/api/suppliers/export` | SupplierController | id, user_id, status, contact_name, contact_email, contact_phone, created_at |

Alle API-Endpunkte erfordern den `X-Api-Version`-Header (Standard: `v1`).

Export-Routen werden VOR den `/{id}`-Parameterrouten registriert, um Konflikte zu vermeiden.

## Service-Admin-API — erweiterte Funktionen

### Admin-API-Endpunkte (Service-Ebene)

Alle Admin-REST-Endpunkte haben das Präfix `/admin/api` und erfordern die `AdminRoleMiddleware`.

| Gruppe | Endpunkte | Controller |
|-------|-----------|------------|
| Dashboard | `GET /dashboard`, `/kyc`, `POST /kyc/{id}/approve`, `/reject` | `Admin\DashboardController` |
| Benutzer | `GET /users`, `/users/export`, `/users/{id}`, `PUT /users/{id}/status` | `Admin\UserController` |
| Produkte | `POST /products`, `PUT /products/{id}`, `DELETE /products/{id}`, `POST /products/{id}/skus`, `PUT /skus/{id}`, `POST /skus/{id}/region-price` | `Admin\ProductController` |
| Produkt-Import/Export | `GET /products/export` (CSV), `POST /products/import` (CSV upsert) | `Admin\ImportExportController` |
| Bestellungen | `GET /orders`, `/orders/export`, `/orders/{id}`, `POST /orders/{id}/refund` | `Admin\OrderController` |
| Rechnungen | `GET /invoices`, `POST /invoices/{orderId}/generate` | `Admin\InvoiceController` |
| Zahlungen | `GET /payments/channels`, `PUT /payments/channels/{id}`, `GET /payments/transactions`, `/reconcile` | `Admin\PaymentController` |
| Bereitstellung | `GET /provisioning/tasks`, `POST /tasks/{id}/retry`, `POST /resources/{id}/upgrade`, `/destroy`, `GET /hosts` | `Provisioning\TaskController` |
| Anbieter-APIs | `GET /providers`, `POST /providers`, `PUT /providers/{id}`, `DELETE /providers/{id}` | `Admin\ProviderApiController` |
| CDN | `GET /cdn/domains`, `PUT /cdn/domains/{id}` | `Admin\CdnController` |
| Anbieter | `GET /suppliers`, `/suppliers/export`, `POST /suppliers/{id}/approve`, `/settle`, `/withdraws/{id}/approve` | `Admin\SupplierController` |
| Anbieter-API-Keys | `GET /suppliers/{id}/api-keys`, `POST /suppliers/{id}/api-keys`, `DELETE /suppliers/api-keys/{id}` | `Admin\SupplierController` |
| Tickets | `GET /tickets`, `POST /tickets/{id}/assign`, `/close` | `Ticket\TicketController` |
| Gutscheine | `GET /coupons`, `POST /coupons`, `DELETE /coupons/{id}` | `Admin\CouponController` |
| Webhooks | `GET /webhooks`, `POST /webhooks`, `DELETE /webhooks`, `POST /webhooks/test` | `Admin\WebhookController` |
| Domains | `GET /domains/tlds`, `POST /domains/tlds`, `PUT /domains/tlds/{id}`, `DELETE /domains/tlds/{id}`, `GET /domains/zones`, `/transfers`, `POST /transfers/{id}/approve` | `Admin\DomainController` |
| Benachrichtigungen | `GET /notifications/templates`, `PUT /notifications/templates/{id}`, `GET /notifications/log` | `Admin\NotificationController` |
| Hilfeartikel | `GET /help`, `POST /help`, `PUT /help/{id}`, `DELETE /help/{id}` | `Admin\HelpController` |
| Berichte | `GET /reports/revenue`, `/supplier`, `/region` | `Report\ReportController` |
| Monitoring | `GET /monitor/dashboard`, `/resources/{id}` | `Monitor\MonitorController` |
| Audit | `GET /audit-logs` | `Admin\SystemController` |
| Systemkonfiguration | `PUT /system/config` | `Admin\SystemController` |

### CDN-Ressourcenverwaltung

Das CDN-Produkt unterstützt vier Anbieter (Cloudflare / CloudFront / Aliyun / Tencent), die Admin-Oberfläche ist in zwei Bereiche unterteilt:

**Anbieter-Kontokonfiguration** (nutzt das ProviderApi-Modell, `Admin\ProviderApiController`):

- `GET/POST /admin/api/providers`, `PUT/DELETE /admin/api/providers/{id}`, hängt an `RbacMiddleware('provider.config')`
- `code`-Konvention `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`; Anmeldedaten-Felder mit Encryptable verschlüsselt gespeichert, `config`-JSON-Spalte für nicht-sensitive Metadaten
- Auflösungspriorität auf Nutzerseite: gebundenes Konto → aktives Konto mit passendem code → env-Fallback; Löschung/Purge nutzt strikte Snapshot (nur gebundenes Konto, fehlt/deaktiviert → 4003)

**CDN-Domainverwaltung** (`Admin\CdnController`):

```
GET /admin/api/cdn/domains        → Alle Domains (inkl. zugehöriger user_id), hängt an RbacMiddleware('cdn.manage')
PUT /admin/api/cdn/domains/{id}   → Paket aktualisieren, plan-Whitelist standard | pro | enterprise,
                                     ungültige Werte → 400; Änderungen schreiben Audit-Log admin_cdn_update_plan
```

### Dashboard-Daten (Service-Ebene)

`Admin\DashboardController::index()` liefert echte operative Kennzahlen:

```php
[
    'today_stats' => [todayOrders, todayRevenue, newUsers, activeResources],
    'revenue_trend_30d' => [...],   // Täglicher Umsatz der letzten 30 Tage
    'region_distribution' => [...],  // Aktive Ressourcen nach Region gruppiert
    'pending_orders' => ...,         // Bestellungen, die auf Zahlung warten
    'pending_kyc' => ...,            // KYC-Einreichungen, die auf Prüfung warten
    'open_tickets' => ...,           // Offene oder in Bearbeitung befindliche Tickets
]
```

### Dashboard-Ansicht des Admin-Panels (`app/view/index/dashboard.html`)

- **8 animierte Statistik-Karten**: Benutzer heute/Woche/Monat/gesamt + Bestellungen heute + Umsatz heute + ausstehende Bestellungen + aktive Ressourcen — jeweils mit Count-up-Animation über das Layui-`count`-Modul
- **3 ECharts-Diagramme**:
  1. 7-Tage-Benutzerregistrierungstrend — Flächendiagramm
  2. 30-Tage-Benutzerregistrierungstrend — Balkendiagramm
  3. Benutzerübersicht — Ring/Torten-Diagramm (heute / Woche / Monat)
- **Systeminformationstabelle**: dynamisch befüllt mit PHP/Workerman/Webman/Admin/MySQL/OS-Versionen
- **Toolbar**: PDF-Export- und Aktualisierungs-Buttons
- Alle Daten werden per AJAX von `/app/admin/dashboard/data` geladen

### Route

```
Route::any('/app/admin/dashboard/data', [DashboardController::class, 'index']);
```

Neben den explizit registrierten Routen registriert `admin/config/route.php` für jede öffentliche Methode jedes Controllers unter `app/controller/` automatisch die Route `/app/admin/{snake_case_controller}/{action}` (z. B. `/app/admin/order_item/index`); die URLs stimmen mit den in Menüs verwendeten snake_case-Controllernamen überein. `/app/admin` und `/app/admin/index` sind die Einstiege für Startseite/Login-Seite des Backends (bei nicht eingeloggten Benutzern wird die Login-Ansicht gerendert); nicht zuzuordnende Anfragen geben einheitlich 404 zurück.

## PDF-Export

Clientseitige PDF-Erzeugung auf der Dashboard-Seite:

- Verwendet **html2canvas 1.4.1** (CDN), um das Dashboard-DOM als Canvas zu erfassen
- Verwendet **jsPDF 2.5.1** (CDN), um ein herunterladbares A4-PDF zu erstellen
- Erfasst Statistik-Karten und ECharts-Diagramme (als Canvas-Elemente gerendert)
- Enthält Titel, Zeitstempel und Branding im PDF
- Ausgelöst durch den "Export PDF"-Button in der Dashboard-Toolbar

```
Dashboard-DOM → html2canvas-Screenshot → jsPDF-Dokument → Browser-Download
```

### Implementierung

```javascript
// In dashboard.html
html2canvas(document.querySelector('.layui-body'), {scale: 2}).then(canvas => {
    const pdf = new jsPDF('p', 'mm', 'a4');
    const imgData = canvas.toDataURL('image/png');
    pdf.addImage(imgData, 'PNG', 10, 10, 190, 0);
    pdf.save('dashboard_' + new Date().toISOString().slice(0, 10) + '.pdf');
});
```

## Testsammlung

```
PHPUnit 11.5 | 67 Tests | 124 Assertions
```

### HashidsTest (21 Tests)
- Kodierungs-/Dekodierungs-Roundtrip (0 bis PHP_INT_MAX)
- Deterministische Kodierung
- Behandlung ungültiger/leerer Strings
- Feldmuster von `hashids_encode_ids` (`id`, `*_id`, `*_ids`)
- Überspringen von Null/Negativ, Unterstützung numerischer Strings
- Rekursion bei verschachtelten Arrays, Erhaltung von Nicht-ID-Feldern

### BaseJsonTest (13 Tests)
- `json()`/`success()`/`fail()` wenden die Hashids-Kodierung an
- Kodierung verschachtelter Objekte
- Behandlung von Snowflake-großen IDs
- Erhaltung von Nicht-ID-Feldern
- Null-Behandlung
- Prüfung der Antwortstruktur

### CrudHashidsTest (14 Tests)
- `selectInput`: Hashid-Dekodierung in `id`/`*_id` WHERE-Feldern
- `selectInput`: Durchreichung numerischer Strings/Roh-Ints
- `updateInput`: Hashid-PK-Dekodierung
- `updateInput`: numerischer String-PK wird zu int gecastet
- `deleteInput`: Batch-ID-Dekodierung, gemischte Typen
- `deleteInput`: leeres Array, Behandlung einzelner IDs

## Datenbank-Migrationssystem

### Architektur

Sowohl die `service/`- als auch die `admin/`-Instanz haben unabhängige Migrationssysteme auf Basis des `illuminate/database` Schema Builder. Jede Instanz registriert Symfony-Console-Befehle über `config/command.php`, die vom Console-Runner von webman gefunden werden.

```
php webman migrate          # Ausstehende Migrationen ausführen
php webman migrate:rollback # Letzte Batch zurücksetzen
php webman migrate:status   # Migrationsstatus anzeigen
```

### MigrationRunner (`service/support/MigrationRunner.php`, `admin/app/common/MigrationRunner.php`)

Kern-Engine, von beiden Instanzen gemeinsam genutzt:

- **`ensureTable()`** — Erstellt beim ersten Lauf die Tracking-Tabelle `migrations` (id, Migrationsname, Batch-Nummer)
- **`migrate()`** — Scannt Migrationsdateien aus `database/migrations/`, führt ausstehende `up()`-Methoden aus, zeichnet Batch auf
- **`rollback()`** — Kehrt die letzte Batch um, indem `down()` auf jede Migration in umgekehrter Reihenfolge aufgerufen wird
- **`status()`** — Listet alle Migrationen mit ihren Batch-Nummern
- **`resolve()`** — Instanziiert Migrationsklassen aus Dateien

### Migrations-Basisklasse (`service/support/Migration.php`, `admin/app/common/Migration.php`)

```php
abstract class Migration
{
    abstract public function up(): void;
    abstract public function down(): void;
}
```

Jede Migrationsdatei gibt eine Klasse zurück, die `Migration` erweitert; die Dateinamen haben einen Zeitstempel-Präfix (z. B. `2024_01_01_000001_create_initial_schema.php`).

### Service-Migrationen

**Verzeichnis**: `service/database/migrations/` — 38 Migrationsdateien (Tabellennamen ohne erik_-Präfix, direkt von Admin-Modellen gemappt)

| Migration | Tabellen |
|-----------|--------|
| `0001_create_users_tables` | users, user_profiles, user_kyc, user_balance, user_balance_log, user_addresses, refresh_tokens |
| `0002_create_product_tables` | product_categories, regions, products, product_skus, product_regions, product_images, product_attributes, product_reviews |
| `0003_create_order_tables` | carts, orders, order_items, order_timeline, order_invoices, refunds |
| `0004_create_payment_tables` | payment_channels, payment_transactions, payment_reconcile |
| `0005_create_provisioning_tables` | resources, resource_servers, resource_ips, resource_disks, resource_domains, provision_tasks, provider_apis |
| `0006_create_host_tables` | host_machines, ip_pools, ip_allocations, disks, disk_resizes |
| `0007_create_supplier_tables` | suppliers, supplier_products, supplier_settlements, supplier_withdraws |
| `0008_create_domain_tables` | domain_tlds, domain_transfers, dns_zones, dns_records |
| `0009_create_ticket_notification_tables` | tickets, ticket_messages, notifications, notification_templates |
| `0010_create_audit_table` | audit_logs |
| `0011_create_kvm_service_tables` | network_services, firewall_services, switch_services |
| `2024_01_01_000001_create_initial_schema` | Führt `docs/database.sql` über `Capsule::unprepared()` aus, löscht alles in `down()` |
| `2025_05_16_000002_add_fcm_token_to_users` | Fügt `fcm_token`, `fcm_platform`-Spalten + Index zu users hinzu |
| `2026_08_26_000003_widen_encrypted_columns` | users.phone / user_addresses.phone / suppliers.contact_phone VARCHAR(20)→VARCHAR(255) (Länge der Encryptable-Ciphertexte) |

### Admin-Migrationen

**Verzeichnis**: `admin/database/migrations/` — 1 Migrationsdatei

| Migration | Beschreibung |
|-----------|-------------|
| `2024_01_01_000001_create_admin_schema` | Führt `admin/install.sql` über `Capsule::unprepared()` aus — erstellt wa_*-Tabellen mit Seed-Daten |

### Registrierung der Console-Befehle

**`service/config/command.php`**:
```php
return [
    \App\Command\MigrateCommand::class,
    \App\Command\MigrateRollbackCommand::class,
    \App\Command\MigrateStatusCommand::class,
];
```

**`admin/config/command.php`** — gleiches Muster unter dem Namespace `app\command`.

## Stripe-Produktionsintegration

### Architektur

Ersetzte gefälschte `random_bytes()`-Zahlungs-IDs durch echte Stripe-API-Integration über `stripe/stripe-php` ^15.0.

**Datei**: `service/app/payment/service/channels/StripeChannel.php`

```
Client-seitig                    Server-seitig                    Stripe API
───────────                    ───────────                    ──────────
Stripe an der Kasse wählen
  → POST /orders/{id}/pay
    → StripeChannel::createPaymentIntent()
      → StripeClient->paymentIntents->create(amount, currency)
        ← {id, client_secret}
      → pi_xxx als transaction_no speichern
      ← client_secret zurückgeben
  → Stripe.js confirmCardPayment(client_secret)
    ← Zahlung von Stripe bestätigt
      → POST /payments/webhook/stripe
        → StripeChannel::handleWebhook()
          → Webhook::constructEvent(payload, signature, secret)
          → Idempotenz prüfen (nicht-ausstehende Transaktionen überspringen)
          → Bestellstatus aktualisieren, Transaktionsdatensatz anlegen
```

### PaymentIntent-Erstellung

```php
public function createPaymentIntent(Order $order): array
{
    $intent = $this->stripe()->paymentIntents->create([
        'amount'   => (int) round($order->total * 100),  // Cent
        'currency' => strtolower($order->currency),
        'metadata' => ['order_id' => $order->id, 'order_no' => $order->order_no],
    ]);
    return [
        'transaction_no' => $intent->id,          // pi_xxxxxxxxxxxxx
        'client_secret'  => $intent->client_secret, // pi_xxx_secret_yyy
    ];
}
```

- `$this->stripe()` initialisiert lazy `\Stripe\StripeClient` mit `STRIPE_SECRET_KEY` aus der Umgebung
- Fällt auf `$this->channel->api_key_encrypted` zurück (über Encryptable entschlüsselt), wenn die Umgebungsvariable nicht gesetzt ist
- Betrag wird in Cent umgerechnet: `(int) round($order->total * 100)`

### Webhook-Signaturprüfung

```php
public function handleWebhook(string $payload, string $signature): void
{
    $event = \Stripe\Webhook::constructEvent(
        $payload, $signature, $this->channel->webhook_secret_encrypted
    );
    // Idempotenz: überspringen, wenn die Transaktion bereits verarbeitet wurde
    $existing = Transaction::where('transaction_no', $event->id)->first();
    if ($existing && $existing->status !== 'pending') return;
    
    match ($event->type) {
        'payment_intent.succeeded' => $this->confirmPayment($event),
        'payment_intent.payment_failed' => $this->failPayment($event),
        default => null,
    };
}
```

- Verwendet `Webhook::constructEvent()`, um den Stripe-Signatur-Header zu prüfen
- **Idempotenz-Schutz**: prüft auf doppelte Webhook-Zustellungen anhand von `transaction_no`
- Unterstützt sowohl Erfolgs- als auch Fehlerereignistypen

## Twilio-SMS-Integration

### Architektur

Ersetzte den `error_log()`-Stub durch echte SMS-Zustellung über `twilio/sdk` ^8.0.

**Datei**: `service/app/notification/queue/SmsSender.php`

### Nachrichtenversand

```php
public function consume(): void
{
    $client = new \Twilio\Rest\Client(
        getenv('TWILIO_ACCOUNT_SID'),
        getenv('TWILIO_AUTH_TOKEN')
    );
    $message = $client->messages->create(
        $this->notification->recipient_phone,
        ['from' => getenv('TWILIO_PHONE_NUMBER'), 'body' => $this->notification->body]
    );
    $this->notification->provider_message_id = $message->sid;
}
```

### Fehlerbehandlung

- Fängt `Twilio\Exceptions\RestException` — erfasst Twilio-Fehlercode und -meldung
- Erstellt einen fehlgeschlagenen Notification-Datensatz mit `send_status = 'failed'`
- Zeichnet `provider_message_id` (Twilio SID) zur Zustellungsverfolgung auf
- Fällt auf `error_log()` zurück, wenn Twilio-Zugangsdaten nicht gesetzt sind (Dev-Modus)

### Konfiguration

Umgebungsvariablen: `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_PHONE_NUMBER`

## FCM-Push-Integration

### Architektur

Ersetzte den `error_log()`-Stub durch echte Push-Zustellung über `kreait/firebase-php` ^7.0.

**Datei**: `service/app/notification/queue/PushSender.php`

### Geräte-Token-Speicherung

Zur `users`-Tabelle per Migration hinzugefügt:
- `fcm_token VARCHAR(512) DEFAULT NULL` — Geräteregistrierungstoken
- `fcm_platform VARCHAR(16) DEFAULT NULL` — `ios` / `android` / `web`
- `INDEX idx_fcm_token (fcm_token)` — Suche nach Token

User-Modell: `fcm_token` und `fcm_platform` zu `$fillable` hinzugefügt.

### Push-Versand

```php
public function consume(): void
{
    $factory = new \Kreait\Firebase\Factory();
    if ($credentialsPath = getenv('FIREBASE_CREDENTIALS_PATH')) {
        $factory = $factory->withServiceAccount($credentialsPath);
    }
    $messaging = $factory->createMessaging();
    
    $message = \Kreait\Firebase\Messaging\CloudMessage::withTarget(
        'token', $this->user->fcm_token
    )->withNotification([
        'title' => $this->notification->title,
        'body'  => $this->notification->body,
    ]);
    
    $result = $messaging->send($message);
}
```

### Token-Bereinigung

- Fängt `Kreait\Firebase\Exception\Messaging\InvalidToken` — setzt das `fcm_token` des Benutzers auf null
- Fängt `Kreait\Firebase\Exception\Messaging\NotFound` — entfernt nicht registriertes Token
- Fällt auf `error_log()` zurück, wenn Firebase-Zugangsdaten nicht gesetzt sind (Dev-Modus)

### Konfiguration

Umgebungsvariablen: `FIREBASE_CREDENTIALS_PATH` (Service-Account-JSON), `FCM_SERVER_KEY` (Legacy)

## Geschäftsablauf-Diagramme

### Bestellung → Zahlung → Bereitstellung (Kern-Geschäftsablauf)

![Bestellungs-Zahlungs-Bereitstellungs-Ablauf](diagrams/order-payment-provisioning.svg)

### Event-getriebene Bereitstellung im Detail

![Event-getriebene Bereitstellung](diagrams/provisioning-detail.svg)

### Benachrichtigungsversand

![Benachrichtigungsversand](diagrams/notification-dispatch.svg)

### Anbieter-Lebenszyklus

![Anbieter-Lebenszyklus](diagrams/supplier-lifecycle.svg)

### Ticket-Lebenszyklus

![Ticket-Lebenszyklus](diagrams/ticket-lifecycle.svg)

## Testsammlung der Service-Ebene

### Übersicht

```
PHPUnit 10.5 | 295 Tests | 455 Assertions
```

**Verzeichnis**: `service/tests/` — 12 Testdateien über 7 Module

**Konfiguration**: `service/phpunit.xml` — einzelne `unit`-Testsuite, deckt `app/`- und `common/`-Quellcode ab

### Test-Bootstrap

`service/tests/bootstrap.php` lädt den Composer-Autoloader und definiert zwei globale Hilfsfunktionen, die der zu testende Code benötigt:

- `request_id()` — gibt eine eindeutige Request-ID-Zeichenkette zurück
- `now()` — gibt das aktuelle `DateTime`-Objekt zurück

Wichtige Erkenntnis: `Webman\Config` kann im Testkontext nicht geladen werden, weil `loadFromDir()` `route.php` auslöst, das `Route::addRoute()` auf null aufruft. Die Tests umgehen Config vollständig — `HashidServiceTest` verwendet `new Hashids()` direkt, `ResponseTest` verwendet lokale Hilfsmethoden.

### Testdateien

| Datei | Tests | Abdeckung |
|------|-------|----------|
| `Captcha/CaptchaServiceTest.php` | 9 | create-Struktur, Schwierigkeitsgrade, verify bestanden/fehlgeschlagen, Einmalverwendung, eindeutige Schlüssel |
| `Confirmation/ConfirmationMiddlewareTest.php` | 11 | Auth erforderlich, fehlendes Passwort, falsches Passwort, erfolgreicher Durchlass, Format des Rate-Limit-Schlüssels, Format des Sperr-Schlüssels, maximale Fehlschwellen |
| `Common/HashidServiceTest.php` | 17 | Kodierungs-/Dekodierungs-Roundtrip, Determinsmus, Salt-Isolation, rekursiver ID-Durchlauf |
| `Common/ResponseTest.php` | 16 | Struktur von success/error/paginierte Antworten, request_id-Konsistenz, HTTP-Fehlercodes |
| `Common/SnowflakeTest.php` | 6 | Zeitstempel-Reihenfolge, Eindeutigkeit, bigint-Bereich, Init-Muster |
| `Common/ValidatorTest.php` | 22 | Validierungsregeln required(), email(), minLength() |
| `Common/LogSanitizerTest.php` | 34 | PII-Schwärzung, verschachtelte Arrays, case-insensitive Übereinstimmung, 20 sensitive Feldtypen |
| `Payment/StripeChannelTest.php` | 19 | Kanal-Konfiguration, Betragsberechnung, Webhook-Signaturen, Idempotenz |
| `Payment/PaymentRouterTest.php` | 10 | Kanalfilter, Betragsgrenzen, Währungs-/Regionsunterstützung, Gebührenberechnung |
| `Notification/NotificationDispatcherTest.php` | 8 | Template-Rendering, Kanal-Routing, Überspringen inaktiver Benutzer |
| `Provisioning/ProviderFactoryTest.php` | 12 | register, create, createFromResource, Fehlerfälle |
| `Provisioning/RetryLogicTest.php` | 12 | exponentielles Backoff, maximale Wiederholungen, Statusübergänge, Host-Auswahl |
| `ClientPlatform/ClientPlatformMiddlewareTest.php` | 13 | gültige Plattformen, fehlender/Standard-Header, nicht unterstützte Plattform, case-insensitive, Nicht-API-Überspringen, Admin-Routen, Downstream-Zugriff |
| `Security/WafMiddlewareTest.php` | 37 | SQLi (4), XSS (6), CMDi (4), Datei-Inklusion (3), Header-Injection/CRLF (2), SSRF (5), NoSQL-Injection (4), Open Redirect (2), sicherer Durchlass (5), URL-Scanning, UA-Scanning |
| `Version/VersionMiddlewareTest.php` | 6 | gültige Version, fehlende Version Standard, nicht unterstützte Version 400, Nicht-API-Überspringen, Admin-API-Validierung, Fehlerantwort-Header |

### Test-Infrastruktur

- `tests/TestCase.php` — Basisklasse, die PHPUnit TestCase erweitert
- `tests/Support/RequestMock.php` — Mock-Request mit im Konstruktor injizierten Parametern

## CI/CD-Pipeline

### Architektur

GitHub-Actions-Workflow unter `.github/workflows/ci.yml`.

**Trigger**: Push auf `main`, Pull Requests auf `main`

### Jobs

| Job | Strategie | Beschreibung |
|-----|----------|-------------|
| `syntax` | PHP 8.2 | `php -l`-Lint aller `.php`-Dateien in admin/ und service/ |
| `admin-tests` | PHP 8.2, 8.3 | `composer install -d admin` → `admin/vendor/bin/phpunit` |
| `service-tests` | PHP 8.2, 8.3 | `composer install -d service` → `service/vendor/bin/phpunit` |
| `composer-validate` | PHP 8.2 | `composer validate --strict` auf beiden composer.json-Dateien |

### PHP-Versionsmatrix

Beide Test-Jobs laufen auf PHP 8.2 und 8.3 über `shivammathur/setup-php@v2`.

### Aktueller Status

Alle 4 Jobs bestehen: 243 Tests insgesamt (67 admin + 176 service), 400 Assertions, beide PHP-Versionen grün.

## Datenbank-Entity-Beziehung

![Datenbank-Entity-Beziehung](diagrams/database-er.svg)

## Zentrale Designentscheidungen

1. **Eigenständige Instanz**: admin/ läuft als eigene webman-Instanz, nicht als Plugin innerhalb von service/. Dies isoliert Admin-Verkehr und -Fehler von der kundenorientierten API.

2. **Encryptable + Passwort-Hashing**: Passwörter werden zuerst bcrypt-gehasht, dann AES-verschlüsselt. Der Encryptable-Cast arbeitet auf Eloquent-Ebene (oberhalb des Hashings), die Schichtung ist also: `Eingabe → bcrypt-Hash → Modell-Attribut setzen → Encryptable::set() verschlüsselt → DB`. Beim Lesen: `DB → Encryptable::get() entschlüsselt → bcrypt-Hash → password_verify()`.

3. **Hashids an der Controller-Grenze**: Kodierung/Dekodierung erfolgt an der HTTP-Grenze (Controller), nicht auf Modell- oder ORM-Ebene. Dadurch bleiben Modelle datenbank-agnostisch und Hashids sind eine reine Darstellungsfrage.

4. **Container-basierte Service-Auflösung**: Dienste (Snowflake, HashidsManager, EncryptionManager) werden als Singletons über Bootstrap-Klassen beim Worker-Start registriert. Die Container-Auflösung über `\support\Container::instance()` verwendet Lazy-Instantiation — Dienste werden nur beim ersten Zugriff erzeugt.

## Erweiterte Funktionen (2026-05-20)

### Service-Admin-API — neue Endpunkte

| Gruppe | Endpunkte | Controller |
|-------|-----------|------------|
| Rechnungen | `GET /admin/api/invoices`, `POST .../invoices/{orderId}/generate` | `Admin\InvoiceController` |
| Anbieter-APIs | `GET/POST /admin/api/providers`, `PUT/DELETE .../providers/{id}` | `Admin\ProviderApiController` |
| Anbieter-API-Keys | `GET/POST /admin/api/suppliers/{id}/api-keys`, `DELETE .../api-keys/{id}` | `Admin\SupplierController` |
| Gutscheine | `GET/POST /admin/api/coupons`, `DELETE .../coupons/{id}` | `Admin\CouponController` |
| Webhooks | `GET/POST/DELETE /admin/api/webhooks`, `POST .../webhooks/test` | `Admin\WebhookController` |
| Produkt-Import/Export | `GET /admin/api/products/export`, `POST .../products/import` | `Admin\ImportExportController` |
| Domainverwaltung | `GET/POST/PUT/DELETE /admin/api/domains/tlds`, `GET .../zones`, `GET .../transfers`, `POST .../transfers/{id}/approve` | `Admin\DomainController` |
| Benachrichtigungs-Templates | `GET /admin/api/notifications/templates`, `PUT .../templates/{id}`, `GET .../log` | `Admin\NotificationController` |
| Hilfeartikel | `GET/POST /admin/api/help`, `PUT/DELETE .../help/{id}` | `Admin\HelpController` |

### Neue Middleware

| Middleware | Zweck |
|------------|---------|
| `VersionMiddleware` | API-Version aus dem X-Api-Version-Header lesen und validieren |
| `RateLimitMiddleware` | Redis-Token-Bucket-Rate-Limit (Standard 60 req/min, Login 5 req/min) |
| `GeoBlockMiddleware` | MaxMind-GeoIP2-Regionsblockierung |
| `MaintenanceMiddleware` | Wartungsmodus (Umgebungsvariablen-Schalter + IP-Whitelist) |
| `ClientPlatformMiddleware` | Client-Plattformerkennung (X-Client-Platform-Header), unterstützt 8 Plattformen |
| `SupplierApiKeyMiddleware` | Authentifizierung der externen Anbieter-API (sk_xxx-Key mit SHA256-Signaturprüfung) |
| `WafMiddleware` (admin) | Admin-Panel-WAF-Middleware, 8 Kategorien mit 45+ Regeln + Anforderungsgrößenbegrenzung + Content-Type-Validierung |

### Geplante Aufgaben

| Zeitplan | Aufgabe | Zweck |
|----------|------|---------|
| `13 */4 * * *` | ExchangeRateSync | Wechselkurs-Aktualisierung |
| `37 2 * * *` | PaymentReconcile | Täglicher Zahlungsabgleich |
| `17 4 * * 1` | SupplierSettlement | Wöchentliche Anbieterabrechnung |
| `23 6 * * *` | ExpirationCheck | Prüfung Ressourcen/Domains auf Ablauf + Benachrichtigung |
| `43 7 * * *` | SslCertificateCheck | Prüfung SSL-Zertifikate auf Ablauf + Benachrichtigung |
| `*/5 * * * *` | CollectMetrics | Erfassung von Ressourcenmetriken |
| `*/30 * * * *` | CheckExpirations | Prüfung des Ressourcenablaufs |

### CLI-Befehle

| Befehl | Zweck |
|---------|---------|
| `php webman migrate` | Ausstehende Migrationen ausführen |
| `php webman migrate:rollback` | Letzte Batch zurücksetzen |
| `php webman migrate:status` | Migrationsstatus anzeigen |
| `php webman db:backup` | Datenbank in SQL-Datei sichern (optional --s3-Upload) |

### Hinzugefügte Datenbank-Migrationen (2026-05-20)

| Migration | Tabellen/Spalten |
|-----------|---------------|
| 000003 | users + totp_secret, totp_enabled |
| 000004 | coupons, user_coupons |
| 000005 | help_articles, supplier_api_keys |
| 000006 | roles, permissions, role_permission + Seed-Daten |
| 000007 | users + email_verified_at, email_verify_token |
| 000008 | users + last_login_ip, last_login_at |

## Dokumentationsindex

### Kerndokumente

| Dokument | Pfad | Beschreibung |
|----------|------|-------------|
| Architekturentwurf | `docs/architecture.md` | Systemarchitektur, Komponentenbeziehungen, Middleware-Pipeline, Sicherheitsschichten, Datenarchitektur, Bereitstellungstopologie |
| Funktionsentwurf | `docs/features.md` | Detaillierter Funktionsentwurf der 21 Module, inkl. Flussdiagramme, Datenmodelle, Interaktionsbeschreibungen |
| API-Dokumentation | `docs/api-reference.md` | Vollständige Referenz mit 200+ Endpunkten, nach Modulen gruppiert, inkl. Anfrage-/Antwortbeispiele, Fehlercodes |
| API-Onlinedokumentation (service) | `http://localhost:8787/apidoc` | Automatisch generiert von hg/apidoc, nach Funktionen gruppiert, mit Online-Debugging |
| API-Onlinedokumentation (admin) | `http://localhost:8788/apidoc` | Automatisch generiert von hg/apidoc, 54 Controller in 13 Funktionsgruppen |
| Systemspezifikation | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` | Vollständige Architektur, Datenmodelle, API-Entwurf, Sicherheitsstrategie |
| Admin-Panel-Entwurf | `docs/admin-design.md` | Admin-Panel-Architektur, Paketintegration, ACL-Berechtigungen, Testsuite |
| Anbieter-API-Dokumentation | `docs/supplier-api.md` | Anbieter-API-Referenz (interne API + externe API), SDK-Beispiele |
| Bereitstellungscheckliste | `docs/deployment.md` | Serverkonfiguration, Umgebungsvariablen, Datenbankmigrationen, Nginx, HTTPS, geplante Aufgaben |

### Umsetzungspläne

| Dokument | Pfad | Beschreibung |
|----------|------|-------------|
| Phase 0 — Grundgerüst | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase0.md` | Projektgerüst, Verzeichnisstruktur, Kerninfrastruktur |
| Phase 1 — Benutzer und Shop | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase1.md` | Benutzerauthentifizierung, Produktverwaltung, Warenkorb, Bestellungen |
| Phase 2 — Ressourcen und Anbieter | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase2.md` | Ressourcenbereitstellung, DNS, Anbieter-Onboarding |
| Phase 3 — Client und Auslieferung | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase3.md` | Flutter-Client, Multiplattform-Anpassung, CI/CD |

### Werkzeuge und Ressourcen

| Dokument | Pfad | Beschreibung |
|----------|------|-------------|
| API-Smoke-Test | `docs/api-test.sh` | Automatisiertes Testskript für API-Endpunkte auf curl-Basis |
| Datenbank-DDL | `docs/database.sql` | Datenbank-Tabellen-DDL |

## Abschließende Teststatistiken

```
OK (362 Tests, 579 Assertions)
Testdateien: 22
```
- Admin: 67 Tests, 124 Assertions
- Service: 295 Tests, 455 Assertions
