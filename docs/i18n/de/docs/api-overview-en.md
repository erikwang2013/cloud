# API-Überblick

> Vollständige API-Referenz (200+ Endpunkte, mit Anfrage-/Antwortbeispielen und Fehlercodes): [API-Referenz](api-reference.md)
> Online-Debugging: [service-API-Dokumentation](http://localhost:8787/apidoc) · [admin-API-Dokumentation](http://localhost:8788/apidoc)

## Öffentliche Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| GET | `/health` | Health-Check |
| POST | `/api/auth/register` | Registrierung (Körper AES-256-GCM-verschlüsselt) |
| POST | `/api/auth/login` | Login (Körper AES-256-GCM-verschlüsselt) |
| POST | `/api/auth/refresh` | Token erneuern (Körper AES-256-GCM-verschlüsselt) |
| POST | `/api/captcha/create` | Klick-CAPTCHA generieren (erforderlich vor Login/Registrierung) |
| GET | `/api/products` | Produktliste (Filter nach Kategorie/Region/Stichwort) |
| GET | `/api/products/{id}` | Produktdetails (id ist ein hashid-String) |
| GET | `/api/regions` | Verfügbare Regionen |
| GET | `/api/domain/check/{domain}/{tld}` | Domain-Verfügbarkeitsprüfung |
| GET | `/api/domain/tlds` | Verfügbare TLDs |
| POST | `/api/payments/webhook/stripe` | Stripe-Webhook (Signatur geprüft, keine Verschlüsselung) |

## Authentifizierte Endpunkte (Bearer Token)

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| GET | `/api/user/profile` | Profil abrufen |
| PUT | `/api/user/profile` | Profil aktualisieren |
| POST | `/api/user/kyc` | KYC einreichen |
| GET | `/api/user/balance` | Kontoguthaben |
| GET/POST | `/api/cart` | Warenkorb |
| POST/GET | `/api/orders` | Bestellungen |
| GET | `/api/orders/{id}/payment-methods` | Verfügbare Zahlungsmethoden |
| POST | `/api/orders/{id}/pay` | Zahlung einleiten |
| GET/POST | `/api/resources` | Meine Ressourcen |
| GET | `/api/resources/{id}/status` | Ressourcenstatus |
| GET | `/api/resources/{id}/console` | VNC-Konsolen-URL |
| GET/POST | `/api/tickets` | Support-Tickets |
| POST | `/api/tickets/{id}/reply` | Ticket beantworten |
| GET/POST | `/api/dns/{domain}` | DNS-Verwaltung |
| POST | `/api/supplier/apply` | Als Anbieter bewerben |
| GET | `/api/supplier/settlements` | Abrechnungsverlauf |
| POST | `/api/supplier/withdraw` | Auszahlung beantragen |

> **Hinweis:** Alle API-Anfragen müssen den Header `X-Api-Version: v1` mitführen (fehlt er, gilt standardmäßig `v1`; geprüft von `VersionMiddleware`). Authentifizierte und administrative Endpunkte werden von `EncryptionMiddleware` verarbeitet. Clients setzen den Header `X-Encrypted: 1` und kapseln den Körper als `{"payload": "<base64(AES-256-GCM)>"}`. Antworten werden ebenfalls verschlüsselt und in einem Feld `payload` gekapselt. Integer-IDs in API-Antworten werden automatisch in 12-stellige Hashid-Strings umgewandelt; Hashid-Strings in Anfragen werden von `HashidRequestMiddleware` zurück in Integer-IDs decodiert.

## Administrative Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| GET | `/admin/api/dashboard` | Betriebs-Dashboard |
| GET/PUT | `/admin/api/users` | Nutzerverwaltung |
| GET/POST | `/admin/api/kyc` | KYC-Prüfung |
| GET/POST/PUT/DELETE | `/admin/api/products` | Produktverwaltung |
| POST | `/admin/api/products/{productId}/skus` | SKU erstellen |
| POST | `/admin/api/skus/{skuId}/region-price` | Regionalpreis festlegen |
| GET/POST | `/admin/api/orders` | Bestellverwaltung (inkl. Rückerstattungen) |
| GET | `/admin/api/orders/export` | Bestellungen exportieren (.xlsx) |
| GET | `/admin/api/users/export` | Nutzer exportieren (.xlsx) |
| GET | `/admin/api/suppliers/export` | Anbieter exportieren (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Kanäle / Transaktionen / Abgleich |
| GET/POST | `/admin/api/provisioning/*` | Bereitstellungsaufgaben / Serververwaltung |
| GET/POST | `/admin/api/suppliers/*` | Anbieter-Genehmigung / Abrechnung / Auszahlung |
| GET/POST | `/admin/api/tickets` | Ticket-Zuweisung / -Schließung |
| GET | `/admin/api/reports/*` | Umsatz- / Regional- / Anbieterberichte |
| GET | `/admin/api/monitor/*` | Monitoring-Dashboard / Ressourcenmetriken |
| GET | `/admin/api/audit-logs` | Audit-Protokolle |
| PUT | `/admin/api/system/config` | Systemkonfiguration aktualisieren |
