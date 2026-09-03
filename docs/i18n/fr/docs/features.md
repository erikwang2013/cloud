# Document de conception fonctionnelle CloudPlatform

## 1. Authentification et autorisation des utilisateurs

### 1.1 Inscription

```
POST /api/v1/auth/register
  → scan WAF
  → limitation de débit 3 req/min
  → validation du mot de passe len≥8
  → vérification d'unicité e-mail/téléphone
  → bcrypt(password, cost=12)
  → génération de user_id par Snowflake::id()
  → chiffrement des champs sensibles par Encryptable::set()
  → création de User + UserProfile + UserBalance
  → envoi de l'e-mail de vérification par NotificationDispatcher::send('email_verify')
  → AuditLogger::record('user_registered')
  ← {access_token, refresh_token, expires_in, token_type}
```

**Flux de données :**

```
Client                    Middleware Chain           AuthService              DB
  │                           │                        │                     │
  │ POST /api/v1/auth/register   │                        │                     │
  │──────────────────────────▶│ WAF→RateLimit→Encrypt  │                     │
  │                           │───────────────────────▶│                     │
  │                           │                        │ User::create() ────▶│
  │                           │                        │ UserProfile::create │
  │                           │                        │ UserBalance::create │
  │                           │                        │ RefreshToken::create│
  │                           │                        │ (client_platform)   │
  │                           │                        │ AuditLogger::record │
  │◀──────────────────────────│◀───────────────────────│                     │
  │ {access_token, refresh}   │                        │                     │
```

### 1.2 Connexion

```
POST /api/v1/auth/login
  → scan WAF
  → limitation de débit 5 req/min
  → vérification Captcha (captcha à clic, limite de 3 tentatives)
  → Hash::check(password, user->password_hash)
  → 5 échecs → login_lock:{userId} Redis TTL 900 s
  → vérification TOTP (obligatoire si activé par l'utilisateur, totp_code requis ;
      erreurs cumulées 5 fois → totp_fail:{userId} → login_lock TTL 900 s)
  → détection de nouvelle IP → alerte e-mail
  → deviceFingerprint = sha256(UA + segment IP, préfixe pour IPv6)
  → clientPlatform = en-tête X-Client-Platform
  → issueTokens() : Access(15 min) + Refresh(30 j)
  → AuditLogger::record('user_login')
  ← {access_token, refresh_token}
```

### 1.3 OAuth (Google / Apple)

```
GET /api/v1/auth/google → Google OAuth → callback?code=xxx
  1. Vérifier le jeton d'ID Google/Apple
  2. Rechercher ou créer l'utilisateur (correspondance e-mail)
  3. Émettre les tokens (avec client_platform)
  4. AuditLogger::record('user_oauth_login')
```

### 1.4 Vérification en deux étapes TOTP

```
1. POST /api/v1/user/totp/setup
     → génération du secret + URL QR (stocké temporairement dans Redis 10 min,
       non persisté)
     ← {secret, qr_url, manual}
2. POST /api/v1/user/totp/verify
     → vérification du code TOTP (la première fois active le setup, ensuite
       simple vérification)
     ← {verified: true}
3. GET /api/v1/user/totp/recovery-codes
     → génération de 8 codes de récupération à usage unique (confirmation de
       mot de passe requise)
     ← {recovery_codes: [8 codes]}
4. Lors de la connexion : saisir le code TOTP ou utiliser un code de récupération
     → POST /api/v1/auth/login/recovery (login, password, recovery_code)
```

### 1.5 Gestion de session

```
GET /api/v1/user/sessions
  → RefreshToken::where(user_id, revoked=false)
  ← [{id, fingerprint, client_platform, created_at, expires_at}]

DELETE /api/v1/user/sessions/{id}
  → RefreshToken::update(revoked=true)

DELETE /api/v1/user/account (suppression GDPR)
  → confirmation de mot de passe
  → suppression logique de User
  → révocation de tous les RefreshToken
```

---

## 2. Gestion des produits

### 2.1 Modèle de produit

```
Product (1) ────── (N) ProductSku ────── (N) ProductRegion
  │                    │                      │
  │ category_id        │ specs (JSON)         │ region_id
  │ supplier_id        │ cycle (monthly/yr)   │ price
  │ status             │                      │ original_price
  │ name (JSON         │                      │ currency
  │  multilingue)      │                      │ stock
  └────────────────────┴──────────────────────┘

Product (1) ────── (N) ProductImage
Product (1) ────── (N) ProductReview
Product (1) ────── (N) ProductAttribute
```

### 2.2 Liste de produits (avec cache)

```
GET /api/v1/products?category_id=1&region_id=2&keyword=vps&page=1

CacheService::remember('products:list:{hash}', TTL 5 min)
  → Product::published()
    → with(category, skus.regionPrices)
    → filtrage par category_id/region_id/keyword/supplier_id
    → count + pagination skip/take
  ← résultat paginé

Invalidation du cache :
  modification admin product/SKU/region-price
  → CacheService::forget("products:detail:{$id}")
  → CacheService::forgetPattern('products:list:*')
```

### 2.3 Recherche de produits (Elasticsearch)

```
GET /api/v1/products/search?q=vps&page=1
  → Product::search('vps')
    → Elasticsearch (segmentation IK pour le chinois)
    → where('status', 'published')
    → paginate(20)
```

### 2.4 Avis sur les produits

```
GET /api/v1/products/{id}/reviews
  → avis vérifiés + note moyenne + répartition des notes
  ← {reviews, avg_rating, total, distribution: {5: 12, 4: 8, ...}}

POST /api/v1/products/{id}/reviews (connexion requise)
  → rating (1-5) + content
  → status = pending (affiché après validation par l'administrateur)
```

### 2.5 Import/export en masse

```
GET /admin/api/v1/products/export
  → téléchargement CSV (produit + SKU + tarifs par région)

POST /admin/api/v1/products/import
  → téléversement CSV upsert
  ← {imported: N, errors: [...]}
```

---

## 3. Système de commandes

### 3.1 Panier

```
POST /api/v1/cart          → addToCart(sku_id, region_id, quantity, cycle)
GET /api/v1/cart           → liste du panier (avec détails SKU + prix en temps réel)
DELETE /api/v1/cart/{id}   → removeFromCart
PUT /api/v1/cart/{id}      → updateCartQuantity
```

### 3.2 Flux de passage de commande

```
1. POST /api/v1/orders                            création de la commande
     → vérification du stock, calcul du prix, application des coupons
     ← {order_id, order_no, items, total}

2. POST /api/v1/coupons/validate                  application d'un coupon
     → {code: "SAVE10"}
     ← {coupon_id, discount, type: percent/fixed}

3. GET /api/v1/orders/{id}/payment-methods        obtention des canaux de paiement
     → PaymentRouter::getAvailableChannels(order)
     ← [{channel_id, name, code, amount, fee, total_amount}]

4. POST /api/v1/orders/{id}/pay                   lancement du paiement
     → confirmation de mot de passe (ConfirmationMiddleware)
     → StripeChannel::createPaymentIntent()
     ← {client_secret, transaction_id}
```

### 3.3 Cycle de vie de la commande

```
                    ┌─────────┐
                    │ pending  │ en attente de paiement
                    └────┬─────┘
                         │ paiement réussi
                    ┌────┴─────┐
                    │  paid    │ payée
                    └────┬─────┘
                         │ événement OrderPaid
                         │ → ProvisioningService
                    ┌────┴─────┐
                    │ completed│ terminée
                    └────┬─────┘
                         │ l'utilisateur demande un remboursement
                    ┌────┴─────┐
                    │ refunded │ remboursée
                    └──────────┘

Conditions de remboursement : serveur sous 72 h | domaine sous 5 jours |
IP non remboursable | articles promotionnels non remboursables (les autres
types comme disk n'ont pas de fenêtre limitée ; catégorie inconnue →
autorisé par défaut)
Flux de remboursement : demande utilisateur → création Ticket → examen du
support → confirmation admin → Provider.destroy() → Payment.refund()
```

---

## 4. Système de paiement

### 4.1 Routage multi-canaux

```
PaymentRouter::route(Order $order)
  → filtrage des canaux disponibles (is_visible + visible_regions + min/max_amount)
  → correspondance par currency
  → calcul du montant réel à payer pour chaque canal (frais inclus)
  → tri ascendant par fee
  ← [{channel: "stripe", fee: 0.49, total: 50.48}, ...]
```

### 4.2 Paiement Stripe

```
Client (Flutter)          Server (webman)            Stripe API
─────────────────         ────────────────           ──────────
1. Choix de Stripe
   → POST /orders/{id}/pay
                          2. createPaymentIntent()
                              amount (cents)
                              currency
                              metadata{order_id, order_no}
                                                    3. paymentIntents.create
                                                       ← {id, client_secret}
                          4. création de la transaction
                             (status=pending)
                           ← client_secret
5. confirmCardPayment()
   (SDK Stripe.js)
                                                    6. l'utilisateur confirme
                                                       le paiement
                                                       ← payment_intent.succeeded
                          7. POST /payments/webhook/stripe
                             Webhook::constructEvent()
                             vérification de signature stripe-signature
                             vérification d'idempotence transaction_no
                          8. transaction=success
                          9. déclenchement de l'événement OrderPaid
                             → ProvisioningService
                             → push WebSocket
                             → notifications e-mail/SMS/Push
```

### 4.3 Rapprochement

```
Cron: PaymentReconcile (quotidien à 02:37)
  → récupération des rapports de règlement de chaque canal
  → rapprochement transaction par transaction avec le système
  → écart > $0.01 → alerte
```

---

## 5. Moteur de livraison des ressources

### 5.1 Architecture de plugins Provider

```php
interface ProviderInterface {
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}

ProviderFactory:
  (productType, provider) → instance Provider
  'server:proxmox'     → ProxmoxProvider
  'server:aws_ec2'     → AwsProvider (extensible)
  'server:aliyun_ecs'  → AliyunProvider (extensible)
  'domain:namecheap'   → DomainProvider (extensible)
```

### 5.2 Chaîne de livraison complète

```
Déclenchement de l'événement OrderPaid
  │
  ▼
ProvisioningService::handleOrderPaid()
  │
  ├→ création d'un ProvisionTask pour chaque OrderItem
  │   status=pending, next_retry_at=now
  │
  ▼
ProvisionWorker (consommation Redis Queue)
  │
  ├→ ProviderFactory::create(task)
  │     └→ ProxmoxProvider
  │
  ├→ HostSelector::select(regionId, specs)
  │     tri par marge cpu/ram/disk + équilibrage de charge
  │
  ├→ IpPool::allocate(hostId)
  │
  ├→ ProxmoxApi::post('/nodes/{node}/qemu')
  │     création de la VM (vmid, name, cores, memory, net, ipconfig)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/config')
  │     montage du disque système (scsi0: pool:sizeG)
  │
  ├→ ProxmoxApi::post('/.../qemu/{vmid}/status/start')
  │     démarrage de la VM
  │
  ├→ création des enregistrements Resource + Disk + IpAllocation
  │
  ├→ mise à jour des ressources allouées de host_machine
  │
  └→ Order::status = completed
       → push WebSocket 'resource.provisioned'
       → NotificationDispatcher::send('resource_ready')

Stratégie de nouvelle tentative :
  1 min → 5 min → 15 min → 1 h → 6 h → 24 h (après 6 échecs : marqué en échec
  + alerte)
```

> **Évolution du canal de livraison :** le kvm-server Rust (`infrastructure/kvm-server`,
> workspace e-cat) est en dépôt — gRPC `ping/create_vm/vm_status` (:50051) + découverte
> d'enregistrement etcd, côté PHP KvmClient / RegistryProcess (`service/app/grpc/`)
> sont câblés. La couche pilote est actuellement un **pilote simulé** (le vrai pilote
> libvirt est en Phase 2) ; la chaîne de livraison passe donc toujours par une connexion
> directe ProxmoxProvider ; lorsque kvm-server prendra en charge la création de VM,
> le flux de cette section restera identique, seul le canal changera.

### 5.3 Résumé des opérations Proxmox

| Opération | API | Opération à chaud |
|------|-----|--------|
| Création de VM | POST /nodes/{node}/qemu | — |
| Mise à niveau CPU | PUT /qemu/{vmid}/config cores | en ligne |
| Mise à niveau mémoire | PUT /qemu/{vmid}/config memory | en ligne |
| Extension du disque système | PUT /qemu/{vmid}/resize disk | en ligne |
| Création d'un disque de données | POST /qemu/{vmid}/config scsi{n} | en ligne |
| Création d'une IP indépendante | POST /qemu/{vmid}/config net{n} | en ligne |
| Destruction de VM | POST stop → DELETE qemu | — |
| Consultation d'état | GET /qemu/{vmid}/status/current | — |

---

## 6. Système fournisseur

### 6.1 Processus d'installation (onboarding)

```
POST /api/v1/supplier/apply (connexion utilisateur requise)
  → {company_name, contact_name, contact_phone, contact_email, settlement_method}
  → status = 'pending'
  → validation par l'administrateur

Approbation par l'administrateur :
  POST /admin/api/v1/suppliers/{id}/approve (confirmation de mot de passe)
    → Supplier::status = 'active'
    → User::role = 'supplier'
    → l'utilisateur obtient les droits fournisseur

Mise en vente de produits :
  POST /api/v1/supplier/products
    → {product_id, commission_rate}
    → association du produit fournisseur

Règlement :
  Cron: SupplierSettlement (chaque lundi à 04:17)
    → comptabilisation des commandes terminées de la période
    → total_sales - commission = payable
    → création de SupplierSettlement

Retrait :
  POST /api/v1/supplier/withdraw (confirmation de mot de passe)
    → vérification du solde retirable
    → création de SupplierWithdraw (status=pending)
    → approbation et paiement par l'administrateur
```

### 6.2 API externe

```
POST /admin/api/v1/suppliers/{id}/api-keys
  → sk_ . bin2hex(random_bytes(24))
  → stockage de hash('sha256', rawKey)
  ← {api_key: "sk_xxx..."} (affiché une seule fois)

Utilisation par le fournisseur :
  GET /api/v1/supplier/external/orders
    Authorization: Bearer sk_xxx...
    → vérification de signature SupplierApiKeyMiddleware
    → filtrage des données par supplierId
```

---

## 7. Domaines et DNS

```
GET /api/v1/domain/check/{domain}/{tld}    # disponibilité du domaine
GET /api/v1/domain/tlds                     # liste des TLD enregistrables (cache 1 h)
GET /api/v1/dns/{domain}                    # liste des enregistrements DNS
POST /api/v1/dns/{domain}/records           # ajout d'un enregistrement DNS
DELETE /api/v1/dns/{domain}/records/{id}    # suppression d'un enregistrement DNS
                                         # (confirmation de mot de passe)
```

---

## 8. Système de tickets

```
POST /api/v1/tickets                    # création d'un ticket
GET /api/v1/tickets                     # mes tickets
GET /api/v1/tickets/{id}                # détail du ticket
POST /api/v1/tickets/{id}/reply         # réponse au ticket

Administrateur :
  GET /admin/api/v1/tickets              # file d'attente des tickets
  POST /admin/api/v1/tickets/{id}/assign # attribution au support
  POST /admin/api/v1/tickets/{id}/close  # fermeture du ticket

Piloté par événements :
  événement TicketCreated
    → AutoAssignListener : attribution au support le moins chargé
    → push WebSocket 'ticket.created'
```

---

## 9. Système de notifications

### 9.1 Distribution sur quatre canaux

```
Déclenchement d'événement → NotificationDispatcher::send(user, eventCode, data, channels)
  │
  ├─ email    → Redis Queue → EmailSender (SMTP/SendGrid)
  ├─ sms      → Redis Queue → SmsSender (Twilio)
  ├─ push     → Redis Queue → PushSender (FCM)
  └─ in_app   → écriture directe dans la table notifications
```

### 9.2 Types de notifications

| Événement | Canal | Moment du déclenchement |
|------|------|---------|
| Vérification d'inscription | email | après inscription par e-mail |
| Alerte de connexion anormale | email | connexion depuis une nouvelle IP |
| Paiement de commande réussi | email/push | paiement terminé |
| Livraison de ressource terminée | email/push/in_app | Provisioning terminé |
| Rappel d'expiration de ressource | email/push | 7 j/3 j/1 j avant |
| Réponse à un ticket | email/push/in_app | nouveau message de Ticket |
| Remboursement terminé | email/push | traitement du remboursement fini |
| Expiration du certificat SSL | email | 30 j avant |
| Expiration du domaine | email | 30 j avant |

---

## 10. Surveillance et alertes

### 10.1 Surveillance des ressources

```
Cron: CollectMetrics (toutes les 5 minutes)
  → interrogation des ressources actives
  → ProxmoxApi::status() / API Provider
  → stockage des métriques dans un hash Redis (TTL 1 h)

Administrateur :
  GET /admin/api/v1/monitor/dashboard
    → statistiques d'ensemble + alertes récentes
  GET /admin/api/v1/monitor/resources/{id}
    → métriques en temps réel (lecture depuis Redis)
```

### 10.2 Règles d'alerte

| Règle | Sévérité | Condition de déclenchement |
|------|--------|---------|
| server_down | critique | 3 Ping infructueux consécutifs |
| cpu_high | avertissement | CPU > 90 % pendant 10 min |
| disk_high | avertissement | disque > 90 % pendant 5 min |
| ssl_expiring | avertissement | certificat SSL expire dans < 30 jours |
| domain_expiring | avertissement | domaine expire dans < 30 jours |
| provision_failed | critique | échecs consécutifs de tâche de livraison |

---

## 11. Tâches planifiées

| Expression Cron | Tâche | Usage |
|------------|------|------|
| `13 */4 * * *` | ExchangeRateSync | synchronisation des taux de change toutes les 4 h |
| `37 2 * * *` | PaymentReconcile | rapprochement quotidien |
| `17 4 * * 1` | SupplierSettlement | règlement des fournisseurs chaque lundi |
| `23 6 * * *` | ExpirationCheck | vérification des expirations + notifications |
| `43 7 * * *` | SslCertificateCheck | vérification des certificats SSL |
| `*/5 * * * *` | CollectMetrics | collecte des métriques de ressources |
| `*/30 * * * *` | CheckExpirations | vérification des expirations de ressources |

---

## 12. Internationalisation (i18n)

### 12.1 Flux de requête

```
Client → Accept-Language: zh-CN
  → LocaleMiddleware (middleware global)
    → I18n::setLocale('zh-CN')
    → chargement de i18n/zh-CN/messages.php
```

### 12.2 Méthodes de traduction

**Texte statique :** `I18n::trans('auth.login_success')` → `登录成功`
**Champs JSON :** `{"zh-CN":"云服务器","en-US":"Cloud Server"}` + `translateField()`
**Remplacement de paramètres :** `I18n::trans('validation.required', ['field' => '邮箱'])` → `邮箱 不能为空`

### 12.3 Périmètre couvert

120 entrées, couvrant l'authentification/les produits/les commandes/le paiement/les ressources/KYC/les tickets/les notifications/les fournisseurs/les Webhooks/le système, tous les modules. Repli de langue pris en charge (langue non prise en charge → en-US).

---

## 13. Interrupteurs Feature Flags

```
config/features.php (valeurs par défaut)
  ↓ peut être remplacé
variables d'environnement .env FEATURE_*
  ↓ peuvent être remplacées à l'exécution
Redis feature:{name} (TTL 1 h, ajustement dynamique via l'API d'administration)

API d'administration :
  GET /admin/api/v1/features → liste de tous les Flags et état/source
  PUT /admin/api/v1/features/{name} → enable/disable/toggle/reset

Flags actuels :
  supplier_external_api, websocket_push, maintenance_redirect,
  totp_two_factor, google_oauth, apple_oauth, facebook_oauth,
  x_oauth, microsoft_oauth, linkedin_oauth, github_oauth
```

## 14. Certificats SSL

Les produits de certificats SSL prennent en charge les trois types DV/OV/EV, émis et renouvelés automatiquement via le protocole ACME (Let's Encrypt) ou les API de CA externes (ZeroSSL/GoGetSSL).

**Flux clé :**

    l'utilisateur choisit un forfait SSL → passe commande et paie → création de
    ProvisionTask → SslProvider::create() → CertificateAuthority::issue()
    → validation ACME HTTP-01/DNS-01 → émission du certificat
    → vérification quotidienne de expires_at → renouvellement automatique 14 j
    avant l'expiration → expiration → status=expired → notification à l'utilisateur

**Modèles de données :** `ssl_plans` (forfaits), `resource_ssl_certs` (instances de certificats)

## 15. Stockage d'objets (S3)

Stockage d'objets compatible API S3, prenant en charge AWS S3 et MinIO auto-hébergé. L'utilisateur téléverse/télécharge des fichiers via des URL pré-signées.

**Modèles de données :** `resource_storage_buckets`

## 16. Accélération CDN

Les produits CDN prennent en charge quatre fournisseurs (Cloudflare / AWS CloudFront / Alibaba Cloud CDN / Tencent Cloud CDN) ; un serveur ou un bucket de stockage peut être connecté comme origine au CDN, avec prise en charge de la purge du cache et de la configuration facultative de certificats HTTPS.

**Architecture d'adaptateurs :** sous `service/app/cdn/provider/`, un adaptateur par fournisseur, tous implémentant `CdnAdapterInterface` (createDomain / configureDomain / purgeCache / disableDomain / requiresIcpRegistration), distribués par `CdnAdapterFactory` selon le `provider_type` :

| provider_type | Adaptateur | Protocole d'accès | Enregistrement ICP requis |
|---------------|-----------|-------------------|---------------------------|
| `cloudflare` | CloudflareAdapter | API REST v4 (avec certificats automatiques SSL SaaS) | Non |
| `cloudfront` | CloudFrontAdapter | aws-sdk-php (CloudFront + ACM) | Non |
| `aliyun` | AliyunCdnAdapter | Signature RPC | Oui |
| `tencent` | TencentCdnAdapter | Signature TC3 | Oui |

**Configuration des comptes fournisseurs :** le panneau d'administration gère les comptes `provider_apis` via le CRUD `/admin/providers` (identifiants chiffrés en base via Encryptable, convention `code` `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`). Ordre de résolution des identifiants côté utilisateur : compte lié (provider_account_id) → compte actif correspondant au code → repli sur la configuration env.

**Liaison stricte par instantané :** le `provider_account_id` est fixé à la création du domaine ; les suppressions et purges de cache ultérieures n'utilisent que ce compte lié ; compte manquant ou désactivé → 4003, sans bascule silencieuse. Les domaines Alibaba/Tencent nécessitent l'enregistrement ICP, sinon 4002 (avec l'indication `requires_icp_registration`).

**Purge du cache :** `POST /api/v1/cdn/domains/{id}/purge`, les URL sont automatiquement dédupliquées et nettoyées des espaces (100 maximum), seuls le domaine lui-même ou ses sous-domaines sont autorisés, les wildcards et URL externes sont refusées, opération idempotente.

**Interfaces :** CdnAdapterInterface + CdnProvider (réutilise le canal de mise à niveau ProvisionProvider, prend en charge la mise à niveau de forfait)

**Modèles de données :** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config ; la clé privée est retirée de cert_config avant l'enregistrement en base, seules les informations de certificat non sensibles sont conservées)

## 17. Facturation à l'usage

Pipeline complet de collecte de l'usage → agrégation → facturation → débit :

    ResourceMonitor collecte les métriques toutes les 5 min → resource_metrics
      → UsageAggregator agrège chaque heure → usage_events
      → BillingEngine débite le solde chaque jour → solde insuffisant →
      suspension de la ressource
      → SuspendCheck vérifie toutes les 30 min → solde rétabli → levée de
      la suspension

**Modèles de données :** `resource_metrics`, `usage_events`, `usage_rates`, `usage_invoice_items`

## 18. Notation des fournisseurs

Les utilisateurs ayant acheté peuvent noter le fournisseur sur quatre dimensions (qualité/prise en charge/vitesse de livraison/rapport qualité-prix), une fois par commande. Le panneau d'administration peut examiner (approve/hide).

**Modèles de données :** `supplier_ratings`, `suppliers.rating_avg/rating_count`

## 19. Distribution par recommandation

L'utilisateur génère un lien de recommandation (?ref=CODE) ; lors de l'inscription d'un nouvel utilisateur, affiliate_code est lié ; après le paiement de la commande, la commission est attribuée automatiquement.

**Piloté par événements :** OrderPaid → Affiliate OrderPaidListener → attributeOrder()

**Modèles de données :** `affiliate_plans`, `affiliate_links`, `affiliate_earnings`, `affiliate_payouts`

## 20. API GraphQL

Deux points de terminaison : POST /graphql (requêtes publiques) et POST /api/v1/graphql (requêtes authentifiées). Basé sur webonyx/graphql-php, limite de profondeur de requête de 5 niveaux, limite de complexité de 100.

**Les opérations sensibles restent REST-only :** paiement, retrait, remboursement, examen KYC.

## 21. Observabilité

Le point de terminaison de métriques Prometheus est un processus indépendant 127.0.0.1:9100, non affecté par le WAF/la limitation de débit. MetricsMiddleware enregistre le comptage des requêtes HTTP et la latence. Docker Compose préconfigure Prometheus + Grafana + règles d'alerte + tableaux de bord.

**Vérifications de santé :** /health (public), /health/live, /health/ready (5 vérifications de dépendances), /health/deps (détails de latence)
