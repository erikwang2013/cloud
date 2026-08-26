# Rapport d'audit complet CloudPlatform

**Date :** 2026-08-04  
**Périmètre de l'audit :** projet complet (qualité du code, sécurité, configuration écologique, déploiement, documentation)  
**Branche :** main  
**Dernier commit :** e321bcc — les 3 problèmes restants corrigés dans cette passe

---

## I. Vue d'ensemble du projet

| Dimension | État |
|------|------|
| Type de projet | Plateforme de commerce de ressources cloud PHP 8.2+ / webman |
| Taille du code | service (15 modules, 295 tests) + admin (53 contrôleurs, 67 tests) + Flutter + HarmonyOS |
| Base de données | MySQL 8.0, 46 tables (7 wa_* + 39 erik_*) |
| Mode de déploiement | Assistant d'installation en une commande / Docker Compose / manuel |
| Documentation | 10 documents + 11 diagrammes SVG d'architecture |

---

## II. Problèmes trouvés

### CRITIQUE (grave)

#### C1. Le déploiement Docker ne comprend pas le panneau d'administration

**Problème :** le Dockerfile ne copie que le répertoire `service/`, docker-compose ne fait que le proxy du port 8787. Le panneau d'administration (admin panel, port 8788) n'est pas du tout conteneurisé.

```dockerfile
# docker/Dockerfile — ne traite actuellement que le service
COPY service/ /app/
```

**Impact :** les utilisateurs d'un déploiement Docker ne peuvent pas utiliser le panneau d'administration. En contradiction avec le « démarrage en une commande Docker Compose » annoncé dans le README.

**Recommandation :** ajouter un Dockerfile pour `admin/` ou utiliser une construction multi-étapes pour déployer les deux services.

---

#### C2. Les ports de la base de données Docker sont exposés sur l'hôte

**Problème :** dans docker-compose.yml, les ports MySQL (3306) et Redis (6379) sont mappés directement sur l'hôte :

```yaml
mysql:
  ports:
    - "3306:3306"    # exposé au public
redis:
  ports:
    - "6379:6379"    # exposé au public
```

**Impact :** si le serveur a une IP publique, la base de données est exposée à l'extérieur. C'est une source fréquente d'incidents de sécurité.

**Recommandation :** retirer le mapping `ports`, ou au minimum lier `127.0.0.1:3306:3306`. Le réseau interne Docker suffit pour l'interconnexion.

---

#### C3. Fichier LICENSE manquant

**Problème :** le README déclare « édition Lite — licence MIT », mais il n'y a pas de fichier `LICENSE` à la racine du projet.

**Impact :** les exigences légales de l'open source ne sont pas remplies. GitHub ne reconnaîtra pas le type de licence du projet.

**Recommandation :** créer un fichier `LICENSE` à la racine avec le texte standard de la licence MIT.

---

### HAUTE (priorité élevée)

#### H1. Des fichiers SQL dupliqués créent de la confusion

**Problème :** le projet contient 3 fichiers DDL SQL :

| Fichier | Lignes | Tables | État |
|------|------|------|------|
| `install.sql` (racine) | 739 | 46 | **actuellement utilisé** |
| `admin/install.sql` | 152 | 7 (uniquement wa_*) | ancien, non supprimé |
| `docs/database.sql` | 629 | 39 (uniquement erik_*) | ancien, non supprimé |

**Impact :** les mainteneurs risquent de modifier le mauvais fichier, entraînant une désynchronisation.

**Recommandation :** supprimer `admin/install.sql` et `docs/database.sql`, ou ajouter un avertissement visible en tête de fichier pointant vers `install.sql`.

---

#### H2. L'assistant d'installation ne crée pas la base d'audit

**Problème :** `install/index.php` inclut la configuration de la base d'audit lors de la génération de `service/.env` :
```ini
AUDIT_DB_DATABASE=cloud_platform_audit
```
Mais l'assistant d'installation ne crée jamais cette base. Si l'application tente d'écrire des journaux d'audit au démarrage, elle échouera avec `Unknown database`.

**Impact :** la fonctionnalité de journaux d'audit est indisponible, la conformité est affectée.

**Recommandation :** à l'exécution de l'installation à l'étape 4, ajouter `CREATE DATABASE IF NOT EXISTS cloud_platform_audit`.

---

#### H3. Service Elasticsearch absent du Docker

**Problème :** docker-compose.yml n'a que trois services : app + mysql + redis. La pile technologique du README liste explicitement Elasticsearch 8.x comme composant requis.

**Impact :** la recherche plein texte (produits, utilisateurs, commandes, tickets) est totalement indisponible dans un déploiement Docker.

**Recommandation :** ajouter un service Elasticsearch dans docker-compose.yml.

---

#### H4. Extensions PHP manquantes dans le Dockerfile

**Problème :** les extensions PHP installées par le Dockerfile sont : `gd pdo_mysql zip bcmath redis`. Mais la vérification d'environnement exige 9 extensions, et il manque :
- `intl` (internationalisation PHP)
- `xml` (analyse XML)
- `fileinfo` (détection de types de fichiers)

**Impact :** certaines fonctionnalités peuvent échouer silencieusement dans l'environnement Docker.

**Recommandation :** ajouter les extensions manquantes : `docker-php-ext-install intl xml fileinfo`

---

### MOYENNE (priorité moyenne)

#### M1. admin/.env.example insuffisamment détaillé

**Problème :** service/.env.example (146 lignes) contre admin/.env.example (64 lignes) ; ce dernier a nettement moins de commentaires et d'options.

**Recommandation :** compléter les commentaires d'admin/.env.example, au moins en indiquant quels champs doivent être cohérents avec le service.

---

#### M2. HASHIDS_SALT codé en dur dans .env.example

**Problème :** les deux fichiers `.env.example` contiennent :
```ini
HASHIDS_SALT=cloud-platform-hashids
```
Si un exploitant fait directement `cp .env.example .env` sans modifier cette valeur, toutes les instances partageront le même sel.

**Recommandation :** utiliser un espace réservé dans `.env.example` et insister dans les commentaires : « une valeur aléatoire unique doit être générée ».

---

#### M3. Lien invalide sur la page de succès de l'assistant d'installation

**Problème :** le lien de la page de fin d'installation utilise `href="#"`, sans URL réellement cliquable.

**Recommandation :** au moins afficher l'URL/le port concret, accompagnés de la commande de démarrage.

---

#### M4. L'assistant d'installation absent du Docker

**Problème :** le Dockerfile ne copie ni `install.php` ni le répertoire `install/`. Les utilisateurs de Docker ne peuvent pas utiliser l'assistant d'installation en une commande.

**Recommandation :** documenter clairement que le déploiement Docker nécessite une configuration manuelle, ou intégrer l'assistant dans l'image.

---

#### M5. Variables d'environnement Docker Compose incomplètes

**Problème :** l'`environment` de docker-compose.yml manque plusieurs configurations nécessaires : clé JWT, sel Hashids, clés de chiffrement, SMTP, Stripe, etc.

**Recommandation :** compléter la liste complète des variables d'environnement, ou référencer le fichier `.env`.

---

### BASSE (priorité basse)

#### L1. Section Docker faible dans la documentation

La section déploiement Docker du README ne fait que quelques lignes, sans explication sur la configuration des variables d'environnement, l'initialisation de la base de données ou l'accès au panneau d'administration.

**Recommandation :** compléter la documentation de déploiement Docker.

---

#### L2. .editorconfig manquant

**Problème :** le projet n'a pas de fichier `.editorconfig`. Pour un projet multi-contributeurs, une indentation et des fins de ligne uniformes sont importantes.

**Recommandation :** ajouter un `.editorconfig` standard : indentation de 4 espaces pour PHP, UTF-8, fins de ligne LF.

---

#### L3. Valeurs par défaut codées en dur à centraliser

**Problème :** `install/index.php` contient plusieurs valeurs par défaut codées en dur (hôte de base de données, port, nom de base, nom d'utilisateur admin), facilement oubliées lors d'une modification.

**Recommandation :** les extraire en définitions de constantes en tête de fichier.

---

## III. Évaluation de l'intégralité de la configuration écologique

### Couverture des variables .env

| Domaine de configuration | service | admin | .env.example |
|--------|:---:|:---:|:---:|
| Connexion à la base de données | ✓ | ✓ | ✓ |
| Base d'audit | ✓ | N/A | ✓ |
| Redis | ✓ | ✓ | ✓ |
| Authentification JWT | ✓ | N/A | ✓ |
| Hashids | ✓ | ✓ | ✓ |
| Snowflake | ✓ | ✓ | ✓ |
| Chiffrement de transport (AES-256-GCM) | ✓ | ✓ | ✓ |
| Chiffrement des champs (AES-128-ECB) | ✓ | ✓ | ✓ |
| Courriel SMTP | ✓ | N/A | ✓ |
| Paiement Stripe | ✓ | N/A | ✓ |
| Elasticsearch | ✓ | ✓ | ✓ |
| SMS Twilio | ✓ | N/A | ✓ |
| Push Firebase | ✓ | N/A | ✓ |
| Captcha à clic | ✓ | N/A | ✓ |
| Surveillance Sentry | ✓ | N/A | ✓ |
| Feature Flags | ✓ | N/A | ✓ |
| Rotation des clés | ✓ | N/A | ✓ |
| **Évaluation** | **complète** | **complète** | **complète** |

### Cohérence des clés partagées générées par l'assistant d'installation

| Clé | service | admin | Cohérent |
|------|:---:|:---:|:---:|
| ENCRYPTION_KEY | ✓ | ✓ | ✓ |
| ENCRYPTION_MASTER_KEY | ✓ | ✓ | ✓ |
| HASHIDS_SALT | ✓ | ✓ | ✓ |
| **Évaluation** | **réussi** | **réussi** | **réussi** |

---

## IV. Évaluation de la sécurité

| Élément | État | Description |
|--------|:--:|------|
| Protection CSRF | ✓ | génération de jeton + validation hash_equals |
| Sécurité de session | ✓ | HttpOnly + SameSite=Strict + strict_mode |
| Validation des entrées | ✓ | regex de validation du nom de base, vérification de la plage de ports |
| Robustesse du mot de passe | ✓ | minimum 8 caractères + lettre + chiffre/caractère spécial |
| Hachage du mot de passe | ✓ | password_hash(PASSWORD_DEFAULT) |
| Génération des clés | ✓ | openssl rand ou random_bytes |
| Protection contre l'injection SQL | ✓ | requêtes préparées PDO |
| Désensibilisation des erreurs | ✓ | les erreurs détaillées vont dans error_log, l'utilisateur voit un message générique |
| Protection XSS | ✓ | échappement de sortie htmlspecialchars() |
| Protection contre la réinstallation | ✓ | détection des tables existantes + fichiers .env |
| Application des étapes | ✓ | session max_step empêche de sauter des étapes |
| Enveloppe de transaction | ✓ | beginTransaction/commit/rollBack |
| Exposition des ports Docker | ✗ | MySQL:3306 / Redis:6379 mappés sur l'hôte |
| Création de la base d'audit | ✗ | l'assistant ne crée pas la base _audit |
| **Note globale** | **A-** | les mesures de sécurité de base sont solides, la configuration Docker doit être améliorée |

---

## V. Intégralité SQL

| Élément | Résultat |
|--------|------|
| Nombre total de tables | 46 (7 wa_* + 39 erik_*) ✓ |
| Moteurs | tous InnoDB ✓ |
| Jeu de caractères | tout utf8mb4 ✓ |
| Type de clé primaire | BIGINT UNSIGNED (non auto-incrémenté) ✓ |
| CREATE IF NOT EXISTS | utilisé partout ✓ |
| Instructions destructives | aucune (pas de DROP TABLE) ✓ |
| Fichiers SQL anciens | 2 anciens fichiers subsistent, à nettoyer ⚠ |

---

## VI. Évaluation de la couverture des tests

| Suite de tests | Framework | Tests | Assertions |
|----------|------|:---:|:---:|
| admin/tests/ | PHPUnit 11 | 67 | ~67 |
| service/tests/ | PHPUnit 10 | 295 | 455 |
| CI/CD | GitHub Actions | 3 jobs | PHP 8.2 + 8.3 |

**Évaluation :** nombre de tests suffisant (362 tests), CI/CD couvrant la vérification de syntaxe sur les deux versions PHP + les tests unitaires des deux applications.

---

## VII. Intégralité de la documentation

| Document | Contenu | État |
|------|------|:--:|
| README.md | vue d'ensemble du projet, architecture, prise en main rapide, aperçu de l'API | ✓ |
| README_EN.md | version anglaise du README | ✓ |
| docs/architecture.md | conception de l'architecture système | ✓ |
| docs/features.md | conception fonctionnelle des 12 modules | ✓ |
| docs/api-reference.md | référence de 135+ points de terminaison | ✓ |
| docs/admin-design.md | conception du panneau d'administration | ✓ |
| docs/supplier-api.md | API fournisseur | ✓ |
| docs/deployment.md | liste de contrôle de déploiement | ✓ |
| docs/editions.md | comparaison des éditions | ✓ |
| docs/diagrams/ (11 SVG) | architecture/sécurité/flux métier | ✓ |
| Fichier LICENSE | **manquant** | ✗ |

---

## VIII. Résumé des recommandations de correctifs

### Première priorité (à corriger avant la prochaine publication)

| # | Problème | Niveau |
|---|------|:--:|
| 1 | Créer le fichier LICENSE (MIT) | CRITIQUE |
| 2 | Supprimer les anciens fichiers SQL (admin/install.sql, docs/database.sql) | HAUTE |
| 3 | Ne pas exposer les ports MySQL/Redis Docker sur l'hôte | CRITIQUE |
| 4 | L'assistant d'installation crée la base d'audit `_audit` | HAUTE |

### Deuxième priorité (à corriger prochainement)

| # | Problème | Niveau |
|---|------|:--:|
| 5 | Support Docker du panneau d'administration (admin panel) | CRITIQUE |
| 6 | Ajout du service Elasticsearch à Docker Compose | HAUTE |
| 7 | Complément des extensions PHP du Dockerfile (intl, xml, fileinfo) | HAUTE |
| 8 | HASHIDS_SALT de .env.example remplacé par un espace réservé | MOYENNE |

### Troisième priorité (amélioration continue)

| # | Problème | Niveau |
|---|------|:--:|
| 9 | Compléter la documentation de déploiement Docker | BASSE |
| 10 | Ajouter .editorconfig | BASSE |
| 11 | Nettoyer les valeurs par défaut codées en dur dans le code | BASSE |
| 12 | Unifier les options de la fonction de génération .env | BASSE |

---

## IX. Conclusion

La qualité globale du projet est bonne ; les problèmes de sécurité de l'assistant d'installation principal ont tous été corrigés après l'audit précédent. L'organisation du code est claire, la modularité élevée, la documentation complète. Les principaux problèmes se concentrent sur **la configuration de déploiement Docker incomplète** — panneau d'administration, service de recherche et extensions PHP manquants, ainsi qu'un risque de sécurité d'exposition des ports de base de données.

**Note globale : B+** — fonctionnalités complètes, noyau de sécurité solide, configuration de l'écosystème Docker à compléter.
