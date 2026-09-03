# Aperçu de l'API

> Référence complète des interfaces (200+ points de terminaison, avec exemples de requêtes/réponses et codes d'erreur) : [Documentation de l'API](api-reference.md)
> Débogage en ligne : [Documentation de l'API service](http://localhost:8787/apidoc) · [Documentation de l'API admin](http://localhost:8788/apidoc)

## Interfaces publiques

| Méthode | Chemin | Description |
|------|------|------|
| GET | `/health` | Vérification de santé |
| POST | `/api/v1/auth/register` | Inscription utilisateur (corps de requête chiffré AES-256-GCM) |
| POST | `/api/v1/auth/login` | Connexion utilisateur (corps de requête chiffré AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | Rafraîchissement du jeton (corps de requête chiffré AES-256-GCM) |
| POST | `/api/v1/captcha/create` | Génération du captcha à clic (à obtenir avant connexion/inscription) |
| GET | `/api/v1/products` | Liste des produits (filtrable par catégorie/région/mot-clé) |
| GET | `/api/v1/products/{id}` | Détail du produit (id est une chaîne hashid) |
| GET | `/api/v1/regions` | Régions disponibles |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Vérification de disponibilité de domaine |
| GET | `/api/v1/domain/tlds` | Liste des suffixes enregistrables |
| POST | `/api/v1/payments/webhook/stripe` | Callback Stripe (vérification de signature, sans chiffrement) |

## Interfaces authentifiées (Bearer Token)

| Méthode | Chemin | Description |
|------|------|------|
| GET | `/api/v1/user/profile` | Informations personnelles |
| PUT | `/api/v1/user/profile` | Mise à jour des informations |
| POST | `/api/v1/user/kyc` | Soumission de la vérification d'identité |
| GET | `/api/v1/user/balance` | Solde du compte |
| GET/POST | `/api/v1/cart` | Panier |
| POST/GET | `/api/v1/orders` | Commandes |
| GET | `/api/v1/orders/{id}/payment-methods` | Moyens de paiement disponibles |
| POST | `/api/v1/orders/{id}/pay` | Initier le paiement |
| GET/POST | `/api/v1/resources` | Mes ressources |
| GET | `/api/v1/resources/{id}/status` | Statut de la ressource |
| GET | `/api/v1/resources/{id}/console` | Lien de console VNC |
| GET/POST | `/api/v1/cdn/domains` | Liste / création de domaines CDN (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | Détail / suppression de domaine CDN |
| POST | `/api/v1/cdn/domains/{id}/purge` | Purge du cache (idempotent, 100 URL maximum) |
| GET/POST | `/api/v1/tickets` | Tickets de support |
| POST | `/api/v1/tickets/{id}/reply` | Réponse au ticket |
| GET/POST | `/api/v1/dns/{domain}` | Gestion DNS |
| POST | `/api/v1/supplier/apply` | Candidature fournisseur |
| GET | `/api/v1/supplier/settlements` | Historique des règlements fournisseur |
| POST | `/api/v1/supplier/withdraw` | Retrait fournisseur |

> **Remarque :** la version d'API se trouve dans le chemin d'URL (p. ex. `/api/v1/...`), validée de façon centralisée par `VersionMiddleware`. Les requêtes/réponses des interfaces authentifiées et administrateur passent par `EncryptionMiddleware`. Le client définit l'en-tête `X-Encrypted: 1`, le corps de requête étant au format `{"payload": "<base64(AES-256-GCM)>"}` ; le corps de réponse est également chiffré puis enveloppé dans le champ `payload`. Tous les ID entiers sont automatiquement convertis en chaînes Hashid de 12 caractères dans les réponses API ; les chaînes Hashid des requêtes sont automatiquement décodées en ID entiers par `HashidRequestMiddleware`.

## Interfaces administrateur

| Méthode | Chemin | Description |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | Tableau de bord opérationnel |
| GET/PUT | `/admin/api/v1/users` | Gestion des utilisateurs |
| GET/POST | `/admin/api/v1/kyc` | Revue KYC |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Gestion des produits |
| POST | `/admin/api/v1/products/{productId}/skus` | Création de SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Définition du prix régional |
| GET/POST | `/admin/api/v1/orders` | Gestion des commandes (y compris remboursements) |
| GET | `/admin/api/v1/orders/export` | Export des commandes (.xlsx) |
| GET | `/admin/api/v1/users/export` | Export des utilisateurs (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Export des fournisseurs (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Canaux de paiement / transactions / rapprochement |
| GET/POST | `/admin/api/v1/provisioning/*` | Tâches de livraison / gestion des hôtes |
| GET/PUT | `/admin/api/v1/cdn/domains` | Gestion des domaines CDN (changement de forfait) |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | Gestion des identifiants de comptes fournisseurs (CDN/livraison partagés, chiffrés via Encryptable) |
| GET/POST | `/admin/api/v1/suppliers/*` | Approbation fournisseur / règlement / retrait |
| GET/POST | `/admin/api/v1/tickets` | Attribution / clôture des tickets |
| GET | `/admin/api/v1/reports/*` | Rapports de revenus / régionaux / fournisseurs |
| GET | `/admin/api/v1/monitor/*` | Panneau de surveillance / métriques des ressources |
| GET | `/admin/api/v1/audit-logs` | Journaux d'audit |
| PUT | `/admin/api/v1/system/config` | Configuration système |
