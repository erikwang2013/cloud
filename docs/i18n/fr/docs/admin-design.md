# Document de conception du panneau d'administration

## Vue d'ensemble

`admin/` est une instance webman v2.1 autonome fournissant un tableau de bord de gestion basé sur Layui. Elle fonctionne indépendamment du backend `service/`, partageant uniquement la base de données MySQL et les 7 packages erikwang2013.

## Architecture

```
┌─────────────────────────────────────────────────┐
│                  Admin Panel                     │
│  ┌──────────┐  ┌──────────┐  ┌───────────────┐ │
│  │ Controller│  │  Model   │  │   Bootstrap   │ │
│  │ (Layui)  │  │(Eloquent)│  │(worker start) │ │
│  └────┬─────┘  └────┬─────┘  └───────┬───────┘ │
│       │             │               │          │
│  ┌────┴─────────────┴───────────────┴─────────┐ │
│  │         7 erikwang2013 Packages             │ │
│  │  Snowflake │ Hashids │ Encryptable          │ │
│  │  Encryption│ Scout   │ Season │ Poster     │ │
│  └────────────────────┬───────────────────────┘ │
└───────────────────────┼─────────────────────────┘
                        │
              ┌─────────┴─────────┐
              │   MySQL 8.0       │
              │   Elasticsearch   │
              └───────────────────┘
```

### Carte des dépendances des modules

![Carte des dépendances des modules](diagrams/module-dependency.svg)

## Structure des répertoires

```
admin/
├── app/
│   ├── bootstrap/       # Démarrage par processus
│   │   ├── SnowflakeBootstrap.php
│   │   ├── EncryptableBootstrap.php
│   │   └── EncryptionBootstrap.php
│   ├── controller/       # 54 fichiers de contrôleurs (Base/Crud + CRUD par entité)
│   │   ├── Base.php     # json() avec hashids_encode_ids
│   │   ├── Crud.php     # Select/Insert/Update/Delete/Export avec décodage hashids
│   │   ├── DashboardController.php  # API de données du tableau de bord (statistiques utilisateurs + tendances)
│   │   ├── AccountController.php    # Connexion/déconnexion/profil/mot de passe
│   │   ├── AdminController.php      # CRUD admin + rôles
│   │   ├── RoleController.php       # CRUD rôles + arborescence de règles
│   │   └── ...
│   ├── model/            # 44 modèles Eloquent (36 tables métier sans préfixe mappées du service + alerts (définies dans install.sql) + 7 tables d'administration wa_*)
│   │   ├── Base.php     # Clé primaire Snowflake + prise en charge Encryptable
│   │   ├── Admin.php    # Encryptable : password, email, mobile
│   │   ├── User.php     # Encryptable : 6 champs + trait Searchable
│   │   └── ...
│   ├── common/           # Auth, Tree, Layui, Util, ExcelExport
│   ├── middleware/        # WafMiddleware + AccessControl
│   ├── exception/        # Handler
│   └── functions.php     # hashids_encode/decode, encrypt_data/decrypt_data
├── api/                  # API publique (plugin\admin\api)
│   └── Auth.php          # ACL canAccess()
├── config/
│   ├── plugin/erikwang2013/  # 7 configurations de plugins
│   ├── hashids.php       # Connexions Hashids (principale + alternative)
│   └── encryption.php    # Configuration du chiffrement (clé maîtresse, cipher)
├── tests/                # Suite de tests PHPUnit 11 (286 tests, 962 assertions)
│   ├── HashidsTest.php   # 21 tests
│   ├── BaseJsonTest.php  # 13 tests
│   ├── CrudHashidsTest.php # 14 tests
│   ├── TreeTest.php      # 19 tests
│   ├── AccessControlMiddlewareTest.php # 7 tests (401/403/autorisation)
│   ├── AdminControllersTest.php        # 48 régressions de contrôleurs par réflexion
│   ├── UtilTest.php      # 17 tests
│   ├── DictTest.php      # 5 tests
│   ├── ExcelExportTest.php # 4 tests
│   ├── LayuiTest.php     # 5 tests
│   └── support/          # RequestMock, TestableCrud
├── install.sql           # DDL (clés primaires bigint unsigned, sans auto-incrément)
└── phpunit.xml
```

## Détails de l'intégration des packages

### 1. Snowflake (clés primaires distribuées)

**Configuration** : `config/plugin/erikwang2013/snowflake-php/app.php`
**Bootstrap** : `app/bootstrap/SnowflakeBootstrap.php`

```php
// Model Base::boot() — événement creating
static::creating(function (Model $model) {
    if (empty($model->getKey())) {
        $snowflake = Container::instance()->get(Snowflake::class);
        $model->setAttribute($model->getKeyName(), $snowflake->id());
    }
});
```

- ID 64 bits : `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`
- Époque : 2024-01-01 (durée de vie maximale ~69 ans)
- `$incrementing = false`, `$keyType = 'int'` sur le modèle Base
- Toutes les colonnes PK et FK : `bigint unsigned NOT NULL`

### 2. Hashids (obfuscation des ID)

**Configuration** : `config/hashids.php` + `config/plugin/erikwang2013/hashids/app.php`

**Chemin d'encodage** (réponse) :
- `Base::json()` appelle `hashids_encode_ids($data)` récursivement
- Les champs nommés `id`, `*_id`, `*_ids` avec des entiers positifs → chaînes hashid
- `Crud::formatNormal()` applique aussi l'encodage (corrigé lors de la revue de code)

**Chemin de décodage** (requête) :
- `Crud::selectInput()` : décode les chaînes hashid `id`/`*_id` dans la clause WHERE
- `Crud::updateInput()` : décode la clé primaire depuis `$request->post()`
- `Crud::deleteInput()` : décode un tableau de clés primaires depuis `$request->post()`
- `AdminController::update()` : utilise directement la valeur de retour de `updateInput()` (dédupliquée)
- `RoleController::select()`/`rules()` : décode `$request->get('id')`

**Fonctions d'aide** (dans `app/functions.php`) :
- `hashids_encode(int $id): string`
- `hashids_decode(string $hash): int` — renvoie 0 en cas d'échec
- `hashids_encode_ids(array $data): array` — récursif, gère les chaînes `is_numeric()`

### 3. Encryptable (chiffrement des champs de base de données)

**Configuration** : `config/plugin/erikwang2013/encryptable/app.php`
**Bootstrap** : `app/bootstrap/EncryptableBootstrap.php`

Utilise l'interface Eloquent `CastsAttributes` :
- `get()` : déchiffre AES la valeur à la lecture de la base
- `set()` : chiffre AES la valeur à l'écriture en base

**Champs chiffrés** :
| Modèle | Champs |
|-------|--------|
| Admin | password, email, mobile |
| User | password, email, mobile, token, last_ip, join_ip |

**Règle critique** : toujours utiliser l'instance de modèle `save()`, jamais le Query Builder `update()`. Utiliser `Admin::where(...)->update(...)` contourne les casts Eloquent et stocke des valeurs brutes. Cela a été corrigé dans `AccountController` lors de la revue de code.

**Empilement des mots de passe** : les mots de passe sont d'abord hachés bcrypt (dans `insertInput`/`updateInput`), puis le hash est chiffré AES par le cast Encryptable au `save()`. À la lecture : déchiffrement AES → hash bcrypt → `password_verify()`.

### 4. Encryption (transport API)

**Configuration** : `config/encryption.php`
**Bootstrap** : `app/bootstrap/EncryptionBootstrap.php`

Réservé au chiffrement requête/réponse au niveau API (AES-256-GCM). Fournit :
- `encrypt_data(string $plaintext): string`
- `decrypt_data(string $ciphertext): string`

Lève une `RuntimeException` avec un message clair si `ENCRYPTION_MASTER_KEY` n'est pas configurée.

### 5. Webman-Scout (Elasticsearch)

**Configuration** : `config/plugin/erikwang2013/webman-scout/app.php` + `command.php`

Le modèle User utilise le trait `Searchable` :
```php
class User extends Base
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'mobile' => $this->mobile,
        ];
    }
}
```

### 6. Season (drapeaux de pays)

**Configuration** : `config/plugin/erikwang2013/season/app.php`

Fonction globale : `country_season_flag(string $code): string`
- `country_season_flag('CN')` → 🇨🇳
- `country_season_flag('US')` → 🇺🇸

Fournit aussi les noms de saisons localisés via la classe `CountrySeason`.

### 7. Poster-PHP (captcha à clic)

**Configuration** : `config/poster.php` + `config/plugin/erikwang2013/poster/app.php`
**Bootstrap** : `config/plugin/erikwang2013/poster/bootstrap.php` → `CaptchaPlugin`

Fournit la vérification CAPTCHA à clic pour la connexion et l'inscription :

```
Client                         Server
──────                         ──────
POST /api/v1/captcha/create
  → CaptchaService::create()
    → captcha_create('click')
      → ClickCaptcha::generate()
        → GD rend une image avec n mots chinois placés aléatoirement
        → Stocke les cibles + clé dans le stockage Redis/Fichier
      ← {key, image (base64), target_count, expires_in}

POST /api/v1/auth/login
  (avec captcha_key + captcha_points)
  → AuthController::verifyCaptcha()
    → CaptchaService::verify(key, [[x1,y1], [x2,y2], ...])
      → captcha_verify(key, 'click', points)
        → CaptchaManager vérifie la distance euclidienne ≤ tolérance 18px
      ← true/false
```

**Fonctionnalités de sécurité** :
- Clés à usage unique : supprimées après vérification réussie
- Protection contre la force brute : maximum 3 tentatives échouées par clé, puis suppression
- TTL de 300 secondes (configurable via `CAPTCHA_TTL`)
- Tolérance de clic : rayon de 18px (configurable)
- Niveaux de difficulté : facile (2 cibles), moyen (3), difficile (4)
- Stockage : détection automatique Redis → repli fichier, configurable via `CAPTCHA_STORAGE`

**Wrapper** : `Common\Captcha\CaptchaService` charge la configuration personnalisée de `config/poster.php`, fournit les méthodes `create()` (retire les cibles de la réponse pour la sécurité) et `verify()`. Utilisé par `AuthController::register()` et `AuthController::login()`.

### 8. ConfirmationMiddleware (revérification du mot de passe)

**Configuration** : middleware de groupe de routes dans `config/route.php`

Protège les opérations destructrices et sensibles en exigeant la ressaisie du mot de passe. Appliqué comme middleware sur 12 points de terminaison sensibles :

```
Client                              Server
──────                              ──────
POST /api/v1/orders/{id}/pay
  (avec le champ confirm_password)
    → ConfirmationMiddleware::process()
      → Vérifie la présence de userId (401 si absent)
      → Vérifie la clé de verrouillage Redis (429 si verrouillé)
      → Valide le mot de passe non vide (422 si absent)
      → User::find() + Hash::check() vérifie le bcrypt
      → En cas d'échec :
        → Compteur Redis INCR confirm_failed:{userId}
        → Si le compte ≥ 5, SETEX confirm_lock:{userId} pendant 900s
        → AuditLogger::record('confirm_failed', ...)
        → Renvoie 403
      → En cas de succès :
        → Suppression du compteur DEL confirm_failed:{userId}
        → AuditLogger::record('confirm_success', ...)
        → Appelle $next($request)
```

**Points de terminaison utilisateur sensibles** (Auth + Confirmation) :
| Méthode | Chemin | Opération |
|--------|------|-----------|
| POST | `/api/v1/orders/{id}/pay` | Lancer le paiement |
| POST | `/api/v1/supplier/withdraw` | Demander un retrait |
| DELETE | `/api/v1/dns/{domain}/records/{id}` | Supprimer un enregistrement DNS |

**Points de terminaison admin sensibles** (Auth + AdminRole + Confirmation) :
| Méthode | Chemin | Opération |
|--------|------|-----------|
| DELETE | `/admin/api/v1/products/{id}` | Supprimer un produit |
| POST | `/admin/api/v1/orders/{id}/refund` | Rembourser une commande |
| POST | `/admin/api/v1/provisioning/resources/{id}/destroy` | Détruire une ressource |
| POST | `/admin/api/v1/kyc/{id}/approve` | Approuver un KYC |
| POST | `/admin/api/v1/kyc/{id}/reject` | Rejeter un KYC |
| POST | `/admin/api/v1/suppliers/{id}/approve` | Approuver un fournisseur |
| POST | `/admin/api/v1/suppliers/{id}/settle` | Générer un règlement |
| POST | `/admin/api/v1/suppliers/withdraws/{id}/approve` | Approuver un retrait |
| PUT | `/admin/api/v1/system/config` | Mettre à jour la configuration système |

La version d'API se trouve dans le chemin d'URL (p. ex. `/api/v1/...`), pas dans un en-tête de requête.

**Fonctionnalités de sécurité** :
- Vérification du mot de passe bcrypt via `Hash::check()`
- Limitation de débit : 5 tentatives échouées déclenchent un verrouillage de 15 minutes (TTL 900 s)
- Le verrouillage s'applique par utilisateur via les clés Redis (`confirm_lock:{userId}`, `confirm_failed:{userId}`)
- Un succès réinitialise le compteur d'échecs
- Toutes les tentatives sont journalisées dans la base d'audit (succès, échec, verrouillage)
- `verifyPassword()` est une méthode protected, permettant les tests via un sous-type anonyme qui la surcharge

**Testabilité** : `ConfirmationMiddlewareTest` (11 tests) utilise un sous-type anonyme qui surcharge `verifyPassword()` pour renvoyer un booléen fixe, évitant la dépendance Eloquent/DB. Les tests couvrent : 401 non authentifié, 422 mot de passe absent/vide, 403 mot de passe erroné, passage réussi, format de clé de limite de débit, format de clé de verrouillage, et frontière du seuil maximal d'échecs (4→pas de verrouillage, 5→verrouillé, 6→verrouillé).

## Système ACL

### Au niveau des contrôleurs

```php
protected $noNeedLogin = ['login', 'logout', 'captcha'];  // Ignorer la connexion
protected $noNeedAuth = ['select'];                         // Ignorer l'authentification
```

Vérifié par `api/Auth::canAccess()` via `ReflectionClass`.

**Réponse d'AccessControlMiddleware** (`middleware/AccessControl.php`) :
- Non connecté (hors `noNeedLogin`) → **HTTP 401**, body contenant un script de redirection vers la page de connexion
- Connecté mais permissions insuffisantes → **HTTP 403** page d'erreur (code 403, plus de 500)
- Dans la liste d'autorisation (page de connexion/captcha, etc.) → autorisé normalement

### Basé sur les rôles

- Les rôles ont des `rules` (identifiants de règles séparés par des virgules ou `*` pour le super-admin)
- Les règles sont stockées dans `wa_rules` comme clés `{Controller}@{action}`
- `api/Auth::canAccess()` résout la clé `$controller@$action` par rapport aux règles du rôle
- Le super-admin (`rules = '*'`) contourne toutes les vérifications

### Limites de données

```php
protected $dataLimit = null;     // Aucune limite
protected $dataLimit = 'auth';   // L'admin voit ses propres données + celles des descendants
protected $dataLimit = 'personal'; // L'admin ne voit que ses propres données
protected $dataLimitField = 'admin_id';
```

## Résultats de la revue de code (corrigés)

Lors de la revue du commit initial, les éléments suivants ont été trouvés et corrigés :

### Critique
1. **AccountController contournant Encryptable** : `password()` et `update()` utilisaient `Admin::where()->update()` qui contourne les casts Eloquent → stockait des valeurs brutes dans des colonnes chiffrées. Corrigé en utilisant `Admin::find()->save()`.
2. **Crud::formatNormal() n'encodant pas les ID** : appelait la `json()` globale au lieu d'appliquer `hashids_encode_ids()`. Corrigé.

### Important
3. **hashids_encode_ids strict en `is_int`** : les grandes valeurs bigint de PDO arrivent sous forme de chaînes PHP. Remplacé par `is_numeric()` avec vérification de nombre entier.
4. **Double décodage d'ID dans AdminController** : `update()` décodait la même clé primaire deux fois. Dédupliqué, corrigé l'ombrage de variable de boucle dans `insert()`.
5. **Code de mot de passe mort dans AccountController::update()`** : le champ mot de passe n'est pas dans la liste d'autorisation. Supprimé.
6. **Pilote MySQL codé en dur** : remplacé par `config('database.default')`.

## Export Excel

### Architecture

L'export Excel utilise PhpSpreadsheet ^2.0 pour générer des fichiers .xlsx côté serveur. Le panneau d'administration a deux chemins d'export distincts car il existe deux mécanismes CRUD :

```
Requête d'export (avec les filtres actuels de la table)
  ├── Contrôleurs basés sur Crud (User, Admin, Role, etc.)
  │     → Crud::export()
  │       → selectInput() réutilise l'analyse de requête (décodage hashids, WHERE, ORDER)
  │       → doSelect() construit la requête Eloquent
  │       → Plafond de 10 000 lignes
  │       → hashids_encode_ids() appliqué aux données du résultat
  │       → ExcelExport::export() génère le .xlsx
  │
  └── TableController (tables génériques comme wa_dict, wa_rules)
        → TableController::export()
          → Construit la requête depuis le schéma de table + les paramètres de requête
          → hashids_encode_ids() appliqué
          → ExcelExport::export() génère le .xlsx
```

### Utilitaire ExcelExport (`app/common/ExcelExport.php`)

Wrapper fluent autour de PhpSpreadsheet :

- `setColumns(array $columns)` — définit l'ordre des colonnes
- `setLabels(array $labels)` — définit les en-têtes de colonnes lisibles
- `addRow(array $row)` / `addRows(array $rows)` — remplit les données
- `save(string $title): string` — écrit le .xlsx dans `runtime/exports/`, renvoie le chemin du fichier
- Helper statique : `ExcelExport::export($title, $columns, $data, $labels)` — export en une fois
- Ajustement automatique de la largeur des colonnes via `Worksheet::getColumnDimension()`

### Crud::export()

```php
public function export(Request $request): Response
{
    [$where, $format, $limit, $field, $order] = $this->selectInput($request);
    $query = $this->doSelect($where, $field, $order);
    $maxRows = 10000;
    $total = min($query->count(), $maxRows);
    $items = $query->limit($maxRows)->get();
    if (method_exists($this, 'afterQuery')) {
        $items = call_user_func([$this, 'afterQuery'], $items);
    }
    $data = array_map(fn($item) => ...toArray(), $items->toArray());
    $data = hashids_encode_ids($data);
    // Dériver les libellés de colonnes depuis les commentaires du schéma de table
    $path = ExcelExport::export($table, $columns, $data, $labels);
    return response()->download($path, $table . '_' . date('YmdHis') . '.xlsx');
}
```

Tous les contrôleurs basés sur Crud (Admin, User, Role, etc.) héritent automatiquement de `export()`.

### Câblage front-end

- L'élément de barre d'outils `"exports"` intégré de Layui (CSV côté client) est remplacé par un bouton personnalisé `{title: "导出", layEvent: "export"}`
- Le gestionnaire d'événement `export` appelle `window.exportExcel()` qui collecte les paramètres de filtre actuels de la table et ouvre l'URL de téléchargement
- `Layui::buildTable()` génère la barre d'outils avec le bouton d'export personnalisé pour toutes les pages CRUD

### Export de l'API admin du service

Le backend service (`service/`) possède aussi un export Excel via son propre wrapper `Common\ExcelExport` :

| Point de terminaison | Contrôleur | Données exportées |
|----------|-----------|---------------|
| `GET /admin/api/v1/orders/export` | OrderController | id, order_no, user_id, type, status, total, currency, created_at, paid_at |
| `GET /admin/api/v1/users/export` | UserController | id, email, phone, role, status, created_at, last_login_at |
| `GET /admin/api/v1/suppliers/export` | SupplierController | id, user_id, status, contact_name, contact_email, contact_phone, created_at |

Tous les points de terminaison API sont accessibles sous le préfixe de version `/api/v1/...`.

Les routes d'export sont placées AVANT les routes de paramètre `/{id}` pour éviter les conflits.

## API admin du service — fonctionnalités étendues

### Points de terminaison de l'API admin (couche service)

Tous les points de terminaison REST admin sont préfixés par `/admin/api/v1` et exigent `AdminRoleMiddleware`.

| Groupe | Points de terminaison | Contrôleur |
|-------|-----------|------------|
| Tableau de bord | `GET /dashboard`, `/kyc`, `POST /kyc/{id}/approve`, `/reject` | `Admin\DashboardController` |
| Utilisateurs | `GET /users`, `/users/export`, `/users/{id}`, `PUT /users/{id}/status` | `Admin\UserController` |
| Produits | `POST /products`, `PUT /products/{id}`, `DELETE /products/{id}`, `POST /products/{id}/skus`, `PUT /skus/{id}`, `POST /skus/{id}/region-price` | `Admin\ProductController` |
| Import/export de produits | `GET /products/export` (CSV), `POST /products/import` (upsert CSV) | `Admin\ImportExportController` |
| Commandes | `GET /orders`, `/orders/export`, `/orders/{id}`, `POST /orders/{id}/refund` | `Admin\OrderController` |
| Factures | `GET /invoices`, `POST /invoices/{orderId}/generate` | `Admin\InvoiceController` |
| Paiements | `GET /payments/channels`, `PUT /payments/channels/{id}`, `GET /payments/transactions`, `/reconcile` | `Admin\PaymentController` |
| Livraison | `GET /provisioning/tasks`, `POST /tasks/{id}/retry`, `POST /resources/{id}/upgrade`, `/destroy`, `GET /hosts` | `Provisioning\TaskController` |
| API de fournisseurs | `GET /providers`, `POST /providers`, `PUT /providers/{id}`, `DELETE /providers/{id}` | `Admin\ProviderApiController` |
| CDN | `GET /cdn/domains`, `PUT /cdn/domains/{id}` | `Admin\CdnController` |
| Fournisseurs | `GET /suppliers`, `/suppliers/export`, `POST /suppliers/{id}/approve`, `/settle`, `/withdraws/{id}/approve` | `Admin\SupplierController` |
| Clés API fournisseur | `GET /suppliers/{id}/api-keys`, `POST /suppliers/{id}/api-keys`, `DELETE /suppliers/api-keys/{id}` | `Admin\SupplierController` |
| Tickets | `GET /tickets`, `POST /tickets/{id}/assign`, `/close` | `Ticket\TicketController` |
| Coupons | `GET /coupons`, `POST /coupons`, `DELETE /coupons/{id}` | `Admin\CouponController` |
| Webhooks | `GET /webhooks`, `POST /webhooks`, `DELETE /webhooks`, `POST /webhooks/test` | `Admin\WebhookController` |
| Domaines | `GET /domains/tlds`, `POST /domains/tlds`, `PUT /domains/tlds/{id}`, `DELETE /domains/tlds/{id}`, `GET /domains/zones`, `/transfers`, `POST /transfers/{id}/approve` | `Admin\DomainController` |
| Notifications | `GET /notifications/templates`, `PUT /notifications/templates/{id}`, `GET /notifications/log` | `Admin\NotificationController` |
| Articles d'aide | `GET /help`, `POST /help`, `PUT /help/{id}`, `DELETE /help/{id}` | `Admin\HelpController` |
| Rapports | `GET /reports/revenue`, `/supplier`, `/region` | `Report\ReportController` |
| Surveillance | `GET /monitor/dashboard`, `/resources/{id}` | `Monitor\MonitorController` |
| Audit | `GET /audit-logs` | `Admin\SystemController` |
| Configuration système | `PUT /system/config` | `Admin\SystemController` |

### Gestion des ressources CDN

Le produit CDN prend en charge quatre fournisseurs (Cloudflare / CloudFront / Alibaba Cloud / Tencent Cloud), côté administration en deux parties :

**Configuration des comptes fournisseurs** (réutilise le modèle ProviderApi, `Admin\ProviderApiController`) :

- `GET/POST /admin/api/v1/providers`, `PUT/DELETE /admin/api/v1/providers/{id}`, soumis à `RbacMiddleware('provider.config')`
- Convention `code` `cdn-cloudflare` / `cdn-cloudfront` / `cdn-aliyun` / `cdn-tencent` ; identifiants chiffrés en base via Encryptable, colonne JSON `config` pour les métadonnées non sensibles
- Ordre de résolution des identifiants côté utilisateur : compte lié → compte actif correspondant au code → repli env ; suppression/purge en instantané strict (seul le compte lié, sinon 4003)

**Gestion des domaines CDN** (`Admin\CdnController`) :

```
GET /admin/api/v1/cdn/domains        → Tous les domaines (avec user_id propriétaire), soumis à RbacMiddleware('cdn.manage')
PUT /admin/api/v1/cdn/domains/{id}   → Mise à jour du forfait, liste blanche plan standard | pro | enterprise,
                                    valeur invalide → 400 ; le changement écrit le journal d'audit admin_cdn_update_plan
```

### Données du tableau de bord (couche service)

`Admin\DashboardController::index()` fournit des métriques opérationnelles réelles :

```php
[
    'today_stats' => [todayOrders, todayRevenue, newUsers, activeResources],
    'revenue_trend_30d' => [...],   // Revenus quotidiens des 30 derniers jours
    'region_distribution' => [...],  // Ressources actives groupées par région
    'pending_orders' => ...,         // Commandes en attente de paiement
    'pending_kyc' => ...,            // Soumissions KYC en attente d'examen
    'open_tickets' => ...,           // Tickets ouverts ou en cours
]
```

### Vue du tableau de bord du panneau admin (`app/view/index/dashboard.html`)

- **8 cartes de statistiques animées** : utilisateurs aujourd'hui/semaine/mois/total + commandes du jour + revenus du jour + commandes en attente + ressources actives — chacune avec animation de comptage via le module Layui `count`
- **3 graphiques ECharts** :
  1. Tendance d'inscription des utilisateurs sur 7 jours — graphique en aires
  2. Tendance d'inscription des utilisateurs sur 30 jours — graphique en barres
  3. Résumé des utilisateurs — graphique en anneau/beignet (aujourd'hui / semaine / mois)
- **Table d'informations système** : remplie dynamiquement avec les versions PHP/Workerman/Webman/Admin/MySQL/OS
- **Barre d'outils** : boutons d'export PDF et d'actualisation
- Toutes les données récupérées via AJAX depuis `/app/admin/dashboard/data`

### Route

```
Route::any('/app/admin/dashboard/data', [DashboardController::class, 'index']);
```

En plus des routes enregistrées explicitement, `admin/config/route.php` enregistre automatiquement pour chaque méthode publique de chaque contrôleur de `app/controller/` la route `/app/admin/{snake_case_controller}/{action}` (ex. `/app/admin/order_item/index`), l'URL étant cohérente avec le nom de contrôleur snake_case utilisé dans les menus ; `/app/admin` et `/app/admin/index` sont les entrées de la page d'accueil/de connexion du back-office (la vue de connexion est rendue si non connecté) ; toute requête sans correspondance renvoie un 404.

## Export PDF

Génération PDF côté client sur la page du tableau de bord :

- Utilise **html2canvas 1.4.1** (CDN) pour capturer le DOM du tableau de bord en canvas
- Utilise **jsPDF 2.5.1** (CDN) pour créer un PDF A4 téléchargeable
- Capture les cartes de statistiques et les graphiques ECharts (rendus comme éléments canvas)
- Inclut le titre, l'horodatage et le branding dans le PDF
- Déclenché par le bouton « Export PDF » de la barre d'outils du tableau de bord

```
DOM du tableau de bord → capture html2canvas → document jsPDF → téléchargement navigateur
```

### Implémentation

```javascript
// In dashboard.html
html2canvas(document.querySelector('.layui-body'), {scale: 2}).then(canvas => {
    const pdf = new jsPDF('p', 'mm', 'a4');
    const imgData = canvas.toDataURL('image/png');
    pdf.addImage(imgData, 'PNG', 10, 10, 190, 0);
    pdf.save('dashboard_' + new Date().toISOString().slice(0, 10) + '.pdf');
});
```

## Suite de tests

```
PHPUnit 11.5 | 67 tests | 124 assertions
```

### HashidsTest (21 tests)
- Aller-retour encode/decode (0 à PHP_INT_MAX)
- Encodage déterministe
- Gestion des chaînes invalides/vides
- Modèles de champs de `hashids_encode_ids` (`id`, `*_id`, `*_ids`)
- Ignorance du zéro/négatif, prise en charge des chaînes numériques
- Récursion des tableaux imbriqués, préservation des champs non-ID

### BaseJsonTest (13 tests)
- `json()`/`success()`/`fail()` appliquent l'encodage hashids
- Encodage d'objets imbriqués
- Gestion des ID de taille Snowflake
- Préservation des champs non-ID
- Gestion du zéro
- Vérification de la structure de réponse

### CrudHashidsTest (14 tests)
- `selectInput` : décodage hashid dans les champs WHERE `id`/`*_id`
- `selectInput` : passage direct des chaînes numériques/int bruts
- `updateInput` : décodage de la clé primaire hashid
- `updateInput` : cast de la chaîne numérique de clé primaire en int
- `deleteInput` : décodage d'ID en lot, types mixtes
- `deleteInput` : tableau vide, gestion d'un seul ID

## Système de migration de base de données

### Architecture

Les instances `service/` et `admin/` ont des systèmes de migration indépendants basés sur le Schema Builder de `illuminate/database`. Chaque instance enregistre des commandes Symfony Console via `config/command.php`, découvrables par l'exécuteur de console de webman.

```
php webman migrate          # Exécuter les migrations en attente
php webman migrate:rollback # Revenir sur le dernier lot
php webman migrate:status   # Afficher l'état des migrations
```

### MigrationRunner (`service/support/MigrationRunner.php`, `admin/app/common/MigrationRunner.php`)

Moteur central partagé par les deux instances :

- **`ensureTable()`** — Crée la table de suivi `migrations` (id, nom de migration, numéro de lot) au premier lancement
- **`migrate()`** — Scanne les fichiers de migration de `database/migrations/`, exécute les méthodes `up()` en attente, enregistre le lot
- **`rollback()`** — Inverse le dernier lot en appelant `down()` sur chaque migration en ordre inverse
- **`status()`** — Liste toutes les migrations avec leurs numéros de lot
- **`resolve()`** — Instancie les classes de migration depuis les fichiers

### Classe de base Migration (`service/support/Migration.php`, `admin/app/common/Migration.php`)

```php
abstract class Migration
{
    abstract public function up(): void;
    abstract public function down(): void;
}
```

Chaque fichier de migration renvoie une classe étendant `Migration`, avec des noms de fichiers préfixés par horodatage (ex. `2024_01_01_000001_create_initial_schema.php`).

### Migrations du service

**Répertoire** : `service/database/migrations/` — 38 fichiers de migration (noms de tables sans préfixe erik_, mappés directement par les modèles admin)

| Migration | Tables |
|-----------|--------|
| `0001_create_users_tables` | users, user_profiles, user_kyc, user_balance, user_balance_log, user_addresses, refresh_tokens |
| `0002_create_product_tables` | product_categories, regions, products, product_skus, product_regions, product_images, product_attributes, product_reviews |
| `0003_create_order_tables` | carts, orders, order_items, order_timeline, order_invoices, refunds |
| `0004_create_payment_tables` | payment_channels, payment_transactions, payment_reconcile |
| `0005_create_provisioning_tables` | resources, resource_servers, resource_ips, resource_disks, resource_domains, provision_tasks, provider_apis |
| `0006_create_host_tables` | host_machines, ip_pools, ip_allocations, disks, disk_resizes |
| `0007_create_supplier_tables` | suppliers, supplier_products, supplier_settlements, supplier_withdraws |
| `0008_create_domain_tables` | domain_tlds, domain_transfers, dns_zones, dns_records |
| `0009_create_ticket_notification_tables` | tickets, ticket_messages, notifications, notification_templates |
| `0010_create_audit_table` | audit_logs |
| `0011_create_kvm_service_tables` | network_services, firewall_services, switch_services |
| `2024_01_01_000001_create_initial_schema` | Exécute `docs/database.sql` via `Capsule::unprepared()`, supprime tout dans `down()` |
| `2025_05_16_000002_add_fcm_token_to_users` | Ajoute les colonnes `fcm_token`, `fcm_platform` + index à users |
| `2026_08_26_000003_widen_encrypted_columns` | users.phone / user_addresses.phone / suppliers.contact_phone VARCHAR(20)→VARCHAR(255) (longueur du texte chiffré Encryptable) |

### Migrations de l'admin

**Répertoire** : `admin/database/migrations/` — 1 fichier de migration

| Migration | Description |
|-----------|-------------|
| `2024_01_01_000001_create_admin_schema` | Exécute `admin/install.sql` via `Capsule::unprepared()` — crée les tables wa_* avec les données de seed |

### Enregistrement des commandes console

**`service/config/command.php`** :
```php
return [
    \App\Command\MigrateCommand::class,
    \App\Command\MigrateRollbackCommand::class,
    \App\Command\MigrateStatusCommand::class,
];
```

**`admin/config/command.php`** — même modèle sous le namespace `app\command`.

## Intégration Stripe en production

### Architecture

Les faux ID de paiement `random_bytes()` ont été remplacés par une vraie intégration API Stripe via `stripe/stripe-php` ^15.0.

**Fichier** : `service/app/payment/service/channels/StripeChannel.php`

```
Côté client                    Côté serveur                    API Stripe
───────────                    ───────────                    ──────────
Sélection de Stripe au paiement
  → POST /orders/{id}/pay
    → StripeChannel::createPaymentIntent()
      → StripeClient->paymentIntents->create(amount, currency)
        ← {id, client_secret}
      → Enregistre pi_xxx comme transaction_no
      ← Renvoie client_secret
  → Stripe.js confirmCardPayment(client_secret)
    ← Paiement confirmé par Stripe
      → POST /payments/webhook/stripe
        → StripeChannel::handleWebhook()
          → Webhook::constructEvent(payload, signature, secret)
          → Vérification d'idempotence (ignore les transactions non-pending)
          → Met à jour le statut de la commande, crée l'enregistrement de transaction
```

### Création du PaymentIntent

```php
public function createPaymentIntent(Order $order): array
{
    $intent = $this->stripe()->paymentIntents->create([
        'amount'   => (int) round($order->total * 100),  // cents
        'currency' => strtolower($order->currency),
        'metadata' => ['order_id' => $order->id, 'order_no' => $order->order_no],
    ]);
    return [
        'transaction_no' => $intent->id,          // pi_xxxxxxxxxxxxx
        'client_secret'  => $intent->client_secret, // pi_xxx_secret_yyy
    ];
}
```

- `$this->stripe()` initialise paresseusement `\Stripe\StripeClient` avec `STRIPE_SECRET_KEY` de l'environnement
- Se replie sur `$this->channel->api_key_encrypted` (déchiffrée via Encryptable) si la variable d'environnement n'est pas définie
- Le montant est converti en cents : `(int) round($order->total * 100)`

### Vérification de signature Webhook

```php
public function handleWebhook(string $payload, string $signature): void
{
    $event = \Stripe\Webhook::constructEvent(
        $payload, $signature, $this->channel->webhook_secret_encrypted
    );
    // Idempotence : ignorer si la transaction est déjà traitée
    $existing = Transaction::where('transaction_no', $event->id)->first();
    if ($existing && $existing->status !== 'pending') return;
    
    match ($event->type) {
        'payment_intent.succeeded' => $this->confirmPayment($event),
        'payment_intent.payment_failed' => $this->failPayment($event),
        default => null,
    };
}
```

- Utilise `Webhook::constructEvent()` pour vérifier l'en-tête de signature Stripe
- **Protection d'idempotence** : vérifie les livraisons webhook dupliquées par `transaction_no`
- Prend en charge les types d'événements de succès et d'échec

## Intégration SMS Twilio

### Architecture

Le stub `error_log()` a été remplacé par une vraie livraison SMS via `twilio/sdk` ^8.0.

**Fichier** : `service/app/notification/queue/SmsSender.php`

### Envoi de messages

```php
public function consume(): void
{
    $client = new \Twilio\Rest\Client(
        getenv('TWILIO_ACCOUNT_SID'),
        getenv('TWILIO_AUTH_TOKEN')
    );
    $message = $client->messages->create(
        $this->notification->recipient_phone,
        ['from' => getenv('TWILIO_PHONE_NUMBER'), 'body' => $this->notification->body]
    );
    $this->notification->provider_message_id = $message->sid;
}
```

### Gestion des erreurs

- Attrape `Twilio\Exceptions\RestException` — capture le code et le message d'erreur Twilio
- Crée un enregistrement Notification en échec avec `send_status = 'failed'`
- Enregistre `provider_message_id` (SID Twilio) pour le suivi de livraison
- Se replie sur `error_log()` quand les identifiants Twilio ne sont pas définis (mode dev)

### Configuration

Variables d'environnement : `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_PHONE_NUMBER`

## Intégration push FCM

### Architecture

Le stub `error_log()` a été remplacé par une vraie livraison push via `kreait/firebase-php` ^7.0.

**Fichier** : `service/app/notification/queue/PushSender.php`

### Stockage des jetons d'appareil

Ajouté à la table `users` via migration :
- `fcm_token VARCHAR(512) DEFAULT NULL` — jeton d'enregistrement de l'appareil
- `fcm_platform VARCHAR(16) DEFAULT NULL` — `ios` / `android` / `web`
- `INDEX idx_fcm_token (fcm_token)` — recherche par jeton

Modèle User : `fcm_token` et `fcm_platform` ajoutés à `$fillable`.

### Envoi de push

```php
public function consume(): void
{
    $factory = new \Kreait\Firebase\Factory();
    if ($credentialsPath = getenv('FIREBASE_CREDENTIALS_PATH')) {
        $factory = $factory->withServiceAccount($credentialsPath);
    }
    $messaging = $factory->createMessaging();
    
    $message = \Kreait\Firebase\Messaging\CloudMessage::withTarget(
        'token', $this->user->fcm_token
    )->withNotification([
        'title' => $this->notification->title,
        'body'  => $this->notification->body,
    ]);
    
    $result = $messaging->send($message);
}
```

### Nettoyage des jetons

- Attrape `Kreait\Firebase\Exception\Messaging\InvalidToken` — met à null le `fcm_token` de l'utilisateur
- Attrape `Kreait\Firebase\Exception\Messaging\NotFound` — retire le jeton non enregistré
- Se replie sur `error_log()` quand les identifiants Firebase ne sont pas définis (mode dev)

### Configuration

Variables d'environnement : `FIREBASE_CREDENTIALS_PATH` (JSON de compte de service), `FCM_SERVER_KEY` (legacy)

## Diagrammes de flux métier

### Commande → Paiement → Livraison (flux métier central)

![Flux commande paiement livraison](diagrams/order-payment-provisioning.svg)

### Détail de la livraison pilotée par événements

![Livraison pilotée par événements](diagrams/provisioning-detail.svg)

### Diffusion des notifications

![Diffusion des notifications](diagrams/notification-dispatch.svg)

### Cycle de vie du fournisseur

![Cycle de vie du fournisseur](diagrams/supplier-lifecycle.svg)

### Cycle de vie du ticket

![Cycle de vie du ticket](diagrams/ticket-lifecycle.svg)

## Suite de tests de la couche service

### Vue d'ensemble

```
PHPUnit 10.5 | 295 tests | 455 assertions
```

**Répertoire** : `service/tests/` — 12 fichiers de tests répartis sur 7 modules

**Configuration** : `service/phpunit.xml` — testsuite `unit` unique, couvre les sources `app/` et `common/`

### Bootstrap de test

`service/tests/bootstrap.php` charge l'autoload Composer et définit deux helpers globaux nécessaires au code testé :

- `request_id()` — renvoie une chaîne d'ID de requête unique
- `now()` — renvoie l'objet `DateTime` courant

Apprentissage critique : `Webman\Config` ne peut pas être chargé dans un contexte de test car `loadFromDir()` déclenche `route.php` qui appelle `Route::addRoute()` sur null. Les tests contournent entièrement Config — `HashidServiceTest` utilise `new Hashids()` directement, `ResponseTest` utilise des méthodes helpers locales.

### Fichiers de test

| Fichier | Tests | Couverture |
|------|-------|----------|
| `Captcha/CaptchaServiceTest.php` | 9 | structure de création, niveaux de difficulté, vérification pass/échec, usage unique, clés uniques |
| `Confirmation/ConfirmationMiddlewareTest.php` | 11 | authentification requise, mot de passe absent, mot de passe erroné, passage réussi, format de clé de limite de débit, format de clé de verrouillage, seuils maximaux d'échecs |
| `Common/HashidServiceTest.php` | 17 | aller-retour encode/decode, déterminisme, isolation du sel, parcours récursif des ID |
| `Common/ResponseTest.php` | 16 | structure success/error/paginated, cohérence de request_id, codes d'erreur HTTP |
| `Common/SnowflakeTest.php` | 6 | ordre des horodatages, unicité, plage bigint, modèle d'initialisation |
| `Common/ValidatorTest.php` | 22 | règles de validation required(), email(), minLength() |
| `Common/LogSanitizerTest.php` | 34 | masquage des PII, tableaux imbriqués, correspondance insensible à la casse, 20 types de champs sensibles |
| `Payment/StripeChannelTest.php` | 19 | configuration du canal, calcul des montants, signatures webhook, idempotence |
| `Payment/PaymentRouterTest.php` | 10 | filtrage des canaux, contraintes de montant, prise en charge devise/région, calcul des frais |
| `Notification/NotificationDispatcherTest.php` | 8 | rendu des modèles, routage des canaux, saut des utilisateurs inactifs |
| `Provisioning/ProviderFactoryTest.php` | 12 | register, create, createFromResource, cas d'erreur |
| `Provisioning/RetryLogicTest.php` | 12 | backoff exponentiel, nouvelles tentatives maximales, transitions de statut, sélection d'hôte |
| `ClientPlatform/ClientPlatformMiddlewareTest.php` | 13 | plateformes valides, en-tête manquant/défaut, plateforme non prise en charge, insensible à la casse, saut non-API, routes admin, accès en aval |
| `Security/WafMiddlewareTest.php` | 37 | SQLi (4), XSS (6), CMDi (4), inclusion de fichiers (3), injection d'en-têtes/CRLF (2), SSRF (5), injection NoSQL (4), redirection ouverte (2), passage sûr (5), scan d'URL, scan UA |
| `Version/VersionMiddlewareTest.php` | 6 | version valide, version manquante par défaut, version non prise en charge 400, saut non-API, validation de l'API admin, en-têtes de réponse d'erreur |

### Infrastructure de test

- `tests/TestCase.php` — classe de base étendant PHPUnit TestCase
- `tests/Support/RequestMock.php` — requête simulée avec paramètres injectés via le constructeur

## Pipeline CI/CD

### Architecture

Workflow GitHub Actions dans `.github/workflows/ci.yml`.

**Déclencheurs** : push sur `main`, pull requests vers `main`

### Jobs

| Job | Stratégie | Description |
|-----|----------|-------------|
| `syntax` | PHP 8.2 | `php -l` vérifie la syntaxe de tous les fichiers .php de admin/ et service/ |
| `admin-tests` | PHP 8.2, 8.3 | `composer install -d admin` → `admin/vendor/bin/phpunit` |
| `service-tests` | PHP 8.2, 8.3 | `composer install -d service` → `service/vendor/bin/phpunit` |
| `composer-validate` | PHP 8.2 | `composer validate --strict` sur les deux fichiers composer.json |

### Matrice de versions PHP

Les deux jobs de tests tournent sur PHP 8.2 et 8.3 via `shivammathur/setup-php@v2`.

### Statut actuel

Les 4 jobs passent : 243 tests au total (67 admin + 176 service), 400 assertions, les deux versions PHP au vert.

## Relation d'entités de base de données

![Relation d'entités de base de données](diagrams/database-er.svg)

## Décisions de conception clés

1. **Instance autonome** : admin/ fonctionne comme sa propre instance webman, pas comme un plugin dans service/. Cela isole le trafic et les pannes de l'administration de l'API orientée client.

2. **Encryptable + hachage de mots de passe** : les mots de passe sont d'abord hachés bcrypt, puis chiffrés AES. Le cast Encryptable opère au niveau Eloquent (au-dessus du hachage), donc l'empilement est : `entrée → hash bcrypt → set d'attribut de modèle → Encryptable::set() chiffre → DB`. À la lecture : `DB → Encryptable::get() déchiffre → hash bcrypt → password_verify()`.

3. **Hashids à la frontière des contrôleurs** : l'encodage/décodage se produit à la frontière HTTP (contrôleurs), pas au niveau modèle ou ORM. Cela garde les modèles indépendants de la base et fait des hashids une pure préoccupation de présentation.

4. **Résolution de services par conteneur** : les services (Snowflake, HashidsManager, EncryptionManager) sont enregistrés comme singletons via les classes Bootstrap au démarrage des workers. La résolution par conteneur via `\support\Container::instance()` utilise l'instanciation paresseuse — les services ne sont créés qu'au premier accès.

## Fonctionnalités étendues (2026-05-20)

### API admin du service — nouveaux points de terminaison

| Groupe | Points de terminaison | Contrôleur |
|-------|-----------|------------|
| Factures | `GET /admin/api/v1/invoices`, `POST .../invoices/{orderId}/generate` | `Admin\InvoiceController` |
| API de fournisseurs | `GET/POST /admin/api/v1/providers`, `PUT/DELETE .../providers/{id}` | `Admin\ProviderApiController` |
| Clés API fournisseur | `GET/POST /admin/api/v1/suppliers/{id}/api-keys`, `DELETE .../api-keys/{id}` | `Admin\SupplierController` |
| Coupons | `GET/POST /admin/api/v1/coupons`, `DELETE .../coupons/{id}` | `Admin\CouponController` |
| Webhooks | `GET/POST/DELETE /admin/api/v1/webhooks`, `POST .../webhooks/test` | `Admin\WebhookController` |
| Import/export de produits | `GET /admin/api/v1/products/export`, `POST .../products/import` | `Admin\ImportExportController` |
| Gestion des domaines | `GET/POST/PUT/DELETE /admin/api/v1/domains/tlds`, `GET .../zones`, `GET .../transfers`, `POST .../transfers/{id}/approve` | `Admin\DomainController` |
| Modèles de notifications | `GET /admin/api/v1/notifications/templates`, `PUT .../templates/{id}`, `GET .../log` | `Admin\NotificationController` |
| Articles d'aide | `GET/POST /admin/api/v1/help`, `PUT/DELETE .../help/{id}` | `Admin\HelpController` |

### Nouveaux middlewares

| Middleware | Objectif |
|------------|---------|
| `VersionMiddleware` | Lit et valide la version d'API depuis le chemin d'URL (p. ex. `/api/v1/...`) |
| `RateLimitMiddleware` | Limitation de débit par seau à jetons Redis (défaut 60 req/min, connexion 5 req/min) |
| `GeoBlockMiddleware` | Blocage géographique MaxMind GeoIP2 |
| `MaintenanceMiddleware` | Mode maintenance (interrupteur variable d'environnement + liste blanche IP) |
| `ClientPlatformMiddleware` | Identification de la plateforme client (en-tête X-Client-Platform), 8 plateformes prises en charge |
| `SupplierApiKeyMiddleware` | Authentification API externe des fournisseurs (vérification de signature SHA256 de la clé sk_xxx) |
| `WafMiddleware` (admin) | Middleware WAF du panneau admin, 8 catégories de 45+ règles + limite de taille de requête + validation Content-Type |

### Tâches planifiées

| Planification | Tâche | Objectif |
|----------|------|---------|
| `13 */4 * * *` | ExchangeRateSync | Mise à jour des taux de change |
| `37 2 * * *` | PaymentReconcile | Rapprochement quotidien des paiements |
| `17 4 * * 1` | SupplierSettlement | Règlement hebdomadaire des fournisseurs |
| `23 6 * * *` | ExpirationCheck | Vérification des expirations de ressources/domaines + notifications |
| `43 7 * * *` | SslCertificateCheck | Vérification des expirations SSL + notifications |
| `*/5 * * * *` | CollectMetrics | Collecte des métriques de ressources |
| `*/30 * * * *` | CheckExpirations | Vérification des expirations de ressources |

### Commandes CLI

| Commande | Objectif |
|---------|---------|
| `php webman migrate` | Exécuter les migrations en attente |
| `php webman migrate:rollback` | Revenir sur le dernier lot |
| `php webman migrate:status` | Consulter l'état des migrations |
| `php webman db:backup` | Sauvegarder la base dans un fichier SQL (option --s3 de téléversement) |

### Migrations de base de données ajoutées (2026-05-20)

| Migration | Tables/Colonnes |
|-----------|---------------|
| 000003 | users + totp_secret, totp_enabled |
| 000004 | coupons, user_coupons |
| 000005 | help_articles, supplier_api_keys |
| 000006 | roles, permissions, role_permission + données de seed |
| 000007 | users + email_verified_at, email_verify_token |
| 000008 | users + last_login_ip, last_login_at |

## Index de la documentation

### Documents principaux

| Document | Chemin | Description |
|----------|------|-------------|
| Document de conception de l'architecture | `docs/architecture.md` | Architecture système, relations entre composants, pipeline de middlewares, couches de sécurité, architecture des données, topologie de déploiement |
| Document de conception fonctionnelle | `docs/features.md` | Conception fonctionnelle détaillée des 21 modules, avec organigrammes, modèles de données, descriptions d'interactions |
| Référence des API | `docs/api-reference.md` | Référence complète de 200+ points de terminaison, groupés par module, avec exemples requête/réponse, codes d'erreur |
| Documentation API en ligne (service) | `http://localhost:8787/apidoc` | Générée automatiquement par hg/apidoc, groupée par fonctionnalité, débogage en ligne pris en charge |
| Documentation API en ligne (admin) | `http://localhost:8788/apidoc` | Générée automatiquement par hg/apidoc, 54 contrôleurs en 13 groupes fonctionnels |
| Spécification de conception système | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` | Architecture complète, modèles de données, conception d'API, stratégie de sécurité |
| Conception du panneau d'administration | `docs/admin-design.md` | Architecture du panneau Admin, intégration des packages, ACL, suite de tests |
| Documentation de l'API fournisseur | `docs/supplier-api.md` | Référence de l'API fournisseur (API interne + API externe), exemples de SDK |
| Checklist de déploiement | `docs/deployment.md` | Configuration serveur, variables d'environnement, migrations de base, Nginx, HTTPS, tâches planifiées |

### Plans d'implémentation

| Document | Chemin | Description |
|----------|------|-------------|
| Phase 0 — Framework de base | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase0.md` | Squelette du projet, structure de répertoires, infrastructure centrale |
| Phase 1 — Utilisateurs et boutique | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase1.md` | Authentification utilisateur, gestion des produits, panier, commandes |
| Phase 2 — Ressources et fournisseurs | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase2.md` | Livraison de ressources, DNS, installation des fournisseurs |
| Phase 3 — Clients et livraison | `docs/superpowers/plans/2026-05-14-cloud-resource-platform-phase3.md` | Client Flutter, adaptation multi-plateformes, CI/CD |

### Outils et ressources

| Document | Chemin | Description |
|----------|------|-------------|
| Test de fumée API | `docs/api-test.sh` | Script de test automatisé des points de terminaison API basé sur curl |
| DDL de base de données | `docs/database.sql` | Instructions de création des tables de la base de données |

## Statistiques finales de tests

```
OK (362 tests, 579 assertions)
Test files: 22
```
- Admin : 67 tests, 124 assertions
- Service : 295 tests, 455 assertions
