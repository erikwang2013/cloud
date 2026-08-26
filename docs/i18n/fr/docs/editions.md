# Cloud Platform — Plateforme mondiale de commerce de ressources cloud

Plateforme de commerce de ressources cloud destinée à un public mondial : achat en ligne et livraison automatique de serveurs (VM), adresses IP, disques cloud, domaines, etc. Les machines physiques détenues en propre sont virtualisées et livrées via Proxmox VE, tout en prenant en charge l'installation de fournisseurs tiers.


## Aperçu des éditions

| | Lite | Standard | Full |
|---|:---:|:---:|:---:|
| **Licence** | Open source (MIT) | Licence commerciale | Licence commerciale |
| **Contact** | GitHub | erik@erik.xyz | erik@erik.xyz |
| **Cas d'usage** | Projets personnels / apprentissage / petite boutique | FSI de taille moyenne | Grande plateforme cloud / multi-fournisseurs |

---

## I. Comparaison des fonctionnalités

### 1.1 Système utilisateur

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Inscription/connexion par e-mail ou téléphone | ✅ | ✅ | ✅ |
| Authentification JWT (Access + Refresh) | ✅ | ✅ | ✅ |
| Réinitialisation du mot de passe | ✅ | ✅ | ✅ |
| Liaison d'empreinte d'appareil + rotation de jeton | ❌ | ✅ | ✅ |
| Verrouillage de connexion (5 échecs → 15 min) | ❌ | ✅ | ✅ |
| Connexion Google OAuth | ❌ | ✅ | ✅ |
| Apple Sign In | ❌ | ✅ | ✅ |
| Double vérification TOTP + codes de récupération | ❌ | ✅ | ✅ |
| Vérification d'e-mail | ❌ | ✅ | ✅ |
| Code de vérification SMS | ❌ | ✅ | ✅ |
| Gestion de session (voir/révoquer) | ✅ | ✅ | ✅ |
| Suppression de compte GDPR | ✅ | ✅ | ✅ |
| Gestion du profil | ✅ | ✅ | ✅ |
| Vérification d'identité KYC | ❌ | ✅ | ✅ |
| Gestion des adresses | ❌ | ✅ | ✅ |
| Compte à solde | ❌ | ✅ | ✅ |
| Alerte de connexion depuis une nouvelle IP | ❌ | ✅ | ✅ |
| Identification de la plateforme client | ❌ | ✅ | ✅ |
| Internationalisation (i18n, 120 entrées) | ✅ | ✅ | ✅ |

### 1.2 Système de produits

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Liste des produits (filtre catégorie/région) | ✅ | ✅ | ✅ |
| Détail du produit (avec SKU + tarification régionale) | ✅ | ✅ | ✅ |
| Recherche plein texte Elasticsearch | ✅ | ✅ | ✅ |
| Avis sur les produits (note + contenu) | ✅ | ✅ | ✅ |
| Attributs de produit | ❌ | ✅ | ✅ |
| Captcha à clic | ❌ | ✅ | ✅ |
| Import/export en masse (CSV) | ❌ | ✅ | ✅ |

### 1.3 Système de commandes

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Panier (ajout/suppression/modification/consultation) | ✅ | ✅ | ✅ |
| Passer commande | ✅ | ✅ | ✅ |
| Liste de commandes + détail | ✅ | ✅ | ✅ |
| Coupons | ❌ | ✅ | ✅ |
| Factures (génération + téléchargement PDF) | ❌ | ✅ | ✅ |
| Remboursement | ❌ | ✅ | ✅ |

### 1.4 Système de paiement

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Paiement Stripe | ❌ | ✅ | ✅ |
| Routage multi-canaux | ❌ | ✅ | ✅ |
| Vérification de signature Webhook | ❌ | ✅ | ✅ |
| Rapprochement quotidien | ❌ | ✅ | ✅ |
| Taux de change multidevise | ❌ | ✅ | ✅ |
| Remboursement sur le moyen de paiement d'origine | ❌ | ✅ | ✅ |

### 1.5 Livraison des ressources

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Virtualisation Proxmox VE | ❌ | ✅ | ✅ |
| Serveur (VM) à cycle de vie complet | ❌ | ✅ | ✅ |
| Disque cloud (création/extension) | ❌ | ✅ | ✅ |
| Gestion + attribution du pool d'IP | ❌ | ✅ | ✅ |
| Stratégie de sélection de l'hôte (équilibrage de charge) | ❌ | ✅ | ✅ |
| Mise à niveau en ligne CPU/mémoire/disque | ❌ | ✅ | ✅ |
| Console VNC | ❌ | ✅ | ✅ |
| File d'attente de livraison asynchrone | ❌ | ✅ | ✅ |
| Stratégie de nouvelle tentative (6 essais avec backoff) | ❌ | ✅ | ✅ |
| Architecture de plugins Provider | ❌ | ✅ | ✅ |
| Surveillance de l'expiration des ressources | ❌ | ✅ | ✅ |

### 1.6 Domaines et DNS

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Vérification de disponibilité de domaine | ❌ | ✅ | ✅ |
| Gestion de la tarification TLD | ❌ | ✅ | ✅ |
| Gestion des enregistrements DNS | ❌ | ✅ | ✅ |
| Approbation de transfert de domaine | ❌ | ✅ | ✅ |

### 1.7 Système de tickets

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Création/réponse de ticket | ❌ | ✅ | ✅ |
| Liste de tickets + détail | ❌ | ✅ | ✅ |
| Attribution au support | ❌ | ✅ | ✅ |
| Suivi SLA | ❌ | ✅ | ✅ |
| Attribution automatique (équilibrage de charge) | ❌ | ✅ | ✅ |

### 1.8 Système de notifications

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Notification par e-mail | ❌ | ✅ | ✅ |
| Notification par SMS (Twilio) | ❌ | ✅ | ✅ |
| Push App (FCM) | ❌ | ✅ | ✅ |
| Message interne au site | ❌ | ✅ | ✅ |
| Gestion des modèles de notification | ❌ | ✅ | ✅ |
| Préférences de notification de l'utilisateur | ❌ | ✅ | ✅ |

### 1.9 Panneau d'administration

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Tableau de bord | ✅ | ✅ | ✅ |
| Gestion des utilisateurs (liste/détail/statut) | ✅ | ✅ | ✅ |
| Gestion des produits (CRUD) | ✅ | ✅ | ✅ |
| Gestion des commandes (liste/détail) | ✅ | ✅ | ✅ |
| Journaux d'audit | ✅ | ✅ | ✅ |
| Revue KYC | ❌ | ✅ | ✅ |
| Gestion SKU + tarification régionale | ❌ | ✅ | ✅ |
| Gestion des canaux de paiement + historique des transactions | ❌ | ✅ | ✅ |
| Surveillance des tâches de livraison de ressources | ❌ | ✅ | ✅ |
| Gestion des hôtes | ❌ | ✅ | ✅ |
| Attribution/clôture des tickets | ❌ | ✅ | ✅ |
| Gestion TLD + zones DNS | ❌ | ✅ | ✅ |
| Gestion des modèles de notification | ❌ | ✅ | ✅ |
| Gestion des coupons | ❌ | ✅ | ✅ |
| Gestion des articles d'aide | ❌ | ✅ | ✅ |
| Gestion des webhooks | ❌ | ✅ | ✅ |
| Gestion des API de fournisseurs cloud | ❌ | ✅ | ✅ |
| Import/export de produits | ❌ | ✅ | ✅ |
| Export utilisateurs/commandes/fournisseurs | ❌ | ✅ | ✅ |
| Rapports (revenus/régions) | ❌ | ✅ | ✅ |
| Panneau de surveillance + métriques de ressources | ❌ | ✅ | ✅ |
| Gestion des fournisseurs | ❌ | ❌ | ✅ |
| Gestion des clés API fournisseur | ❌ | ❌ | ✅ |
| Interrupteurs dynamiques Feature Flags | ❌ | ❌ | ✅ |

### 1.10 Système fournisseur

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Installation des fournisseurs + approbation | ❌ | ❌ | ✅ |
| Mise en vente de produits + commission | ❌ | ❌ | ✅ |
| Règlement (hebdomadaire/mensuel) | ❌ | ❌ | ✅ |
| Demande de retrait + approbation | ❌ | ❌ | ✅ |
| API externe (authentification par clé API) | ❌ | ❌ | ✅ |
| Isolation des données fournisseur | ❌ | ❌ | ✅ |

### 1.11 Communication temps réel

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Push temps réel WebSocket | ❌ | ❌ | ✅ |
| Surveillance des exceptions Sentry | ❌ | ❌ | ✅ |
| Scripts de test de charge k6 | ❌ | ✅ | ✅ |


### 1.12 Certificats SSL

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Achat de certificat SSL (DV/OV/EV) | ❌ | ❌ | ✅ |
| Émission automatique Let's Encrypt | ❌ | ❌ | ✅ |
| Renouvellement automatique (14 jours avant expiration) | ❌ | ❌ | ✅ |
| Téléchargement du certificat (PEM/KEY) | ❌ | ❌ | ✅ |
| Gestion des plans SSL (côté administration) | ❌ | ❌ | ✅ |

### 1.13 Stockage d'objets

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Stockage d'objets compatible S3 | ❌ | ❌ | ✅ |
| Stockage MinIO auto-hébergé | ❌ | ❌ | ✅ |
| URL pré-signées de téléversement/téléchargement | ❌ | ❌ | ✅ |
| Gestion des quotas de stockage | ❌ | ❌ | ✅ |

### 1.14 Accélération CDN

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Gestion des domaines CDN | ❌ | ❌ | ✅ |
| Purge du cache | ❌ | ❌ | ✅ |
| Type d'origine (serveur/stockage) | ❌ | ❌ | ✅ |
| Intégration Cloudflare | ❌ | ❌ | ✅ |

### 1.15 Facturation à l'usage

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Facturation à l'heure/au trafic | ❌ | ❌ | ✅ |
| Collecte et agrégation de l'usage | ❌ | ❌ | ✅ |
| Débit automatique sur le solde | ❌ | ❌ | ✅ |
| Suspension/reprise des ressources en impayé | ❌ | ❌ | ✅ |

### 1.16 Notation des fournisseurs

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Notation sur quatre dimensions (qualité/support/livraison/valeur) | ❌ | ❌ | ✅ |
| Restriction aux utilisateurs ayant acheté | ❌ | ❌ | ✅ |
| Revue des notations (côté administration) | ❌ | ❌ | ✅ |
| Affichage de la moyenne du fournisseur | ❌ | ❌ | ✅ |

### 1.17 Distribution par recommandation

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Génération de liens de recommandation | ❌ | ❌ | ✅ |
| Attribution de commandes (paramètre ref) | ❌ | ❌ | ✅ |
| Calcul et retrait des commissions | ❌ | ❌ | ✅ |
| Gestion des plans de distribution (côté administration) | ❌ | ❌ | ✅ |

### 1.18 GraphQL

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Point de terminaison GraphQL (public + authentifié) | ❌ | ❌ | ✅ |
| Requêtes produits/commandes/ressources | ❌ | ❌ | ✅ |
| Limitation de profondeur de requête | ❌ | ❌ | ✅ |

### 1.19 Observabilité

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Export des métriques Prometheus | ❌ | ❌ | ✅ |
| Tableaux de bord Grafana préconfigurés | ❌ | ❌ | ✅ |
| Règles d'alerte (file d'attente/taux d'erreur/latence) | ❌ | ❌ | ✅ |
| Vérifications de santé (live/ready/deps) | ❌ | ❌ | ✅ |
| i18n 7 langues (550+ entrées) | ❌ | ❌ | ✅ |

### 1.12 Clients

| Fonction | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Client Flutter | ❌ | ❌ | ✅ |
| Client HarmonyOS | ❌ | ❌ | ✅ |

---

## II. Comparaison de la conception d'architecture

### 2.1 Middlewares

| Middleware | Lite | Standard | Full |
|--------|:---:|:---:|:---:|
| CorsMiddleware (CORS) | ✅ | ✅ | ✅ |
| LocaleMiddleware (multilingue) | ✅ | ✅ | ✅ |
| HashidRequestMiddleware (décodage d'ID) | ✅ | ✅ | ✅ |
| AuthMiddleware (authentification JWT) | ✅ | ✅ | ✅ |
| RateLimitMiddleware (limitation de débit) | ✅ | ✅ | ✅ |
| WafMiddleware de base (SQLi/XSS) | ✅ | ✅ | ✅ |
| WafMiddleware complet (8 catégories, 45+ règles) | ❌ | ✅ | ✅ |
| AdminRoleMiddleware (RBAC) | ❌ | ✅ | ✅ |
| EncryptionMiddleware (AES-256-GCM) | ❌ | ✅ | ✅ |
| VersionMiddleware (version d'API) | ❌ | ✅ | ✅ |
| ClientPlatformMiddleware (identification de plateforme) | ❌ | ✅ | ✅ |
| ConfirmationMiddleware (confirmation de mot de passe) | ❌ | ✅ | ✅ |
| GeoBlockMiddleware (blocage géographique) | ❌ | ✅ | ✅ |
| MaintenanceMiddleware (mode maintenance) | ❌ | ✅ | ✅ |
| SupplierApiKeyMiddleware | ❌ | ❌ | ✅ |
| FeatureFlags | ❌ | ❌ | ✅ |
| RbacMiddleware | ❌ | ✅ | ✅ |

### 2.2 Architecture des données

| Caractéristique | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Clé primaire distribuée Snowflake | ✅ | ✅ | ✅ |
| Obfuscation d'ID Hashids | ✅ | ✅ | ✅ |
| Base unique MySQL | ✅ | ❌ | ❌ |
| Séparation lecture/écriture MySQL | ❌ | ✅ | ✅ |
| Base d'audit indépendante | ❌ | ✅ | ✅ |
| Chiffrement de transport AES-256-GCM | ❌ | ✅ | ✅ |
| Chiffrement des champs AES-128-ECB | ❌ | ✅ | ✅ |
| Cache multiniveau Redis | ❌ | ✅ | ✅ |
| Recherche plein texte Elasticsearch | ✅ | ✅ | ✅ |
| Optimisation des index de base de données (13) | ❌ | ✅ | ✅ |

### 2.3 Protections de sécurité

| Caractéristique | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Détection d'injection SQL (2 règles) | ✅ | ✅ | ✅ |
| Détection XSS (3 règles) | ✅ | ✅ | ✅ |
| Détection d'injection de commandes | ❌ | ✅ | ✅ |
| Détection d'inclusion de fichiers | ❌ | ✅ | ✅ |
| Détection d'injection d'en-têtes HTTP | ❌ | ✅ | ✅ |
| Détection SSRF | ❌ | ✅ | ✅ |
| Détection d'injection NoSQL | ❌ | ✅ | ✅ |
| Détection de redirection ouverte | ❌ | ✅ | ✅ |
| Limitation de la taille du corps de requête | ❌ | ✅ | ✅ |
| Liste blanche Content-Type | ❌ | ✅ | ✅ |

### 2.4 Haute concurrence

| Caractéristique | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Multi-processus webman | ✅ | ✅ | ✅ |
| Compression gzip Nginx | ❌ | ✅ | ✅ |
| Proxy buffering Nginx | ❌ | ✅ | ✅ |
| Nginx limit_req/limit_conn | ❌ | ✅ | ✅ |
| Couche de cache Redis | ❌ | ✅ | ✅ |
| Invalidation active du cache | ❌ | ✅ | ✅ |
| Séparation lecture/écriture MySQL | ❌ | ✅ | ✅ |
| Index composites de base de données | ❌ | ✅ | ✅ |
| Push WebSocket | ❌ | ❌ | ✅ |

---

## III. Déploiement et exploitation

| Caractéristique | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Déploiement Docker Compose | ✅ | ✅ | ✅ |
| Reverse proxy Nginx | ✅ | ✅ | ✅ |
| CI/CD (GitHub Actions) | ✅ | ✅ | ✅ |
| Tests PHPUnit | 95 tests | 295 tests | 295 tests |
| Tâches planifiées (7) | ❌ | ✅ | ✅ |
| Traitement asynchrone Redis Queue | ❌ | ✅ | ✅ |
| Commande de migration de base de données | ✅ | ✅ | ✅ |
| Commande de sauvegarde de base de données | ❌ | ✅ | ✅ |
| Point de terminaison de santé | ✅ | ✅ | ✅ |
| Point de terminaison d'état du service | ✅ | ✅ | ✅ |
| Surveillance des exceptions Sentry | ❌ | ❌ | ✅ |
| Publication progressive Feature Flags | ❌ | ❌ | ✅ |
| Test de charge k6 | ❌ | ❌ | ✅ |

---

## IV. Chiffres clés

| Indicateur | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Points de terminaison API | ~35 | ~130 | 200+ |
| Modèles de données | 15 | 50+ | 70+ |
| Tables de base de données | 15 | 50+ | 60+ |
| Middlewares globaux | 3 | 7 | 9 |
| Middlewares de routes | 1 | 5 | 6 |
| Tâches planifiées | 0 | 7 | 10 |
| Fichiers de migration | 5 | 20 | 27 |
| Nombre de tests | 95 | 295 | 295 |
| Nombre de règles WAF | 5 | 45+ | 45+ |
| Nombre de documents | 2 | 6 | 8 |
| Documentation en ligne hg/apidoc | ✅ | ✅ | ✅ |
| Points de terminaison API GraphQL | ❌ | ❌ | ✅ |
| Métriques Prometheus | ❌ | ❌ | ✅ |
| Système de notation Supplier | ❌ | ❌ | ✅ |
| Système de recommandation Affiliate | ❌ | ❌ | ✅ |

---

## V. Chemin de mise à niveau

```
Lite
  │
  │  + paiement + livraison + domaines + tickets + notifications
  │  + panneau d'administration complet + sécurité complète + optimisation haute concurrence
  ▼
Standard
  │
  │  + système fournisseur + API externe + WebSocket
  │  + Sentry + Feature Flags + client Flutter
  ▼
Full
```

**Compatibilité des données :** la structure de base de données de l'édition Lite est compatible avec les tables principales de Standard, migration directe possible. De Standard à Full, il s'agit d'un pur incrément (nouvelles tables liées aux fournisseurs), sans migration de données.

---

## VI. Moyens d'obtention

| Édition | Moyen d'obtention |
|------|---------|
| **Lite** | Open source GitHub, licence MIT |
| **Standard** | Licence commerciale, contacter **erik@erik.xyz** |
| **Full** | Licence commerciale, contacter **erik@erik.xyz** |
