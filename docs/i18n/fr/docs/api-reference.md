# Documentation des API CloudPlatform

## Vue d'ensemble

**Base URL** : `https://api.example.com`

**Contrôle de version** : spécifié via l'en-tête de requête HTTP `X-Api-Version: v1`. Par défaut `v1` si absent, les versions non prises en charge renvoient `400`. La version ne se trouve pas dans le chemin d'URL.

**Méthodes d'authentification** :

| Extrémité | Méthode | En-tête de requête |
|----|------|--------|
| Côté utilisateur | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| Côté administration | JWT Bearer Token | `Authorization: Bearer <access_token>` |
| API externe fournisseur | API Key | `Authorization: Bearer sk_xxx...` |
| Webhook Stripe | Vérification de signature | `Stripe-Signature: ...` |

**Plateforme client** : toutes les requêtes API devraient porter l'en-tête `X-Client-Platform`, supportant `ios/android/macos/windows/linux/web/harmonyos/ipados`.

**Multilingue** : toutes les requêtes API devraient porter l'en-tête `Accept-Language` (`zh-CN` / `en-US`), qui influence les textes traduits et les valeurs des champs JSON multilingues. Défaut `en-US` si absent.

---

## Format de réponse unifié

### Succès

```json
{ "code": 0, "message": "ok", "data": {...} }
```

### Pagination

```json
{
  "code": 0,
  "data": [...],
  "meta": { "page": 1, "page_size": 20, "total": 1523 }
}
```

### Erreur

```json
{ "code": 401, "message": "Unauthorized", "data": null }
```

### Codes de statut HTTP

| code | Description |
|------|------|
| 0 | Succès |
| 400 | Erreur de paramètres de requête / version API non prise en charge / plateforme client non prise en charge |
| 401 | Non authentifié |
| 403 | Sans permission / interception WAF |
| 404 | Ressource inexistante (firstOrFail/findOrFail sans correspondance mappé de façon unifiée en 404) |
| 413 | Corps de requête trop volumineux (>10 Mo) |
| 414 | URL trop longue (>2 Ko) |
| 415 | Content-Type non pris en charge |
| 422 | Échec de validation des paramètres |
| 429 | Dépassement de la fréquence de requêtes |

---

## Groupes de routes et matrice de middlewares

| Groupe de routes | Middlewares | Préfixe |
|--------|--------|------|
| Public | Chaîne de middlewares globaux | `/health`, `/api/*` |
| `/health` (interne) | Globaux + InternalToken | `/health/live`, `/health/ready`, `/health/deps` |
| `/api/auth` | Globaux + Encryption | `/api/auth/*` |
| `/api` (utilisateur) | Globaux + Encryption + Auth | `/api/user/*`, `/api/cart`, `/api/orders` |
| `/api` (sensible) | Globaux + Encryption + Auth + Confirmation | `/api/orders/{id}/pay` |
| `/api/supplier/external` | Version + SupplierApiKey | API externe fournisseur |
| `/admin/api` | Globaux + Encryption + Auth + AdminRole | API du panneau d'administration |
| `/admin/api` (sensible) | Globaux + Encryption + Auth + AdminRole + Confirmation | Opérations d'administration sensibles |

---

## I. Points de terminaison publics

### Vérification de santé

```
GET /health
→ 200 { "status": "healthy", "timestamp": "...", "version": "1.0.0" }
```

### État du service

```
GET /api/status
→ {
  "overall": "operational",
  "components": {
    "api": "healthy",
    "database": "healthy",
    "redis": "healthy",
    "payment_gateway": "healthy",
    "provisioning": "healthy"
  }
}
```

### Produits

```
GET /api/products
   Paramètres : category_id, region_id, keyword, supplier_id, page (défaut 1),
   page_size (défaut 20, max 50)
  → Liste de produits paginée (avec category, skus.regionPrices)

GET /api/products/search
   Paramètres : q (requis), page
  → Recherche plein texte Elasticsearch

GET /api/products/{id}
  → Détail du produit (avec category, skus, images, reviews)

GET /api/products/{productId}/reviews
  → Liste d'avis + avg_rating + total + distribution
   Énumération de statut : pending (en attente)/approved (approuvé)/rejected
   (rejeté), seuls les approved sont renvoyés
```

### Domaines

```
GET /api/domain/check/{domain}/{tld}
  → { domain, tld, available: true, price: { register, renew, transfer } }

GET /api/domain/tlds
  → Liste des TLD disponibles (cache Redis 1 h)
```

### Centre d'aide

```
GET /api/help
   Paramètres : category, page
   En-tête : Accept-Language (en-US / zh-CN)
  → Articles d'aide paginés

GET /api/help/categories
  → Liste des catégories d'articles

GET /api/help/{slug}
  → Détail d'un article unique
```

---

## II. Points de terminaison d'authentification

### Captcha

```
POST /api/captcha/create
   En-tête : X-Encrypted: 1
  → { key, image (base64), target_count, expires_in }
```

### Inscription

```
POST /api/auth/register
   En-tête : X-Encrypted: 1
   Corps (chiffré) : { email?, phone?, password, language?, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Limitation de débit : 3 req/min
```

- `deviceFingerprint` (optionnel) : enregistre l'empreinte de l'appareil à l'inscription, vérifiée lors de la connexion/du rafraîchissement ; l'empreinte n'est pas liée si absente
- email/phone sont chiffrés de façon déterministe avant stockage (ECB, requête par égalité sur le texte chiffré), la validation d'unicité et la requête de connexion se font sur le texte chiffré

### Connexion

```
POST /api/auth/login
   En-tête : X-Encrypted: 1
   Corps (chiffré) : { login (email/phone), password, captcha_key, captcha_points, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }

Limitation de débit : 5 req/min, 5 échecs → verrouillage 15 min
```

- `login` est requêté par égalité sur le texte chiffré (chiffrement déterministe Encryptable) ; une requête en clair ne touche pas les colonnes chiffrées

### Rafraîchissement du token

```
POST /api/auth/refresh
   En-tête : X-Encrypted: 1
   Corps (chiffré) : { refresh_token, deviceFingerprint? }
  → { access_token, refresh_token, expires_in, token_type }
```

- `deviceFingerprint` incohérent avec celui enregistré à l'inscription → 401 `Device mismatch` ; le refresh token est requêté par hash du texte chiffré

### OAuth

Fournisseurs pris en charge : google, apple, facebook, x, microsoft, linkedin, github
(l'activation dépend de la configuration `{PROVIDER}_OAUTH_CLIENT_ID` etc. dans .env)

```
GET /api/auth/{provider}            → { url }        # redirection vers la page d'autorisation (PKCE/nonce anti-rejeu)
GET /api/auth/{provider}/callback?code=xxx&state=yyy
POST /api/auth/{provider}/callback  Corps : { code, state }
```

- Apple/Microsoft renvoient un id_token, le serveur vérifie la signature via JWKS, ainsi que iss/aud/exp/nonce
- Tous les fournisseurs exigent `email_verified=true` pour autoriser la connexion, sinon 422
- `state` absent ou non conforme → 422 (anti-CSRF, expiration 5 minutes)
- Limitation du flux OAuth : 10 fois par 60 secondes (redirect + callback)

### Réinitialisation du mot de passe

```
POST /api/auth/forgot-password
   Corps : { email }
  → Envoi d'un e-mail avec code de vérification

POST /api/auth/reset-password
   Corps : { email, code, password }
  → Réinitialisation réussie
  → 5 erreurs cumulées → 429, limitation de débit 10 minutes
```

### Vérification d'e-mail

```
GET /api/auth/verify-email?token=xxx
  → Vérification réussie
```

### Vérification SMS

```
POST /api/auth/send-sms
   Corps : { phone }
  → Envoi d'un code de vérification SMS (refroidissement 60 s)
```

### Vérification en deux étapes TOTP

```
POST /api/user/totp/setup        → { secret, qr_url }        # non persisté, doit être vérifié sous 10 min
POST /api/user/totp/verify       Corps : { code } → { verified: true }   # au premier activage, message de succès
POST /api/user/totp/disable      Corps : { password }             # confirmation de mot de passe requise, sinon 403
GET /api/user/totp/recovery-codes → { recovery_codes }        # génère 8 codes à usage unique à chaque fois,
                                                               # confirmation de mot de passe requise, sinon 403
POST /api/auth/login/recovery    Corps : { login, password, recovery_code }
```

- Une fois TOTP activé, la connexion doit porter `totp_code`, sinon 401
- 5 erreurs TOTP consécutives → verrouillage de l'utilisateur 15 minutes (login_lock)

---

## III. Points de terminaison utilisateur (authentification requise)

### Profil

```
GET /api/user/profile
PUT /api/user/profile
   Corps : { nickname?, avatar?, country?, language?, timezone? }
```

### Vérification d'identité KYC

```
POST /api/user/kyc
   Corps : { id_type, id_number, real_name, front_image, back_image }
```

### Solde

```
GET /api/user/balance
  → { balances: [{currency, balance, frozen}] }

GET /api/user/balance/transactions
   Paramètres : page
  → Historique des mouvements de solde
```

### Gestion des adresses

```
GET /api/user/addresses
POST /api/user/addresses
   Corps : { type: billing/shipping, name, phone, country, state, city, address, postcode, is_default }
PUT /api/user/addresses/{id}
DELETE /api/user/addresses/{id}
```

### Gestion de session

```
GET /api/user/sessions
  → [{ id, fingerprint, client_platform, created_at, expires_at }]

DELETE /api/user/sessions/{id}
  → Révoque la session spécifiée

DELETE /api/user/account
   Corps : { confirm_password }
  → Suppression de compte GDPR
```

### Notifications

```
GET /api/user/notifications
   Paramètres : page
  → Liste de notifications paginée

POST /api/user/notifications/{id}/read
  → Marquer comme lue

GET /api/user/notification-prefs
PUT /api/user/notification-prefs
   Corps : { email: {order_paid: true, ...}, push: {...} }
```

### E-mail

```
POST /api/user/resend-verify-email
  → Renvoi de l'e-mail de vérification
```

### Téléversement de fichiers

```
POST /api/upload
   Corps : multipart/form-data { file, type: avatar/kyc/attach }
   Limites : avatar 2 Mo, kyc 5 Mo, attach 10 Mo
   Autorisés : jpg, jpeg, png, gif, pdf
   Note : validation par liste blanche de types + détection de contenu finfo
   (extension non conforme au MIME → 422)
```

---

## IV. Panier et commandes

### Panier

```
POST /api/cart
   Corps : { sku_id, region_id, quantity, cycle }
GET /api/cart
DELETE /api/cart/{id}
PUT /api/cart/{id}
   Corps : { quantity }
```

> Convention des champs de montant (décision D4/P4.2) : tous les montants sont des
> chaînes à 4 décimales (ex. "9.9900"), les number/float sont interdits — cohérent
> avec la sortie brute des colonnes DECIMAL MySQL via PDO, la précision est portée
> par la chaîne 4dp elle-même. S'applique à tous les points de terminaison
> commandes/solde/rapports.

### Commandes

```
POST /api/orders
  → Création de la commande depuis le panier
  ← { order, order_no, items, subtotal, discount, tax, total }   # subtotal/discount/tax/total : string 4dp

GET /api/orders
   Paramètres : page, status (pending/paid/provisioning/completed/refunded,
   valeur invalide → 400)
  → Liste de mes commandes

GET /api/orders/{id}
  → Détail de la commande (avec items, timeline)

GET /api/orders/{id}/payment-methods
  → Canaux de paiement disponibles + montant réel à payer pour chaque canal

POST /api/orders/{id}/pay    🔒 confirmation de mot de passe
   Corps : { channel_id, confirm_password }
  → { client_secret, transaction_id }
```

### Coupons

```
POST /api/coupons/validate
   Corps : { code, order_total }
  → { coupon_id, discount, type }   # discount : string 4dp (ex. "2.0000")

422 : invalide/expiré/conditions d'utilisation non satisfaites
```

### Factures

```
GET /api/invoices
   Paramètres : page
GET /api/invoices/{id}
GET /api/invoices/{id}/download
  → Téléchargement PDF
```

---

## V. Gestion des ressources

```
GET /api/resources
   Paramètres : page, status
  → Liste de mes ressources

GET /api/resources/{id}
  → Détail de la ressource

GET /api/resources/{id}/status
  → État actuel de la ressource + métriques

GET /api/resources/{id}/console
  → URL VNC/console

POST /api/resources/batch
   Corps : { action: start/stop/restart, resource_ids: [...] }
```

---

## VI. Gestion DNS

```
GET /api/dns/{domain}
  → Liste des enregistrements DNS

POST /api/dns/{domain}/records
   Corps : { type, name, value, ttl?, priority? }

DELETE /api/dns/{domain}/records/{id}   🔒 confirmation de mot de passe
```

---

## VII. Tickets

```
POST /api/tickets
   Corps : { resource_id?, category, priority?, title, content }

GET /api/tickets
   Paramètres : page, status

GET /api/tickets/{id}

POST /api/tickets/{id}/reply
   Corps : { content }
```

---

## VIII. Fournisseurs (API interne)

```
POST /api/supplier/apply
   Corps : { company_name, contact_name, contact_phone, contact_email, settlement_method }

GET /api/supplier/settlements
  → Liste des règlements

POST /api/supplier/withdraw    🔒 confirmation de mot de passe
   Corps : { amount, confirm_password, account_info: { method, bank_name, account_number } }

GET /api/supplier/products
POST /api/supplier/products
   Corps : { product_id, commission_rate }
DELETE /api/supplier/products/{id}
```

---

## IX. API externe fournisseur

**Authentification** : `Authorization: Bearer sk_xxx...` (vérification de signature SHA256)

**Limitation de débit** : 120 req/min (retrait 10 req/min)

```
GET /api/supplier/external/orders
   Paramètres : page, page_size, status, from, to

GET /api/supplier/external/orders/{id}
  → Détail de la commande (uniquement celles associées à ce fournisseur)

GET /api/supplier/external/resources
   Paramètres : page, status, type

GET /api/supplier/external/resources/{id}/status
  → { id, type, status, provisioned_at, expired_at }

GET /api/supplier/external/settlements
   Paramètres : page, status

GET /api/supplier/external/settlements/{id}

POST /api/supplier/external/withdraw
   Corps : { amount, account_info: { method, ... } }

GET /api/supplier/external/withdraws
   Paramètres : page
```

---

## X. API du panneau d'administration

**Authentification** : JWT Bearer Token + rôle Admin

### Tableau de bord

```
GET /admin/api/dashboard
  → { today_stats, revenue_trend_30d, region_distribution, pending_orders, pending_kyc, open_tickets }
```

### Gestion des utilisateurs

```
GET /admin/api/users               Paramètres : page, status, keyword
GET /admin/api/users/export       → Téléchargement Excel
GET /admin/api/users/{id}
PUT /admin/api/users/{id}/status   Corps : { status }
```

### Examen KYC

```
GET /admin/api/kyc                 Paramètres : page, status

POST /admin/api/kyc/{id}/approve   🔒 confirmation de mot de passe
   Corps : { confirm_password }

POST /admin/api/kyc/{id}/reject    🔒 confirmation de mot de passe
   Corps : { confirm_password, reason }
```

### Gestion des produits

```
POST /admin/api/products
PUT /admin/api/products/{id}
DELETE /admin/api/products/{id}         🔒 confirmation de mot de passe
POST /admin/api/products/{productId}/skus
PUT /admin/api/skus/{id}
POST /admin/api/skus/{skuId}/region-price
GET /admin/api/products/export         → Téléchargement CSV
POST /admin/api/products/import        → Téléversement CSV upsert
```

### Gestion des commandes

```
GET /admin/api/orders               Paramètres : page, status, keyword
GET /admin/api/orders/export       → Téléchargement Excel
GET /admin/api/orders/{id}

POST /admin/api/orders/{id}/refund  🔒 confirmation de mot de passe
   Corps : { confirm_password, amount?, reason }
```

### Gestion des paiements

```
GET /admin/api/payments/channels
PUT /admin/api/payments/channels/{id}
GET /admin/api/payments/transactions   Paramètres : page, channel, status
GET /admin/api/payments/reconcile      Paramètres : date ; records.status : verified/mismatch/unverified
POST /admin/api/payments/reconcile/run   Paramètres : date ; déclenche le rapprochement quotidien
```

### Ressources et livraison

```
GET /admin/api/provisioning/tasks               Paramètres : page, status
POST /admin/api/provisioning/tasks/{id}/retry
POST /admin/api/provisioning/resources/{id}/upgrade
   Corps : { cpu?, ram?, disk? }
POST /admin/api/provisioning/resources/{id}/destroy   🔒 confirmation de mot de passe
GET /admin/api/provisioning/hosts
```

### Gestion des fournisseurs

```
GET /admin/api/suppliers                  Paramètres : page, status
GET /admin/api/suppliers/export          → Téléchargement Excel

POST /admin/api/suppliers/{id}/approve    🔒 confirmation de mot de passe
POST /admin/api/suppliers/{id}/settle     🔒 confirmation de mot de passe
   Corps : { period_start, period_end, confirm_password }

POST /admin/api/suppliers/withdraws/{id}/approve  🔒 confirmation de mot de passe
```

### Clés API fournisseur

```
GET /admin/api/suppliers/{id}/api-keys
POST /admin/api/suppliers/{id}/api-keys
   Corps : { name }
  ← { api_key: "sk_xxx...", prefix } (affiché une seule fois)

DELETE /admin/api/suppliers/api-keys/{id}
```

### Gestion des tickets

```
GET /admin/api/tickets                   Paramètres : page, status, priority, assigned_to
POST /admin/api/tickets/{id}/assign      Corps : { user_id }
POST /admin/api/tickets/{id}/close
```

### Gestion des domaines

```
GET /admin/api/domains/tlds
POST /admin/api/domains/tlds
   Corps : { tld, wholesale_price, retail_price, registrar, promo_price?, promo_end_at? }
PUT /admin/api/domains/tlds/{id}
DELETE /admin/api/domains/tlds/{id}
GET /admin/api/domains/zones              Paramètres : page
GET /admin/api/domains/transfers          Paramètres : page
POST /admin/api/domains/transfers/{id}/approve
```

### Gestion des notifications

```
GET /admin/api/notifications/templates
PUT /admin/api/notifications/templates/{id}
   Corps : { name?, channels?, title_template?, body_template?, variables? }
GET /admin/api/notifications/log          Paramètres : page
```

### Coupons

```
GET /admin/api/coupons
POST /admin/api/coupons
   Corps : { code, type, value, min_amount?, max_discount?, max_uses?, starts_at?, expires_at? }
DELETE /admin/api/coupons/{id}
```

### Articles d'aide

```
GET /admin/api/help
POST /admin/api/help
   Corps : { category, title, slug, content, locale, sort?, status? }
PUT /admin/api/help/{id}
DELETE /admin/api/help/{id}              → suppression logique (status=archived)
```

### API des fournisseurs cloud

```
GET /admin/api/providers
POST /admin/api/providers
   Corps : { name, code, api_key?, api_secret?, webhook_secret? }
PUT /admin/api/providers/{id}
DELETE /admin/api/providers/{id}         → désactivation (status=disabled)
```

### Gestion des Webhooks

```
GET /admin/api/webhooks
POST /admin/api/webhooks
   Corps : { url }
DELETE /admin/api/webhooks               Corps : { id }
POST /admin/api/webhooks/test            Corps : { url }
```

### Rapports

```
GET /admin/api/reports/revenue            Paramètres : from, to, granularity
  → { daily: [{date, currency, revenue, orders}], total_revenue, total_orders, by_category }
  # revenue/total_revenue : string 4dp (SUM(DECIMAL) cohérent avec l'agrégation bcmath)
GET /admin/api/reports/supplier           Paramètres : from, to
  → { settlements, total_payable, total_paid }   # payable/total_payable/total_paid : string 4dp
GET /admin/api/reports/region             Paramètres : from, to
  → [{region, orders, revenue}]                  # revenue : string 4dp
```

### Surveillance

```
GET /admin/api/monitor/dashboard
  → { active_resources, alerts_today, resource_distribution, recent_alerts }

GET /admin/api/monitor/resources/{id}
  → { cpu_percent, mem_percent, disk_percent, bandwidth_usage, uptime }
```

### Journaux d'audit

```
GET /admin/api/audit-logs                 Paramètres : page, user_id, action, from, to
  → Journaux d'audit paginés (avec client_platform)
```

### Feature Flags

```
GET /admin/api/features
  → [{ name, enabled, default, source }]

PUT /admin/api/features/{name}
   Corps : { action: enable/disable/toggle/reset }
```

### Configuration système

```
PUT /admin/api/system/config              🔒 confirmation de mot de passe
```

### Import/export de produits

```
GET /admin/api/products/export           → Téléchargement CSV
POST /admin/api/products/import          → Téléversement CSV upsert
```

### Export fournisseurs + utilisateurs

```
GET /admin/api/suppliers/export          → Téléchargement Excel
GET /admin/api/users/export              → Téléchargement Excel
GET /admin/api/orders/export             → Téléchargement Excel
```

---

## XI. Certificats SSL

### Côté utilisateur

```
GET /api/ssl/plans
  → Liste des forfaits SSL (DV/OV/EV, prix incluant register/renew/transfer)

GET /api/ssl-certs
  → Liste de mes certificats (avec status : pending/active/expired/revoked)

GET /api/ssl-certs/{id}
  → Détail du certificat (domaine, autorité de délivrance, période de validité, état du renouvellement)

GET /api/ssl-certs/{id}/download
  → Téléchargement des fichiers du certificat (chaîne de certificats + clé privée)

POST /api/ssl-certs/{id}/auto-renew
   Corps : { auto_renew: true/false }
  → Bascule du renouvellement automatique
```

### Côté administration

```
GET /admin/api/ssl/plans              → Liste des forfaits
POST /admin/api/ssl/plans             → Création d'un forfait
PUT /admin/api/ssl/plans/{id}         → Mise à jour d'un forfait
DELETE /admin/api/ssl/plans/{id}      → Suppression d'un forfait
GET /admin/api/ssl/certs              → Tous les certificats
POST /admin/api/ssl/certs/{id}/revoke → Révocation d'un certificat
```

---

## XII. Stockage d'objets

Stockage d'objets compatible S3, téléversement/téléchargement via URL pré-signées, la clé n'est jamais transmise.

```
GET /api/storage/buckets
  → Liste de mes buckets de stockage (usage, statut)

GET /api/storage/buckets/{id}
  → Détail du bucket

POST /api/storage/buckets/{id}/presign-upload
   Corps : { filename, content_type, size }
  → { upload_url, object_key } URL de téléversement pré-signée (limitée dans le temps)

POST /api/storage/buckets/{id}/presign-download
   Corps : { object_key }
  → URL de téléchargement pré-signée (limitée dans le temps)

GET /api/storage/buckets/{id}/credentials
  → Identifiants d'accès temporaires (valables peu de temps, pour un téléversement direct SDK)
```

---

## XIII. Accélération CDN

### Côté utilisateur

```
GET /api/cdn/domains
  → Liste de mes domaines CDN (origine, statut, forfait)

POST /api/cdn/domains
   Corps : { resource_id, domain, provider_type (cloudflare|cloudfront|aliyun|tencent),
        origin_type (server|storage), origin_value, cert_config? }
  → Création d'un domaine CDN (création côté fournisseur et liaison de l'origine)
  → provider_type=aliyun|tencent : le domaine doit être enregistré ICP (sinon 4002)
  → La réponse contient le champ indicatif requires_icp_registration
  → Résolution des identifiants : d'abord le compte lié au domaine (provider_account_id),
     sinon le compte provider_apis actif selon code=cdn-{provider_type},
     sinon repli sur la configuration env

GET /api/cdn/domains/{id}
  → Détail du domaine CDN

DELETE /api/cdn/domains/{id}
  → Suppression du domaine CDN (désactivation côté fournisseur, idempotent)

POST /api/cdn/domains/{id}/purge
   Corps : { urls: ["https://cdn.example.com/path"] }
  → Purge du cache (URL en double automatiquement dédupliquées, idempotent ; 100 maximum)

GET /api/cdn/domains/{id}/stats
  → Aperçu du domaine (cdn_domain / provider_type / plan / status / purged_at)
```

### Côté administration

```
GET /admin/api/cdn/domains            → Tous les domaines CDN (avec l'utilisateur propriétaire)
PUT /admin/api/cdn/domains/{id}       → Mise à jour du forfait (liste blanche plan : standard | pro | enterprise)
```

Les routes CDN du panneau d'administration sont soumises à `RbacMiddleware('cdn.manage')` ; les changements de forfait sont écrits dans les journaux d'audit (`admin_cdn_update_plan`). Les identifiants des comptes fournisseurs sont gérés via le CRUD `/admin/api/providers` (RbacMiddleware `provider.config`, convention `code` `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, identifiants chiffrés en base via Encryptable).

### Codes d'erreur CDN

| code | Description |
|------|-------------|
| 4001 | Paramètres CDN manquants/invalides (urls vide, provider_type invalide, format de domaine incorrect) |
| 4002 | Domaine non enregistré ICP (mappé lorsque l'API Alibaba/Tencent le refuse) |
| 4003 | Identifiants du fournisseur CDN non configurés (compte manquant/désactivé, l'instantané strict ne bascule pas silencieusement) |
| 4005 | Échec de la purge du cache CDN |
| 5001 | Échec de l'appel API du fournisseur CDN |

> Les ressources CDN n'appartenant pas à l'utilisateur (ressources d'autrui/inexistantes) retournent systématiquement **404** (mappage findOrFail, sans révéler l'existence de la ressource), sans code métier dédié.

---

## XIV. Facturation à l'usage

```
GET /admin/api/billing/rates          → Liste des taux de facturation (par type/spécifications de ressource)
POST /admin/api/billing/rates         → Création d'un taux
PUT /admin/api/billing/rates/{id}     → Mise à jour d'un taux
DELETE /admin/api/billing/rates/{id}  → Suppression d'un taux
GET /admin/api/billing/usage          → Résumé de l'usage (agrégé par utilisateur/ressource)
```

Pipeline de facturation : ResourceMonitor collecte toutes les 5 minutes → UsageAggregator agrège chaque heure → BillingEngine débite chaque jour, solde insuffisant → suspension de la ressource.

---

## XV. Commissions d'affiliation

### Côté utilisateur

```
GET /api/affiliate/summary
  → Aperçu des commissions (cumulées/en attente de règlement/retirables, nombre de liens, taux de conversion)

POST /api/affiliate/links
   Corps : { source? }
  → Génération d'un lien de promotion (?ref=CODE)

GET /api/affiliate/earnings
   Paramètres : status, page
  → Détail des commissions (commande attribuée, taux, statut : pending/approved/paid)

POST /api/affiliate/payout
   Corps : { amount, method }
  → Lancement d'une demande de retrait
```

### Côté administration

```
GET /admin/api/affiliate/plans                → Liste des plans de commission
POST /admin/api/affiliate/plans               → Création d'un plan de commission
GET /admin/api/affiliate/earnings             → Tous les enregistrements de commissions
POST /admin/api/affiliate/earnings/{id}/approve → Examen d'une commission
GET /admin/api/affiliate/payouts              → Liste des demandes de retrait
POST /admin/api/affiliate/payouts/{id}/approve → Examen/paiement d'un retrait
```

---

## XVI. GraphQL

```
POST /graphql
  → Requêtes publiques (produits, domaines, aide et autres données en lecture seule)
   Limites : profondeur de requête 5 niveaux, complexité 100

POST /api/graphql                          🔒 authentification requise
  → Requêtes complètes (y compris les données utilisateur)
```

**Les opérations sensibles restent REST-only** : paiement, retrait, remboursement, examen KYC ne passent pas par GraphQL.

---

## XVII. Notation des fournisseurs et avis sur les produits

### Public

```
GET /api/regions
  → Liste des régions disponibles (avec devise/fuseau horaire)

GET /api/suppliers/{supplierId}/ratings
  → Liste des notations du fournisseur (quatre dimensions : qualité/prise en
  charge/vitesse de livraison/rapport qualité-prix, seuls les approved sont renvoyés)
```

### Côté utilisateur (authentification requise)

```
POST /api/products/{productId}/reviews
   Corps : { rating, content, images? }
  → Soumission d'un avis produit (une fois par commande, affiché après examen)

POST /api/supplier/ratings
   Corps : { supplier_id, quality, support, delivery_speed, value, comment? }
  → Soumission d'une notation fournisseur (une fois par commande)

GET /api/supplier/ratings/me
  → Mes enregistrements de notation
```

### Côté administration

```
GET /admin/api/suppliers/{id}/ratings          → Toutes les notations (y compris pending)
POST /admin/api/suppliers/ratings/{id}/approve → Approbation
POST /admin/api/suppliers/ratings/{id}/hide    → Masquage
```

---

## XVIII. Webhooks de paiement

```
POST /api/payments/webhook/stripe
   En-tête : Stripe-Signature: ...
  → Rappel Stripe (paiement réussi/remboursement/contestation),
    échec de vérification de signature → 400
```

---

## XIX. Événements WebSocket

**Connexion** : `ws://host:8282` (en déploiement docker, le WS passe par le reverse proxy nginx, l'adresse de connexion est `ws://host/ws/`, le 8282 n'est exposé qu'à l'intérieur du conteneur)

L'authentification se fait par le premier message après la connexion (le token ne passe pas dans l'URL/les journaux d'accès) : après l'établissement de la connexion, un message `auth` doit d'abord être envoyé ; sans authentification sous 30 secondes, la connexion est fermée ; en cas d'échec d'authentification, une `error` est renvoyée et la connexion est fermée.

### Client → serveur

```json
{ "type": "auth", "token": "<jwt_access_token>" }
{ "type": "ping" }
```

### Serveur → client

```json
{ "type": "connected", "user_id": 123 }
{ "type": "pong", "ts": 1680000000 }
```

### Événements poussés

| Événement | Données | Moment du déclenchement |
|------|------|---------|
| `order.paid` | `{order_id, order_no, amount, currency}` | Paiement réussi |
| `resource.provisioned` | `{resource_id, type, ip_address}` | Livraison de ressource terminée |
| `resource.expiring` | `{resource_id, expired_at, days_left}` | La ressource expire bientôt |
| `ticket.updated` | `{ticket_id, title, status}` | Changement de statut du ticket |
| `notification.new` | `{notification_id, title, body}` | Nouvelle notification |

---

## XX. Référence des codes d'erreur

| code | Description |
|------|------|
| 400 | Erreur de paramètres / version API non prise en charge / plateforme client non prise en charge |
| 401 | Non authentifié / Token expiré / API Key invalide / empreinte d'appareil non conforme (Device mismatch) |
| 403 | Sans permission / rôle non fournisseur / interception WAF / échec de confirmation du mot de passe |
| 404 | Ressource inexistante (firstOrFail/findOrFail sans correspondance mappé de façon unifiée en 404) |
| 413 | Corps de requête supérieur à 10 Mo |
| 414 | URL supérieure à 2 Ko |
| 415 | Content-Type hors liste blanche (seuls application/json, multipart/form-data, x-www-form-urlencoded autorisés) |
| 422 | Échec de validation des paramètres (e-mail déjà enregistré / stock insuffisant / solde retirable insuffisant / demande déjà soumise) |
| 429 | Dépassement de la fréquence de requêtes |
| 500 | Erreur serveur |

### Messages 422 courants

| Message | Point de terminaison |
|------|------|
| `Email or phone required` | /api/auth/register |
| `Email already registered` | /api/auth/register |
| `Invalid credentials` | /api/auth/login |
| `Account temporarily locked` | /api/auth/login |
| `You already have a supplier application` | /api/supplier/apply |
| `Insufficient withdrawable balance` | /api/supplier/withdraw |
| `Product already assigned to this supplier` | /api/supplier/products |
| `Invalid or revoked API key` | /api/supplier/external/* |
| `Captcha verification failed` | /api/auth/login, /api/auth/register |
| `Email already verified` | /api/user/resend-verify-email |
| `Password too short` | /api/auth/register |
| `Unknown feature: xxx` | /admin/api/features/{name} |
| `Refund window expired: server orders are refundable within 72 hours of payment` | /admin/api/orders/{id}/refund |
| `Refund window expired: domain orders are refundable within 5 days of payment` | /admin/api/orders/{id}/refund |
| `This product type (IP) is not refundable` | /admin/api/orders/{id}/refund |
