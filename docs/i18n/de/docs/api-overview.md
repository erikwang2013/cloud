# API-Überblick

> Vollständige Schnittstellen-Referenz (200+ Endpunkte, mit Anfrage-/Antwortbeispielen und Fehlercodes): [API-Referenz](api-reference.md)
> Online-Debugging: [service-API-Dokumentation](http://localhost:8787/apidoc) · [admin-API-Dokumentation](http://localhost:8788/apidoc)

## Öffentliche Schnittstellen

| Methode | Pfad | Beschreibung |
|------|------|------|
| GET | `/health` | Health-Check |
| POST | `/api/v1/auth/register` | Nutzerregistrierung (Anfragekörper muss mit AES-256-GCM verschlüsselt sein) |
| POST | `/api/v1/auth/login` | Nutzerlogin (Anfragekörper muss mit AES-256-GCM verschlüsselt sein) |
| POST | `/api/v1/auth/refresh` | Token erneuern (Anfragekörper muss mit AES-256-GCM verschlüsselt sein) |
| POST | `/api/v1/captcha/create` | Klick-CAPTCHA generieren (vor Login/Registrierung abrufen) |
| GET | `/api/v1/products` | Produktliste (Filter nach Kategorie/Region/Stichwort möglich) |
| GET | `/api/v1/products/{id}` | Produktdetails (id ist ein hashid-String) |
| GET | `/api/v1/regions` | Verfügbare Regionen |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Domain-Verfügbarkeitsprüfung |
| GET | `/api/v1/domain/tlds` | Liste der registrierbaren TLDs |
| POST | `/api/v1/payments/webhook/stripe` | Stripe-Callback (Signaturprüfung, keine Verschlüsselung erforderlich) |

## Authentifizierte Schnittstellen (Bearer Token erforderlich)

| Methode | Pfad | Beschreibung |
|------|------|------|
| GET | `/api/v1/user/profile` | Persönliche Daten |
| PUT | `/api/v1/user/profile` | Daten aktualisieren |
| POST | `/api/v1/user/kyc` | Identitätsverifizierung (KYC) einreichen |
| GET | `/api/v1/user/balance` | Kontoguthaben |
| GET/POST | `/api/v1/cart` | Warenkorb |
| POST/GET | `/api/v1/orders` | Bestellungen |
| GET | `/api/v1/orders/{id}/payment-methods` | Verfügbare Zahlungsmethoden |
| POST | `/api/v1/orders/{id}/pay` | Zahlung einleiten |
| GET/POST | `/api/v1/resources` | Meine Ressourcen |
| GET | `/api/v1/resources/{id}/status` | Ressourcenstatus |
| GET | `/api/v1/resources/{id}/console` | VNC-Konsolenlink |
| GET/POST | `/api/v1/cdn/domains` | CDN-Domainliste / -erstellung (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | CDN-Domain-Details / -Löschung |
| POST | `/api/v1/cdn/domains/{id}/purge` | Cache leeren (idempotent, max. 100 URLs) |
| GET/POST | `/api/v1/tickets` | Tickets |
| POST | `/api/v1/tickets/{id}/reply` | Ticket-Antwort |
| GET/POST | `/api/v1/dns/{domain}` | DNS-Verwaltung |
| POST | `/api/v1/supplier/apply` | Anbieterantrag |
| GET | `/api/v1/supplier/settlements` | Anbieter-Abrechnungsverlauf |
| POST | `/api/v1/supplier/withdraw` | Anbieter-Auszahlung |

> **Hinweis:** Die API-Version steht im URL-Pfad (z. B. `/api/v1/...`); zentral geprüft von `VersionMiddleware`. Anfragen/Antworten authentifizierter und administrativer Schnittstellen werden von `EncryptionMiddleware` verarbeitet. Der Client setzt den Header `X-Encrypted: 1`; das Anfrageformat ist `{"payload": "<base64(AES-256-GCM)>"}`, der Antwortkörper wird ebenfalls verschlüsselt und im Feld `payload` gekapselt. Alle Integer-IDs werden in API-Antworten automatisch in 12-stellige Hashid-Strings umgewandelt; Hashid-Strings in Anfragen werden von `HashidRequestMiddleware` automatisch zurück in Integer-IDs decodiert.

## Administratorschnittstellen

| Methode | Pfad | Beschreibung |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | Betriebs-Dashboard |
| GET/PUT | `/admin/api/v1/users` | Nutzerverwaltung |
| GET/POST | `/admin/api/v1/kyc` | KYC-Prüfung |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Produktverwaltung |
| POST | `/admin/api/v1/products/{productId}/skus` | SKU erstellen |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Regionalpreis festlegen |
| GET/POST | `/admin/api/v1/orders` | Bestellverwaltung (einschließlich Rückerstattungen) |
| GET | `/admin/api/v1/orders/export` | Bestellexport (.xlsx) |
| GET | `/admin/api/v1/users/export` | Nutzerexport (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Anbieterexport (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Zahlungskanäle / Transaktionen / Abgleich |
| GET/POST | `/admin/api/v1/provisioning/*` | Bereitstellungsaufgaben / Serververwaltung |
| GET/PUT | `/admin/api/v1/cdn/domains` | CDN-Domainverwaltung (Paketänderung) |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | Verwaltung der Anbieter-Kontoanmeldedaten (CDN/Bereitstellung gemeinsam, Encryptable-verschlüsselt) |
| GET/POST | `/admin/api/v1/suppliers/*` | Anbieter-Genehmigung / Abrechnung / Auszahlung |
| GET/POST | `/admin/api/v1/tickets` | Ticket-Zuweisung / -Schließung |
| GET | `/admin/api/v1/reports/*` | Umsatz- / Regional- / Anbieterberichte |
| GET | `/admin/api/v1/monitor/*` | Monitoring-Panel / Ressourcenmetriken |
| GET | `/admin/api/v1/audit-logs` | Audit-Protokolle |
| PUT | `/admin/api/v1/system/config` | Systemkonfiguration |
