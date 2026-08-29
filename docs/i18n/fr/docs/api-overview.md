# Aperçu de l'API

> Référence complète des interfaces (200+ points de terminaison, avec exemples de requêtes/réponses et codes d'erreur) : [Documentation de l'API](api-reference.md)
> Débogage en ligne : [Documentation de l'API service](http://localhost:8787/apidoc) · [Documentation de l'API admin](http://localhost:8788/apidoc)

## Interfaces publiques

| Méthode | Chemin | Description |
|------|------|------|
| GET | `/health` | Vérification de santé |
| POST | `/api/auth/register` | Inscription utilisateur (corps de requête chiffré AES-256-GCM) |
| POST | `/api/auth/login` | Connexion utilisateur (corps de requête chiffré AES-256-GCM) |
| POST | `/api/auth/refresh` | Rafraîchissement du jeton (corps de requête chiffré AES-256-GCM) |
| POST | `/api/captcha/create` | Génération du captcha à clic (à obtenir avant connexion/inscription) |
| GET | `/api/products` | Liste des produits (filtrable par catégorie/région/mot-clé) |
| GET | `/api/products/{id}` | Détail du produit (id est une chaîne hashid) |
| GET | `/api/regions` | Régions disponibles |
| GET | `/api/domain/check/{domain}/{tld}` | Vérification de disponibilité de domaine |
| GET | `/api/domain/tlds` | Liste des suffixes enregistrables |
| POST | `/api/payments/webhook/stripe` | Callback Stripe (vérification de signature, sans chiffrement) |

## Interfaces authentifiées (Bearer Token)

| Méthode | Chemin | Description |
|------|------|------|
| GET | `/api/user/profile` | Informations personnelles |
| PUT | `/api/user/profile` | Mise à jour des informations |
| POST | `/api/user/kyc` | Soumission de la vérification d'identité |
| GET | `/api/user/balance` | Solde du compte |
| GET/POST | `/api/cart` | Panier |
| POST/GET | `/api/orders` | Commandes |
| GET | `/api/orders/{id}/payment-methods` | Moyens de paiement disponibles |
| POST | `/api/orders/{id}/pay` | Initier le paiement |
| GET/POST | `/api/resources` | Mes ressources |
| GET | `/api/resources/{id}/status` | Statut de la ressource |
| GET | `/api/resources/{id}/console` | Lien de console VNC |
| GET/POST | `/api/cdn/domains` | Liste / création de domaines CDN (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/cdn/domains/{id}` | Détail / suppression de domaine CDN |
| POST | `/api/cdn/domains/{id}/purge` | Purge du cache (idempotent, 100 URL maximum) |
| GET/POST | `/api/tickets` | Tickets de support |
| POST | `/api/tickets/{id}/reply` | Réponse au ticket |
| GET/POST | `/api/dns/{domain}` | Gestion DNS |
| POST | `/api/supplier/apply` | Candidature fournisseur |
| GET | `/api/supplier/settlements` | Historique des règlements fournisseur |
| POST | `/api/supplier/withdraw` | Retrait fournisseur |

> **Remarque :** toutes les requêtes API doivent comporter l'en-tête `X-Api-Version: v1` (défaut `v1` si absent, validé par `VersionMiddleware`). Les requêtes/réponses des interfaces authentifiées et administrateur passent par `EncryptionMiddleware`. Le client définit l'en-tête `X-Encrypted: 1`, le corps de requête étant au format `{"payload": "<base64(AES-256-GCM)>"}` ; le corps de réponse est également chiffré puis enveloppé dans le champ `payload`. Tous les ID entiers sont automatiquement convertis en chaînes Hashid de 12 caractères dans les réponses API ; les chaînes Hashid des requêtes sont automatiquement décodées en ID entiers par `HashidRequestMiddleware`.

## Interfaces administrateur

| Méthode | Chemin | Description |
|------|------|------|
| GET | `/admin/api/dashboard` | Tableau de bord opérationnel |
| GET/PUT | `/admin/api/users` | Gestion des utilisateurs |
| GET/POST | `/admin/api/kyc` | Revue KYC |
| GET/POST/PUT/DELETE | `/admin/api/products` | Gestion des produits |
| POST | `/admin/api/products/{productId}/skus` | Création de SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | Définition du prix régional |
| GET/POST | `/admin/api/orders` | Gestion des commandes (y compris remboursements) |
| GET | `/admin/api/orders/export` | Export des commandes (.xlsx) |
| GET | `/admin/api/users/export` | Export des utilisateurs (.xlsx) |
| GET | `/admin/api/suppliers/export` | Export des fournisseurs (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Canaux de paiement / transactions / rapprochement |
| GET/POST | `/admin/api/provisioning/*` | Tâches de livraison / gestion des hôtes |
| GET/PUT | `/admin/api/cdn/domains` | Gestion des domaines CDN (changement de forfait) |
| GET/POST/PUT/DELETE | `/admin/api/providers` | Gestion des identifiants de comptes fournisseurs (CDN/livraison partagés, chiffrés via Encryptable) |
| GET/POST | `/admin/api/suppliers/*` | Approbation fournisseur / règlement / retrait |
| GET/POST | `/admin/api/tickets` | Attribution / clôture des tickets |
| GET | `/admin/api/reports/*` | Rapports de revenus / régionaux / fournisseurs |
| GET | `/admin/api/monitor/*` | Panneau de surveillance / métriques des ressources |
| GET | `/admin/api/audit-logs` | Journaux d'audit |
| PUT | `/admin/api/system/config` | Configuration système |
