# Aperçu de l'API

> Référence complète des interfaces (200+ points de terminaison, exemples de requêtes/réponses et codes d'erreur) : [Documentation de l'API](api-reference.md)
> Débogage en ligne : [Documentation de l'API service](http://localhost:8787/apidoc) · [Documentation de l'API admin](http://localhost:8788/apidoc)

## Points de terminaison publics

| Méthode | Chemin | Description |
|--------|------|-------------|
| GET | `/health` | Vérification de santé |
| POST | `/api/v1/auth/register` | Inscription (corps chiffré AES-256-GCM) |
| POST | `/api/v1/auth/login` | Connexion (corps chiffré AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | Rafraîchissement du jeton (corps chiffré AES-256-GCM) |
| POST | `/api/v1/captcha/create` | Génération du captcha à clic (requis avant connexion/inscription) |
| GET | `/api/v1/products` | Liste des produits (filtrable par catégorie/région/mot-clé) |
| GET | `/api/v1/products/{id}` | Détail du produit (id est une chaîne hashid) |
| GET | `/api/v1/regions` | Régions disponibles |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Vérification de disponibilité de domaine |
| GET | `/api/v1/domain/tlds` | TLD disponibles |
| POST | `/api/v1/payments/webhook/stripe` | Webhook Stripe (signature vérifiée, sans chiffrement) |

## Points de terminaison authentifiés (Bearer Token)

| Méthode | Chemin | Description |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | Obtenir le profil |
| PUT | `/api/v1/user/profile` | Mettre à jour le profil |
| POST | `/api/v1/user/kyc` | Soumettre le KYC |
| GET | `/api/v1/user/balance` | Solde du compte |
| GET/POST | `/api/v1/cart` | Panier |
| POST/GET | `/api/v1/orders` | Commandes |
| GET | `/api/v1/orders/{id}/payment-methods` | Moyens de paiement disponibles |
| POST | `/api/v1/orders/{id}/pay` | Initier le paiement |
| GET/POST | `/api/v1/resources` | Mes ressources |
| GET | `/api/v1/resources/{id}/status` | Statut de la ressource |
| GET | `/api/v1/resources/{id}/console` | URL de la console VNC |
| GET/POST | `/api/v1/tickets` | Tickets de support |
| POST | `/api/v1/tickets/{id}/reply` | Répondre au ticket |
| GET/POST | `/api/v1/dns/{domain}` | Gestion DNS |
| POST | `/api/v1/supplier/apply` | Candidature fournisseur |
| GET | `/api/v1/supplier/settlements` | Historique des règlements |
| POST | `/api/v1/supplier/withdraw` | Demande de retrait |

> **Remarque :** la version d'API se trouve dans le chemin d'URL (p. ex. `/api/v1/...`), validée de façon centralisée par `VersionMiddleware`. Les points de terminaison authentifiés et administrateur passent par `EncryptionMiddleware`. Le client définit l'en-tête `X-Encrypted: 1` et enveloppe le corps sous la forme `{"payload": "<base64(AES-256-GCM)>"}`. Les réponses sont de même chiffrées et enveloppées dans un champ `payload`. Les ID entiers des réponses API sont automatiquement convertis en chaînes Hashid de 12 caractères ; les chaînes Hashid des requêtes sont décodées en ID entiers par `HashidRequestMiddleware`.

## Points de terminaison administrateur

| Méthode | Chemin | Description |
|--------|------|-------------|
| GET | `/admin/api/v1/dashboard` | Tableau de bord opérationnel |
| GET/PUT | `/admin/api/v1/users` | Gestion des utilisateurs |
| GET/POST | `/admin/api/v1/kyc` | Revue KYC |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Gestion des produits |
| POST | `/admin/api/v1/products/{productId}/skus` | Créer un SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Définir le prix régional |
| GET/POST | `/admin/api/v1/orders` | Gestion des commandes (y compris remboursements) |
| GET | `/admin/api/v1/orders/export` | Exporter les commandes (.xlsx) |
| GET | `/admin/api/v1/users/export` | Exporter les utilisateurs (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Exporter les fournisseurs (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Canaux / transactions / rapprochement |
| GET/POST | `/admin/api/v1/provisioning/*` | Tâches de livraison / gestion des hôtes |
| GET/POST | `/admin/api/v1/suppliers/*` | Approbation fournisseur / règlement / retrait |
| GET/POST | `/admin/api/v1/tickets` | Attribution / clôture des tickets |
| GET | `/admin/api/v1/reports/*` | Rapports de revenus / régionaux / fournisseurs |
| GET | `/admin/api/v1/monitor/*` | Tableau de bord de surveillance / métriques des ressources |
| GET | `/admin/api/v1/audit-logs` | Journaux d'audit |
| PUT | `/admin/api/v1/system/config` | Mise à jour de la configuration système |
