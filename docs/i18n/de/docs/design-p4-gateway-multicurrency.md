# P4.1 + P4.2 Design: Unabhängiges API-Gateway / einheitliches Rate-Limiting + Multiwährungs-Konsistenz über die gesamte Kette

> Version: 2026-08-17 v1 | Architekten-Ergebnis, zur Implementierung durch gateway-impl / multicurrency-impl, geprüft durch reviewer-gate
> Grundlage: docs/team-plan.md v2 Phase 4, docs/architecture.md, tatsächliche Codeprüfung

---

## P4.1 Unabhängiges API-Gateway + einheitliches Rate-Limiting

### Ist-Zustand (per Codeprüfung bestätigt)

| Ebene | Ist-Zustand |
|----|------|
| Edge-Gateway | docker/nginx.conf übernimmt das service-L7-Gateway: `limit_req_zone api 10r/s` (globales Rate-Limiting), proxy_pass 8787 (service), 8282 (ws). **admin ist ein separater Container** (Dockerfile admin target, nginx-admin.conf listen 8788 proxy 8788), **ohne limit_req** |
| App-Level-Limiting | `service/common/security/RateLimitMiddleware.php` existiert: Redis INCR+expire Fixed-Window, **nur per-IP**, Regelauswahl über `ROUTE_MAP`, angehängt an **explizite Routen** (route.php insgesamt ~12 Stellen) |
| Regelkonfiguration | `config/security.php rate_limits`: default/login/register/password_reset/oauth/captcha/sms/pay/upload/supplier_api/graphql, alle mit rate/burst/per, aber das **burst-Feld wird derzeit nicht verwendet** |
| Globale Middleware | `config/middleware.php` `''`-Key unterstützt bereits die Wirkung auf alle Routen (WAF/GeoBlock/Security usw., 10 Einträge hier) |
| Lücken | `/graphql` (public + authenticated, zwei Routen) **ohne jegliches Rate-Limiting**; per-Token-Limiting existiert nicht; 429-Antworten ohne `Retry-After`-Header; webhook ohne Ausnahme/eigene Regel |

### Entscheidungen

**D1: Keinen separaten Gateway-Prozess neu einführen.** nginx ist das Gateway (Netzwerkkante + Rate-Limiting + Routenverteilung), webman übernimmt das einheitliche Rate-Limiting.
- Begründung: Ein eigenständiger Gateway-Container bräuchte neue Abhängigkeiten/neue Deployment-Topologie/doppelte Authentifizierung — bei der aktuellen Single-Instance-Größe Überengineering;
- Abwägung: Token-/routendifferenziertes Limiting ist auf Gateway-Ebene nicht möglich (nginx hat nur per-IP-Zonen). Die Differenzierung übernimmt die Anwendungsebene, die nginx-Ebene behält nur grobkörniges IP-Limiting als Fallback (bestehende 10r/s auf 100r/s erhöhen, um Geschäftsfälle nicht zu beeinträchtigen; bei k6-Verifikation auf Demo-Schwellenwerte zurücksetzen).
- Evolutionspfad: Bei künftig mehreren Instanzen/Diensten den globalen Limiter aus `config/middleware.php` unverändert in einen separaten Gateway-Dienst übernehmen — die Middleware ist sich der Deployment-Form nicht bewusst.

**D2: Einheitliches Rate-Limiting = globale Middleware + zweidimensionale Buckets (per-IP + per-Token).**
- `RateLimitMiddleware` aus den expliziten Routen entfernen (route.php tatsächlich ~12 Stellen, grep maßgeblich), in die `''`-Globalliste von `config/middleware.php` aufnehmen (nach WAF, vor den Business-Middlewares) — **deckt damit natürlich alle App-Routen ab (einschließlich beider /graphql-Routen)**.
- **Bucket-Semantik (eindeutig, gegen Umgehung)**: `ratelimit:ip:{realIp}:{rule}` und `ratelimit:tok:{sha256(token)}:{rule}` als zwei unabhängige Buckets gezählt, **jeder Bucket über dem Limit ergibt 429 (OR)**. AND-Implementierung verboten — bei AND kann ein IP-Wechsel den per-IP-Bucket und ein Token-Wechsel den per-Token-Bucket umgehen.
- **Ausnahmeliste**: `/health*` (Monitoring-Probes) und `/api/payments/webhook/stripe` (Signaturprüfung ist die echte Verteidigungslinie + Stripe 429 Auto-Backoff-Retry + nginx grobkörniges 100r/s-Fallback bleibt wirksam; Rate-Limiting bringt keinen Sicherheitsgewinn, nur Event-Verlust/verzögerte Gutschrift). Alle übrigen Routen sind zwingend limitiert.
- Antwort: `HTTP 429` + `Retry-After`-Header (Restlaufzeit der zwei Bucket-Windows, **max** nehmen; Fixed-Window mit Redis `PTTL` exakt ermitteln) + Body `{code:429, message, retry_after}` (konsistent mit bestehendem `Response::error`).
- Burst: burst-Feld aktivieren — `rate` ist die stabile Quote im Window, `burst` das überziehbare Kontingent. Implementiert als Redis-Key-Zähllimit `rate + burst` (Überziehung im Fixed-Window), kein Sliding-Window nötig (ponytail: Fixed-Window hat an den Grenzen eine 2-fache Window-Aufweitung, für per-IP gegen Einzelmaschinen-Missbrauch ausreichend; bei strengeren Anforderungen auf Sliding-Window umstellen).
- Routing→Regel-Mapping: bestehende `ROUTE_MAP` behalten, `'/graphql' => 'graphql'` ergänzen (config/security.php:46 hat bereits `{rate:30, burst:5, per:60}`); unbekannte Routen nutzen `default` (60/60s).
- Redis nicht verfügbar: bestehendes fail-open beibehalten (catch Exception, durchlassen) — nginx 100r/s grobkörniges Fallback bleibt wirksam.
- **Umfang**: nur der service-Container. admin ist ein separater Container (nginx-admin.conf ohne limit_req, aktuell unlimitiert), service/config und service-Middleware-Änderungen berühren admin nicht — admin-Limiting ist nicht in P4.1 enthalten, wird separat entschieden.

**D3: Rate-Limiting vor der Authentifizierung.** Die globale Middleware liegt vor AuthMiddleware (Reihenfolge in middleware.php ist die Ausführungsreihenfolge), daher degeneriert der per-Token-Bucket für Anfragen ohne Token zu einem per-IP-Bucket; Anfragen mit Token zählen auch bei anonymen Pfaden (z. B. /api/products) in den Token-Bucket — schützt vor gemeinsam genutztem Token-Missbrauch.

### Auswirkungsfläche

| Punkt | Änderung |
|----|------|
| `service/common/security/RateLimitMiddleware.php` | Umbau: per-Token-Bucket, burst, Retry-After, graphql-Regel |
| `service/config/middleware.php` | `''`-Liste um RateLimitMiddleware ergänzen; alle expliziten Mounts aus route.php entfernen |
| `service/config/security.php` | `default` {60,10,60} unverändert lassen (Abnahmeschwelle = rate+burst = 70); `graphql` {30,5,60} existiert bereits, nichts hinzufügen; burst-Feld weiterverwenden |
| `service/config/route.php` | ~12 explizite `RateLimitMiddleware::class`-Mounts entfernen (grep maßgeblich, auth/supplier/admin-Gruppen) |
| `docker/nginx.conf` | `limit_req` rate 10r/s → 100r/s (grobkörniges Fallback, Geschäftsfälle nicht zusätzlich über der globalen Middleware bremsen) |
| Tests | Tests im service-Suite, die vom expliziten Mount der Limit-Middleware abhängen, synchronisieren; neue Middleware-Unit-Tests |

### Abnahme (k6)

```
# Beliebige anonyme Route (z. B. GET /api/products) und /graphql, jeweils 200 Anfragen/10s:
# Über der Limit-Schwelle alle 429, Antwort mit Retry-After; unter der Schwelle alle 200.
# Assertion: 429-Anzahl == Gesamtanfragen - Schwelle; /graphql wirksam (ursprüngliche Lücke).
```

---

## P4.2 Multiwährungs-Konsistenz über die gesamte Kette (inkl. Fee-Rundungsstrategie)

### Ist-Zustand (per Codeprüfung bestätigt)

- **Speicherung**: alle Beträge in `install.sql` als DECIMAL — Guthaben/Einfrieren `(16,4)`, Order subtotal/discount/tax/total, Positions-Unit-Preise unit_price/total_price `(12,4)`, `exchange_rate DECIMAL(12,6)` bereits auf `orders`, `payment_transactions` vorhanden; `user_balances` zeilenweise pro Währung (währungsgetrennte Buchführung).
- **Wechselkursquelle**: `service/app/cron/ExchangeRateSync.php` bereits implementiert — externe kostenlose API (`EXCHANGE_RATE_API_URL` env konfigurierbar, Standard exchangerate-api.com) synchronisiert stündlich nach Redis `exchange_rate:{CURRENCY}`; `OrderService::getExchangeRate` liest beim Bestellen den Redis-Snapshot (USD konstant 1.0) und schreibt ihn in das `exchange_rate`-Feld der Order. **Externe Abhängigkeit existiert bereits und die Quelle ist per env austauschbar, nichts Neues nötig.**
- **Fee-Truncation-Problem**: `PaymentRouter::calculateFee` = `bcadd(bcmul($amount, $rate, 8), $fixed, 4)` — bcmath **trunkiert** nach scale (nicht rundet), Richtung **zu wenig berechnet** <0.0001/Order; außerdem kann `total_amount = amount + fee` bei Beträgen mit 5+ Nachkommastellen (z. B. 10.12345) nach Truncation vom Order-Total abweichen.
- **suspend-Prüfung** bewertet bereits währungsweise Guthaben (Multiwährung), Billing rechnet nach Meter ab (usage_rates Einzelpreis DECIMAL(12,4)).

### Entscheidungen

**D4: Einheitliche Betragsinvariante — pro Währung eine interne Präzision, Rundung nur an einem einzigen Punkt.**
- Interne Berechnung einheitlich `DECIMAL(12,4)` (Order-Granularität) und `DECIMAL(16,4)` (Guthaben-Granularität); nach jeder Multiplikation muss `bcround(x, 4, PHP_ROUND_HALF_UP)` folgen, `bcadd/bcsub` nur für gleichpräzise Addition/Subtraktion (selbst exakt).
- Einzigen neuen Betragshelfer `service/common/money/Money.php` anlegen (ca. 40 Zeilen):
  - `bcround(string $v, int $scale = 4, int $mode = PHP_ROUND_HALF_UP): string` — idempotent; `round()` hat bei Floats Präzisionsrisiken, String-Pfad zwingend: `bcadd($v, '0', $scale+1)` und dann anhand der $scale+1-ten Stelle HALF-UP entscheiden (Implementierungshinweis: Negativzahlen behandeln, über bccomp auf abs prüfen).
  - Jedes Betragsfeld muss vor dem Schreiben durch `bcround(…, 4)`; **verboten** sind `(float)`/`round()` mitten in der Rechenkette (das bestehende `round((float) bcmul(...))` in StripeChannel ist genau eine solche Gefahr).
- Bestehender `calculateFee` wird zu: `$fee = bcround(bcadd(bcmul(bcround($amount,4), $rate, 8), $fixed, 8), 4)` — erst amount auf 4 Stellen ausrichten, dann mit Rate multiplizieren, dann HALF_UP auf 4 Stellen. **Richtungskorrektur: zu wenig → Standard-Half-Up** (Differenz pro Order ≤0.00005, Erwartungswert gegen 0). **Negativ-Fee-Clamping auf 0 bleibt erhalten** (Verhalten der aktuellen PaymentRouter.php:44 unverändert).

**D5: Order-Identität und Channel-Fee trennen (Null-Abweichung im Abgleich).** Zwei unabhängige Fakten:
- **Order-Positions-Identität** `total − subtotal − tax + discount == 0` (exakt auf 0.0000): Bestellkette (OrderService::createFromCart) Positions `bcround(bcmul(price, qty, 8), 4)` (erst hochpräzise multiplizieren, dann runden — doppelte Truncation vermeiden) → subtotal = Positionssumme (exakt) → total = subtotal + tax − discount (gleichpräzise Addition/Subtraktion, exakt). **tax ist aktuell konstant 0** (createFromCart setzt kein tax, install.sql:345 DEFAULT 0.0000) — keine neue Steuerberechnung (außerhalb von P4.2, mit Compliance-Auswirkungen), Assertion gemäß Ist-Wert `tax=0` implementieren, aber der Formelterm tax bleibt erhalten.
- **Channel-Fee**: channel_fee separat `bcround(…,4)`, Zahlungskanalbetrag = total + channel_fee exakt gleich auf 4dp.
- Validierung: `PaymentController::reconcile*` und Reports verwenden das gespeicherte Order-Total als Basis, keine Neuberechnung.

**D6: Wechselkurs-Snapshot und Umrechnungspunkt.**
- Kursquelle bleibt ExchangeRateSync cron + Redis (existiert, unverändert). Die `exchange_rate`-Spalte wird bereits mit Order/Transaktion gesnapshotet (DECIMAL(12,6)), **Umrechnungspunkt = Abrechnung (Schreiben in DB)**, keine Echtzeit-Umrechnung bei Anzeige (Anzeige-Echtzeitpreis ist nur UI-Schicht, multipliziert mit aktuellem Redis-Kurs, berührt keine Buchführung).
- Regel: **Bei Buchführung/Guthaben zwingend den Order-Snapshot-Kurs verwenden; bei Auszeichnung/Anzeige den aktuellen Kurs erlaubt.** Vermischung zweier Kurse in der Abrechnungskette verboten.
- Guthabenebene ist bereits währungsgetrenntes Hauptbuch (user_balances zeilenweise pro currency), keine einheitliche Basiswährungsumrechnung; Reports, die eine Basiswährung (z. B. USD) benötigen, aggregieren mit dem Order-Snapshot-Kurs, die Summe läuft weiterhin durch `bcround(…,4)` (ponytail: Rundungsfehler der währungsübergreifenden Summierung liegen in der Summenposition; falls die Prüfung später währungsweise Summen fordert, aufteilen).

**D7: Änderungsliste (inkl. Prüfpunkte für bestehenden Multiwährungs-Code).**
- Ändern: `PaymentRouter::calculateFee`, `StripeChannel` (Betrags-Eingaben ausrichten + float round entfernen, inkl. convertToSmallest auf bcround($total,2) umstellen), `OrderService::createFromCart` (Positionen/subtotal/total sequenziell runden), **`Order/Model/Coupon.php::calculateDiscount` (:31-44 aktuell float+round, auf bcround-String-Pfad umstellen)**, `PaymentController::reconcile*` (D5-Identität asserten), `Report/*` (Summen einheitlich bcround).
- Prüfen, nicht ändern: Billing-Meter (Einzelpreis bereits DECIMAL(12,4), Abrechnung über bcround ausrichten reicht), suspend-Prüfung (währungsweise Guthabenbewertung, bereits korrekt), `Cron/ExchangeRateSync.php` (Redis-Write behält 6 Stellen im Original, unverändert).
- Neu: `service/common/money/Money.php` + Unit-Tests (HALF-UP-Grenzen: 0.00005 → 0.0001, 0.00004 → 0.0000, **-0.00005 → -0.0001 (negative weg von null)**, Idempotenz).
- Migration: `install.sql` ohne Strukturänderung (exchange_rate-Spalte existiert); falls historische Orders durch Fee-Truncation Restdifferenzen <0.0001 haben, sind das buchhalterisch irreversible Differenzen — **nur protokollieren, nicht reparieren** (eine Korrekturbuchung würde den historischen Abgleich verändern), neue Audit-Abfrage `fee_drift` listet Orders mit |total−subtotal−tax+discount|>0 zur manuellen Prüfung.

### Abnahme

```
# k6 (P4.1): feste einzelne IP. GET /api/products und /graphql, jeweils 200 Anfragen/10s:
#   default-Regel-Schwelle = rate+burst = 70/60s-Window → erwartet 429 ≈ 200−70 = 130 (±Window-Grenzen 1-2)
#   graphql-Regel-Schwelle = 35 → erwartet 429 ≈ 165; beide mit Retry-After-Header; niedriger Traffic alles 200
# Unit-Tests (P4.2): Money::bcround-Grenzen (0.00005→0.0001, 0.00004→0.0000, -0.00005→-0.0001, Idempotenz)
# Identitätstest: Mehrpositions-Order konstruieren (Einzelpreis mit 5 Nachkommastellen + Coupon),
#   asserten dass total−subtotal−tax+discount == 0 konstant gilt
# Regression: bestehende service 491 tests komplett grün (inkl. Betrags-Assertions)
```

---

## Risiken und Review

- **D2 Globales Rate-Limiting-Risiko (mittel)**: Globaler Mount betrifft alle service-Endpunkte (**nicht admin** — separater Container, service/config-Änderungen berühren nichts), webhook ist ausgenommen; falsche Schwellenwerte schaden Geschäftsfällen, security-auditor muss Standard-Schwellen und fail-open-Strategie gegenprüfen. **Der admin-Container ist aktuell unlimitiert** (nginx-admin.conf ohne limit_req), nicht in P4.1 enthalten, separat zu entscheiden.
- **D4/D5 Geldpfad (hoch)**: Rundungsrichtungsänderung betrifft den Betrag jeder Order (zu wenig → Standard-Half-Up), security-auditor-Review + Vier-Augen-Prinzip nötig; historische Daten nur protokollieren, nicht reparieren.
- **Abhängigkeiten**: keine neuen composer-Abhängigkeiten; keine neuen Tabellen; nginx-Konfigurationsänderung erfordert Reload.

```yaml
design:
  objective: "P4.1 einheitliches Rate-Limiting auf allen Routen wirksam (inkl. graphql) + P4.2 Multiwährungs-Rundungsstrategie ausgerichtet, Buchhaltungs-Identität mit Null-Abweichung"
  files_affected:
    - service/common/security/RateLimitMiddleware.php
    - service/config/middleware.php
    - service/config/route.php
    - service/config/security.php
    - docker/nginx.conf
    - service/common/money/Money.php (new)
    - service/app/payment/service/PaymentRouter.php
    - service/app/payment/service/channels/StripeChannel.php
    - service/app/order/service/OrderService.php
    - service/app/order/model/Coupon.php
    - service/app/payment/controller/PaymentController.php
    - service/app/report/controller/ReportController.php
    - tests/ (middleware + money + Identität)
  modules_touched: ["Gateway/Route", "Security", "Payment", "Order", "Billing", "Report"]
  api_changes: [{method: "ALL", path: "/graphql", error_codes: ["429"]}, {method: "ALL", path: "ALL", error_codes: ["429 + Retry-After"]}]
  data_changes: []   # keine Strukturänderung; exchange_rate-Spalte existiert; tax bleibt 0, nichts Neues
  client_impact: ["flutter", "harmonyos"]  # 429 muss der Client elegant behandeln; admin-Container unbeeinflusst
  risk: "high"       # D4/D5 Geldpfad
  review_needed: ["security-auditor"]
  testing_points: ["429+Retry-After auf allen Routen (k6 einzelne IP, 429≈130/165)", "graphql-Limitierungslücke geschlossen", "webhook-Ausnahme ohne 429", "Zwei-Bucket-OR-Semantik (Token-/IP-Wechsel beide nicht umgehbar)", "fee HALF-UP-Grenzen inkl. negativer Werte", "Coupon bcround-Stringifizierung", "total−subtotal−tax+discount==0-Identität", "historische Orders fee_drift-Audit-Abfrage"]
  dependencies: []
```
