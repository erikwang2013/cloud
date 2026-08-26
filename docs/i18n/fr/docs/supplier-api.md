# Documentation de l'API fournisseur v1

## Vue d'ensemble

La fonctionnalité fournisseur propose deux ensembles d'API :

| Type | Authentification | Préfixe | Statut |
|------|---------|------|------|
| **API interne** | Bearer Token utilisateur | `/api/supplier/` | Disponible |
| **API externe** | API Key (`sk_xxx`) | `/api/supplier/external/` | Disponible |

**Base URL** : `https://api.example.com`

**Contrôle de version** : spécifié via l'en-tête HTTP `X-Api-Version: v1`. Par défaut `v1` si absent, les versions non prises en charge renvoient `400`. Effectif uniquement sur les chemins `/api/*` et `/admin/api/*`, traité de façon unifiée par `VersionMiddleware`.

---

## API interne (actuellement disponible)

L'API interne utilise la même authentification Bearer Token utilisateur que les autres interfaces de la plateforme ; elle est destinée aux utilisateurs fournisseurs connectés, appelée depuis le client/front-end.

### Authentification

```
Authorization: Bearer <user_access_token>
X-Api-Version: v1
```

L'utilisateur doit d'abord se connecter via `/api/auth/login` pour obtenir un Token, et le rôle du compte doit être `supplier` (défini après approbation de la demande de fournisseur par l'administrateur).

---

### Format de réponse

#### Réponse de succès

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### Réponse paginée

```json
{
  "code": 0,
  "message": "ok",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 45
  }
}
```

#### Réponse d'erreur

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | Description |
|------|------|
| 0 | Succès |
| 400 | Erreur de paramètres de requête / version API non prise en charge |
| 401 | Non connecté ou Token expiré |
| 403 | Accès non autorisé (rôle non fournisseur / échec de confirmation de mot de passe) |
| 404 | Ressource inexistante |
| 422 | Échec de validation des paramètres |
| 429 | Dépassement de la fréquence de requêtes |

---

### Points de terminaison

#### 1. Installation du fournisseur

```
POST /api/supplier/apply
```

Demander à devenir fournisseur. Chaque utilisateur ne peut soumettre qu'une seule demande.

**Corps de requête** :

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| Paramètre | Type | Requis | Description |
|------|------|------|------|
| company_name | string | oui | Nom de l'entreprise |
| contact_name | string | oui | Nom du contact |
| contact_phone | string | oui | Téléphone du contact |
| contact_email | string | oui | E-mail du contact |
| settlement_method | string | non | Méthode de règlement, défaut `bank` |

**Réponse** : l'objet fournisseur, avec le statut `pending`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "aBc123XyZ",
    "user_id": "UsEr456AbC",
    "company_name": "示例科技有限公司",
    "contact_name": "张三",
    "contact_phone": "138****8000",
    "contact_email": "zha***@example.com",
    "status": "pending",
    "settlement_method": "bank",
    "created_at": "2026-05-20T10:30:00Z"
  }
}
```

> Les champs sensibles (nom du contact, téléphone, e-mail) sont chiffrés dans la base de données et partiellement masqués à la réponse de l'API.

**Erreurs** :

| code | Scénario |
|------|------|
| 422 | Demande de fournisseur déjà soumise |

```bash
curl -X POST "https://api.example.com/api/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. Gestion des produits

##### Obtenir les produits attribués

```
GET /api/supplier/products
```

**Paramètres de requête** :

| Paramètre | Type | Requis | Description |
|------|------|------|------|
| page | int | non | Numéro de page, défaut 1 |

**Réponse** : liste paginée, chaque élément contient les informations du produit et le taux de commission

```json
{
  "code": 0,
  "data": [{
    "id": "SpAbC123",
    "supplier_id": "aBc123XyZ",
    "product_id": "PrOdEfG456",
    "commission_rate": 0.1,
    "approved_at": "2026-05-20T10:30:00Z",
    "product": {
      "id": "PrOdEfG456",
      "name": "高性能云服务器",
      "status": "active"
    }
  }],
  "meta": { "page": 1, "page_size": 20, "total": 5 }
}
```

##### Ajouter un produit

```
POST /api/supplier/products
```

Associer un produit existant au fournisseur actuel.

**Corps de requête** :

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| Paramètre | Type | Requis | Description |
|------|------|------|------|
| product_id | string | oui | ID du produit (Hashid) |
| commission_rate | float | non | Taux de commission, défaut 0.1 |

**Réponse** : l'objet SupplierProduct créé

**Erreurs** :

| code | Scénario |
|------|------|
| 422 | Le produit est déjà attribué à ce fournisseur |

##### Retirer un produit

```
DELETE /api/supplier/products/{id}
```

Annuler l'association du produit avec le fournisseur.

**Réponse** :

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. Gestion des règlements

##### Obtenir la liste des règlements

```
GET /api/supplier/settlements
```

**Réponse** : tous les règlements du fournisseur actuel, triés par date de création décroissante

```json
{
  "code": 0,
  "data": [{
    "id": "SeTtLe123",
    "supplier_id": "aBc123XyZ",
    "period_start": "2026-05-01",
    "period_end": "2026-05-31",
    "total_sales": "15000.00",
    "commission": "1500.0000",
    "payable": "13500.0000",
    "status": "pending",
    "created_at": "2026-06-01T02:17:00Z"
  }]
}
```

| Champ | Description |
|------|------|
| total_sales | Total des ventes des commandes terminées de la période |
| commission | Total des commissions de la plateforme |
| payable | Montant payable au fournisseur (total_sales - commission) |
| status | `pending` / `paid` |

---

#### 4. Retrait

##### Demander un retrait

```
POST /api/supplier/withdraw
```

> Cette opération nécessite une confirmation de mot de passe (champ `confirm_password`), validée par `ConfirmationMiddleware`.
> Après 5 échecs, verrouillage de 15 minutes.

**Corps de requête** :

```json
{
  "amount": "5000.00",
  "confirm_password": "user_password_here",
  "account_info": {
    "method": "bank_transfer",
    "bank_name": "中国工商银行",
    "account_number": "6222021234567890",
    "account_holder": "张三"
  }
}
```

| Paramètre | Type | Requis | Description |
|------|------|------|------|
| amount | string | oui | Montant du retrait (chaîne pour éviter les problèmes de précision des flottants) |
| confirm_password | string | oui | Mot de passe de connexion de l'utilisateur (deuxième confirmation) |
| account_info | object | oui | Informations du compte de réception |
| account_info.method | string | oui | Méthode de retrait : `bank_transfer` / `alipay` / `wechat` |

**Calcul du solde retirable** : somme des `payable` de tous les règlements terminés - somme des `amount` de tous les retraits en cours

**Réponse** :

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**Erreurs** :

| code | Scénario |
|------|------|
| 422 | Solde retirable insuffisant |
| 403 | Échec de confirmation du mot de passe |

```bash
curl -X POST "https://api.example.com/api/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### Récapitulatif des points de terminaison de l'API interne

| Méthode | Chemin | Authentification | Confirmation du mot de passe | Description |
|------|------|------|---------|------|
| POST | `/api/supplier/apply` | Token | - | Demander à devenir fournisseur |
| GET | `/api/supplier/products` | Token | - | Consulter les produits attribués |
| POST | `/api/supplier/products` | Token | - | Ajouter une association de produit |
| DELETE | `/api/supplier/products/{id}` | Token | - | Retirer une association de produit |
| GET | `/api/supplier/settlements` | Token | - | Consulter les règlements |
| POST | `/api/supplier/withdraw` | Token | requise | Demander un retrait |

---

## API externe (spécification de conception, à implémenter)

L'API externe permet aux fournisseurs de gérer par programmation les commandes, les ressources et les règlements. Toutes les requêtes nécessitent une authentification par API Key.

**Base URL** : `https://api.example.com/api`

### Authentification

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
X-Api-Version: v1
```

Les API Keys sont générées par l'administrateur de la plateforme dans le panneau d'administration, sous `Gestion des fournisseurs → API Keys`.

**Exigences de sécurité** :
- Accès uniquement via HTTPS
- L'API Key n'est affichée qu'une seule fois à la création ; conservez-la précieusement
- Il est recommandé d'ajouter l'IP du serveur à la liste blanche

---

### Format de réponse

Identique à l'API interne, avec en plus `request_id` pour le suivi :

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### Points de terminaison

#### 1. Gestion des commandes

##### Obtenir la liste des commandes

```
GET /api/supplier/orders
```

**Paramètres de requête** :

| Paramètre | Type | Requis | Description |
|------|------|------|------|
| page | int | non | Numéro de page, défaut 1 |
| page_size | int | non | Nombre par page, défaut 20, maximum 50 |
| status | string | non | Filtre de statut : pending/paid/completed/refunded |
| from | date | non | Date de début YYYY-MM-DD |
| to | date | non | Date de fin YYYY-MM-DD |

##### Obtenir le détail d'une commande

```
GET /api/supplier/orders/{id}
```

---

#### 2. Gestion des ressources

##### Obtenir la liste des ressources

```
GET /api/supplier/resources
```

**Paramètres de requête** : page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### Obtenir le statut d'une ressource

```
GET /api/supplier/resources/{id}/status
```

---

#### 3. Gestion des règlements

##### Obtenir la liste des règlements

```
GET /api/supplier/settlements
```

##### Obtenir le détail d'un règlement

```
GET /api/supplier/settlements/{id}
```

---

#### 4. Retrait

##### Demander un retrait

```
POST /api/supplier/withdraw
```

##### Historique des retraits

```
GET /api/supplier/withdraws
```

---

#### 5. Gestion des produits

##### Obtenir mes produits

```
GET /api/supplier/products
```

##### Soumettre une demande de mise en vente

```
POST /api/supplier/products
```

---

### Récapitulatif des points de terminaison de l'API externe

| Méthode | Chemin | Description |
|------|------|------|
| GET | `/api/supplier/orders` | Liste des commandes |
| GET | `/api/supplier/orders/{id}` | Détail d'une commande |
| GET | `/api/supplier/resources` | Liste des ressources |
| GET | `/api/supplier/resources/{id}/status` | Statut d'une ressource |
| GET | `/api/supplier/settlements` | Liste des règlements |
| GET | `/api/supplier/settlements/{id}` | Détail d'un règlement |
| POST | `/api/supplier/withdraw` | Demander un retrait |
| GET | `/api/supplier/withdraws` | Historique des retraits |
| GET | `/api/supplier/products` | Liste des produits |
| POST | `/api/supplier/products` | Soumettre un produit |

---

## Webhook (réception d'événements de la plateforme)

Les fournisseurs peuvent enregistrer une URL Webhook pour recevoir les événements en temps réel. Configurée dans le panneau d'administration.

### Types d'événements

| Événement | Moment du déclenchement |
|------|----------|
| `order.paid` | L'utilisateur termine le paiement |
| `order.refunded` | La commande a été remboursée |
| `resource.provisioned` | La livraison de la ressource est terminée |
| `resource.expiring` | La ressource expire bientôt (sous 7 jours) |
| `resource.destroyed` | La ressource a été détruite |
| `settlement.created` | Génération d'un règlement |
| `withdrawal.approved` | Retrait approuvé |

### Format de requête Webhook

```json
POST {your_webhook_url}
Content-Type: application/json
X-Webhook-Signature: sha256=abc123...
X-Webhook-Event: order.paid

{
  "event": "order.paid",
  "timestamp": "2026-05-20T14:30:00Z",
  "data": {
    "order_id": "abc123",
    "amount": "49.99",
    "currency": "USD"
  }
}
```

**Vérification de signature** : `HMAC-SHA256(payload, webhook_secret)`

---

## Limitation de débit

| Point de terminaison | Limite |
|------|------|
| API interne | 60 req/min par utilisateur (défaut) |
| Connexion API interne | 5 req/min |
| API externe | 120 req/min par API Key (règle `supplier_api`, appliquée via `RateLimitMiddleware`) |
| Retrait API externe | 10 req/min (valeur suggérée, réglable dans `config/security.php`) |

> Les règles de limitation de l'API externe sont définies dans `rate_limits.supplier_api` de `config/security.php`,
> appliquées de façon unifiée par `RateLimitMiddleware` sur les chemins `/api/supplier/external/*` (comptage atomique INCR,
> accès autorisé si Redis est indisponible).

En-têtes de limitation :

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## Exemples de SDK

### PHP

```php
$token = 'user_access_token_here';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/',
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'X-Api-Version' => 'v1',
        'Accept'        => 'application/json',
    ],
]);

// Demander à devenir fournisseur
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// Obtenir les règlements
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// Demander un retrait
$response = $client->post('supplier/withdraw', [
    'json' => [
        'amount'           => '5000.00',
        'confirm_password' => 'mypassword',
        'account_info'     => [
            'method'          => 'bank_transfer',
            'bank_name'       => '中国工商银行',
            'account_number'  => '6222021234567890',
        ],
    ],
]);
```

### Python

```python
import requests

headers = {
    'Authorization': 'Bearer <user_access_token>',
    'X-Api-Version': 'v1',
}

# Obtenir les produits attribués
resp = requests.get('https://api.example.com/api/supplier/products',
                     headers=headers)
products = resp.json()

# Demander un retrait
resp = requests.post('https://api.example.com/api/supplier/withdraw',
                      headers=headers,
                      json={
                          'amount': '5000.00',
                          'confirm_password': 'mypassword',
                          'account_info': {
                              'method': 'bank_transfer',
                              'bank_name': 'ICBC',
                              'account_number': '6222021234567890',
                          },
                      })
print(resp.json())
```

---

## Recommandations de gestion des erreurs

1. **429 Limitation de débit** : attendre `Retry-After` secondes avant de réessayer
2. **401 Non autorisé** : vérifier que le Token est valide et non expiré
3. **403 Interdit** : vérifier que le rôle du compte est `supplier` ; en cas d'échec de confirmation du mot de passe, attendre la levée du verrouillage
4. **422 Échec de validation** : corriger les paramètres de requête d'après le champ `message`
5. **5xx Erreur serveur** : nouvelle tentative avec backoff exponentiel (1s -> 5s -> 25s)

---

## Référence des points de terminaison du panneau d'administration

Voici les points de terminaison liés à la gestion des fournisseurs (réservés au back-office, rôle Admin requis) :

| Méthode | Chemin | Description |
|------|------|------|
| GET | `/admin/api/suppliers` | Liste des fournisseurs (filtre par status pris en charge) |
| GET | `/admin/api/suppliers/export` | Export des fournisseurs en Excel |
| POST | `/admin/api/suppliers/{id}/approve` | Approuver un fournisseur |
| POST | `/admin/api/suppliers/{id}/settle` | Générer un règlement |
| POST | `/admin/api/suppliers/withdraws/{id}/approve` | Approuver un retrait |
| GET | `/admin/api/suppliers/{id}/api-keys` | Consulter la liste des API Keys du fournisseur |
| POST | `/admin/api/suppliers/{id}/api-keys` | Créer une API Key (la clé brute n'est renvoyée qu'une seule fois) |
| DELETE | `/admin/api/suppliers/api-keys/{id}` | Révoquer une API Key |
