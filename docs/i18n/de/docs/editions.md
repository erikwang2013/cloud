# Cloud Platform — Globale Cloud-Ressourcen-Handelsplattform

Eine Cloud-Ressourcen-Handelsplattform für globale Nutzer, die den Online-Kauf und die automatische Bereitstellung von Servern (VM), IP-Adressen, Cloud-Datenträgern, Domains und weiteren Produkten unterstützt. Selbst betriebene physische Server werden über Proxmox VE virtualisiert und bereitgestellt; zusätzlich können Drittanbieter einsteigen und Produkte anbieten.

## Versionsübersicht

| | Lite | Standard | Full |
|---|:---:|:---:|:---:|
| **Lizenz** | Open Source (MIT) | Kommerziell | Kommerziell |
| **Kontakt** | GitHub | erik@erik.xyz | erik@erik.xyz |
| **Einsatzszenario** | Persönliche Projekte/Lernen/kleine Shops | Mittelständische Cloud-Anbieter | Große Cloud-Plattformen/multi-Provider |

---

## I. Funktionsvergleich

### 1.1 Nutzersystem

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Registrierung/Login per E-Mail/Telefon | ✅ | ✅ | ✅ |
| JWT-Authentifizierung (Access + Refresh) | ✅ | ✅ | ✅ |
| Passwort zurücksetzen | ✅ | ✅ | ✅ |
| Geräte-Fingerprint-Bindung + Token-Rotation | ❌ | ✅ | ✅ |
| Login-Sperre (5 Fehlversuche sperren 15 min) | ❌ | ✅ | ✅ |
| Google-OAuth-Login | ❌ | ✅ | ✅ |
| Apple Sign In | ❌ | ✅ | ✅ |
| TOTP-Zwei-Faktor + Wiederherstellungscodes | ❌ | ✅ | ✅ |
| E-Mail-Verifizierung | ❌ | ✅ | ✅ |
| SMS-Verifizierungscode | ❌ | ✅ | ✅ |
| Sitzungsverwaltung (anzeigen/widerrufen) | ✅ | ✅ | ✅ |
| GDPR-Konto löschen | ✅ | ✅ | ✅ |
| Profilverwaltung | ✅ | ✅ | ✅ |
| KYC-Identitätsverifizierung | ❌ | ✅ | ✅ |
| Adressverwaltung | ❌ | ✅ | ✅ |
| Guthabenkonto | ❌ | ✅ | ✅ |
| Login-Alarm bei neuer IP | ❌ | ✅ | ✅ |
| Client-Plattform-Erkennung | ❌ | ✅ | ✅ |
| Mehrsprachigkeit (i18n, 120 Einträge) | ✅ | ✅ | ✅ |

### 1.2 Produktsystem

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Produktliste (Kategorie-/Regionsfilter) | ✅ | ✅ | ✅ |
| Produktdetails (mit SKU + Regionalpreisen) | ✅ | ✅ | ✅ |
| Elasticsearch-Volltextsuche | ✅ | ✅ | ✅ |
| Produktbewertungen (Sterne + Text) | ✅ | ✅ | ✅ |
| Produktattribute | ❌ | ✅ | ✅ |
| Klick-CAPTCHA | ❌ | ✅ | ✅ |
| Massenimport/-export (CSV) | ❌ | ✅ | ✅ |

### 1.3 Bestellsystem

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Warenkorb (CRUD) | ✅ | ✅ | ✅ |
| Bestellung aufgeben | ✅ | ✅ | ✅ |
| Bestellliste + Details | ✅ | ✅ | ✅ |
| Gutscheine | ❌ | ✅ | ✅ |
| Rechnungen (Erzeugung + PDF-Download) | ❌ | ✅ | ✅ |
| Rückerstattung | ❌ | ✅ | ✅ |

### 1.4 Zahlungssystem

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Stripe-Zahlung | ❌ | ✅ | ✅ |
| Multi-Kanal-Routing | ❌ | ✅ | ✅ |
| Webhook-Signaturprüfung | ❌ | ✅ | ✅ |
| Täglicher Abgleich | ❌ | ✅ | ✅ |
| Mehrwährungs-Wechselkurse | ❌ | ✅ | ✅ |
| Rückerstattung auf Originalweg | ❌ | ✅ | ✅ |

### 1.5 Ressourcenbereitstellung

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Proxmox-VE-Virtualisierung | ❌ | ✅ | ✅ |
| Server (VM) voller Lebenszyklus | ❌ | ✅ | ✅ |
| Cloud-Datenträger (Erstellen/Erweitern) | ❌ | ✅ | ✅ |
| IP-Pool-Verwaltung + Zuweisung | ❌ | ✅ | ✅ |
| Host-Auswahlstrategie (Lastverteilung) | ❌ | ✅ | ✅ |
| Online-Upgrade von CPU/RAM/Datenträger | ❌ | ✅ | ✅ |
| VNC-Konsole | ❌ | ✅ | ✅ |
| Asynchrone Bereitstellungswarteschlange | ❌ | ✅ | ✅ |
| Retry-Strategie (6 Versuche mit Backoff) | ❌ | ✅ | ✅ |
| Provider-Plug-in-Architektur | ❌ | ✅ | ✅ |
| Ressourcen-Ablaufüberwachung | ❌ | ✅ | ✅ |

### 1.6 Domains und DNS

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Domain-Verfügbarkeitsprüfung | ❌ | ✅ | ✅ |
| TLD-Preisverwaltung | ❌ | ✅ | ✅ |
| DNS-Record-Verwaltung | ❌ | ✅ | ✅ |
| Domain-Transfer-Genehmigung | ❌ | ✅ | ✅ |

### 1.7 Ticket-System

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Ticket erstellen/beantworten | ❌ | ✅ | ✅ |
| Ticketliste + Details | ❌ | ✅ | ✅ |
| Support-Zuweisung | ❌ | ✅ | ✅ |
| SLA-Tracking | ❌ | ✅ | ✅ |
| Automatische Zuweisung (Lastverteilung) | ❌ | ✅ | ✅ |

### 1.8 Benachrichtigungssystem

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| E-Mail-Benachrichtigungen | ❌ | ✅ | ✅ |
| SMS-Benachrichtigungen (Twilio) | ❌ | ✅ | ✅ |
| App-Push (FCM) | ❌ | ✅ | ✅ |
| In-App-Nachrichten | ❌ | ✅ | ✅ |
| Benachrichtigungsvorlagenverwaltung | ❌ | ✅ | ✅ |
| Benachrichtigungspräferenzen der Nutzer | ❌ | ✅ | ✅ |

### 1.9 Admin-Backend

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Dashboard | ✅ | ✅ | ✅ |
| Nutzerverwaltung (Liste/Details/Status) | ✅ | ✅ | ✅ |
| Produktverwaltung (CRUD) | ✅ | ✅ | ✅ |
| Bestellverwaltung (Liste/Details) | ✅ | ✅ | ✅ |
| Audit-Protokolle | ✅ | ✅ | ✅ |
| KYC-Prüfung | ❌ | ✅ | ✅ |
| SKU + Regionalpreisverwaltung | ❌ | ✅ | ✅ |
| Zahlungskanalverwaltung + Transaktionsverlauf | ❌ | ✅ | ✅ |
| Überwachung der Bereitstellungsaufgaben | ❌ | ✅ | ✅ |
| Serververwaltung | ❌ | ✅ | ✅ |
| Ticket-Zuweisung/-Schließung | ❌ | ✅ | ✅ |
| Domain-TLD + DNS-Zonenverwaltung | ❌ | ✅ | ✅ |
| Benachrichtigungsvorlagenverwaltung | ❌ | ✅ | ✅ |
| Gutscheinverwaltung | ❌ | ✅ | ✅ |
| Hilfeartikelverwaltung | ❌ | ✅ | ✅ |
| Webhook-Verwaltung | ❌ | ✅ | ✅ |
| Cloud-Anbieter-API-Verwaltung | ❌ | ✅ | ✅ |
| Produktimport/-export | ❌ | ✅ | ✅ |
| Nutzer-/Bestell-/Anbieterexport | ❌ | ✅ | ✅ |
| Berichte (Umsatz/Regionen) | ❌ | ✅ | ✅ |
| Monitoring-Panel + Ressourcenmetriken | ❌ | ✅ | ✅ |
| Anbieterverwaltung | ❌ | ❌ | ✅ |
| Anbieter-API-Key-Verwaltung | ❌ | ❌ | ✅ |
| Feature-Flags-Schalter | ❌ | ❌ | ✅ |

### 1.10 Anbietersystem

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Anbieter-Onboarding + Genehmigung | ❌ | ❌ | ✅ |
| Produkte anbieten + Provisionen | ❌ | ❌ | ✅ |
| Abrechnung (wöchentlich/monatlich) | ❌ | ❌ | ✅ |
| Auszahlungsantrag + Genehmigung | ❌ | ❌ | ✅ |
| Externe API (API-Key-Authentifizierung) | ❌ | ❌ | ✅ |
| Anbieter-Datenisolation | ❌ | ❌ | ✅ |

### 1.11 Echtzeitkommunikation

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| WebSocket-Echtzeit-Push | ❌ | ❌ | ✅ |
| Sentry-Fehlerüberwachung | ❌ | ❌ | ✅ |
| k6-Lasttest-Skripte | ❌ | ✅ | ✅ |

### 1.12 SSL-Zertifikate

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| SSL-Zertifikatkauf (DV/OV/EV) | ❌ | ❌ | ✅ |
| Let's-Encrypt-Automatikausstellung | ❌ | ❌ | ✅ |
| Automatische Verlängerung (14 Tage vor Ablauf) | ❌ | ❌ | ✅ |
| Zertifikat-Download (PEM/KEY) | ❌ | ❌ | ✅ |
| SSL-Planverwaltung (Admin) | ❌ | ❌ | ✅ |

### 1.13 Objektspeicher

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| S3-kompatibler Objektspeicher | ❌ | ❌ | ✅ |
| MinIO-Eigenbetrieb | ❌ | ❌ | ✅ |
| Presigned Upload/Download-URLs | ❌ | ❌ | ✅ |
| Speicherkontingentverwaltung | ❌ | ❌ | ✅ |

### 1.14 CDN-Beschleunigung

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| CDN-Domainverwaltung | ❌ | ❌ | ✅ |
| Cache-Purge | ❌ | ❌ | ✅ |
| Ursprungstyp (Server/Speicher) | ❌ | ❌ | ✅ |
| Cloudflare-Integration | ❌ | ❌ | ✅ |

### 1.15 Nutzungsbasierte Abrechnung

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Abrechnung nach Stunden/Traffic | ❌ | ❌ | ✅ |
| Nutzungserfassung und -aggregation | ❌ | ❌ | ✅ |
| Automatische Guthabenbelastung | ❌ | ❌ | ✅ |
| Aussetzung/Wiederaufnahme bei Zahlungsrückstand | ❌ | ❌ | ✅ |

### 1.16 Anbieterbewertung

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Vierdimensionale Bewertung (Qualität/Support/Lieferung/Wert) | ❌ | ❌ | ✅ |
| Nur für Käufer | ❌ | ❌ | ✅ |
| Bewertungsprüfung (Admin) | ❌ | ❌ | ✅ |
| Durchschnittsbewertung der Anbieter | ❌ | ❌ | ✅ |

### 1.17 Empfehlungsprogramm

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Empfehlungslink-Generierung | ❌ | ❌ | ✅ |
| Bestellattribution (ref-Parameter) | ❌ | ❌ | ✅ |
| Provisionsberechnung und -auszahlung | ❌ | ❌ | ✅ |
| Programmverwaltung (Admin) | ❌ | ❌ | ✅ |

### 1.18 GraphQL

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| GraphQL-Endpunkte (öffentlich + authentifiziert) | ❌ | ❌ | ✅ |
| Produkt-/Bestell-/Ressourcenabfragen | ❌ | ❌ | ✅ |
| Abfragetiefenbegrenzung | ❌ | ❌ | ✅ |

### 1.19 Observability

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Prometheus-Metrikexport | ❌ | ❌ | ✅ |
| Grafana-Dashboards | ❌ | ❌ | ✅ |
| Alarmregeln (Queue/Fehlerrate/Latenz) | ❌ | ❌ | ✅ |
| Health-Checks (live/ready/deps) | ❌ | ❌ | ✅ |
| i18n 7 Sprachen (550+ Einträge) | ❌ | ❌ | ✅ |

### 1.20 Clients

| Funktion | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Flutter-Client | ❌ | ❌ | ✅ |
| HarmonyOS-Client | ❌ | ❌ | ✅ |

---

## II. Architekturvergleich

### 2.1 Middleware

| Middleware | Lite | Standard | Full |
|--------|:---:|:---:|:---:|
| CorsMiddleware (CORS) | ✅ | ✅ | ✅ |
| LocaleMiddleware (Mehrsprachigkeit) | ✅ | ✅ | ✅ |
| HashidRequestMiddleware (ID-Decodierung) | ✅ | ✅ | ✅ |
| AuthMiddleware (JWT-Authentifizierung) | ✅ | ✅ | ✅ |
| RateLimitMiddleware (Rate-Limiting) | ✅ | ✅ | ✅ |
| WafMiddleware Basis (SQLi/XSS) | ✅ | ✅ | ✅ |
| WafMiddleware Voll (8 Kategorien, 45+ Regeln) | ❌ | ✅ | ✅ |
| AdminRoleMiddleware (RBAC) | ❌ | ✅ | ✅ |
| EncryptionMiddleware (AES-256-GCM) | ❌ | ✅ | ✅ |
| VersionMiddleware (API-Version) | ❌ | ✅ | ✅ |
| ClientPlatformMiddleware (Plattformerkennung) | ❌ | ✅ | ✅ |
| ConfirmationMiddleware (Passwortbestätigung) | ❌ | ✅ | ✅ |
| GeoBlockMiddleware (Geo-Sperre) | ❌ | ✅ | ✅ |
| MaintenanceMiddleware (Wartungsmodus) | ❌ | ✅ | ✅ |
| SupplierApiKeyMiddleware | ❌ | ❌ | ✅ |
| FeatureFlags | ❌ | ❌ | ✅ |
| RbacMiddleware | ❌ | ✅ | ✅ |

### 2.2 Datenarchitektur

| Merkmal | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Snowflake-verteilte Primärschlüssel | ✅ | ✅ | ✅ |
| Hashids-ID-Verschleierung | ✅ | ✅ | ✅ |
| MySQL-Einzeldatenbank | ✅ | ❌ | ❌ |
| MySQL-Master-Slave-Lese-/Schreibtrennung | ❌ | ✅ | ✅ |
| Separate Audit-Datenbank | ❌ | ✅ | ✅ |
| AES-256-GCM-Transportverschlüsselung | ❌ | ✅ | ✅ |
| AES-128-ECB-Feldverschlüsselung | ❌ | ✅ | ✅ |
| Redis-Mehrstufencache | ❌ | ✅ | ✅ |
| Elasticsearch-Volltextsuche | ✅ | ✅ | ✅ |
| Datenbankindex-Optimierung (13) | ❌ | ✅ | ✅ |

### 2.3 Sicherheit

| Merkmal | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| SQL-Injection-Erkennung (2 Regeln) | ✅ | ✅ | ✅ |
| XSS-Erkennung (3 Regeln) | ✅ | ✅ | ✅ |
| Command-Injection-Erkennung | ❌ | ✅ | ✅ |
| File-Inclusion-Erkennung | ❌ | ✅ | ✅ |
| HTTP-Header-Injection-Erkennung | ❌ | ✅ | ✅ |
| SSRF-Erkennung | ❌ | ✅ | ✅ |
| NoSQL-Injection-Erkennung | ❌ | ✅ | ✅ |
| Open-Redirect-Erkennung | ❌ | ✅ | ✅ |
| Anfragekörpergrößenbegrenzung | ❌ | ✅ | ✅ |
| Content-Type-Whitelist | ❌ | ✅ | ✅ |

### 2.4 Hohe Parallelität

| Merkmal | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| webman-Multiprozess | ✅ | ✅ | ✅ |
| Nginx-gzip-Komprimierung | ❌ | ✅ | ✅ |
| Nginx-proxy-buffering | ❌ | ✅ | ✅ |
| Nginx limit_req/limit_conn | ❌ | ✅ | ✅ |
| Redis-Cache-Schicht | ❌ | ✅ | ✅ |
| Aktive Cache-Invalidierung | ❌ | ✅ | ✅ |
| MySQL-Lese-/Schreibtrennung | ❌ | ✅ | ✅ |
| Zusammengesetzte Datenbankindizes | ❌ | ✅ | ✅ |
| WebSocket-Push | ❌ | ❌ | ✅ |

---

## III. Deployment und Betrieb

| Merkmal | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Docker-Compose-Deployment | ✅ | ✅ | ✅ |
| Nginx-Reverse-Proxy | ✅ | ✅ | ✅ |
| CI/CD (GitHub Actions) | ✅ | ✅ | ✅ |
| PHPUnit-Tests | 95 tests | 295 tests | 295 tests |
| Scheduled Tasks (7) | ❌ | ✅ | ✅ |
| Redis-Queue-Async-Verarbeitung | ❌ | ✅ | ✅ |
| Datenbank-Migrationsbefehl | ✅ | ✅ | ✅ |
| Datenbank-Backup-Befehl | ❌ | ✅ | ✅ |
| Health-Check-Endpunkte | ✅ | ✅ | ✅ |
| Dienststatus-Endpunkte | ✅ | ✅ | ✅ |
| Sentry-Fehlerüberwachung | ❌ | ❌ | ✅ |
| Feature-Flags-Canary-Release | ❌ | ❌ | ✅ |
| k6-Lasttests | ❌ | ❌ | ✅ |

---

## IV. Kennzahlen

| Metrik | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| API-Endpunkte | ~35 | ~130 | 200+ |
| Datenmodelle | 15 | 50+ | 70+ |
| Datenbanktabellen | 15 | 50+ | 60+ |
| Globale Middlewares | 3 | 7 | 9 |
| Routen-Middlewares | 1 | 5 | 6 |
| Scheduled Tasks | 0 | 7 | 10 |
| Migrationsdateien | 5 | 20 | 27 |
| Testanzahl | 95 | 295 | 295 |
| WAF-Regeln | 5 | 45+ | 45+ |
| Dokumentanzahl | 2 | 6 | 8 |
| hg/apidoc-Onlinedokumentation | ✅ | ✅ | ✅ |
| GraphQL-API-Endpunkte | ❌ | ❌ | ✅ |
| Prometheus-Metriken | ❌ | ❌ | ✅ |
| Anbieter-Bewertungssystem | ❌ | ❌ | ✅ |
| Affiliate-Empfehlungssystem | ❌ | ❌ | ✅ |

---

## V. Upgrade-Pfad

```
Lite
  │
  │  + Zahlung + Bereitstellung + Domains + Tickets + Benachrichtigungen
  │  + Vollständiges Admin-Backend + Komplettes Sicherheitspaket + Optimierung für hohe Parallelität
  ▼
Standard
  │
  │  + Anbietersystem + Externe API + WebSocket
  │  + Sentry + Feature Flags + Flutter-Client
  ▼
Full
```

**Datenkompatibilität:** Die Datenbankstruktur von Lite ist mit den Kerntabellen von Standard kompatibel und kann direkt migriert/upgegradet werden. Der Wechsel von Standard zu Full ist rein inkrementell (neue anbieterspezifische Tabellen), keine Datenmigration erforderlich.

---

## VI. Bezug

| Version | Bezug |
|------|---------|
| **Lite** | GitHub Open Source, MIT-Lizenz |
| **Standard** | Kommerzielle Lizenz, Kontakt **erik@erik.xyz** |
| **Full** | Kommerzielle Lizenz, Kontakt **erik@erik.xyz** |
