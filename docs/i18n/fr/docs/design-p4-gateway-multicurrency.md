# Conception P4.1 + P4.2 : passerelle API indépendante / limitation de débit unifiée + cohérence multidevise de bout en bout

> Version : 2026-08-17 v1｜Produite par l'architecte, pour implémentation par gateway-impl / multicurrency-impl, revue par reviewer-gate
> Références : docs/team-plan.md v2 Phase 4, docs/architecture.md, lecture réelle du code existant

---

## P4.1 Passerelle API indépendante + limitation de débit unifiée

### État des lieux (confirmé par lecture réelle)

| Couche | État actuel |
|----|------|
| Passerelle périphérique | docker/nginx.conf assure la passerelle L7 du service : `limit_req_zone api 10r/s` (limitation globale), proxy_pass 8787 (service), 8282 (ws). **admin est un conteneur indépendant** (Dockerfile admin target, nginx-admin.conf listen 8788 proxy 8788), **sans limit_req** |
| Limitation applicative | `service/common/security/RateLimitMiddleware.php` existe déjà : Redis INCR+expire fenêtre fixe, **uniquement par IP**, règles sélectionnées via `ROUTE_MAP`, monté sur les **routes explicites** (~12 emplacements dans route.php) |
| Configuration des règles | `config/security.php rate_limits` : default/login/register/password_reset/oauth/captcha/sms/pay/upload/supplier_api/graphql, toutes avec rate/burst/per, mais **le champ burst n'est actuellement pas utilisé** |
| Middleware global | la clé `''` de `config/middleware.php` prend déjà en charge l'application à toutes les routes (10 éléments : WAF/GeoBlock/Security, etc.) |
| Manques | `/graphql` (routes public + authentifié) **sans aucune limitation de débit** ; aucune limitation par jeton ; réponse 429 sans en-tête `Retry-After` ; webhook sans exemption/règle dédiée |

### Décisions

**D1 : ne pas créer de nouveau processus de passerelle indépendant.** nginx tient lieu de passerelle (bord réseau + limitation de débit + répartition des routes), la limitation unifiée se fait dans webman.
- Raison : un conteneur de passerelle indépendant exigerait de nouvelles dépendances / une nouvelle topologie de déploiement / une double authentification, ce qui est une sur-ingénierie à l'échelle actuelle d'une seule instance ;
- Compromis : impossible de faire une limitation différenciée par jeton/par route au niveau de la passerelle (nginx ne connaît que les segments par IP). La différenciation est assurée par la couche applicative ; nginx ne conserve qu'un filet de sécurité IP grossier (le 10r/s actuel passe à 100r/s pour ne pas pénaliser le métier, ramené au seuil de démonstration lors de la validation k6).
- Chemin d'évolution : si à l'avenir il y a plusieurs instances/services, il suffira de déplacer le limiteur global de `config/middleware.php` tel quel vers un service de passerelle indépendant — le middleware ne dépend pas de la forme de déploiement.

**D2 : limitation unifiée = middleware global + double seau (par IP + par jeton).**
- Retirer `RateLimitMiddleware` des routes explicites (~12 emplacements réels dans route.php, selon grep) et le monter dans la liste globale `''` de `config/middleware.php` (après WAF, avant les middlewares métier), **couvrant naturellement toutes les routes de l'application (y compris les deux /graphql)**.
- **Sémantique des seaux (explicite, anti-contournement)** : `ratelimit:ip:{realIp}:{rule}` et `ratelimit:tok:{sha256(token)}:{rule}` comptent indépendamment dans deux seaux, **un dépassement dans l'un ou l'autre entraîne un 429 (OU)**. Interdiction d'implémenter en ET — avec ET, changer d'IP contourne le seau IP et changer de jeton contourne le seau jeton.
- **Liste d'exemption** : `/health*` (sondes de surveillance) et `/api/payments/webhook/stripe` (la vérification de signature est la vraie ligne de défense + Stripe fait des nouvelles tentatives automatiques avec backoff sur 429 + le filet grossier nginx à 100r/s reste effectif ; la limitation de débit n'apporte aucun gain de sécurité, seulement des risques d'événements perdus/de paiements retardés). Toutes les autres routes sont obligatoirement limitées.
- Réponse : `HTTP 429` + en-tête `Retry-After` (max des fenêtres restantes des deux seaux, `PTTL` Redis pour le reste exact en fenêtre fixe) + body `{code:429, message, retry_after}` (aligné sur `Response::error` existant).
- Rafales : activer le champ burst — `rate` est le quota stable par fenêtre, `burst` la capacité empruntable. Implémenté comme limite du compteur Redis à `rate + burst` (emprunt en fenêtre fixe), sans fenêtre glissante (ponytail: la fenêtre fixe amplifie d'un facteur 2 aux bords, suffisant pour un abus mono-machine par IP ; passer à la fenêtre glissante si plus strict requis).
- Mappage route→règle : conserver le `ROUTE_MAP` existant, ajouter `'/graphql' => 'graphql'` (config/security.php:46 a déjà `{rate:30, burst:5, per:60}`) ; les routes inconnues passent par `default` (60/60s).
- Redis indisponible : conserver le fail-open actuel (catch Exception, laisser passer) — le filet grossier nginx à 100r/s reste en place.
- **Périmètre** : conteneur service uniquement. admin est un conteneur indépendant (nginx-admin.conf sans limit_req, aucun débit actuellement) ; les modifications de service/config et des middlewares service n'affectent pas admin — la limitation d'admin est hors périmètre P4.1, décision séparée.

**D3 : limitation avant authentification.** Le middleware global se situe avant AuthMiddleware (l'ordre de middleware.php est l'ordre d'exécution) ; ainsi, pour une requête sans jeton, le seau par jeton dégénère en seau par IP ; une requête avec jeton compte dans le seau jeton même sur un chemin anonyme (ex. /api/products) — protection contre l'abus de jetons partagés.

### Surface d'impact

| Élément | Changement |
|----|------|
| `service/common/security/RateLimitMiddleware.php` | Refonte : seau par jeton, burst, Retry-After, règle graphql |
| `service/config/middleware.php` | Ajout de RateLimitMiddleware à la liste `''` ; retrait de tous les montages explicites de route.php |
| `service/config/security.php` | Conserver `default` {60,10,60} inchangé (seuil de recette = rate+burst = 70) ; `graphql` {30,5,60} existe déjà, rien à ajouter ; champ burst utilisé tel quel |
| `service/config/route.php` | Supprimer ~12 montages explicites de `RateLimitMiddleware::class` (selon le grep réel, groupes auth/supplier/admin) |
| `docker/nginx.conf` | `limit_req` rate 10r/s → 100r/s (filet grossier, pour ne pas brider le métier par-dessus le middleware global) |
| Tests | Les tests de la suite service dépendant du montage explicite du middleware de limitation doivent être synchronisés ; ajout de tests unitaires du middleware |

### Recette (k6)

```
# Choisir une route anonyme (ex. GET /api/products) et /graphql, chacune 200 requêtes/10s :
# tout dépassement du seuil → 429 avec en-tête Retry-After ; sous le seuil → tout 200.
# Assertions : nombre de 429 == total requêtes − seuil ; /graphql également effectif (manque d'origine).
```

---

## P4.2 Cohérence multidevise de bout en bout (y compris stratégie d'arrondi des frais)

### État des lieux (confirmé par lecture réelle)

- **Stockage** : dans `install.sql`, tous les montants sont en DECIMAL — solde/gelé `(16,4)`, commande subtotal/discount/tax/total, unit_price/total_price des lignes `(12,4)` ; `exchange_rate DECIMAL(12,6)` déjà présent sur `orders` et `payment_transactions` ; `user_balances` tient une ligne par devise (comptabilité par devise).
- **Source des taux** : `service/app/cron/ExchangeRateSync.php` déjà implémenté — API externe gratuite (`EXCHANGE_RATE_API_URL` configurable par env, défaut exchangerate-api.com) synchronisée chaque heure dans Redis `exchange_rate:{CURRENCY}` ; `OrderService::getExchangeRate` lit l'instantané Redis à la commande (USD toujours 1.0) et l'écrit dans le champ `exchange_rate` de la commande. **Dépendance externe déjà présente et source remplaçable par env, rien à ajouter.**
- **Problème de troncature des frais** : `PaymentRouter::calculateFee` = `bcadd(bcmul($amount, $rate, 8), $fixed, 4)` — bcmath **tronque** selon l'échelle (pas d'arrondi), dans le sens d'une **sous-perception** <0,0001/commande ; de plus `total_amount = amount + fee` avec un amount à 5+ décimales (ex. 10.12345) peut diverger du total de commande après troncature.
- **Le contrôle de suspension** juge déjà par solde de devise (multidevise) ; la facturation Billing se fait par compteur meter (prix unitaire usage_rates en DECIMAL(12,4)).

### Décisions

**D4 : invariant de montant unifié — une précision interne par devise, arrondi uniquement en un seul point.**
- Calculs internes unifiés en `DECIMAL(12,4)` (granularité commande) et `DECIMAL(16,4)` (granularité solde) ; toute multiplication doit passer par `bcround(x, 4, PHP_ROUND_HALF_UP)` ; `bcadd/bcsub` ne font que des additions/soustractions à précision égale (exactes en soi).
- Nouveau helper de montant unique `service/common/money/Money.php` (~40 lignes) :
  - `bcround(string $v, int $scale = 4, int $mode = PHP_ROUND_HALF_UP): string` — idempotent ; `round()` présente un risque de précision sur les flottants, chemin obligatoirement en chaîne : `bcadd($v, '0', $scale+1)` puis décision HALF-UP sur le chiffre $scale+1 (attention aux nombres négatifs dans l'implémentation, utiliser bccomp sur la valeur absolue).
  - Tout champ de montant doit passer par `bcround(…, 4)` avant écriture en base ; **interdiction** d'utiliser `(float)`/`round()` au milieu d'une chaîne de calcul (le `round((float) bcmul(...))` de StripeChannel actuel est une source de risque).
- Le `calculateFee` existant devient : `$fee = bcround(bcadd(bcmul(bcround($amount,4), $rate, 8), $fixed, 8), 4)` — aligner d'abord amount sur 4 décimales, puis multiplier par le taux, puis HALF_UP sur 4 décimales. **Correction de sens : sous-perception → demi-arrondi standard** (écart ≤0,00005/commande, valeur attendue tendant vers 0). **Le garde-fou de clamp à 0 pour les frais négatifs est conservé** (comportement du code actuel PaymentRouter.php:44 inchangé).

**D5 : identité de commande et frais de canal séparés (rapprochement à dérive nulle).** Deux faits indépendants :
- **Identité des lignes de commande** `total − subtotal − tax + discount == 0` (précis à 0,0000) : dans le chemin de création de commande (OrderService::createFromCart), lignes `bcround(bcmul(price, qty, 8), 4)` (multiplication haute précision puis arrondi, pour éviter la double troncature) → subtotal = somme des lignes (exact) → total = subtotal + tax − discount (addition/soustraction à précision égale, exacte). **tax est actuellement toujours 0** (createFromCart ne fixe pas tax, install.sql:345 DEFAULT 0.0000) — pas de nouveau calcul de taxe (hors périmètre P4.2, implications de conformité), l'assertion suit la valeur actuelle tax=0 mais la formule conserve le terme tax.
- **Frais de canal** : channel_fee indépendant `bcround(…,4)`, montant du canal de paiement = total + channel_fee, exactement égal à 4 décimales.
- Vérification : `PaymentController::reconcile*` et les rapports (Report) se basent sur le total stocké de la commande, sans recalcul.

**D6 : instantané de taux et point de conversion.**
- Source des taux maintenue par ExchangeRateSync cron + Redis (déjà en place, inchangée). La colonne `exchange_rate` est déjà un instantané avec les commandes/transactions (DECIMAL(12,6)) ; **le point de conversion = le règlement (écriture en base)**, pas de conversion en temps réel à l'affichage (le prix en temps réel à l'affichage n'est qu'une multiplication par le taux Redis courant au niveau UI, sans effet comptable).
- Règle : **tout ce qui touche aux comptes/soldes doit utiliser le taux instantané de la commande ; tout ce qui touche au prix/à l'affichage peut utiliser le taux courant**. Interdiction de mélanger les deux taux dans la chaîne de règlement.
- La couche de solde est déjà un grand livre par devise (user_balances par ligne de devise), pas de conversion vers une devise de référence unique ; quand un rapport a besoin d'une devise de référence (ex. USD), il agrège avec le taux instantané des commandes, l'agrégat passant toujours par `bcround(…,4)` (ponytail: l'erreur d'arrondi d'agrégation multi-devises se situe au niveau des totaux ; si l'audit exige des sous-totaux par devise, les séparer).

**D7 : liste des changements (y compris points de revue du code multidevise existant).**
- À modifier : `PaymentRouter::calculateFee`, `StripeChannel` (alignement des montants d'entrée + suppression du round float, y compris convertToSmallest passant à bcround($total,2)), `OrderService::createFromCart` (arrondi séquentiel lignes/subtotal/total), **`Order/Model/Coupon.php::calculateDiscount` (:31-44 actuellement float+round, à passer au chemin chaîne bcround)**、`PaymentController::reconcile*` (assertion de l'identité D5)、`Report/*` (agrégats unifiés bcround).
- Revue sans modification : compteurs Billing (prix unitaire déjà DECIMAL(12,4), facturation alignée par bcround suffit), contrôle de suspension (jugement par solde de devise, déjà correct), `Cron/ExchangeRateSync.php` (écriture Redis conservant les 6 décimales d'origine, inchangé).
- À ajouter : `service/common/money/Money.php` + tests unitaires (bornes HALF_UP : 0.00005 → 0.0001, 0.00004 → 0.0000, **-0.00005 → -0.0001 (négatifs loin de zéro)**、idempotence).
- Migration : aucune modification structurelle d'install.sql (la colonne exchange_rate existe déjà) ; si la troncature des frais des commandes historiques a produit des écarts de queue <0,0001, il s'agit de différences comptables irréversibles, **enregistrement sans correction** (une écriture changerait le rapprochement historique), nouvelle requête d'audit `fee_drift` listant les commandes avec |total−subtotal−tax+discount|>0 pour vérification manuelle.

### Recette

```
# k6 (P4.1) : IP unique fixe. GET /api/products et /graphql, chacune 200 requêtes/10s :
#   seuil règle default = rate+burst = 70/fenêtre 60s → 429 attendus ≈ 200−70 = 130 (±1-2 aux bords de fenêtre)
#   seuil règle graphql = 35 → 429 attendus ≈ 165 ; tous avec en-tête Retry-After ; trafic faible → tout 200
# Tests unitaires (P4.2) : bornes Money::bcround (0.00005→0.0001, 0.00004→0.0000, -0.00005→-0.0001, idempotence)
# Test d'identité : construire une commande multi-lignes (avec prix unitaire à 5 décimales + coupon), affirmer total−subtotal−tax+discount == 0 toujours vrai
# Régression : les 491 tests service existants tous verts (y compris assertions de montants)
```

---

## Risques et revue

- **Risque du limiteur global D2 (moyen)** : le montage global affecte tous les points de terminaison service (**admin non inclus** — conteneur indépendant, les changements service/config ne le touchent pas), webhook exempté ; des seuils inappropriés peuvent pénaliser à tort, security-auditor doit revoir les seuils par défaut et la stratégie fail-open. **Le conteneur admin est actuellement sans limitation de débit** (nginx-admin.conf sans limit_req), P4.1 non inclus, décision séparée.
- **Chaîne financière D4/D5 (élevé)** : le changement de sens de l'arrondi affecte le montant de chaque commande (sous-perception → demi-arrondi standard), revue security-auditor + revue à deux requises ; les données historiques sont enregistrées sans correction.
- **Dépendances** : aucune nouvelle dépendance composer ; aucune nouvelle table ; le changement de configuration nginx nécessite un rechargement.

```yaml
design:
  objective: "P4.1 limitation de débit unifiée effective sur toutes les routes (y compris graphql) + P4.2 alignement de la stratégie d'arrondi multidevise, identité comptable à dérive nulle"
  files_affected:
    - service/common/security/RateLimitMiddleware.php
    - service/config/middleware.php
    - service/config/route.php
    - service/config/security.php
    - docker/nginx.conf
    - service/common/money/Money.php (new)
    - service/app/payment/service/PaymentRouter.php
    - service/app/payment/service/channels/StripeChannel.php
    - service/app/order/service/OrderService.php
    - service/app/order/model/Coupon.php
    - service/app/payment/controller/PaymentController.php
    - service/app/report/controller/ReportController.php
    - tests/ (middleware + money + identité)
  modules_touched: ["Gateway/Route", "Security", "Payment", "Order", "Billing", "Report"]
  api_changes: [{method: "ALL", path: "/graphql", error_codes: ["429"]}, {method: "ALL", path: "ALL", error_codes: ["429 + Retry-After"]}]
  data_changes: []   # aucun changement structurel ; colonne exchange_rate existante ; tax reste à 0, pas d'ajout
  client_impact: ["flutter", "harmonyos"]  # 429 nécessite une gestion élégante côté client ; conteneur admin non affecté
  risk: "high"       # chaîne financière D4/D5
  review_needed: ["security-auditor"]
  testing_points: ["429+Retry-After sur toutes les routes (k6 IP unique, 429≈130/165)", "manque de limitation graphql fermé", "exemption webhook sans 429", "sémantique OU des deux seaux (changement d'IP/jaeton impossible à contourner)", "bornes HALF_UP des frais y compris négatives", "Coupon bcround en chaîne", "identité total−subtotal−tax+discount==0", "requête d'audit fee_drift des commandes historiques"]
  dependencies: []
```
