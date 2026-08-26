# CloudPlatform — Umfassender Audit-Bericht

**Datum:** 2026-08-04  
**Prüfumfang:** Gesamtes Projekt (Codequalität, Sicherheit, Ökosystem-Konfiguration, Deployment, Dokumentation)  
**Branch:** main  
**Letzter Commit:** e321bcc — die 3 verbleibenden Probleme dieser Runde

---

## I. Projektübersicht

| Dimension | Status |
|------|------|
| Projekttyp | PHP 8.2+ / webman Cloud-Ressourcen-Handelsplattform |
| Codeumfang | service (15 Module, 295 tests) + admin (53 Controller, 67 tests) + Flutter + HarmonyOS |
| Datenbank | MySQL 8.0, 46 Tabellen (7 wa_* + 39 erik_*) |
| Deployment | Ein-Klick-Installationsassistent / Docker Compose / manuell |
| Dokumentation | 10 Dokumente + 11 SVG-Architekturdiagramme |

---

## II. Gefundene Probleme

### CRITICAL (schwerwiegend)

#### C1. Docker-Deployment ohne Admin-Backend

**Problem:** Das Dockerfile kopiert nur das Verzeichnis `service/`; docker-compose proxyt nur Port 8787. Das Admin-Backend (admin panel, Port 8788) ist überhaupt nicht dockerisiert.

```dockerfile
# docker/Dockerfile — verarbeitet derzeit nur service
COPY service/ /app/
```

**Auswirkung:** Nutzer, die per Docker deployen, können das Admin-Backend nicht verwenden. Das widerspricht der README-Aussage „Docker Compose Ein-Klick-Start".

**Empfehlung:** Ein Dockerfile für `admin/` hinzufügen oder einen Multi-Stage-Build verwenden, um beide Dienste zu deployen.

---

#### C2. Docker-Datenbankports auf dem Host exponiert

**Problem:** In docker-compose.yml sind die Ports von MySQL (3306) und Redis (6379) direkt auf den Host gemappt:

```yaml
mysql:
  ports:
    - "3306:3306"    # ins öffentliche Netz exponiert
redis:
  ports:
    - "6379:6379"    # ins öffentliche Netz exponiert
```

**Auswirkung:** Wenn der Server eine öffentliche IP hat, sind die Datenbanken von außen erreichbar. Dies ist eine häufige Quelle von Sicherheitsvorfällen.

**Empfehlung:** Das `ports`-Mapping entfernen oder zumindest auf `127.0.0.1:3306:3306` binden. Das interne Docker-Netzwerk kommuniziert bereits ohne Port-Mapping.

---

#### C3. LICENSE-Datei fehlt

**Problem:** Die README erklärt „Lite — MIT License", aber im Projektstamm gibt es keine `LICENSE`-Datei.

**Auswirkung:** Rechtliche Voraussetzungen für Open Source fehlen. GitHub erkennt den Lizenztyp des Projekts nicht.

**Empfehlung:** Im Stammverzeichnis eine `LICENSE`-Datei mit dem Standard-MIT-Lizenztext anlegen.

---

### HIGH (hohe Priorität)

#### H1. Doppelte SQL-Dateien verursachen Verwirrung

**Problem:** Das Projekt enthält 3 SQL-DDL-Dateien:

| Datei | Zeilen | Tabellen | Status |
|------|------|------|------|
| `install.sql` (Stammverzeichnis) | 739 | 46 | **aktuell verwendet** |
| `admin/install.sql` | 152 | 7 (nur wa_*) | Altversion, nicht gelöscht |
| `docs/database.sql` | 629 | 39 (nur erik_*) | Altversion, nicht gelöscht |

**Auswirkung:** Wartende könnten die falsche Datei bearbeiten, was zu Desynchronisation führt.

**Empfehlung:** `admin/install.sql` und `docs/database.sql` löschen oder einen deutlich sichtbaren Deprecation-Hinweis auf `install.sql` im Dateikopf hinzufügen.

---

#### H2. Installationsassistent erstellt keine Audit-Datenbank

**Problem:** `install/index.php` generiert `service/.env` mit der Audit-Datenbankkonfiguration:
```ini
AUDIT_DB_DATABASE=cloud_platform_audit
```
Der Installationsassistent erstellt diese Datenbank aber nie. Versucht die Anwendung nach dem Start Audit-Protokolle zu schreiben, schlägt sie mit `Unknown database` fehl.

**Auswirkung:** Die Audit-Log-Funktion ist nicht nutzbar, Compliance ist betroffen.

**Empfehlung:** In Schritt 4 der Installation `CREATE DATABASE IF NOT EXISTS cloud_platform_audit` ausführen.

---

#### H3. Docker ohne Elasticsearch-Dienst

**Problem:** docker-compose.yml enthält nur die drei Dienste app + mysql + redis. Der README-Technologiestack listet Elasticsearch 8.x ausdrücklich als erforderliche Komponente.

**Auswirkung:** Volltextsuche (Produkte, Nutzer, Bestellungen, Tickets) ist im Docker-Deployment komplett unbrauchbar.

**Empfehlung:** In docker-compose.yml einen Elasticsearch-Dienst hinzufügen.

---

#### H4. PHP-Erweiterungen im Dockerfile unvollständig

**Problem:** Die im Dockerfile installierten PHP-Erweiterungen sind: `gd pdo_mysql zip bcmath redis`. Die Umgebungsprüfung verlangt aber 9 Erweiterungen; es fehlen:
- `intl` (PHP-Internationalisierung)
- `xml` (XML-Parsing)
- `fileinfo` (Dateityperkennung)

**Auswirkung:** Einige Funktionen können in der Docker-Umgebung stillschweigend fehlschlagen.

**Empfehlung:** Fehlende Erweiterungen hinzufügen: `docker-php-ext-install intl xml fileinfo`

---

### MEDIUM (mittlere Priorität)

#### M1. admin/.env.example nicht detailliert genug

**Problem:** service/.env.example (146 Zeilen) vs. admin/.env.example (64 Zeilen); letztere hat deutlich weniger Kommentare und Konfigurationseinträge.

**Empfehlung:** Kommentare in admin/.env.example ergänzen, mindestens kennzeichnen, welche Felder mit der service-Seite übereinstimmen müssen.

---

#### M2. HASHIDS_SALT in .env.example hartcodiert

**Problem:** Beide `.env.example`-Dateien enthalten:
```ini
HASHIDS_SALT=cloud-platform-hashids
```
Wenn Betriebspersonal einfach `cp .env.example .env` ausführt, ohne den Wert zu ändern, teilen alle Instanzen denselben Salt.

**Empfehlung:** In `.env.example` einen Platzhalter verwenden und im Kommentar betonen: „muss einen eindeutigen Zufallswert erzeugen".

---

#### M3. Link der Erfolgsseite des Installationsassistenten ungültig

**Problem:** Die Links der Abschlussseite verwenden `href="#"`, ohne tatsächlich klickbare URL.

**Empfehlung:** Zumindest konkrete URL-/Port-Informationen samt Startbefehl anzeigen.

---

#### M4. Docker enthält keinen Installationsassistenten

**Problem:** Das Dockerfile kopiert weder `install.php` noch das Verzeichnis `install/`. Docker-Nutzer können den Ein-Klick-Installationsassistenten nicht verwenden.

**Empfehlung:** In der Dokumentation klarstellen, dass Docker-Deployment manuelle Konfiguration erfordert, oder den Installationsassistenten ins Image integrieren.

---

#### M5. Docker-Compose-Umgebungsvariablen unvollständig

**Problem:** Die `environment`-Sektion in docker-compose.yml lässt mehrere notwendige Konfigurationen vermissen: JWT-Schlüssel, Hashids-Salt, Verschlüsselungsschlüssel, SMTP, Stripe usw.

**Empfehlung:** Die vollständige Liste der Umgebungsvariablen ergänzen oder auf die `.env`-Datei verweisen.

---

### LOW (niedrige Priorität)

#### L1. Docker-Abschnitt in der Dokumentation schwach

Der Docker-Deployment-Abschnitt der README umfasst nur wenige Zeilen; er erklärt nicht, wie Umgebungsvariablen konfiguriert, die Datenbank initialisiert und das Admin-Backend aufgerufen wird.

**Empfehlung:** Vollständige Docker-Deployment-Dokumentation ergänzen.

---

#### L2. .editorconfig fehlt

**Problem:** Das Projekt hat keine `.editorconfig`-Datei. Bei Projekten mit mehreren Beitragenden sind einheitliche Einrückungs- und Zeilenumbruchseinstellungen wichtig.

**Empfehlung:** Eine Standard-`.editorconfig` hinzufügen: PHP mit 4 Leerzeichen Einrückung, UTF-8, LF-Zeilenumbrüche.

---

#### L3. Hartcodierte Standardwerte im Code zentralisieren

**Problem:** `install/index.php` enthält mehrere hartcodierte Standardwerte (Datenbankhost, Port, Datenbankname, Administratorbenutzername), die leicht übersehen werden können.

**Empfehlung:** Als Konstanten am Dateianfang herausziehen.

---

## III. Vollständigkeit der Ökosystem-Konfiguration

### .env-Variablenabdeckung

| Konfigurationsbereich | service | admin | .env.example |
|--------|:---:|:---:|:---:|
| Datenbankverbindung | ✓ | ✓ | ✓ |
| Audit-Datenbank | ✓ | N/A | ✓ |
| Redis | ✓ | ✓ | ✓ |
| JWT-Authentifizierung | ✓ | N/A | ✓ |
| Hashids | ✓ | ✓ | ✓ |
| Snowflake | ✓ | ✓ | ✓ |
| Transportverschlüsselung (AES-256-GCM) | ✓ | ✓ | ✓ |
| Feldverschlüsselung (AES-128-ECB) | ✓ | ✓ | ✓ |
| SMTP-E-Mail | ✓ | N/A | ✓ |
| Stripe-Zahlung | ✓ | N/A | ✓ |
| Elasticsearch | ✓ | ✓ | ✓ |
| Twilio-SMS | ✓ | N/A | ✓ |
| Firebase-Push | ✓ | N/A | ✓ |
| Klick-CAPTCHA | ✓ | N/A | ✓ |
| Sentry-Monitoring | ✓ | N/A | ✓ |
| Feature Flags | ✓ | N/A | ✓ |
| Schlüsselrotation | ✓ | N/A | ✓ |
| **Bewertung** | **Vollständig** | **Vollständig** | **Vollständig** |

### Konsistenz der vom Installationsassistenten generierten gemeinsamen Schlüssel

| Schlüssel | service | admin | Konsistent |
|------|:---:|:---:|:---:|
| ENCRYPTION_KEY | ✓ | ✓ | ✓ |
| ENCRYPTION_MASTER_KEY | ✓ | ✓ | ✓ |
| HASHIDS_SALT | ✓ | ✓ | ✓ |
| **Bewertung** | **Bestanden** | **Bestanden** | **Bestanden** |

---

## IV. Sicherheitsbewertung

| Prüfpunkt | Status | Beschreibung |
|--------|:--:|------|
| CSRF-Schutz | ✓ | Token-Generierung + hash_equals-Prüfung |
| Session-Sicherheit | ✓ | HttpOnly + SameSite=Strict + strict_mode |
| Eingabevalidierung | ✓ | DB-Namensregex-Prüfung, Portbereichsprüfung |
| Passwortstärke | ✓ | Mindestens 8 Zeichen + Buchstabe + Zahl/Sonderzeichen |
| Passwort-Hashing | ✓ | password_hash(PASSWORD_DEFAULT) |
| Schlüsselgenerierung | ✓ | openssl rand oder random_bytes |
| SQL-Injection-Schutz | ✓ | PDO prepared statements |
| Fehler-Anonymisierung | ✓ | Detaillierte Fehler nur in error_log, Nutzer sehen generische Meldung |
| XSS-Schutz | ✓ | htmlspecialchars()-Ausgabe-Escaping |
| Neuinstallationsschutz | ✓ | Erkennung vorhandener Tabellen + .env-Datei |
| Schritt-Erzwingung | ✓ | session max_step verhindert Überspringen von Schritten |
| Transaktionsverwendung | ✓ | beginTransaction/commit/rollBack |
| Docker-Portexposition | ✗ | MySQL:3306 / Redis:6379 auf Host gemappt |
| Audit-Datenbankerstellung | ✗ | Installationsassistent erstellt die _audit-Datenbank nicht |
| **Gesamtnote** | **A-** | Kern-Sicherheitsmaßnahmen solide, Docker-Konfiguration verbesserungsbedürftig |

---

## V. SQL-Vollständigkeit

| Prüfpunkt | Ergebnis |
|--------|------|
| Tabellen gesamt | 46 (7 wa_* + 39 erik_*) ✓ |
| Engine | Alle InnoDB ✓ |
| Zeichensatz | Alle utf8mb4 ✓ |
| Primärschlüsseltyp | BIGINT UNSIGNED (nicht autoincrement) ✓ |
| CREATE IF NOT EXISTS | Überall verwendet ✓ |
| Destruktive Anweisungen | Keine (kein DROP TABLE) ✓ |
| Alte SQL-Dateien | 2 Altdateien existieren noch, Bereinigung nötig ⚠ |

---

## VI. Testabdeckungsbewertung

| Testsuite | Framework | Tests | Assertions |
|----------|------|:---:|:---:|
| admin/tests/ | PHPUnit 11 | 67 | ~67 |
| service/tests/ | PHPUnit 10 | 295 | 455 |
| CI/CD | GitHub Actions | 3 jobs | PHP 8.2 + 8.3 |

**Bewertung:** Ausreichende Testanzahl (362 Tests), CI/CD deckt Syntaxprüfung für zwei PHP-Versionen + Unit-Tests beider Seiten ab.

---

## VII. Dokumentationsvollständigkeit

| Dokument | Inhalt | Status |
|------|------|:--:|
| README.md | Projektübersicht, Architektur, Schnellstart, API-Überblick | ✓ |
| README_EN.md | Englische README | ✓ |
| docs/architecture.md | Systemarchitektur-Design | ✓ |
| docs/features.md | Funktionsdesign der 12 Module | ✓ |
| docs/api-reference.md | Referenz mit 135+ Endpunkten | ✓ |
| docs/admin-design.md | Admin-Backend-Design | ✓ |
| docs/supplier-api.md | Anbieter-API | ✓ |
| docs/deployment.md | Deployment-Checkliste | ✓ |
| docs/editions.md | Versionsvergleich | ✓ |
| docs/diagrams/ (11 SVG) | Architektur/Sicherheit/Geschäftsprozesse | ✓ |
| LICENSE-Datei | **fehlt** | ✗ |

---

## VIII. Zusammenfassung der Reparaturempfehlungen

### Erste Priorität (vor dem nächsten Release beheben)

| # | Problem | Stufe |
|---|------|:--:|
| 1 | LICENSE-Datei erstellen (MIT) | CRITICAL |
| 2 | Alte SQL-Dateien löschen (admin/install.sql, docs/database.sql) | HIGH |
| 3 | Docker MySQL/Redis-Ports nicht auf Host exponieren | CRITICAL |
| 4 | Installationsassistent erstellt Audit-Datenbank `_audit` | HIGH |

### Zweite Priorität (kurzfristig beheben)

| # | Problem | Stufe |
|---|------|:--:|
| 5 | Docker-Unterstützung für Admin-Backend (admin panel) | CRITICAL |
| 6 | Elasticsearch-Dienst in Docker Compose hinzufügen | HIGH |
| 7 | PHP-Erweiterungen im Dockerfile ergänzen (intl, xml, fileinfo) | HIGH |
| 8 | HASHIDS_SALT in .env.example auf Platzhalter umstellen | MEDIUM |

### Dritte Priorität (kontinuierliche Verbesserung)

| # | Problem | Stufe |
|---|------|:--:|
| 9 | Docker-Deployment-Dokumentation vervollständigen | LOW |
| 10 | .editorconfig hinzufügen | LOW |
| 11 | Hartcodierte Standardwerte im Code bereinigen | LOW |
| 12 | Konfigurationseinträge der .env-Generierungsfunktion vereinheitlichen | LOW |

---

## IX. Fazit

Die Gesamtqualität des Projekts ist gut; die Sicherheitsprobleme des Kern-Installationsassistenten wurden nach der letzten Auditrunde alle behoben. Der Code ist klar organisiert, stark modularisiert und die Dokumentation ist vollständig. Die Hauptprobleme konzentrieren sich auf **unvollständige Docker-Deployment-Konfiguration** — es fehlen Admin-Backend, Suchdienst und PHP-Erweiterungen, und es gibt das Sicherheitsrisiko exponierter Datenbankports.

**Gesamtnote: B+** — Funktionen vollständig, Sicherheitskern vorhanden, die Docker-Ökosystemkonfiguration muss ergänzt werden.
