# Plateforme mondiale d'échange de ressources cloud — Conception du système

## Aperçu du projet

Plateforme d'échange de ressources cloud destinée aux utilisateurs du monde entier, prenant en charge un modèle hybride de vente propre + fournisseurs tiers. Les utilisateurs peuvent acheter des serveurs, des IP, des disques cloud, des domaines et d'autres produits cloud. Livraison de ressources entièrement automatisée, canaux de paiement multiples, devises multiples, langues multiples.

### Pile technologique

| Couche | Technologie |
|------|------|
| Application client | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| Panneau d'administration | webman-admin |
| Serveur | PHP webman (monolithe modulaire) |
| Base de données | MySQL 8.0 (maître-esclave) |
| Cache/file d'attente | Redis (cache + session + file d'attente) |
| Stockage | S3/OSS + CDN |
| Surveillance | Prometheus + Grafana + Sentry + ELK/Loki |

---

## I. Découpage en modules (12 modules principaux)

| Module | Responsabilité |
|------|------|
| **User** | Inscription/connexion (OAuth + e-mail + téléphone), vérification KYC, niveaux de membre, compte de solde |
| **Product** | Définition des produits (SKU), tarification multi-régions, gestion des stocks, catégories, recherche, évaluations |
| **Order** | Panier, commande, cycle de vie de la commande (en attente de paiement → payée → en cours de livraison → terminée → remboursée), renouvellement/mise à niveau |
| **Payment** | Routage des canaux de paiement, devis multidevises, taux de change, remboursement, rapprochement |
| **Provisioning** | Intégration des API des fournisseurs cloud, création/renouvellement/destruction automatiques des ressources |
| **Domain** | Recherche de domaines, enregistrement, transfert, renouvellement, gestion DNS |
| **Supplier** | Inscription des fournisseurs, approbation, mise en ligne des produits, règlement, partage des commissions |
| **Monitor** | Contrôle d'activité des ressources, collecte des métriques d'utilisation, règles d'alerte |
| **Ticket** | Soumission de tickets, attribution, suivi SLA |
| **Notification** | E-mail/SMS/Push App/message en interne, modèles multiples multilingues |
| **Report** | Rapports de revenus, rapports de règlement fournisseurs, tendances de ventes |
| **I18n** | Termes multilingues, taux de change multidevises, fuseaux horaires multiples |

---

## II. Modèles de données principaux

### Centre utilisateur (User)

- **users** — table principale des utilisateurs (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — profil utilisateur (user_id, avatar, nickname, country)
- **user_kyc** — vérification d'identité (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — compte de solde (user_id, currency, balance, frozen_balance)
- **user_balance_log** — journal des mouvements de solde (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — adresses utilisateur (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### Centre produits (Product)

- **product_categories** — catégories de produits (id, parent_id, name, icon, sort)
- **products** — table principale des produits (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — tarification par région (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — images produit (product_id, url, sort)
- **product_attributes** — attributs personnalisés (product_id, key, value)
- **product_reviews** — évaluations de produits (user_id, product_id, order_id, rating, content)
- **regions** — table des régions (id, name, continent, country, city, data_center, status)

### Centre commandes (Order)

- **carts** — panier (user_id, sku_id, region_id, quantity, cycle)
- **orders** — table principale des commandes (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — lignes de commande (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — chronologie de commande (order_id, status, operator, remark, created_at)
- **order_invoices** — factures (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — demandes de remboursement (order_id, user_id, amount, reason, status, handled_by)

### Centre paiement (Payment)

- **payment_channels** — configuration des canaux de paiement (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — enregistrements de transactions (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — table de rapprochement (date, channel_id, channel_total, system_total, diff, status)

### Livraison des ressources (Provisioning)

- **resources** — table principale des ressources (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — détails du serveur (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — détails IP (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — détails du disque cloud (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — détails du domaine (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — tâches de livraison (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — configuration des API des fournisseurs cloud (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### Gestion des machines physiques (Host & IP Pool)

Les serveurs physiques en propre sont gérés avec Proxmox VE (édition communautaire, gratuite), via l'API REST pour créer/gérer les VM, allouer des IP et monter des disques.

- **host_machines** — machines hôtes (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — pools d'IP (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — enregistrements d'allocation d'IP (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — détails des disques VM (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — enregistrements d'extension de disque (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### Fournisseurs (Supplier)

- **suppliers** — table principale des fournisseurs (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — association fournisseur-produit (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — bons de règlement (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — enregistrements de retrait (supplier_id, amount, method, account_info, status)

### Service de domaines (Domain)

- **domain_tlds** — TLD pris en charge (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — transferts de domaine (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — zones DNS (domain_name, user_id, zone_id)
- **dns_records** — enregistrements DNS (zone_id, type, name, value, ttl, priority)

### Tickets et notifications (Ticket & Notification)

- **tickets** — tickets (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — messages de ticket (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — enregistrements de notification (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — modèles de notification (code, name, channels, title_template, body_template, variables)

---

## III. Spécifications de conception de l'API

### Gestion des versions

La version de l'API est spécifiée via l'en-tête HTTP `X-Api-Version`, pas dans le chemin de l'URL. Le serveur injecte l'en-tête de version dans le routage interne via un middleware.

```
Requête:  GET /api/auth/login
En-tête: X-Api-Version: v1

Routage interne → /api/auth/login → contrôleur
En-tête de réponse: X-Api-Version: v1
```

**Versions prises en charge**: `v1` (par défaut, utilisée automatiquement si l'en-tête est absent)

**Mécanisme de contrôle de version**: `VersionMiddleware` valide l'en-tête `X-Api-Version` pour tous les chemins `/api/*` et `/admin/api/*` ; en l'absence d'en-tête, `v1` est utilisé par défaut ; une version non prise en charge renvoie `400`. Le numéro de version ne figure plus dans le chemin de l'URL.

**Étapes pour ajouter une version**:
1. Ajouter le numéro de version au tableau `VersionMiddleware::SUPPORTED`
2. Enregistrer le nouveau groupe de routes de version dans `route.php`
3. Le contrôleur récupère la version via `$request->properties['api_version']` pour un traitement différencié

### Routage RESTful

```
Préfixe unifié: /api
Panneau d'administration: /admin/api
```

**Groupes de routes et matrice de middlewares:**

| Groupe de routes | Middlewares | Exemples d'endpoints |
|--------|--------|---------|
| Public (sans préfixe) | Chaîne de middlewares globale | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | Globale + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (utilisateur) | Globale + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (sensible) | Globale + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | Globale + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (sensible) | Globale + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### Format de réponse unifié

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 1523
  },
  "request_id": "req_abc123"
}
```

### Schéma d'authentification

| Côté | Méthode |
|----|------|
| Utilisateur | JWT (access_token 2h + refresh_token 30j) + vérification en deux étapes TOTP + codes de récupération |
| Administration | JWT (access_token 2h + refresh_token 7j) |
| API fournisseur | API Key (préfixe sk_, stockée en hash SHA256, affichée une seule fois à la création) |
| Rappels fournisseurs cloud | Vérification de signature (HMAC-SHA256) |

**Fonctions d'authentification implémentées**:
- Inscription par e-mail + lien de vérification par e-mail
- Inscription par téléphone + code de vérification SMS Twilio (refroidissement 60 s + limitation de débit IP 5 fois/heure)
- Connexion Google OAuth / Apple Sign In
- Mot de passe oublié (code de vérification par e-mail + TTL Redis 10 min)
- Vérification en deux étapes TOTP (configuration par scan de code QR, codes de récupération de secours)
- Gestion des sessions actives (voir/révoquer les appareils connectés, avec informations client_platform)
- Suppression du compte conformément au GDPR (confirmation du mot de passe + suppression douce + révocation de tous les jetons)
- Alerte de connexion anormale (notification par e-mail en cas de connexion depuis une nouvelle IP)
- Verrouillage de connexion (5 échecs → verrouillage 15 minutes)

**Flux d'authentification utilisateur:**

```
Flux d'inscription                         Flux de connexion
─────────────                             ────────────────
1. POST /captcha/create                   1. POST /captcha/create
   ← {key, image(position de clic)}          ← {key, image}
2. POST /auth/register                    2. POST /auth/login
   → {email, password, captcha}              → {login, password, captcha}
   → [Analyse WAF]                           → [Analyse WAF]
   → [Limitation: 3 req/min]                 → [Limitation: 5 req/min]
   → [Mot de passe bcrypt(cost=12)]          → [Hash::check()]
   → [Empreinte d'appareil: sha256(UA+IP)]   → [Empreinte d'appareil: sha256(UA+IP)]
   → [Enregistrement client_platform]        → [Enregistrement client_platform]
   → User::create()                          → [5 échecs → verrou 15min]
   → RefreshToken::create()                  → [Nouvelle IP détectée → alerte e-mail]
     user_id, token_hash,                    → RefreshToken::create()
     device_fingerprint,                         user_id, token_hash,
     client_platform,                            device_fingerprint,
     expires_at                                  client_platform,
   → NotificationDispatcher::send()               expires_at
     (e-mail de vérification)               → AuditLogger::record('user_login')
   → AuditLogger::record                   ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}        OAuth (Google/Apple):
                                          ─────────────────────
                                          1. GET /auth/google
                                          2. Autorisation Google → code
                                          3. GET /auth/google/callback?code=xxx
                                          4. Vérification du jeton Google
                                          5. Création ou recherche de l'utilisateur
                                          6. Émission de jetons (avec client_platform)
                                          7. AuditLogger::record('user_oauth_login')

Vérification TOTP en deux étapes            Gestion des sessions
──────────────────────────                 ───────────────────
1. POST /user/totp/setup                   GET /user/sessions
   ← {secret, qr_code_url}                    ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                            created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}               DELETE /user/sessions/{id}
3. POST /auth/login                          → RefreshToken::update(revoked=true)
   → {login, password, totp_code}            ← Succès
   ou → /auth/login/recovery
   → {login, password, recovery_code}      DELETE /user/account
                                             → confirmation du mot de passe +
Mécanisme de verrouillage de connexion       suppression douce + révocation de
──────────────────────────────               tous les jetons
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### Solution multilingue

- En-tête de requête: Accept-Language: zh-CN / en-US / ja-JP
- Stockage multilingue dans des colonnes JSON: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- Fichiers i18n pour les textes statiques, un ensemble pour le frontend et un pour le backend

---

## IV. Système de protection de la sécurité

### Modèle de protection en couches

```
┌─────────────────────────────────────────────────────┐
│ Couche 1: Protection du périmètre réseau             │
│   Anti-DDoS / WAF / Liste blanche et noire IP /      │
│   Geo-Blocking                                        │
├─────────────────────────────────────────────────────┤
│ Couche 2: Protection du transport et de l'application│
│   HTTPS+TLS1.3 / CSP / CORS / Auth JWT / Limitation  │
│   de débit                                            │
├─────────────────────────────────────────────────────┤
│ Couche 3: Sécurité des données et du stockage        │
│   Stockage chiffré / Masquage / Journaux d'audit /   │
│   Sauvegardes                                        │
├─────────────────────────────────────────────────────┤
│ Couche 4: Virtualisation et isolation des ressources │
│   Durcissement Proxmox / Isolation entre VM /        │
│   Isolation réseau                                    │
├─────────────────────────────────────────────────────┤
│ Couche 5: Exploitation et gestion des risques        │
│   Audit des opérations / Détection d'anomalies /     │
│   Alertes / Réponse aux incidents                    │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 Protection du périmètre réseau

#### Protection anti-DDoS

```
Requête utilisateur → CDN (Cloudflare / CDN Alibaba Cloud)
              │
              ├── Défi JS / CAPTCHA (trafic suspect)
              ├── Limitation de débit (requêtes par IP et par seconde)
              ├── Blocage régional (bloquer des pays/régions spécifiques)
              │
              ▼
          Serveur d'origine (Nginx + webman)
```

| Couche | Mesure | Description |
|------|------|------|
| Couche CDN | Nettoyage anti-DDoS automatique | L'offre gratuite Cloudflare prend déjà en charge la protection L3/L4 |
| Couche CDN | Bot Management | Identifier et bloquer les robots malveillants/scripts de commandes frauduleuses |
| Couche Nginx | limit_req_zone | 10 req/s par IP, retour 429 en cas de dépassement |
| Couche Nginx | limit_conn | Maximum 20 connexions simultanées par IP |
| Couche webman | Middleware de limitation à seau de jetons | Limitation précise par utilisateur/endpoint |

#### Règles WAF (middleware webman)

Le middleware WAF analyse les requêtes via 8 groupes de règles de regex, la configuration est stockée dans `config/security.php` et peut être rechargée à chaud sans redémarrage. L'analyse couvre le corps JSON de la requête, le chemin d'URL + la chaîne de requête, le User-Agent et le corps brut de la requête (anti-échappement d'encodage JSON).

**8 catégories de règles de détection (45+ règles):**

| Catégorie | Couverture |
|------|---------|
| Injection SQL | Guillemets simples/symboles de commentaire, mots-clés SQL, encodage hexadécimal, variantes d'union, conditions toujours vraies (`' OR '1'='1`), injection temporelle (`sleep`/`benchmark`), requêtes empilées, contournement par commentaires multi-lignes |
| XSS | Balises HTML (y compris variantes encodées), balises Script et variantes, 13 gestionnaires d'événements JS, objets globaux/fonctions dangereuses JS, pseudo-protocole `javascript:`, encodage d'entités HTML, injection Data URI, attributs d'événements en ligne |
| Injection de commandes | Pipe suivi d'une commande (`\| cat`), point-virgule suivi d'une commande (`; whoami`), substitution de commande `$(cmd)` et backticks, mots-clés de commandes isolées |
| Inclusion de fichiers | Traversée de chemins (multi-encodage), pseudo-protocoles PHP (`php://`/`data://`/`phar://`), sondage de chemins absolus (`/etc/`/`C:\`), injection de null byte |
| Injection d'en-têtes HTTP | Injection CRLF (`%0d%0a`/`\r\n`), injection dans les en-têtes Host/Cookie/Set-Cookie |
| **SSRF** | Adresses IPv4 internes (127.x/10.x/172.16-31.x/192.168.x), alias localhost, endpoints metadata cloud (169.254.169.254), protocole file:// |
| **Injection NoSQL** | Opérateurs MongoDB ($where/$gt/$regex/$or, etc.), injection JS $where, commandes Redis dangereuses (FLUSHALL/CONFIG SET/SHUTDOWN) |
| **Redirection ouverte** | Détection d'URL externes dans les paramètres redirect_uri/return_url/next/callback, contournement par double encodage |

**Protection au niveau de la requête:**

| Élément protégé | Mesure |
|--------|------|
| Limite de taille du corps de requête | 10 Mo maximum (retour 413 au-delà) |
| Limite de longueur d'URL | 2 Ko maximum (retour 414 au-delà, anti-ReDoS) |
| Liste blanche de Content-Type | Uniquement application/json, multipart/form-data, application/x-www-form-urlencoded |

**Flux de détection WAF:**

```
Entrée de la requête
  │
  ▼
1. Récupérer le texte à analyser
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # corps de requête
   │     └── false → repli serialize()
   ├── mb_substr(path + queryString, 0, 2048)                # URL (troncature anti-ReDoS)
   ├── en-tête User-Agent                                     # UA
   └── file_get_contents('php://input')                      # corps brut (anti-échappement JSON)
  │
  ▼
2. Charger les règles (depuis config/security.php)
   ├── security.waf.sqli_patterns               (9 règles)
   ├── security.waf.xss_patterns                (8 règles)
   ├── security.waf.cmd_injection_patterns      (5 règles)
   ├── security.waf.file_inclusion_patterns     (4 règles)
   ├── security.waf.header_injection_patterns   (2 règles)
   ├── security.waf.ssrf_patterns               (6 règles)
   ├── security.waf.nosql_injection_patterns    (3 règles)
   └── security.waf.open_redirect_patterns      (2 règles)
   → array_merge() + array_unique()
  │
  ▼
3. Correspondance règle par règle
   foreach patterns as pattern:
     match($pattern, $input) ───→ correspondance → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ correspondance → retour 403 "Request blocked by WAF"
     match($pattern, $ua)    ───→ correspondance →
     match($pattern, $raw)   ───→ correspondance →
  │
  ▼
4. Vérification stricte dans match()
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → correspondance ✓
   ├── $result === 0    → pas de correspondance (autorisation sûre)
   └── $result === false → erreur de pattern → error_log() → traité comme pas de correspondance
  │
  ▼
5. Aucune correspondance → $next($request) passe au middleware suivant
```

```php
class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        $url = mb_substr($request->path() . '?' . $request->queryString(), 0, 2048);
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

        // Load 8 rule groups from config/security.php
        $patterns = array_unique(array_merge(
            config('security.waf.sqli_patterns'),
            config('security.waf.xss_patterns'),
            config('security.waf.cmd_injection_patterns'),
            config('security.waf.file_inclusion_patterns'),
            config('security.waf.header_injection_patterns'),
        ));

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input)
                || $this->match($pattern, $url)
                || $this->match($pattern, $ua)
                || $this->match($pattern, $raw)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }

    private function match(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            error_log("WAF: invalid regex pattern: $pattern");
            return false;
        }
        return $result === 1;
    }
}
```

#### Liste blanche et noire IP

```
Liste noire:
- Base de données d'IP malveillantes connues (synchronisation périodique avec AbuseIPDB)
- IP déclenchant fréquemment les règles WAF (ajout automatique, TTL Redis 24h)
- IP de tentatives de connexion par force brute (5 échecs → verrouillage 30min)

Liste blanche:
- IP des machines hôtes Proxmox
- Plages d'IP des rappels des fournisseurs cloud
- Plages d'IP des webhooks des passerelles de paiement
- Réseau de bureau des administrateurs (optionnel)
```

#### Geo-Blocking

```php
// Bibliothèque GeoIP2 (MaxMind)
$country = geoip($request->getRealIp());

// Liste de blocage configurable
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 Sécurité du transport et de l'application

#### Chaîne d'exécution des middlewares globaux

Toutes les requêtes HTTP passent par les middlewares dans l'ordre suivant, chacun étant testable indépendamment :

```
Requête → VersionMiddleware        # validation X-Api-Version (v1 par défaut si absent, 400 si invalide)
     → CorsMiddleware            # en-têtes de réponse CORS
     → ClientPlatformMiddleware  # identification X-Client-Platform (8 plateformes), injection dans $request->properties
     → WafMiddleware             # analyse de sécurité 8 catégories, 45+ règles (SQLi/XSS/injection de commandes/inclusion de fichiers/injection d'en-têtes/SSRF/NoSQL/redirection ouverte)
     → LocaleMiddleware          # analyse Accept-Language, définition de la locale
     → HashidRequestMiddleware   # décodage hashid → ID réels dans les paramètres de requête
     → MaintenanceMiddleware     # mode maintenance (liste blanche IP autorisée)
     ↓
  [Middlewares de route — attachés par groupe de routes]
     → EncryptionMiddleware      # chiffrement AES-256-GCM du corps requête/réponse
     → Captcha                   # validation du CAPTCHA à clic (avant connexion/inscription)
     → AuthMiddleware            # vérification du jeton porteur JWT + injection du rôle
     → AdminRoleMiddleware       # contrôle des permissions RBAC administrateur
     → ConfirmationMiddleware    # confirmation du mot de passe pour les opérations sensibles (5 échecs → verrou 15min)
     ↓
     Contrôleur
```

#### Responsabilités de chaque middleware

| Middleware | Enregistrement | Responsabilité |
|--------|---------|------|
| `VersionMiddleware` | Global | Valide l'en-tête `X-Api-Version`, `v1` par défaut si absent, retour `400` pour une version non prise en charge |
| `CorsMiddleware` | Global | Gère la pré-vérification OPTIONS, reflète l'Origin dans `Access-Control-Allow-Origin` |
| `ClientPlatformMiddleware` | Global | Valide l'en-tête `X-Client-Platform`, identifie le système d'exploitation du client (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web), injecte `$request->properties['client_platform']` |
| `WafMiddleware` | Global (service) + instance admin | 8 catégories, 45+ règles + limite de taille de requête + validation Content-Type, journal d'audit après interception |
| `LocaleMiddleware` | Global | Analyse l'en-tête `Accept-Language`, définit la locale multilingue |
| `HashidRequestMiddleware` | Global | Décode automatiquement les chaînes hashid des requêtes en ID entiers réels |
| `MaintenanceMiddleware` | Global | Vérifie la variable d'environnement `MAINTENANCE_MODE`, autorise les IP de la liste blanche |
| `EncryptionMiddleware` | Groupe de routes (/api/auth, /api, /admin/api) | Chiffrement AES-256-GCM du corps requête/réponse, déclenché par l'en-tête `X-Encrypted: 1` |
| `AuthMiddleware` | Groupe de routes (/api, /admin/api) | Vérification du JWT HS256 access_token, injection de `$request->userId` et `$request->userRole` |
| `AdminRoleMiddleware` | Groupe de routes (/admin/api) | Contrôle des permissions RBAC administrateur |
| `ConfirmationMiddleware` | Groupe de routes (opérations sensibles) | Confirmation du mot de passe, compteur d'échecs Redis, verrouillage 15 minutes après 5 échecs |

#### Détails du middleware ClientPlatform

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // Only applies to API routes
        $path = $request->path();
        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/admin/api/')) {
            return $next($request);
        }

        $platform = strtolower(trim($request->header('X-Client-Platform', '')));

        if ($platform === '') {
            $platform = 'unknown';
        } elseif (!in_array($platform, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported client platform: {$platform}")
            ), 400, ['X-Client-Platform' => $platform]);
        }

        // Inject request property for downstream use (audit logs, session records)
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**Flux de données**: injection middleware → enregistrement automatique par `AuditLogger` → écriture dans `refresh_tokens` par `AuthService::issueTokens()` → `GET /api/user/sessions` renvoie les informations de plateforme

#### Imposition de HTTPS

```nginx
# Nginx configuration
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload";
    add_header X-Content-Type-Options "nosniff";
    add_header X-Frame-Options "DENY";
    add_header X-XSS-Protection "1; mode=block";
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
}
```

#### Durcissement de la sécurité JWT

```
- access_token valable 2h, refresh_token valable 30j
- Clé RSA256 (asymétrique), rotation périodique (90 jours)
- jti (JWT ID) stocké dans Redis pour une révocation active
- refresh_token lié à l'empreinte de l'appareil (User-Agent + plage IP)
- Le refresh_token précédent est invalidé immédiatement lors de l'émission d'un nouveau (rotation)
- Les opérations sensibles (paiement/destruction de ressources) exigent une vérification en deux étapes

Empreinte d'appareil:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  La table refresh_token enregistre cette empreinte, vérifiée lors de la rotation
```

#### Politique de mot de passe

```
- Chiffrement bcrypt, facteur de coût = 12
- Minimum 8 caractères, doit contenir des lettres majuscules et minuscules + des chiffres
- 5 échecs consécutifs d'inscription/connexion → verrouillage du compte 15 minutes
- Après modification du mot de passe, tous les jetons émis sont immédiatement invalidés
- Vérification en deux étapes TOTP prise en charge (activation facultative par l'utilisateur)
```

#### Politique CORS

```php
// middleware webman
class CorsMiddleware
{
    public function process(Request $request, callable $next): Response
    {
        $allowedOrigins = config('cors.allowed_origins', []);
        $origin = $request->header('Origin');

        $response = $next($request);

        if (in_array($origin, $allowedOrigins)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type,Authorization,Accept-Language');
            $response->header('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
```

#### Sécurité du téléversement de fichiers

```
- Validation de la liste blanche des extensions (uniquement: jpg, jpeg, png, pdf, gif)
- Validation du type MIME du fichier (interdiction des Content-Type falsifiés)
- Limite de taille des fichiers: avatar 2 Mo, documents KYC 5 Mo, pièces jointes 10 Mo
- Renommage après téléversement: {uuid}.{ext}, le nom de fichier d'origine n'est pas conservé
- Traitement secondaire des images: GD/Imagick pour supprimer EXIF + métadonnées
- Les chemins de stockage ne sont pas accessibles via le web, lecture via un proxy PHP
- Analyse antivirus: ClamAV (documents KYC/fichiers téléversés par les utilisateurs)
```

---

### 4.3 Sécurité des données et du stockage

#### Chiffrement des données sensibles

```
Algorithme de chiffrement: AES-256-GCM (chiffrement authentifié, anti-falsification)
Gestion des clés: clé maîtresse dans les variables d'environnement, clé dérivée indépendante pour chaque champ

Champs nécessitant un stockage chiffré:
| Type de données | Champ | Méthode de chiffrement |
|----------|------|----------|
| Mot de passe | users.password_hash | bcrypt (sens unique) |
| Clé de paiement | payment_channels.api_key | AES-256-GCM |
| Clé de fournisseur cloud | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Jeton Proxmox | host_machines.api_token_encrypted | AES-256-GCM |
| Numéro de document KYC | user_kyc.id_number | AES-256-GCM |
| Compte de paiement | compte de retrait | AES-256-GCM |
| Mot de passe de connexion (VNC) | resource_servers.login_password | AES-256-GCM |

Dérivation de clé:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### Masquage des journaux

```php
class LogSanitizer
{
    // Field name patterns sanitized automatically
    private array $sensitiveFields = [
        'password', 'password_hash', 'secret', 'api_key',
        'token', 'credit_card', 'cvv', 'ssn', 'id_number',
        'login_password', 'private_key',
    ];

    public function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->matchSensitive($key)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }
}

// Monolog Processor calls it automatically before writing logs
```

#### Sécurité de la base de données

```
- MySQL utilise des prepared statements (gérés automatiquement par Eloquent)
- Principe du moindre privilège pour les comptes d'accès à la base:
  - app_user: SELECT, INSERT, UPDATE, DELETE (pas de DDL)
  - migration_user: droits DDL (utilisé uniquement pour les migrations, IP restreinte)
  - read_user: SELECT en lecture seule (rapports/analyse de données)
- Connexions via SSL/TLS (options SSL PHP PDO)
- Le port de la base de données n'est pas exposé au public (accès réseau interne uniquement)
- Sauvegardes périodiques: sauvegarde complète tous les jours, binlog synchronisé en temps réel
```

#### Sauvegarde et récupération des données

```
Stratégie de sauvegarde:
- MySQL: sauvegarde complète quotidienne + incrémentale binlog en temps réel
- Redis: RDB toutes les heures + persistance AOF en temps réel
- Fichiers téléversés par les utilisateurs: S3/OSS avec réplication multi-copies + cross-région
- Instantanés de VM Proxmox: hebdomadaires (conservation 4 semaines)
- Chiffrement des sauvegardes: stockage après chiffrement AES-256

Exercices de récupération:
- Exercice de reprise après sinistre chaque trimestre
- Objectif de temps de récupération (RTO): < 4 heures
- Objectif de point de récupération (RPO): < 1 heure
```

---

### 4.4 Virtualisation et isolation des ressources

#### Durcissement de la sécurité Proxmox

```
1. Contrôle d'accès API:
   - L'API Proxmox n'écoute que sur l'IP interne (pas de liaison publique)
   - Privilèges de jeton minimisés: chaque rôle ne reçoit que les permissions nécessaires
   - Le port API (8006) n'autorise que l'IP du serveur d'application PHP (iptables)

2. Durcissement SSH:
   - Connexion par mot de passe désactivée, authentification par clé uniquement
   - Connexion root désactivée, compte d'administration dédié
   - Port SSH non standard (réduction du balayage)
   - Fail2ban: verrouillage 1 heure après 5 échecs

3. Mises à jour système:
   - Abonnement aux alertes de sécurité Proxmox par e-mail
   - apt update && apt upgrade périodiques
   - Livepatch du noyau (Canonical Livepatch Service)

4. Pare-feu (iptables/nftables):
   - Refus de tout trafic entrant par défaut
   - Ouvert uniquement: 8006 (IP du serveur d'application uniquement), port SSH (IP d'administration uniquement)
   - Isolation entre le pont réseau des VM et le réseau de gestion de l'hôte
```

#### Isolation entre VM

```
- Chaque VM utilise un pont virtuel VLAN indépendant
- Communication inter-VM interdite (règles du pare-feu Proxmox + isolation VLAN)
- Les utilisateurs ne peuvent accéder à leur VM que via l'IP publique
- Limites de ressources VM (cgroup): empêcher une VM d'épuiser les ressources de l'hôte
  - Limite CPU: plafond du nombre de cœurs achetés
  - Limite RAM: plafond de la capacité achetée
  - Limite IOPS disque: prévention de la contention disque
  - Limite de bande passante réseau: plafond de la bande passante achetée
```

#### Sécurité de l'allocation IP

```
- Enregistrement d'audit complet des allocations IP (qui, quand, quelle IP allouée)
- Période de refroidissement de 24h après libération d'une IP (éviter les mauvaises utilisations dues à une réallocation immédiate)
- Liste noire IP: les IP signalées pour abus sont marquées non allouables
- Surveillance de l'utilisation IP: vérifications régulières que les IP allouées sont bien utilisées
```

---

### 4.5 Sécurité des paiements

```
1. Conformité PCI DSS:
   - Les données de carte de crédit ne transitent pas par nos serveurs (Stripe Elements / Checkout)
   - card_token est généré directement par le frontend Stripe, le backend ne reçoit que le jeton
   - Aucun CVV/numéro complet de carte stocké dans les journaux ou la base de données

2. Cryptomonnaie:
   - Clés privées de réception en stockage à froid (signature hors ligne)
   - Le portefeuille chaud ne conserve que le montant de trésorerie quotidien
   - Vérification de la somme de contrôle après génération de l'adresse de réception
   - Transactions importantes (> $10000) vérifiées manuellement avant confirmation

3. Anti-fraude des paiements:
   - Paiements à haute fréquence du même utilisateur/IP en peu de temps → gel par la gestion des risques
   - Paiement important d'un utilisateur nouvellement inscrit → vérification manuelle
   - Montant de paiement anormal (incohérent avec le prix du produit) → blocage
   - Utilisateurs avec un taux de remboursement trop élevé → marqués pour la gestion des risques

4. Vérification de signature des rappels:
   - Stripe: validation de la signature webhook (en-tête stripe-signature)
   - Coinbase: validation de la signature webhook (en-tête X-CC-Webhook-Signature)
   - Alipay: validation de notify_id, double confirmation via un appel aux serveurs Alipay
   - Tous les rappels: validation que l'IP appartient aux plages d'IP connues des passerelles de paiement
```

#### Sécurité des remboursements

```
- Les remboursements exigent une approbation à deux niveaux (initiée par le support → confirmée par l'administrateur)
- Vérifications avant remboursement: statut de la commande, délai de remboursement, nombre de remboursements
- Le montant du remboursement ne peut pas dépasser le montant réellement payé de la commande d'origine
- Retour à la source: interface de remboursement du canal de paiement + remboursement du solde
- Verrou mutex de remboursement (Redis): prévenir les remboursements dupliqués concurrents
```

---

### 4.6 Contrôle d'accès et permissions

#### Modèle RBAC

```
Hiérarchie des rôles:
  super_admin    (super administrateur — toutes les permissions)
  admin          (administrateur — tout sauf la configuration système)
  finance        (finances — paiement/rapprochement/remboursement/règlement)
  support        (support — gestion des utilisateurs/commandes/tickets)
  supplier       (fournisseur — ses propres produits/commandes/règlements)
  user           (utilisateur — ses propres ressources/commandes/tickets)

Définition des permissions:
  {module}.{action}
  Ex.: order.view, order.create, order.refund, resource.destroy

Middleware de contrôle des permissions:
  class RbacMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $user = Auth::user();
          $requiredPermission = $request->route->get('permission');
          
          if (!$user || !$user->hasPermission($requiredPermission)) {
              AuditLog::unauthorized($user, $requiredPermission, $request);
              return errorResponse(403, 'Forbidden');
          }
          return $next($request);
      }
  }
```

#### Limitation de débit de l'API

```php
// middleware de limitation webman (seau de jetons Redis)
class RateLimitMiddleware
{
    // Default: 60 req/min per user
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // anti brute-force
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // anti inscriptions en masse
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // limitation des paiements
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // appels API
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // limitation des téléversements
    ];
    
    public function process(Request $request, callable $next): Response
    {
        $route = $request->route->getName();
        $limit = $this->limits[$route] ?? $this->limits['default'];
        $key = "ratelimit:{$request->getRealIp()}:{$route}";
        
        if (!$this->checkLimit($key, $limit)) {
            return errorResponse(429, 'Too Many Requests', [
                'retry_after' => $limit['per'],
            ]);
        }
        return $next($request);
    }
}
```

#### Isolation des données fournisseurs

```
Principe d'isolation des données:
- Un fournisseur ne peut consulter et manipuler que ses propres ressources
- Toute requête impliquant supplier_id ajoute automatiquement WHERE supplier_id = auth()->supplier_id

Implémentation:
  // Global Scope
  class SupplierScope implements Scope
  {
      public function apply(Builder $builder, Model $model)
      {
          if ($user = Auth::user()) {
              if ($user->role === 'supplier') {
                  $builder->where('supplier_id', $user->supplier_id);
              }
          }
      }
  }
  
  // Registered on Product/Order and other Models
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 Audit des opérations

```
Contenu des journaux d'audit:
- ID de l'opérateur, IP, User-Agent
- Heure de l'opération
- Module de l'opération (quel menu/endpoint)
- Type d'opération: création/modification/suppression/export/approbation
- Objet de l'opération: quel champ de quelle ressource
- Valeur avant / valeur après (changement au niveau du champ)
- Résultat de l'opération: succès/échec
- ID de requête (traçabilité de bout en bout)

Périmètre d'enregistrement:
- Toutes les opérations du panneau d'administration (enregistrement à 100 %)
- Opérations sensibles côté utilisateur: paiement/destruction de ressources/soumission KYC/modification du mot de passe (100 %)
- Connexions/déconnexions (100 %)
- Création/révocation d'API Keys (100 %)

Stockage et conservation:
- Les journaux d'audit sont écrits dans une base de données distincte (audit_db), séparée de la base applicative
- Conservation minimale de 1 an, 3 ans pour les éléments liés aux finances
- Export CSV/JSON pris en charge pour les audits de conformité

Middleware des journaux d'audit:
  class AuditMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $startTime = microtime(true);
          $response = $next($request);
          $duration = microtime(true) - $startTime;
          
          if ($this->shouldAudit($request)) {
              AuditLog::record([
                  'user_id'    => Auth::id(),
                  'ip'         => $request->getRealIp(),
                  'method'     => $request->method(),
                  'path'       => $request->path(),
                  'input'      => LogSanitizer::sanitize($request->all()),
                  'status'     => $response->getStatusCode(),
                  'duration'   => $duration,
                  'request_id' => $request->header('X-Request-Id'),
                  'user_agent' => $request->header('User-Agent'),
              ]);
          }
          return $response;
      }
  }
```

---

### 4.8 Règles de gestion des risques

```
Moteur de gestion des risques en temps réel:

Règle 1: Comportement anormal des nouveaux comptes
  Condition: temps d'inscription < 24h ET (total des paiements > $500 OU plus de 5 tickets créés)
  Action: marquer le compte « sous observation », notifier le gestionnaire des risques

Règle 2: Détection des inscriptions en masse
  Condition: plus de 3 comptes inscrits depuis la même IP en 24h
  Action: refuser les nouvelles inscriptions, geler les nouveaux comptes sous cette IP

Règle 3: Anomalie de paiement
  Condition: plus de 5 échecs de paiement en 1h pour le même utilisateur
  Action: geler la fonction de paiement 2h, générer un ticket de gestion des risques

Règle 4: Abus de remboursements
  Condition: plus de 3 remboursements en 30 jours pour le même utilisateur OU taux de remboursement > 20 %
  Action: limiter le droit de remboursement du compte, marquer les nouvelles commandes pour examen des risques

Règle 5: Abus d'API
  Condition: plus de 10 000 appels d'API en 1h pour un même jeton
  Action: dégrader ce jeton (abaisser le seuil de limitation), notifier l'administrateur

Règle 6: Abus de ressources
  Condition: VM signalée pour spam/DDoS/minage de cryptomonnaies (réception de notifications d'abus)
  Action: arrêt automatique, gel de la ressource, génération d'un ticket haute priorité

Actions de gestion des risques:
- Marquer (flag): enregistrement seul, aucune incidence sur l'utilisation
- Dégrader (throttle): abaisser le seuil de limitation
- Geler (freeze): désactiver temporairement une fonctionnalité spécifique
- Bannir (ban): bannissement permanent du compte
```

---

### 4.9 Réponse aux incidents

```
Niveaux de gravité des incidents de sécurité:

P0 (urgent) — fuite de données, perte financière, panne de la plateforme
  → notification immédiate du CTO + équipe sécurité
  → lancement de la réponse aux incidents sous 30 minutes
  → mise hors ligne des services amont affectés, conservation des preuves
  → rapport d'incident publié sous 24h après la correction

P1 (grave) — vol d'un compte, fraude de paiement, hausse anormale des déclenchements WAF
  → notification du responsable sécurité
  → traitement sous 2h
  → gel des comptes/ressources concernés

P2 (normal) — vulnérabilités de gravité faible/moyenne trouvées par des analyses, alertes de connexion anormale
  → saisie dans le système de tickets
  → correction au prochain itération

Contacts d'urgence:
- Notification automatique après déclenchement d'alertes P0/P1 (e-mail + SMS + téléphone)
- Endpoint de contrôle d'état webman: GET /health (retour 200 ou alerte)
- Tableau d'astreinte: roulement 7×24, au moins 2 personnes de garde
```

---

## V. Moteur de livraison des ressources

### Architecture des plugins Provider

Chaque combinaison type de produit cloud × fournisseur cloud implémente une interface unifiée :

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // Dedicated to self-operated physical machines
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

ProviderFactory route vers l'implémentation concrète selon (product_type, provider) :
- ProxmoxProvider (machines physiques en propre: serveurs/disques de données/IP)
- AwsServerProvider / AliyunServerProvider (serveurs cloud tiers)
- GcpIpProvider (IP tierces)
- AzureDiskProvider (disques cloud tiers)
- NamecheapDomainProvider / GoDaddyDomainProvider (domaines)

### Garantie des tâches asynchrones

- Le worker de livraison interroge la table provision_tasks
- Contrôle de la concurrence par provider (maximum 5 en parallèle par provider)
- Stratégie de nouvelle tentative: 1min → 5min → 15min → 1h → 6h → 24h (6 tentatives maximum)
- Échec non réessayable → alerte + génération automatique d'un ticket

### Chaîne complète de la commande à la livraison des ressources

```
Commande utilisateur                        Paiement                        Livraison des ressources
──────────────────                        ────────                        ────────────────────────
1. POST /cart                             5. POST /orders/{id}/pay        9. Événement OrderPaid
   → addToCart(sku, region, qty)             → confirmation du mot de passe  → ProvisioningService
                                               (Confirmation)                .handleOrderPaid()
2. POST /orders                              → PaymentRouter::route()
   → createOrder()                             sélection du canal         10. Pour chaque OrderItem:
   ← {order, order_items}                       de paiement                   → ProvisionTask::create()
                                                                                status=pending
3. Application du bon de réduction         6. StripeChannel::
   POST /coupons/validate                      createPaymentIntent()     11. Worker de la file Redis Queue
   → validate('CODE', order_total)             → API Stripe                   → ProviderFactory
   ← {discount, coupon_id}                     ← {client_secret}              .create(task)
                                           7. confirmCardPayment() côté
4. GET /orders/{id}/payment-methods            frontend                  12. Provider->create()
   → récupération des canaux               8. Rappel webhook Stripe           ├→ HostSelector::select()
     de paiement disponibles                  → vérification de signature     ├→ ProxmoxApi::create()
   ← [{channel, fee, total}]                    + contrôle d'idempotence        │  createVM(CPU,RAM,Disk)
                                                → transaction=success          │  allocateIP()
                                                → déclenchement                │  startVM()
                                                de l'événement OrderPaid        ├→ création de l'enregistrement
                                                                                │  Resource
                                            Stratégie de nouvelle tentative   └→ mise à jour de host_machine
                                            ────────────────────────              ressources déjà allouées
                                            1min → 5min → 15min
                                            → 1h → 6h → 24h              13. Order::status = completed
                                            (échec après 6 tentatives          → NotificationDispatcher
                                             + alerte)                           ::send('resource_ready')
                                            Processus de remboursement
                                            ──────────────────────
                                            demande utilisateur → examen
                                            support → confirmation admin
                                            → provider.destroy()
                                            → payment.refund()
                                            → retour à la source
```

### Solution machines physiques en propre : Proxmox VE (édition communautaire)

Les serveurs en propre utilisent Proxmox VE (open source gratuit, licence AGPL v3). PHP gère le cycle de vie des VM KVM et l'allocation des ressources via l'API REST Proxmox en HTTP.

Architecture:
```
PHP (webman) ──HTTPS──> API Proxmox VE (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (attribuée à l'utilisateur)
```

#### Encapsulation du client ProxmoxApi

```php
class ProxmoxApi
{
    private string $baseUrl;
    private string $token;

    public function __construct(HostMachine $host)
    {
        $this->baseUrl = "https://{$host->ip_address}:8006/api2/json";
        $this->token   = $host->api_token_encrypted;
    }

    // GET  /api2/json/nodes/{node}/...
    public function get(string $path, array $params = []): array;
    // POST /api2/json/nodes/{node}/...
    public function post(string $path, array $data = []): array;
    // PUT  /api2/json/nodes/{node}/...
    public function put(string $path, array $data = []): array;
    // DELETE /api2/json/nodes/{node}/...
    public function delete(string $path): array;
}
```

#### Opérations sur les ressources

**Création de VM (serveur):**
1. HostSelector sélectionne une machine hôte disposant de suffisamment de ressources (tri par marge cpu/ram/disk + équilibrage de charge)
2. Allocation d'une IP depuis le ip_pool de cette machine hôte
3. ProxmoxApi.post("/nodes/{node}/qemu") crée la VM (définit vmid, name, cores, memory, net0, ipconfig0)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") monte le disque système (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") démarre la VM
6. Mise à jour des quantités allouées de host_machine.specs (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**Mise à niveau CPU (à chaud):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // update host resource statistics
```

**Mise à niveau mémoire (à chaud):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**Extension du disque système:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**Création indépendante d'un disque de données:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**Création indépendante d'une IP:**
Allocation depuis le pool d'IP → ajout d'une carte réseau virtuelle + configuration de l'IP via l'API Proxmox, ou conservation comme ressource indépendante attachée en carte réseau supplémentaire à une VM existante.

**Destruction de VM:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // arrêt
$api->delete("/nodes/{node}/qemu/{vmid}");             // suppression de la VM
releaseIp($resourceId);                                // libération de l'IP dans le pool
$host->deallocate($specs);                             // récupération des ressources de l'hôte
```

#### Stratégie de sélection de la machine hôte

```php
class HostSelector
{
    public function select(int $regionId, array $specs): HostMachine
    {
        return HostMachine::where('region_id', $regionId)
            ->where('status', 'online')
            ->whereRaw('JSON_EXTRACT(specs, "$.cpu_total") - JSON_EXTRACT(specs, "$.cpu_allocated") >= ?', [$specs['cpu']])
            ->whereRaw('JSON_EXTRACT(specs, "$.ram_total_gb") - JSON_EXTRACT(specs, "$.ram_allocated_gb") >= ?', [$specs['ram']])
            ->whereRaw('JSON_EXTRACT(specs, "$.disk_total_gb") - JSON_EXTRACT(specs, "$.disk_allocated_gb") >= ?', [$specs['system_disk']])
            ->orderByRaw('JSON_EXTRACT(specs, "$.cpu_allocated") / JSON_EXTRACT(specs, "$.cpu_total") ASC')
            ->firstOrFail();
    }
}
```

#### Récapitulatif des opérations de découpage des ressources

| Opération | Implémentation | Opération à chaud |
|------|----------|--------|
| Création de VM (CPU+mémoire+disque système+IP) | Proxmox create qemu | — |
| Mise à niveau CPU seule | PUT config cores | À chaud |
| Mise à niveau mémoire seule | PUT config memory | À chaud |
| Extension du disque système | PUT resize disk | À chaud (si la VM le supporte) |
| Création indépendante d'un disque de données | POST config ajout de disque | À chaud |
| Création indépendante d'une IP | Allocation depuis le pool d'IP + ajout de carte réseau à la VM | À chaud |

### Cycle de vie des ressources

```
pending → active → destroyed (conservée 30 jours) → purged (irrécupérable)
```

Renouvellement: active → (renew) → active (prolongation de expired_at)
Mise à niveau: active → (upgrade) → upgrading → active

### Origine des ressources

| Origine | Virtualisation/API | Types de produits | Description |
|------|-----------|----------|------|
| Machines physiques en propre | Proxmox VE (édition communautaire) | Serveurs, disques de données, IP | Hébergement en datacenter propre, PHP appelle l'API Proxmox |
| Fournisseurs cloud tiers | SDK AWS/GCP/Alibaba Cloud/Huawei Cloud/Azure | Serveurs, IP, disques cloud | Revente de ressources cloud tierces |
| Registrars de domaines | API Namecheap/GoDaddy/Alibaba Cloud Wanwang | Enregistrement/transfert de domaines | Service de domaines |

### Intégrations de la première phase

| Région | Serveurs | IP | Disques cloud | Domaines |
|------|--------|----|------|------|
| Asie-Pacifique | Alibaba Cloud, Huawei Cloud, AWS | Alibaba Cloud, GCP | Alibaba Cloud, Huawei Cloud | Alibaba Cloud Wanwang, Namecheap |
| Europe | AWS, GCP, Hetzner | GCP, OVH | AWS, GCP | Namecheap, Gandi |
| Amérique du Nord | AWS, GCP, Azure | AWS, GCP | AWS, Azure | GoDaddy, Namecheap |

---

## VI. Système de paiement

### Routage multi-canaux

PaymentRouter interroge les canaux disponibles selon la préférence de devise de l'utilisateur, calcule le montant réel à payer pour chaque canal (frais de canal inclus) et renvoie la liste des options de paiement.

### Flux de paiement (Stripe)

```
Client (Flutter)                    Serveur (webman)                    API Stripe
───────────────                    ──────────────                    ──────────
1. Sélection du paiement Stripe
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. Création de payment_transaction
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → SDK Stripe ──────────────────────────────────────────→ 6. Confirmation du paiement
                                                              par l'utilisateur
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  Vérification de signature (stripe-signature)
                                  Contrôle d'idempotence (transaction_no)
                               8. Mise à jour transaction=success
                               9. Déclenchement de l'événement OrderPaid
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← page de paiement réussi      ← retour du statut de la commande
```

### Paiement en cryptomonnaie

1. L'utilisateur choisit la devise (ex. USDT-TRC20)
2. Le backend génère l'adresse de réception via l'API Coinbase Commerce / BitPay
3. Un worker vérifie les confirmations blockchain toutes les 30 s (ou via webhook)
4. Réception confirmée → déclenchement de l'événement OrderPaid

### Taux de change et multidevises

- Les sources de taux sont récupérées périodiquement depuis exchangerate-api et stockées dans Redis
- Les prix des produits sont basés sur l'USD, conversion en temps réel pour les autres devises
- Le taux est verrouillé à la commande, le remboursement se fait au taux d'origine

### Contrôle de visibilité des canaux de paiement

Champs de la table payment_channels :
- is_visible: affichage ou non côté utilisateur
- visible_regions: régions visibles restreintes, vide = toutes
- min_amount / max_amount: limites de montant de commande

### Rapprochement

Chaque jour à l'aube, les rapports de règlement de chaque canal sont récupérés et rapprochés transaction par transaction avec le système ; un écart > $0,01 déclenche une alerte.

### Politique de remboursement

- Serveurs/VPS: remboursement intégral sous 72h après l'achat
- Domaines: remboursable sous 5 jours après l'enregistrement (normes ICANN)
- IP: non remboursable après l'achat
- Disques cloud: même politique que les serveurs
- Produits promotionnels spéciaux: non remboursables

Processus de remboursement: demande utilisateur → génération d'un ticket → examen support → confirmation admin → provider.destroy() → payment.refund() → retour à la source

---

## VII. Structure des pages client

### Application client Flutter / HarmonyOS

- **Authentification**: connexion/inscription (e-mail + mot de passe, Google OAuth, Apple ID, téléphone), mot de passe oublié, vérification en deux étapes
- **Accueil**: sélecteur de région, entrées des catégories de produits, bannière/promotions, produits recommandés
- **Produits**: liste (filtres multi-critères), détail (calculatrice de configuration/région/prix), évaluations
- **Achat et paiement**: panier, confirmation de commande (mode de paiement/adresse de facturation/solde/code promo), caisse, résultat du paiement
- **Mes ressources**: liste des ressources (filtre par statut), opérations de détail (redémarrage/arrêt/renouvellement/mise à niveau/destruction), SSO console, graphiques d'utilisation
- **Commandes**: liste (en attente de paiement/payée/terminée/remboursée), détail, factures
- **Tickets**: liste, création, conversation
- **Espace personnel**: profil/KYC, solde et rechargement, notifications, gestion des adresses, paramètres de langue/devise/sécurité
- **Public**: centre d'aide, conditions de service, à propos

### Panneau d'administration webman-admin

- **Tableau de bord**: vue d'ensemble + graphiques de tendance
- **Gestion des utilisateurs**: liste/détail/examen KYC
- **Gestion des produits**: catégories/liste/tarification (SKU×région)/stocks/évaluations
- **Gestion des commandes**: liste/détail/examen des remboursements/factures
- **Gestion des paiements**: configuration des canaux/enregistrements de transactions/rapports de rapprochement
- **Gestion des ressources**: liste/surveillance des tâches de livraison/configuration des API des fournisseurs cloud
- **Gestion des fournisseurs**: examen d'inscription/liste/attribution des produits/règlements/retraits
- **Gestion des tickets**: file d'attente/mes tickets/surveillance SLA
- **Gestion des domaines**: tarification TLD/API des registrars/gestion des transferts
- **Notifications**: gestion des modèles/enregistrements d'envoi
- **Paramètres système**: administrateurs et rôles/journaux d'opérations/multilingue/taux de change/régions/paramètres système
- **Rapports**: revenus/règlements fournisseurs/analyse des ventes de produits/analyse régionale

---

## VIII. Système de notifications

### Quatre canaux

E-mail (SMTP/SendGrid) / SMS (Twilio/Alibaba SMS) / Push (FCM/HMS) / messages en interne

### Flux

Déclenchement d'événement → Notification Dispatcher → correspondance du modèle (code d'événement + préférence de langue) → distribution vers chaque canal selon les préférences de l'utilisateur → envoi asynchrone via la file Redis Queue

### Types de notifications

Code de vérification d'inscription, paiement de commande réussi, livraison de ressource terminée, rappel d'expiration de ressource (7j/3j/1j), réponse de ticket, remboursement terminé, alerte de sécurité, promotions

### Nouvelle tentative en cas d'échec

3 tentatives avec backoff, gérées via la file redis-queue webman.

---

## IX. Système de fournisseurs

### Processus d'inscription

Inscription → soumission des informations de l'entreprise + contact + mode de règlement → examen par l'administrateur → après approbation, mise en ligne des produits → examen des produits par l'admin → achat par l'utilisateur → répartition automatique des revenus → demande de retrait par le fournisseur → paiement par l'admin

### Isolation des permissions

Les fournisseurs ne peuvent voir que leurs propres produits/commandes/bons de règlement/tickets/enregistrements de retrait. Ils ne peuvent pas voir les revenus de la plateforme, les données des autres fournisseurs ni la configuration des canaux de paiement.

### Règles de répartition des revenus

- Produits en propre: commission_rate = 100 % (entièrement à la plateforme)
- Produits tiers: commission_rate = 5 % ~ 20 % (part de la plateforme)
- Formule de règlement: montant des produits commandés - part de la plateforme - frais de canal = montant dû au fournisseur
- Cycle de règlement: hebdomadaire / mensuel

### Flux métier complet du fournisseur

```
Inscription du fournisseur                    Approbation de l'administrateur
──────────────────────                       ─────────────────────────────
POST /supplier/apply                         GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,               → examen des informations du fournisseur
     contact_phone, contact_email,            POST /admin/api/suppliers/{id}/approve
     settlement_method}                         → confirmation du mot de passe
  → SupplierService::apply()                    → SupplierService::approve()
  ← {supplier, status:pending}                   → User::role = 'supplier'
                                                ← succès
Mise en ligne des produits
───────────────────────
POST /supplier/products                      Examen de l'administrateur
  → {product_id, commission_rate}              → association du fournisseur au produit
  ← {supplier_product}                           + définition du taux de commission
                                                → statut du produit: published

Commande utilisateur ──→ paiement terminé ──→ livraison des ressources ──→ commande terminée

Règlement périodique (lundi 04:17)            Retrait
─────────────────────────────                ──────
Cron: SupplierSettlement                     POST /supplier/withdraw
  → calcul des commandes terminées              → confirmation du mot de passe
    de la période                                 (ConfirmationMiddleware)
  → calcul total_sales - commission            → SupplierService::requestWithdraw()
  → = payable                                    → vérification du solde retirable
  → création de SupplierSettlement               → création de SupplierWithdraw
  → Webhook: settlement.created                    (status:pending)
                                                ← succès

Paiement par l'administrateur                 Gestion des API Keys
───────────────                               de l'administrateur
POST /admin/api/suppliers/                    ──────────────────
  withdraws/{id}/approve                      POST /admin/api/suppliers/{id}/api-keys
  → confirmation du mot de passe                → génération de sk_xxx (stockage SHA256)
  → SupplierWithdraw: status=completed          ← {api_key} (affiché une seule fois)
  → Webhook: withdrawal.approved              DELETE /admin/api/suppliers/api-keys/{id}
                                                → revoked=true
```

---

## X. Surveillance et exploitation

### Surveillance des ressources

- Métriques collectées: taux d'utilisation CPU/mémoire/disque/bande passante, connectivité IP, IOPS des disques cloud, résolution DNS, expiration des certificats SSL
- Méthodes de collecte: rapport d'agent / SNMP (en propre) + API de surveillance des fournisseurs cloud (tiers) + interrogation WHOIS/DNS (domaines)
- Période de collecte: 5 minutes, stockage Prometheus + VictoriaMetrics

### Règles d'alerte

| Événement d'alerte | Gravité | Condition de déclenchement |
|----------|--------|----------|
| Panne du serveur | Grave | Ping injoignable 3 fois consécutives |
| CPU/mémoire > 90 % | Info | Dure 10 minutes |
| Disque > 90 % | Avertissement | Dure 5 minutes |
| Bande passante > 80 % | Info | Dure 30 minutes |
| Certificat SSL < 30 jours avant expiration | Avertissement | Vérification quotidienne |
| Domaine < 30 jours avant expiration | Avertissement | Vérification quotidienne |
| Échec de tâche de livraison | Grave | 2 échecs consécutifs |
| Écart de rapprochement des paiements | Grave | > $0,01 par transaction |

---

## XI. Architecture de déploiement

### Environnement de production

- Serveurs d'application × 2: webman (multi-processus) + Nginx + Supervisor
- Base de données: MySQL 8.0 maître-esclave (1 maître, 2 esclaves) + Redis Cluster
- Files d'attente: redis-queue webman (rappels de paiement/notifications/tâches de livraison)
- Tâches planifiées: Crontab (rapprochement/règlement/vérification des domaines/rappels de renouvellement)
- Stockage: S3/OSS + CDN
- Surveillance des journaux: ELK/Loki + Prometheus + Grafana + Sentry

### Structure des répertoires

```
cloud-php/
├── apps/
│   ├── flutter/           # client Flutter
│   └── harmonyos/         # client HarmonyOS (ArkTS)
├── service/               # serveur webman
│   ├── app/
│   │   ├── controller/    # contrôleurs (par module)
│   │   ├── service/       # logique métier (par module)
│   │   ├── model/         # modèles de données
│   │   ├── middleware/     # middlewares
│   │   ├── event/         # définitions d'événements
│   │   ├── listener/      # écouteurs d'événements
│   │   ├── queue/         # tâches de file d'attente
│   │   ├── provider/      # adaptateurs de fournisseurs cloud
│   │   └── cron/          # tâches planifiées
│   ├── common/            # bibliothèque commune (auth/payment/i18n/notification/helper)
│   ├── config/            # fichiers de configuration
│   ├── database/
│   │   └── migrations/    # migrations de base de données
│   └── storage/           # journaux/cache/téléversements
├── admin/                 # webman-admin
├── docs/                  # documentation
└── docker/                # configuration Docker
```

### Dépendances Composer clés

workerman/webman-framework、webman/admin、webman/redis-queue、illuminate/database、firebase/php-jwt、stripe/stripe-php、phpseclib/phpseclib、monolog/monolog

### Optimisation haute concurrence

#### 1. Séparation lecture/écriture MySQL

Eloquent route automatiquement les SELECT vers la connexion read, et les INSERT/UPDATE/DELETE vers la connexion write.

```
Configuration (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (base maître)
  connections.mysql.read  → DB_READ_HOST  (esclave, plusieurs configurables pour l'équilibrage de charge)
  sticky = true           → lecture après écriture sur le maître pendant le même cycle de requête
                             (prévention du retard de réplication)

Variables d'environnement:
  DB_HOST=10.0.1.1          # base maître (écriture)
  DB_READ_HOST=10.0.2.1     # base esclave (lecture), plusieurs déployables
```

**Règles de routage lecture/écriture:**

| Type d'opération | Cible de routage | Exemple |
|---------|---------|------|
| SELECT | connexion read | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | connexion write | `Order::create(...)` |
| Toutes les opérations en transaction | connexion write | `DB::transaction(...)` |
| Lecture après écriture (sticky) | connexion write | dans le même cycle de requête |

#### 2. Stratégie de cache multiniveau Redis

`CacheService` est utilisé pour mettre en cache les données fréquemment lues ; si Redis est indisponible, on retombe automatiquement sur une requête directe à la base de données.

```
Hiérarchie de cache:
  L1: Redis (partagé entre processus, millisecondes)
  L2: MySQL (persistant, repli)

Stratégies de cache:
  Liste de produits    TTL 5min    clés par region_id + category_id + keyword
  Détail produit       TTL 10min   clé par product_id, invalidation active en cas de modification
  Liste des régions    TTL 1h      données régionales très peu variables
  Taux de change       TTL 30min   actualisation par tâche planifiée + mise à jour active
  Tarification TLD     TTL 1h      fréquence de variation des prix TLD faible
  Articles d'aide      TTL 10min   invalidation active à la publication/modification
  Catégories de produits TTL 10min invalidation active en cas de changement de l'arborescence

Préchauffage du cache (après déploiement):
  CacheService::warmUp(['products:all', 'regions', 'tlds', 'exchange_rates'])

Invalidation active (en cas de modification des données):
  ProductController::update() → CacheService::forgetPattern('products:*')
  Crontab::ExchangeRateSync → CacheService::put('exchange_rates', $rates, TTL)
```

```php
// usage example
$products = CacheService::remember(
    "products:list:{$regionId}:{$categoryId}",
    CacheService::TTL_PRODUCT_LIST,
    fn() => Product::where('region_id', $regionId)->where('category_id', $categoryId)->get()
);
```

#### 3. Compression de réponse Nginx + limitation de débit

```
Compression gzip:
  gzip on, comp_level=6
  gzip_types: application/json, text/plain, text/javascript, image/svg+xml
  Effet: taux de compression des réponses JSON de 70 à 85 %, économie de bande passante

Optimisation proxy:
  proxy_buffering on           # mise en tampon des réponses amont, les clients lents
                                # ne bloquent pas les workers
  proxy_http_version 1.1       # réutilisation des connexions persistantes HTTP/1.1
  keep-alive vers l'amont       # réduction des poignées de main TCP

Limitation de débit:
  limit_req: 10 req/s par IP (burst 20)
  limit_conn: 20 connexions simultanées par IP
  l'endpoint /health n'est pas limité (access_log désactivé pour réduire les E/S)
```

#### 4. Recommandations d'index de base de données

D'après l'analyse des modèles de requêtes, les index suivants réduisent considérablement le nombre de lignes scannées dans les scénarios à forte concurrence :

| Table | Index recommandé | Requêtes couvertes |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | liste des commandes de l'utilisateur + filtre par statut |
| `orders` | `(order_no)` (unique) | recherche exacte par numéro de commande |
| `products` | `(status, category_id, sort)` | liste front office + filtre par catégorie + tri |
| `product_skus` | `(product_id, status)` | liste des SKU + filtre par statut |
| `product_regions` | `(sku_id, region_id)` (unique) | recherche de tarification par région |
| `resources` | `(user_id, status)` | liste de mes ressources |
| `resources` | `(expired_at, status)` | tâche planifiée de contrôle des expirations |
| `provision_tasks` | `(status, next_retry_at)` | interrogation des tâches en attente par le worker |
| `refresh_tokens` | `(user_id, revoked)` | requêtes de gestion des sessions |
| `payment_transactions` | `(order_id)` | recherche de transactions par commande |
| `payment_transactions` | `(transaction_no)` (unique) | contrôle d'idempotence des webhooks |
| `tickets` | `(user_id, status)` | liste des tickets de l'utilisateur |
| `notifications` | `(user_id, read_at, created_at)` | liste des notifications de l'utilisateur |

#### 5. Estimation des connexions simultanées

```
webman multi-processus:
  nombre de cœurs CPU × nombre de processus = nombre de workers
  Ex.: 4 cœurs × 8 workers = 32 processus worker
  
Connexions MySQL:
  chaque worker maintient 1 connexion persistante
  32 workers × 2 instances (service + admin) = 64 connexions
  maître 32 + esclaves 32, recommandation prudente: max_connections ≥ 200

Connexions Nginx:
  worker_connections 1024 × worker_processes auto
  pic de concurrence ≈ worker_connections × worker_processes / 2
  serveur 4 cœurs ≈ 2048 connexions simultanées
```

---

## XII. Tableau d'état d'implémentation

### Modules principaux

| Module | Statut | Description |
|------|------|------|
| **User** | ✅ Terminé | inscription/connexion/vérification e-mail/OAuth/TOTP/gestion des sessions/suppression GDPR/CRUD d'adresses |
| **Product** | ✅ Terminé | tarification SKU×région, catégories, recherche (ES), évaluations, attributs, import/export en masse |
| **Order** | ✅ Terminé | panier, commande, cycle de vie, remboursement, factures (PDF), bons de réduction |
| **Payment** | ✅ Terminé | canal Stripe, routage multi-canaux, vérification de signature webhook, rapprochement |
| **Provisioning** | ✅ Terminé | Proxmox + AWS EC2 + architecture extensible ProviderFactory |
| **Domain** | ✅ Terminé | tarification TLD, enregistrements DNS, approbation des transferts de domaine |
| **Supplier** | ✅ Terminé | approbation d'inscription, mise en ligne des produits, règlement, retrait, gestion des API Keys |
| **Monitor** | ✅ Terminé | contrôle d'activité des ressources, moteur d'alertes, surveillance des certificats SSL |
| **Ticket** | ✅ Terminé | création/réponse/attribution/fermeture/suivi SLA |
| **Notification** | ✅ Terminé | quatre canaux e-mail/SMS/Push/message interne + gestion des préférences utilisateur |
| **Report** | ✅ Terminé | rapports de revenus/fournisseurs/régions |
| **I18n** | ✅ Terminé | multilingue, multidevises, fuseaux horaires multiples |

### Système de sécurité

| Fonctionnalité | Statut |
|------|------|
| WAF (8 catégories, 45+ règles: injection SQL/XSS/injection de commandes/inclusion de fichiers/injection d'en-têtes/SSRF/injection NoSQL/redirection ouverte) | ✅ |
| Middleware CORS | ✅ |
| Middleware d'identification de plateforme ClientPlatform (8 plateformes) | ✅ |
| Limitation de débit API (seau de jetons Redis) | ✅ |
| Geo-Blocking (MaxMind GeoIP2) | ✅ |
| Mode maintenance (interrupteur variable d'environnement + liste blanche IP) | ✅ |
| Chiffrement requête/réponse (AES-256-GCM) | ✅ |
| Journaux d'audit (base distincte, avec suivi client_platform) | ✅ |
| Masquage des données (traitement automatique journaux/réponses) | ✅ |
| JWT lié à l'empreinte d'appareil + rotation des jetons + enregistrement client_platform | ✅ |
| Mot de passe bcrypt (cost=12) + second chiffrement Encryptable | ✅ |
| Confirmation du mot de passe (ConfirmationMiddleware, verrou 15min après 5 échecs) | ✅ |
| Middleware WAF du panneau admin | ✅ |
| Surveillance des exceptions Sentry (SentryBootstrap + masquage before_send) | ✅ |
| Feature Flags (surcharge dynamique Redis + API du panneau d'administration) | ✅ |

### Nouvelles fonctionnalités (2026-05-21)

| Fonctionnalité | Statut |
|------|------|
| API externe des fournisseurs (authentification par API Key + endpoints commandes/ressources/règlements/retraits) | ✅ |
| Push temps réel WebSocket (WebSocket natif Workerman + écoute d'événements) | ✅ |
| Scripts de test de charge k6 (smoke/produits/concurrence) | ✅ |

### Statistiques du backend

| Métrique | Quantité |
|------|------|
| Endpoints API | 135 |
| Modèles de données | 50+ |
| Tables de base de données | 50+ |
| Middlewares | 15 (7 globaux + 6 de route + 1 API externe + admin WebSocket) |
| Tâches planifiées | 7 |
| Fichiers de migration | 22 |
| Tests | 362 tests / 579 assertions (Service 295 + Admin 67) |
| Fichiers de test | 22 |
| Scripts de test de charge k6 | 3 (smoke / products / concurrent) |

### Documentation

| Document | Chemin |
|------|------|
| Spécification de conception du système | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| Conception du panneau d'administration | `docs/admin-design.md` |
| Documentation de l'API fournisseur | `docs/supplier-api.md` |
| Liste de contrôle de déploiement | `docs/deployment.md` |
| Script de test smoke de l'API | `docs/api-test.sh` |

### État du frontend

| Côté | Statut | Description |
|----|------|------|
| Flutter | 🟡 En cours | ApiClient a intégré le numéro de version d'en-tête + couche de données unifiée ApiService ; connexion/liste de produits/panier/liste des ressources déjà connectés à l'API ; historique des commandes/centre de notifications à vérifier dans l'environnement de compilation |
| HarmonyOS | 🔴 Début | Seulement la page de connexion et ApiClient |
| Panneau admin | ✅ Terminé | tableau de bord/utilisateurs/produits/commandes/paiements/ressources/fournisseurs/tickets/domaines/notifications/système/rapports/Webhooks/import-export, toutes fonctionnalités |
