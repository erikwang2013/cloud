# API-Überblick

> Vollständige API-Referenz (200+ Endpunkte, mit Anfrage-/Antwortbeispielen und Fehlercodes): [API-Referenz](api-reference.md)
> Online-Debugging: [service-API-Dokumentation](http://localhost:8787/apidoc) · [admin-API-Dokumentation](http://localhost:8788/apidoc)

## Öffentliche Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| GET | `/health` | Health-Check |
| POST | `/api/v1/auth/register` | Registrierung (Körper AES-256-GCM-verschlüsselt) |
| POST | `/api/v1/auth/login` | Login (Körper AES-256-GCM-verschlüsselt) |
| POST | `/api/v1/auth/refresh` | Token erneuern (Körper AES-256-GCM-verschlüsselt) |
| POST | `/api/v1/captcha/create` | Klick-CAPTCHA generieren (erforderlich vor Login/Registrierung) |
| GET | `/api/v1/products` | Produktliste (Filter nach Kategorie/Region/Stichwort) |
| GET | `/api/v1/products/{id}` | Produktdetails (id ist ein hashid-String) |
| GET | `/api/v1/regions` | Verfügbare Regionen |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Domain-Verfügbarkeitsprüfung |
| GET | `/api/v1/domain/tlds` | Verfügbare TLDs |
| POST | `/api/v1/payments/webhook/stripe` | Stripe-Webhook (Signatur geprüft, keine Verschlüsselung) |

## Authentifizierte Endpunkte (Bearer Token)

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | Profil abrufen |
| PUT | `/api/v1/user/profile` | Profil aktualisieren |
| POST | `/api/v1/user/kyc` | KYC einreichen |
| GET | `/api/v1/user/balance` | Kontoguthaben |
| GET/POST | `/api/v1/cart` | Warenkorb |
| POST/GET | `/api/v1/orders` | Bestellungen |
| GET | `/api/v1/orders/{id}/payment-methods` | Verfügbare Zahlungsmethoden |
| POST | `/api/v1/orders/{id}/pay` | Zahlung einleiten |
| GET/POST | `/api/v1/resources` | Meine Ressourcen |
| GET | `/api/v1/resources/{id}/status` | Ressourcenstatus |
| GET | `/api/v1/resources/{id}/console` | VNC-Konsolen-URL |
| GET/POST | `/api/v1/tickets` | Support-Tickets |
| POST | `/api/v1/tickets/{id}/reply` | Ticket beantworten |
| GET/POST | `/api/v1/dns/{domain}` | DNS-Verwaltung |
| POST | `/api/v1/supplier/apply` | Als Anbieter bewerben |
| GET | `/api/v1/supplier/settlements` | Abrechnungsverlauf |
| POST | `/api/v1/supplier/withdraw` | Auszahlung beantragen |

> **Hinweis:** Die API-Version steht im URL-Pfad (z. B. `/api/v1/...`); zentral geprüft von `VersionMiddleware`. Authentifizierte und administrative Endpunkte werden von `EncryptionMiddleware` verarbeitet. Clients setzen den Header `X-Encrypted: 1` und kapseln den Körper als `{"payload": "<base64(AES-256-GCM)>"}`. Antworten werden ebenfalls verschlüsselt und in einem Feld `payload` gekapselt. Integer-IDs in API-Antworten werden automatisch in 12-stellige Hashid-Strings umgewandelt; Hashid-Strings in Anfragen werden von `HashidRequestMiddleware` zurück in Integer-IDs decodiert.

## Administrative Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| GET | `/admin/api/v1/dashboard` | Betriebs-Dashboard |
| GET/PUT | `/admin/api/v1/users` | Nutzerverwaltung |
| GET/POST | `/admin/api/v1/kyc` | KYC-Prüfung |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Produktverwaltung |
| POST | `/admin/api/v1/products/{productId}/skus` | SKU erstellen |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Regionalpreis festlegen |
| GET/POST | `/admin/api/v1/orders` | Bestellverwaltung (inkl. Rückerstattungen) |
| GET | `/admin/api/v1/orders/export` | Bestellungen exportieren (.xlsx) |
| GET | `/admin/api/v1/users/export` | Nutzer exportieren (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Anbieter exportieren (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Kanäle / Transaktionen / Abgleich |
| GET/POST | `/admin/api/v1/provisioning/*` | Bereitstellungsaufgaben / Serververwaltung |
| GET/POST | `/admin/api/v1/suppliers/*` | Anbieter-Genehmigung / Abrechnung / Auszahlung |
| GET/POST | `/admin/api/v1/tickets` | Ticket-Zuweisung / -Schließung |
| GET | `/admin/api/v1/reports/*` | Umsatz- / Regional- / Anbieterberichte |
| GET | `/admin/api/v1/monitor/*` | Monitoring-Dashboard / Ressourcenmetriken |
| GET | `/admin/api/v1/audit-logs` | Audit-Protokolle |
| PUT | `/admin/api/v1/system/config` | Systemkonfiguration aktualisieren |
