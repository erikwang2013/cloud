# Document de conception de l'architecture CloudPlatform

## 1. Vue d'ensemble du système

CloudPlatform est une plateforme de commerce de ressources cloud destinée à un public mondial, prenant en charge un mode hybride de machines physiques en propre + fournisseurs tiers. Les utilisateurs peuvent acheter des serveurs (VM), des adresses IP, des disques cloud, des domaines et d'autres produits via le Web/mobile, et le système traite automatiquement le paiement et la livraison des ressources.

### 1.1 Décisions architecturales clés

| Décision | Choix | Raison |
|------|------|------|
| Framework backend | PHP webman (Workerman) | Résident en mémoire, piloté par événements, multi-processus, réponse en millisecondes |
| Modèle d'architecture | Monolithe modulaire | Modules découpés verticalement par métier, couches MVC internes, découplage par événements entre modules |
| Panneau d'administration | Instance webman indépendante (webman-admin + Layui) | Isoler le trafic d'administration du trafic utilisateur, séparation des domaines de défaillance |
| ORM | Illuminate/Eloquent | Écosystème Laravel mature, requêtes relationnelles, Scope, événements, migrations |
| Clé primaire distribuée | Snowflake 64-bit | Sans dépendance à l'auto-incrément, prend naturellement en charge le partitionnement base/table |
| Obfuscation des ID | Hashids | Masquer l'ampleur réelle des ID à l'extérieur, empêcher le parcours par robots |
| Authentification | JWT HS256 | Authentification sans état, Access 15 min + Refresh 30 j |
| Chiffrement du transport | AES-256-GCM | Chiffrement/déchiffrement transparent par middleware, chiffrement authentifié GCM anti-altération |
| Chiffrement des champs | AES-128-ECB | Cast Eloquent chiffre/déchiffre automatiquement, chiffrement déterministe (le texte chiffré est requêtable par égalité, la connexion/validation d'unicité en dépend) ; seul ECB est pris en charge |
| File de messages | Redis Queue | Traitement asynchrone des rappels de paiement, diffusion des notifications, ouverture des ressources |
| Moteur de recherche | database (par défaut) / Elasticsearch 8.x | Le pilote database de webman-scout par défaut (dégradation SQL LIKE) ; après configuration d'ES, indexation avec segmentation IK |
| Virtualisation | Proxmox VE + kvm-server | Les VM en propre sont fournies par le kvm-server Rust (gRPC :50051, découverte d'enregistrement e-cat/etcd) ; le pilote actuel est un pilote simulé, le vrai pilote libvirt en Phase 2 |
| Clients | Flutter | Code unique pour cinq plateformes iOS/macOS/Windows/Linux/Web + HarmonyOS |

### 1.2 Limites du système

```
┌──────────────────────────────────────────────────────────────────┐
│                         Côté utilisateur                          │
│  Flutter (iOS/macOS/Windows/Linux/Web) + HarmonyOS (ArkTS)      │
└─────────────────────────┬────────────────────────────────────────┘
                          │ HTTPS + JWT
┌─────────────────────────┼────────────────────────────────────────┐
│                    Reverse proxy Nginx                            │
│  Terminaison SSL / compression gzip / limitation de débit /      │
│  Upgrade WebSocket                                               │
└─────────────────────────┬────────────────────────────────────────┘
                          │
┌─────────────────────────┼────────────────────────────────────────┐
│              Serveur webman (multi-processus)                     │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ Chaîne de middlewares globaux : Version→CORS→            │     │
│  │ SecurityHeaders→ClientPlatform→GeoBlock→WAF→             │     │
│  │ SecurityPlugin→RateLimit→Locale→Metrics→Hashid→          │     │
│  │ Maintenance→[middlewares de routes]                       │     │
│  └─────────────────────────────────────────────────────────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌───────┐     │
│  │  User   │ │ Product │ │  Order  │ │ Payment │ │Provision│    │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘ └───────┘     │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐               │
│  │ Domain  │ │Supplier │ │ Ticket  │ │  Notif  │  ...           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘               │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │ Serveur WebSocket (:8282) — push temps réel              │     │
│  └─────────────────────────────────────────────────────────┘     │
└──────────┬──────────────┬────────────────┬───────────────────────┘
           │              │                │
    ┌──────┴──────┐ ┌─────┴─────┐ ┌───────┴────────┐
    │  MySQL 8.0  │ │ Redis 7.x │ │ Elasticsearch  │
    │  (maître/   │ │(cache/    │ │    8.x        │
    │   esclave)  │ │ file)     │ │                │
    └─────────────┘ └───────────┘ └────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  kvm-server (gRPC Rust)     │
    │  découverte d'registr. e-cat│
    │  / etcd                     │
    │  pilote simulé (libvirt     │
    │  Phase 2)                   │
    └──────┬──────────────────────┘
           │
    ┌──────┴──────────────────────┐
    │  API Proxmox VE (:8006)     │
    │  Virtualisation KVM/QEMU    │
    │  Pool IP / pool disques /   │
    │  hôtes                      │
    └─────────────────────────────┘
```

---

## 2. Architecture des composants

### 2.1 Conception à double instance

Le projet contient deux instances webman indépendantes, partageant la base de données MySQL :

```
                    ┌─────────────────────┐
                    │   admin/ (Layui)     │
  Administrateur ──▶│   port : 8788        │
                    │   middlewares :      │
                    │   WAF→ACL            │
                    └──────────┬───────────┘
                               │ SQL
                    ┌──────────┴───────────┐
                    │     MySQL 8.0        │
                    └──────────┬───────────┘
                               │ SQL
  Utilisateur/API ─▶│   service/           │
                    │   port : 8787        │
                    │   12 globaux + 6     │
                    │   middlewares de     │
                    │   routes             │
                    └─────────────────────┘
```

| Instance | Port | Responsabilité | Middlewares |
|------|------|------|--------|
| **service** | 8787 | API utilisateur + API d'administration + WebSocket | 12 globaux + 6 de routes + SupplierApiKey |
| **admin** | 8788 | Panneau d'administration HTML (Layui) | WafMiddleware + AccessControl |

### 2.2 Structure en couches des modules

Chaque module métier suit une hiérarchie uniforme :

```
app/{Module}/
├── controller/     # Couche HTTP : validation des paramètres, appel des Services, renvoi des Response
│   └── external/   # Contrôleurs d'API externes (authentification par clé API fournisseur)
├── service/        # Logique métier : sans dépendance HTTP, réutilisable par Controller/Queue Worker
├── model/          # Modèles de données Eloquent : définitions de relations, scopes de requête, Casts
├── event/          # Définitions d'événements de domaine (OrderPaid, TicketCreated, etc.)
├── listener/       # Écouteurs d'événements (Provisioning, push WebSocket)
├── provider/       # Adaptateurs de fournisseurs cloud (ProxmoxProvider, etc.)
├── queue/          # Consommateurs de file (ProvisionWorker, EmailSender, etc.)
└── cron/           # Tâches planifiées (ExchangeRateSync, ExpirationCheck, etc.)
```

### 2.3 Bibliothèque commune en couches

```
common/
├── auth/middleware/     # AuthMiddleware, AdminRoleMiddleware, RbacMiddleware,
│                         RoleMiddleware, SupplierApiKeyMiddleware
├── captcha/             # Service de captcha à clic
├── clientplatform/      # ClientPlatformMiddleware (en-tête X-Client-Platform)
├── confirmation/        # Middleware de confirmation de mot de passe
├── encryption/          # Middleware de chiffrement de transport AES-256-GCM
├── feature/             # Interrupteurs de fonctionnalités Feature Flags
├── hashid/              # Middleware de décodage de requêtes Hashids + service d'encodage/décodage
├── helper/              # Formatage des Response + CacheService
├── http/                # Outils de client HTTP
├── i18n/middleware/     # LocaleMiddleware multilingue
├── security/            # CORS / WAF / RateLimit / GeoBlock / Maintenance / AuditLogger / LogSanitizer
├── snowflake/           # Service d'ID Snowflake + Trait Eloquent
├── metrics/             # Collecteur + rendu de métriques Prometheus + middleware de comptage de requêtes HTTP
├── version/             # VersionMiddleware (version d'API depuis le chemin d'URL, p. ex. /api/v1/...)
└── webhook/             # Répartiteur d'événements Webhook
```

### 2.4 Module CDN

Le module CDN produit (`service/app/cdn/`) connecte quatre fournisseurs via un modèle d'adaptateurs, en utilisant un serveur ou un bucket de stockage comme origine :

```
CdnAdapterInterface
  ├── CloudflareAdapter   REST v4 (certificats automatiques SSL SaaS), enregistrement ICP non requis
  ├── CloudFrontAdapter   aws-sdk-php (CloudFront + ACM), enregistrement ICP non requis
  ├── AliyunCdnAdapter    Signature RPC, enregistrement ICP requis
  └── TencentCdnAdapter   Signature TC3, enregistrement ICP requis
         ▲
CdnAdapterFactory.resolve(type, accountId, strict)
  1) Compte lié (provider_account_id) → 2) compte actif code=cdn-{type} → 3) repli env
  strict=true (suppression/purge) : seul le compte lié est utilisé, sinon 4003, sans bascule silencieuse
```

**Gestion des comptes :** réutilise le modèle `provider_apis` (identifiants chiffrés en base via Encryptable), CRUD `/admin/providers` côté administration (RbacMiddleware), convention `code` `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent`, les identifiants env passent en fallback.

**Modèle de données :** `resource_cdn` (provider_type / provider_account_id / zone_id / provider_domain_id / cert_config / config ; la clé privée est retirée de cert_config avant l'enregistrement en base). Isolement des permissions : les ressources CDN sont vérifiées via `resource.user_id`, toute ressource d'autrui retourne 404.

---

## 3. Pipeline d'exécution des middlewares

### 3.1 Chaîne de middlewares globaux (toutes les requêtes)

```
Requête HTTP
  │
  ▼
1. VersionMiddleware         ← lecture de la version d'API depuis le chemin d'URL (`/api/v1/...`),
  │                            invalid → 400 ; actif uniquement sur /api/v1/ et /admin/api/v1/
  ▼
2. CorsMiddleware            ← pré-vérification OPTIONS renvoyant les en-têtes CORS,
  │                            réflexion de l'Origine
  ▼
3. SecurityHeadersMiddleware ← en-têtes de réponse de sécurité HSTS / X-Frame-Options /
  │                            CSP / Referrer-Policy
  ▼
4. ClientPlatformMiddleware  ← identification de l'en-tête X-Client-Platform (8 plateformes),
  │                            injection dans properties ; actif uniquement sur /api/v1/ et
  │                            /admin/api/v1/
  ▼
5. GeoBlockMiddleware        ← blocage par pays GEO_BLOCKED_COUNTRIES (MaxMind GeoIP2)
  ▼
6. WafMiddleware             ← scan 8 catégories de 45+ règles (corps JSON + URL + UA + corps brut)
  │                          ← liste blanche Content-Type + limite du corps de requête 10 Mo
  │                            + limite URL 2 Ko ; correspondance → AuditLogger::threat() → 403
  ▼
7. SecurityPlugin            ← 31 types de détection d'attaques (XSS/injection SQL/SSRF/
  │                            désérialisation, etc.), liste noire/blanche IP
  ▼
8. RateLimitMiddleware       ← limitation de débit sur toutes les routes (double seau
  │                            per-IP + per-token)
  ▼
9. LocaleMiddleware          ← analyse Accept-Language, définition de la région
  ▼
10. MetricsMiddleware        ← comptage des requêtes HTTP et enregistrement de la latence Prometheus
  ▼
11. HashidRequestMiddleware  ← chaînes hashid des paramètres de requête → décodage en ID entiers réels
  ▼
12. MaintenanceMiddleware    ← vérification MAINTENANCE_MODE, IP de la liste blanche autorisées
  │
  ▼
[Middlewares de routes — attachés par groupe de routes]
  │
  ├─ /health (surveillance interne) ──
  │   InternalTokenMiddleware      ← validation du jeton interne /health/live|ready|deps
  │
  ├─ /api/v1/auth ─────────────────────
  │   EncryptionMiddleware          ← chiffrement/déchiffrement du corps de requête/réponse
  │                                   AES-256-GCM
  │
  ├─ /api/v1 (authentification utilisateur) ──
  │   EncryptionMiddleware
  │   AuthMiddleware                ← vérification JWT Bearer Token → $request->userId/role
  │
  ├─ /api/v1 (opérations sensibles) ───
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   ConfirmationMiddleware        ← confirmation de mot de passe, compteur Redis,
  │                                   5 échecs → verrouillage 15 min
  │
  ├─ /api/v1/supplier/external ────────
  │   VersionMiddleware
  │   SupplierApiKeyMiddleware      ← vérification SHA256 sk_xxx → $request->supplierId
  │
  ├─ /admin/api/v1 ────────────────────
  │   EncryptionMiddleware
  │   AuthMiddleware
  │   AdminRoleMiddleware           ← vérification des permissions RBAC
  │
  └─ /admin/api/v1 (opérations sensibles) ──
      EncryptionMiddleware
      AuthMiddleware
      AdminRoleMiddleware
      ConfirmationMiddleware
  │
  ▼
Contrôleur → Service → Model → DB
```

### 3.2 Détails de chaque middleware

| Middleware | Emplacement | Mode d'enregistrement | Responsabilité |
|--------|------|---------|------|
| `VersionMiddleware` | common/Version | Global | Valide la version d'API depuis le chemin d'URL (p. ex. `/api/v1/...`) |
| `CorsMiddleware` | common/Security | Global | Pré-vérification OPTIONS, réflexion de l'Origine |
| `SecurityHeadersMiddleware` | common/Security | Global | En-têtes de réponse de sécurité HSTS / X-Frame-Options / CSP / Referrer-Policy |
| `ClientPlatformMiddleware` | common/ClientPlatform | Global | Identification de `X-Client-Platform` (8 plateformes) |
| `GeoBlockMiddleware` | common/Security | Global | Blocage géographique GEO_BLOCKED_COUNTRIES (MaxMind GeoIP2) |
| `WafMiddleware` | common/Security | Global (service) + admin | 8 catégories de 45+ règles + limites de requête |
| `SecurityPlugin` | Erikwang2013\Security | Global | 31 types de détection d'attaques, liste blanche/noire IP |
| `RateLimitMiddleware` | common/Security | Global | Limitation de débit par seau à jetons Redis (double seau per-IP + per-token) |
| `LocaleMiddleware` | common/I18n | Global | Analyse Accept-Language |
| `MetricsMiddleware` | common/Metrics | Global | Comptage des requêtes HTTP et latence Prometheus |
| `HashidRequestMiddleware` | common/Hashid | Global | Décodage des requêtes hashid |
| `MaintenanceMiddleware` | common/Security | Global | Mode maintenance + liste blanche IP |
| `InternalTokenMiddleware` | common/Security | Groupe de routes | Validation du jeton interne `/health/live|ready|deps` |
| `EncryptionMiddleware` | common/Encryption | Groupe de routes | Chiffrement/déchiffrement AES-256-GCM |
| `AuthMiddleware` | common/Auth | Groupe de routes | Vérification JWT Bearer Token |
| `AdminRoleMiddleware` | common/Auth | Groupe de routes | RBAC administrateur |
| `ConfirmationMiddleware` | common/Confirmation | Groupe de routes | Confirmation de mot de passe |
| `SupplierApiKeyMiddleware` | common/Auth | Groupe de routes | Vérification de signature SHA256 de la clé API sk_xxx |

---

## 4. Architecture des données

### 4.1 Clé primaire distribuée : Snowflake

```
Structure de l'ID Snowflake 64-bit :
┌─────────────────┬──────────┬──────────┬──────────────┐
│ timestamp (41b) │ dc (5b)  │ wk (5b)  │ seq (12b)    │
└─────────────────┴──────────┴──────────┴──────────────┘
  horodatage en      centre de  nœud de     numéro de
  millisecondes      données    travail     séquence
  Époque : 2024-01-01
  Durée de vie maximale : ~69 ans
```

Tous les modèles Eloquent génèrent automatiquement l'ID dans l'événement `creating` via le trait `HasSnowflakeId`. Le type de colonne en base est `bigint unsigned`.

### 4.2 Obfuscation des ID : Hashids

```
Flux de requête :
  Client : GET /api/v1/products/aB3xK7mQ9w
    → décodage HashidRequestMiddleware → int(1234567890)
      → Controller/Service opère avec l'ID entier
        → Response::success() / Response::paginated()
          → hashids_encode_ids() encode récursivement tous les champs id/*_id
            ← { "id": "aB3xK7mQ9w", "category_id": "Xy7..." }
```

### 4.3 Connexions à la base de données

```
┌────────────────────┐     ┌────────────────────┐
│  MySQL maître      │     │  MySQL esclave     │
│  (écriture)        │     │  (lecture)         │
│  DB_HOST           │     │  DB_READ_HOST      │
└────────┬───────────┘     └────────┬───────────┘
         │ écriture                 │ lecture (SELECT)
         └──────────┬───────────────┘
                    │
         ┌──────────┴───────────┐
         │  Eloquent Capsule    │
         │  sticky = true       │
         │  connexion           │
         │  persistante (PDO)   │
         └──────────────────────┘
                    │
         ┌──────────┴───────────┐
         │  base audit          │
         │  (connexion          │
         │  indépendante)       │
         │  stockage isolé des  │
         │  journaux d'audit    │
         └──────────────────────┘
```

### 4.4 Couches de chiffrement

| Couche | Algorithme | Implémentation | Usage |
|------|------|------|------|
| Transport | AES-256-GCM | EncryptionMiddleware | Chiffrement du corps des requêtes/réponses API, authentification GCM |
| Champs | AES-128-ECB | Cast Encryptable | Chiffrement/déchiffrement automatique des champs sensibles (chiffrement déterministe : même clair → même chiffré, requête par égalité sur le chiffré pour la connexion/validation d'unicité ; seul ECB est pris en charge, changer de cipher nécessite une migration de re-chiffrement) |
| Hachage | bcrypt + SHA256 | JWT / clé API | Stockage irréversible des mots de passe/Tokens |
| Clés primaires | Hashids | Response + Middleware | Obfuscation des ID à l'extérieur |

### 4.5 Couches de cache

```
L1 : Couche de cache Redis (CacheService)
    Liste de produits TTL 5 min | détail produit TTL 10 min
    Régions TTL 1 h | taux de change TTL 30 min | TLD TTL 1 h
    Stratégie d'invalidation : forget / forgetPattern actifs en cas de
    modification des données

L2 : Couche de requête MySQL (Eloquent + optimisation des index)
    13 index composites/covering couvrent les requêtes à haute fréquence

L3 : Compression des réponses Nginx (gzip niveau 6)
    Taux de compression des réponses JSON 70-85 %
```

### 4.6 Internationalisation (i18n)

```
Accept-Language : zh-CN,zh;q=0.9
         │
         ▼
  LocaleMiddleware (middleware global)
         │  analyse de la langue principale → zh-CN
         │  I18n::setLocale('zh-CN')
         │  chargement de i18n/zh-CN/messages.php
         ▼
  Contrôleur / Service
         │
         ├── I18n::trans('auth.login_success')  →  '登录成功'
         ├── I18n::translateField($jsonField)   →  valeur selon la langue
         └── I18n::getLocale()                  →  'zh-CN'
```

| Capacité | Description |
|------|------|
| Analyse d'en-tête | `LocaleMiddleware` analyse automatiquement l'en-tête `Accept-Language` |
| Repli de langue | Langue non prise en charge → `fallback_locale` |
| Traductions statiques | 120 entrées, couvrant 15 modules (`i18n/{locale}/messages.php`) |
| Remplacement de paramètres | `I18n::trans('key', ['field' => 'value'])` |
| Champs JSON | `translateField()` traite les colonnes JSON multilingues |

---

## 5. Architecture de sécurité

### 5.1 Système de règles WAF (8 catégories de 45+ règles)

| Catégorie | Nombre de règles | Périmètre de détection |
|------|--------|---------|
| Injection SQL | 9 | Caractères de commentaire, mots-clés, encodage hexadécimal, requêtes UNION, conditions toujours vraies, injection temporelle aveugle, requêtes empilées |
| XSS | 8 | Balises HTML, variantes de Script, 13 gestionnaires d'événements, protocoles pseudo-JS, encodage d'entités, Data URI |
| Injection de commandes | 5 | Commandes après pipe, commandes après point-virgule, $(cmd), backticks, mots-clés de commandes indépendantes |
| Inclusion de fichiers | 4 | Traversée de chemins, protocoles pseudo-PHP, chemins absolus, Null byte |
| Injection d'en-têtes HTTP | 2 | Sauts de ligne CRLF, injection Host/Cookie/Set-Cookie |
| SSRF | 6 | IP internes, localhost, metadata cloud, protocole file:// |
| Injection NoSQL | 3 | Opérateurs MongoDB, commandes dangereuses Redis |
| Redirection ouverte | 2 | URL externe redirect_uri, contournement par double encodage |

**Périmètre de scan :** les règles d'injection de valeurs (SQLi/XSS/injection de commandes/injection d'en-têtes/SSRF/NoSQL/redirection ouverte) scannent la query string, le corps de requête et le User-Agent ; le chemin d'URL n'utilise que le modèle d'inclusion de fichiers (traversée de chemins) pour une validation structurelle. Les chemins métier contiennent des mots ordinaires comme select/insert/alert (par ex. `/order_item/select`) ; si le chemin entier était scanné, tous les points de terminaison CRUD seraient bloqués à tort, c'est pourquoi le chemin ne participe pas à la correspondance d'injection de valeurs.

**Protection au niveau des requêtes :** liste blanche Content-Type, limite du corps de requête 10 Mo, limite d'URL 2 Ko

### 5.2 Système d'authentification

```
┌─────────────────────────────────────────────┐
│               Méthodes d'authentification   │
├──────────────┬──────────────┬───────────────┤
│  Utilisateur │  Admin       │  API          │
│              │              │  fournisseur  │
│  JWT HS256   │  JWT HS256   │  API Key      │
│  Access 15   │  Access 2 h  │  préfixe      │
│  min         │              │  sk_xxx       │
│  Refresh 30 j│  Refresh 7 j │  stockage     │
│  TOTP        │              │  SHA256       │
│  optionnel   │              │  affiché une  │
│  OAuth       │              │  seule fois   │
│  optionnel   │              │               │
└──────────────┴──────────────┴───────────────┘
```

---

## 6. Architecture de déploiement

### 6.1 Topologie de production

```
                    Internet
                        │
               ┌────────┴────────┐
               │  Cloudflare CDN │  ← Protection périphérique de la plateforme (DDoS/Bot),
               │  DDoS / Bot     │    sans lien avec le module CDN produit (quatre
               └────────┬────────┘    fournisseurs, voir §2.4)
                        │
               ┌────────┴────────┐
               │  Nginx × 2      │
               │  SSL / gzip     │
               │  limit_req      │
               └──┬──────────┬───┘
                  │          │
         ┌────────┴──┐  ┌───┴──────────┐
         │ webman × 2│  │ webman × 2   │
         │ service   │  │ admin        │
         │ :8787     │  │ :8788        │
         │ :8282 WS  │  │              │
         └─────┬─────┘  └──────┬───────┘
               │               │
         ┌─────┴──────┬───────┴───────┐
         │ MySQL      │ Redis Cluster │
         │ maître/    │ cache+file    │
         │ esclave    │               │
         │ 1 maître   │               │
         │ 2 esclaves │               │
         └─────────────┴───────────────┘
               │
         ┌─────┴──────────────────────┐
         │  kvm-server (gRPC Rust)    │
         │  enregistrement            │
         │  e-cat / etcd              │
         │  pilote simulé             │
         │  (libvirt Phase 2)         │
         └─────┬──────────────────────┘
               │
         ┌─────┴──────────────────────┐
         │  Cluster Proxmox VE        │
         │  machines physiques × N    │
         │  Virtualisation KVM/QEMU   │
         └────────────────────────────┘
```

### 6.2 Modèle de processus

```
webman service (8787)
├── HTTP Worker × cpu_count()*2    (8 par défaut)
├── Queue Worker : provisioning     (×2)
├── Queue Worker : email            (×5)
├── Queue Worker : sms              (×10)
├── Queue Worker : push             (×20)
├── WebSocket Worker                (×2, port 8282)
└── Cron Timer                      (×1)

webman admin (8788)
└── HTTP Worker × 4
```

---

## 7. Dépendances techniques

### 7.1 Framework de base

| Paquet | Version | Usage |
|----|------|------|
| workerman/webman-framework | ^2.1 | Framework Web (résident en mémoire, multi-processus) |
| illuminate/database | ^10.0 | ORM Eloquent |
| illuminate/events | ^10.0 | Système d'événements |
| illuminate/redis | ^10.0 | Client Redis |
| webman/redis-queue | ^1.0 | File de messages Redis |

### 7.2 Paquets de l'écosystème erikwang2013

| Paquet | Usage |
|----|------|
| snowflake-php | Clés primaires distribuées 64 bits |
| hashids | Obfuscation des ID API |
| encryptable | Chiffrement des champs de base de données |
| encryption | Chiffrement de transport AES-256-GCM |
| jwt-webman | Authentification JWT |
| webman-scout | Recherche plein texte Elasticsearch |
| season | Emoji de drapeaux de pays |
| poster-php | Captcha à clic |

### 7.3 Intégrations tierces

| Paquet | Usage |
|----|------|
| stripe/stripe-php | Paiement Stripe |
| twilio/sdk | SMS |
| kreait/firebase-php | Push FCM |
| guzzlehttp/guzzle | Client HTTP (API Proxmox, etc.) |
| sentry/sentry | Surveillance des exceptions |
| phpoffice/phpspreadsheet | Export Excel |
