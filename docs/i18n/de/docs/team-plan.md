# CloudPlatform-Teamplanung

> Version: 2026-08-17 (v2) ｜ v1 wurde von einer Multi-Agenten-Pipeline erstellt (PASS_WITH_FIXES); v2 wurde vom Lead auf Basis der tatsächlichen Ergebnisse von Phase 0-2 aktualisiert
> Grundlage: v1 + alle Commits von Phase 0-2 (git 111 commits) + Zweipersonen-Review-Protokolle + gemessene Testbasis

## 1. Ist-Zustand (2026-08-17)

### 1.1 Phasenfortschritt

| Phase | Status | Wichtigste Ergebnisse |
|------|------|----------|
| Phase 0 Sofortmaßnahmen | ✅ 4/4 | Echte Rechnungsdarstellung, 6 Typen von Benachrichtigungsvorlagen, explizites unverified bei Abgleich, CSP-Header/Umgebungsvorlagen |
| Phase 1 Kurzfristig | ✅ 8/8 | Warenkorb mit Mengenänderung, vereinheitlichter Bewertungsstatus, realer Abgleich (Stripe-Berichte + tagesweise), Rückerstattungsbedingungsprüfung (72h/5 Tage + Idempotenz + TOCTOU-Index), 7 Anbieter-Webhook-Typen, Feature-Flags-Verdrahtung + Verwaltung, Dokumentationssynchronisation, echte Tests |
| Phase 2 Mittelfristig | ✅ 8/8 | 4 Geldwächter, service/admin-Testschulden, install.sql 31 Tabellen, RbacMiddleware an 57 Routen, admin im Image + nginx 8788 + CI beidseitig, audit-Regression + vollständiger Login-Kette |
| Phase 3 Langfristig | ✅ 9/9 | Gateway + einheitliches Rate-Limiting (P4.1), Mehrwährung-End-to-End (P4.2), HarmonyOS-Engineering + CI (P4.3), ES-Umsetzung (P4.4), Beobachtungspunkte verdaut (P4.5), 4 Dokumentationsabweichungen (P3.1), Berechtigungskonsolidierung (P3.2), Bestell-Idempotenzschlüssel (P3.3), Anbieterbewertungsprüfung (P3.4), i18n 7 Sprachen (P3.6); reviewer-gate unabhängiges Review: alle approved |

### 1.2 Qualitätsbasis (gemessen, sequenzielle Verifikation nach Commit)

- service-Suite: **568 tests / 1279 assertions**, 10 skip (alles DB-Umgebungslücken)
- admin-Suite: **255 tests / 887 assertions**, 1 skip (DB-Schreibpfad)
- CI 6 Jobs: PHP Syntax / Admin Tests / Service Tests / Flutter Build / HarmonyOS Project Check / (docker-bezogen)
- Geld/Sicherheit alle durch Zweipersonen-Review (security-auditor + reviewer unabhängig identische Ergebnisse); git commits nach Aufgaben gruppiert, Arbeitsbaum sauber
- Zusätzlich: 9 Encryptable-Modelle mit verborgenen Anmeldedaten bei Serialisierung (P1/P2 vollständig geprüft)

## 2. Rückstände und Risikoliste (Review 2026-08-17)

### 2.1 Deployment-blockierende Punkte (hohe Priorität)

- **DB_PASSWORD-Umgebungslücke**: service/.env ist leer → alle DB-Endpunkte 500, Grundursache der 9+1 Skip-Tests. Kein Code-Problem, Betrieb muss Werte eintragen (Vorlage bereits in der Root-.env.example)
- **Fehlendes HarmonyOS-Projektgerüst**: apps/harmonyos enthält nur 3 .ets (LoginPage/AuthManager/ApiClient), es fehlt die gesamte hvigor/DevEco-Projektkonfiguration → nicht baubar; CI harmonyos-check meldet ehrlich Fehler (exit 1)

### 2.2 Dokument-Code-Abweichungen (4 offene Punkte P1)

- GET /api/orders Statusfilter nicht implementiert
- WebSocket-Push-Ereignisse fehlen (in der websocket_push-Dokumentation deklariert)
- ticket.updated-Auslösebereich unklar
- product_attributes totes Schema (kein Code nutzt es)

### 2.3 Geld-/Sicherheits-Beobachtungspunkte (Zweipersonen-Review-Protokoll, niedrige Stufe)

- **Bestellungen ohne Idempotenzschlüssel**: erneutes Absenden desselben Warenkorbs kann Doppelbestellungen erzeugen (medium, Einplanung empfohlen)
- Anbieterbewertung prüft nicht Bestellzuordnung/Status
- fee-bcmath-Trunkierung (5. Nachkommastelle, Richtung: zu wenig eingezogen <0.0001/Transaktion; konsistent mit Routing, keine Abgleichsabweichung)
- WAF-Multipart-Großkörper liest weiterhin raw (json-Szenario durch $input abgedeckt, multipart ist zusätzliche Verteidigungsfläche)
- user_coupons ohne Unique-Constraint (semantisch erlaubt ein Nutzer mehrere Bestellungen/Zeilen, beobachten)
- nginx-admin ohne CSP (admin ist Layui-Frontend mit Inline-Skripten, aktuellen Zustand beibehalten)

### 2.4 Berechtigungsmodell-Inkonsistenzen (neu in P2 entdeckt, zu konsolidieren)

- DB-only 6 Berechtigungskennzeichen / Rbac-only 19 / Rollenzuweisungsunterschiede (support/supplier)
- AdminRoleMiddleware schließt finance aus, während Rbac.php die Rolle finance definiert

### 2.5 Sonstiges

- i18n neue Sprachdateien sind englische Originaltexte (T6), 7 Sprachen unvollständig
- HarmonyOS-CI-Strukturprüfung soll nach Fertigstellung des Gerüsts auf echten hvigor-Build aufgewertet werden

## 3. Roadmap

Prioritätsprinzip (unverändert): **Geld/Sicherheit > Bereitstellungszuverlässigkeit > Kern-Geschäftszyklus > Erlebnis und Erweiterung**.

### Phase 3 — Restliche Abschlussarbeiten (1 Monat)

**Ziel**: Alle Abweichungen und Beobachtungspunkte schließen, Deployment reproduzierbar (DB-End-to-End-Tests real grün).

| Aufgabe | Beteiligt | Rolle | Abhängigkeit |
|------|------|------|------|
| 4 Dokument-Code-Abweichungen abschließen (orders-Statusfilter implementieren / WebSocket-Push verdrahten / ticket.updated korrigieren / product_attributes löschen oder umsetzen) | Order, WebSocket, Ticket, Product, docs | coder + researcher | keine |
| Berechtigungsmodell konsolidieren (DB/Rbac-Differenzen angleichen + Rollenseeds + AdminRoleMiddleware-Review) | Rbac, install.sql, admin | coder + security-auditor | keine |
| Bestell-Idempotenzschlüssel (cart→order Doppelbestellung verhindern) | OrderService | coder | keine (Geld: Zweipersonen-Review) |
| Anbieterbewertung: Bestellzuordnung/Status prüfen | Supplier, Review | coder | keine |
| DB_PASSWORD betrieblich anbinden + 10 Skip-Tests real ausführen | Betrieb, tests | security-auditor | Betriebskooperation |
| i18n 7-Sprachen-Übersetzung vervollständigen | i18n-Dateien | coder | keine |

**Abnahme**: 4 Abweichungen geschlossen; Berechtigungsmatrix DB/Code konsistent; Idempotenzschlüssel-Tests; DB-End-to-End-Tests real grün; i18n mindestens Chinesisch/Englisch nutzbar.

### Phase 4 — Architekturentwicklung (1-3 Monate)

**Ziel**: Vierschichtarchitektur etabliert, unterstützt Multi-Client-Multi-Währungs-Wachstum.

| Aufgabe | Beteiligt | Rolle | Abhängigkeit |
|------|------|------|------|
| Eigenständiges API-Gateway + einheitliches Rate-Limiting (inkl. graphql-Lücke) | gateway, route | architect + coder | P3 |
| Mehrwährungs-End-to-End-Konsistenz (inkl. fee-Rundungsstrategie) | Payment, Billing | architect + performance-engineer | wie oben |
| HarmonyOS-Engineering: Gerüst + echter CI-Build + Login durchgängig | apps/harmonyos | mobile-dev | keine |
| ES-Audit-Umsetzung, Workaround ersetzen | docker, Product-Suche | coder | keine |
| Beobachtungspunkte batchweise verdauen (WAF multipart / user_coupons-Constraint / Anbieter-Webhook-End-to-End) | Security, Order, Supplier | coder + tester | keine |

**Abnahme**: k6 verifiziert Rate-Limiting auf allen Routen; Mehrwährungsabrechnung null Fehler; HarmonyOS baut über CI; ES-Suche real nutzbar.

## 4. Teamaufteilung

Fester Kern: Lead(planner) / architect / coder / tester / reviewer / researcher
Bei Bedarf: mobile-dev / security-architect / security-auditor / performance-engineer

| Phase | Hinzugezogene Rollen | Beschreibung |
|------|----------|------|
| P3 | coder (Hauptkraft), researcher, security-auditor | Abschlussarbeiten; Berechtigungen/Idempotenz mit Zweipersonen-Review |
| P4 | architect, coder, mobile-dev, performance-engineer | Architekturentwicklung; security-architect als ständiger Berater |

Kollaborationsmodell unverändert: CLAUDE.md-Pipeline (architect→coder→tester→reviewer), P3/P4-interne Aufgaben als Fan-out parallel; **Geld-/Sicherheitsaufgaben zwingend mit Zweipersonen-Review**; am Ende jeder Phase dieses Dokument aktualisieren (diese v2 wurde direkt vom Lead erstellt, nicht über die Pipeline, kann geprüft werden).

## 5. Risiko-Tracking-Methode

- Diese Liste wird am Ende jeder Phase rollierend aktualisiert; neue Funde (z. B. Berechtigungsmodell-Inkonsistenzen, Bestell-Idempotenz aus P2) werden sofort aufgenommen
- Bekannte niedrige Prioritäten (Anbieter-Webhook-End-to-End, multipart body) sind im P4-Verdauungsbatch, breiten sich nicht außerhalb der Liste aus

## 6. Hauptnachweise

- Commits: git log (111 commits, Phase 0-2 nach Aufgaben gruppiert)
- Testbasis: gemessene Ausgaben der service/admin-Suiten
- Review-Protokolle: Zweipersonen-Review-Nachrichten P1/P2 (Geldwächter, logout/WAF, RBAC, audit-Regression)
- Dokumente: v1 (docs/team-plan.md Verlauf), docs/audit-report-2026-08-06-v3.md, docs/api-reference.md
