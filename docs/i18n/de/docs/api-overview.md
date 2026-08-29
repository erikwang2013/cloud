# API-Überblick

> Vollständige Schnittstellen-Referenz (200+ Endpunkte, mit Anfrage-/Antwortbeispielen und Fehlercodes): [API-Referenz](api-reference.md)
> Online-Debugging: [service-API-Dokumentation](http://localhost:8787/apidoc) · [admin-API-Dokumentation](http://localhost:8788/apidoc)

## Öffentliche Schnittstellen

| Methode | Pfad | Beschreibung |
|------|------|------|
| GET | `/health` | Health-Check |
| POST | `/api/auth/register` | Nutzerregistrierung (Anfragekörper muss mit AES-256-GCM verschlüsselt sein) |
| POST | `/api/auth/login` | Nutzerlogin (Anfragekörper muss mit AES-256-GCM verschlüsselt sein) |
| POST | `/api/auth/refresh` | Token erneuern (Anfragekörper muss mit AES-256-GCM verschlüsselt sein) |
| POST | `/api/captcha/create` | Klick-CAPTCHA generieren (vor Login/Registrierung abrufen) |
| GET | `/api/products` | Produktliste (Filter nach Kategorie/Region/Stichwort möglich) |
| GET | `/api/products/{id}` | Produktdetails (id ist ein hashid-String) |
| GET | `/api/regions` | Verfügbare Regionen |
| GET | `/api/domain/check/{domain}/{tld}` | Domain-Verfügbarkeitsprüfung |
| GET | `/api/domain/tlds` | Liste der registrierbaren TLDs |
| POST | `/api/payments/webhook/stripe` | Stripe-Callback (Signaturprüfung, keine Verschlüsselung erforderlich) |

## Authentifizierte Schnittstellen (Bearer Token erforderlich)

| Methode | Pfad | Beschreibung |
|------|------|------|
| GET | `/api/user/profile` | Persönliche Daten |
| PUT | `/api/user/profile` | Daten aktualisieren |
| POST | `/api/user/kyc` | Identitätsverifizierung (KYC) einreichen |
| GET | `/api/user/balance` | Kontoguthaben |
| GET/POST | `/api/cart` | Warenkorb |
| POST/GET | `/api/orders` | Bestellungen |
| GET | `/api/orders/{id}/payment-methods` | Verfügbare Zahlungsmethoden |
| POST | `/api/orders/{id}/pay` | Zahlung einleiten |
| GET/POST | `/api/resources` | Meine Ressourcen |
| GET | `/api/resources/{id}/status` | Ressourcenstatus |
| GET | `/api/resources/{id}/console` | VNC-Konsolenlink |
| GET/POST | `/api/cdn/domains` | CDN-Domainliste / -erstellung (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/cdn/domains/{id}` | CDN-Domain-Details / -Löschung |
| POST | `/api/cdn/domains/{id}/purge` | Cache leeren (idempotent, max. 100 URLs) |
| GET/POST | `/api/tickets` | Tickets |
| POST | `/api/tickets/{id}/reply` | Ticket-Antwort |
| GET/POST | `/api/dns/{domain}` | DNS-Verwaltung |
| POST | `/api/supplier/apply` | Anbieterantrag |
| GET | `/api/supplier/settlements` | Anbieter-Abrechnungsverlauf |
| POST | `/api/supplier/withdraw` | Anbieter-Auszahlung |

> **Hinweis:** Alle API-Anfragen müssen den Header `X-Api-Version: v1` mitführen (fehlt er, gilt standardmäßig `v1`; geprüft von `VersionMiddleware`). Anfragen/Antworten authentifizierter und administrativer Schnittstellen werden von `EncryptionMiddleware` verarbeitet. Der Client setzt den Header `X-Encrypted: 1`; das Anfrageformat ist `{"payload": "<base64(AES-256-GCM)>"}`, der Antwortkörper wird ebenfalls verschlüsselt und im Feld `payload` gekapselt. Alle Integer-IDs werden in API-Antworten automatisch in 12-stellige Hashid-Strings umgewandelt; Hashid-Strings in Anfragen werden von `HashidRequestMiddleware` automatisch zurück in Integer-IDs decodiert.

## Administratorschnittstellen

| Methode | Pfad | Beschreibung |
|------|------|------|
| GET | `/admin/api/dashboard` | Betriebs-Dashboard |
| GET/PUT | `/admin/api/users` | Nutzerverwaltung |
| GET/POST | `/admin/api/kyc` | KYC-Prüfung |
| GET/POST/PUT/DELETE | `/admin/api/products` | Produktverwaltung |
| POST | `/admin/api/products/{productId}/skus` | SKU erstellen |
| POST | `/admin/api/skus/{skuId}/region-price` | Regionalpreis festlegen |
| GET/POST | `/admin/api/orders` | Bestellverwaltung (einschließlich Rückerstattungen) |
| GET | `/admin/api/orders/export` | Bestellexport (.xlsx) |
| GET | `/admin/api/users/export` | Nutzerexport (.xlsx) |
| GET | `/admin/api/suppliers/export` | Anbieterexport (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Zahlungskanäle / Transaktionen / Abgleich |
| GET/POST | `/admin/api/provisioning/*` | Bereitstellungsaufgaben / Serververwaltung |
| GET/PUT | `/admin/api/cdn/domains` | CDN-Domainverwaltung (Paketänderung) |
| GET/POST/PUT/DELETE | `/admin/api/providers` | Verwaltung der Anbieter-Kontoanmeldedaten (CDN/Bereitstellung gemeinsam, Encryptable-verschlüsselt) |
| GET/POST | `/admin/api/suppliers/*` | Anbieter-Genehmigung / Abrechnung / Auszahlung |
| GET/POST | `/admin/api/tickets` | Ticket-Zuweisung / -Schließung |
| GET | `/admin/api/reports/*` | Umsatz- / Regional- / Anbieterberichte |
| GET | `/admin/api/monitor/*` | Monitoring-Panel / Ressourcenmetriken |
| GET | `/admin/api/audit-logs` | Audit-Protokolle |
| PUT | `/admin/api/system/config` | Systemkonfiguration |
