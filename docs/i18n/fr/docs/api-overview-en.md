# Aperçu de l'API

> Référence complète des interfaces (200+ points de terminaison, exemples de requêtes/réponses et codes d'erreur) : [Documentation de l'API](api-reference.md)
> Débogage en ligne : [Documentation de l'API service](http://localhost:8787/apidoc) · [Documentation de l'API admin](http://localhost:8788/apidoc)

## Points de terminaison publics

| Méthode | Chemin | Description |
|--------|------|-------------|
| GET | `/health` | Vérification de santé |
| POST | `/api/auth/register` | Inscription (corps chiffré AES-256-GCM) |
| POST | `/api/auth/login` | Connexion (corps chiffré AES-256-GCM) |
| POST | `/api/auth/refresh` | Rafraîchissement du jeton (corps chiffré AES-256-GCM) |
| POST | `/api/captcha/create` | Génération du captcha à clic (requis avant connexion/inscription) |
| GET | `/api/products` | Liste des produits (filtrable par catégorie/région/mot-clé) |
| GET | `/api/products/{id}` | Détail du produit (id est une chaîne hashid) |
| GET | `/api/regions` | Régions disponibles |
| GET | `/api/domain/check/{domain}/{tld}` | Vérification de disponibilité de domaine |
| GET | `/api/domain/tlds` | TLD disponibles |
| POST | `/api/payments/webhook/stripe` | Webhook Stripe (signature vérifiée, sans chiffrement) |

## Points de terminaison authentifiés (Bearer Token)

| Méthode | Chemin | Description |
|--------|------|-------------|
| GET | `/api/user/profile` | Obtenir le profil |
| PUT | `/api/user/profile` | Mettre à jour le profil |
| POST | `/api/user/kyc` | Soumettre le KYC |
| GET | `/api/user/balance` | Solde du compte |
| GET/POST | `/api/cart` | Panier |
| POST/GET | `/api/orders` | Commandes |
| GET | `/api/orders/{id}/payment-methods` | Moyens de paiement disponibles |
| POST | `/api/orders/{id}/pay` | Initier le paiement |
| GET/POST | `/api/resources` | Mes ressources |
| GET | `/api/resources/{id}/status` | Statut de la ressource |
| GET | `/api/resources/{id}/console` | URL de la console VNC |
| GET/POST | `/api/tickets` | Tickets de support |
| POST | `/api/tickets/{id}/reply` | Répondre au ticket |
| GET/POST | `/api/dns/{domain}` | Gestion DNS |
| POST | `/api/supplier/apply` | Candidature fournisseur |
| GET | `/api/supplier/settlements` | Historique des règlements |
| POST | `/api/supplier/withdraw` | Demande de retrait |

> **Remarque :** toutes les requêtes API doivent comporter l'en-tête `X-Api-Version: v1` (défaut `v1` si omis, validé par `VersionMiddleware`). Les points de terminaison authentifiés et administrateur passent par `EncryptionMiddleware`. Le client définit l'en-tête `X-Encrypted: 1` et enveloppe le corps sous la forme `{"payload": "<base64(AES-256-GCM)>"}`. Les réponses sont de même chiffrées et enveloppées dans un champ `payload`. Les ID entiers des réponses API sont automatiquement convertis en chaînes Hashid de 12 caractères ; les chaînes Hashid des requêtes sont décodées en ID entiers par `HashidRequestMiddleware`.

## Points de terminaison administrateur

| Méthode | Chemin | Description |
|--------|------|-------------|
| GET | `/admin/api/dashboard` | Tableau de bord opérationnel |
| GET/PUT | `/admin/api/users` | Gestion des utilisateurs |
| GET/POST | `/admin/api/kyc` | Revue KYC |
| GET/POST/PUT/DELETE | `/admin/api/products` | Gestion des produits |
| POST | `/admin/api/products/{productId}/skus` | Créer un SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | Définir le prix régional |
| GET/POST | `/admin/api/orders` | Gestion des commandes (y compris remboursements) |
| GET | `/admin/api/orders/export` | Exporter les commandes (.xlsx) |
| GET | `/admin/api/users/export` | Exporter les utilisateurs (.xlsx) |
| GET | `/admin/api/suppliers/export` | Exporter les fournisseurs (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Canaux / transactions / rapprochement |
| GET/POST | `/admin/api/provisioning/*` | Tâches de livraison / gestion des hôtes |
| GET/POST | `/admin/api/suppliers/*` | Approbation fournisseur / règlement / retrait |
| GET/POST | `/admin/api/tickets` | Attribution / clôture des tickets |
| GET | `/admin/api/reports/*` | Rapports de revenus / régionaux / fournisseurs |
| GET | `/admin/api/monitor/*` | Tableau de bord de surveillance / métriques des ressources |
| GET | `/admin/api/audit-logs` | Journaux d'audit |
| PUT | `/admin/api/system/config` | Mise à jour de la configuration système |
