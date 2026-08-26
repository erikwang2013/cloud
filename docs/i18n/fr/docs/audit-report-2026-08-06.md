# Rapport d'audit global CloudPlatform

**Date** : 2026-08-06
**Périmètre de l'audit** : service complet (app / common / config / tests) + configuration écologique + protections de sécurité
**Méthode** : suite de tests PHPUnit, vérification syntaxe PHP complète, audit des routes/middlewares, revue de code des nouvelles fonctionnalités OAuth, vérification de cohérence des variables d'environnement et de la configuration, audit de sécurité des dépendances, tests de fumée

---

## I. Conclusion générale

| Dimension | Conclusion |
|------|------|
| Tests | **314 éléments tous réussis** (494 assertions après correction de 2 bugs) |
| Syntaxe | 287 fichiers PHP sans erreur de syntaxe |
| Sécurité des dépendances | composer audit sans vulnérabilité connue ; 1 package obsolète (doctrine/annotations) |
| Architecture de sécurité | protections multi-couches complètes (double moteur WAF, liste blanche CORS, chiffrement de transport, chiffrement des champs, bcrypt cost=12, liste noire JWT, journaux d'audit) |
| Problèmes graves | **1 P0 (id_token Apple sans vérification de signature → prise de contrôle de compte possible), 4 P1** |
| Configuration écologique | **.env.example manque 31 variables en usage**, y compris tous les identifiants OAuth ; les canaux de notification sont des implémentations factices |

---

## II. Résultats des tests

```
OK (314 tests, 494 assertions)
```

### Les 2 bugs corrigés cette fois

| ID | Fichier | Problème | Correctif |
|----|------|------|------|
| B1 | `service/common/captcha/CaptchaService.php:31` | lit `$result['extra']['targets']`, mais la bibliothèque renvoie `extra.texts` → `target_count` toujours 0 | passage à `extra.texts` |
| B2 | `vendor/erikwang2013/poster-php/src/Captcha/ClickCaptcha.php:17` | la bibliothèque a un `targetCount = 5` par défaut, en contradiction avec le contrat de son propre README (medium=3 cibles) → 3 tests Captcha en échec | valeur par défaut 5 → 3 |

> B2 est un bug de la bibliothèque vendue (vendor/ est suivi par git, le correctif est persistant). Une soumission du correctif au dépôt amont est recommandée.

---

## III. Problèmes de sécurité graves (P0 / P1)

### P0-1. `id_token` Apple sans vérification de signature — prise de contrôle de compte directe
**Fichier** : `service/app/user/service/OAuthService.php:180-192` (`appleProfile()`)

```php
$parts  = explode('.', $tokenData['id_token']);
$claims = json_decode(base64_decode($parts[1]), true);   // simple décodage base64, sans vérification de signature/iss/aud/exp
```

Un attaquant peut construire lui-même un `id_token` et forger un email quelconque pour terminer la connexion OAuth. `resolveUser()` fait correspondre l'email aux utilisateurs existants et émet directement un jeton → **prise de contrôle de n'importe quel compte**.

**Correctif** : vérification de signature via JWKS Apple (`https://appleid.apple.com/auth/keys`) + `Firebase\JWT\JWT::decode($idToken, $keys, ['ES256'])`, et validation de `iss=appleid.apple.com`, `aud=client_id`, `exp`, `nonce`.

### P1-1. Connexion OAuth sans vérification de `email_verified`
**Fichier** : `OAuthService.php:163-178, 282-303`

Google/Facebook/Microsoft/LinkedIn renvoient tous le champ `email_verified`, complètement ignoré par le code. Un utilisateur dont l'email n'est pas vérifié chez le fournisseur peut utiliser cet email pour lier directement/prendre le contrôle d'un compte déjà enregistré. Le chemin GitHub vérifie `verified` (correct), les autres fournisseurs doivent être unifiés.

### P1-2. Le middleware de limitation de débit existe mais n'est jamais monté — documentation et implémentation en désaccord
**Fichier** : `common/Security/RateLimitMiddleware.php` + `config/security.php` + `config/route.php`

- `security.php` configure des règles login=5/min, register=3/min, etc.
- `RateLimitMiddleware` **n'est référencé par aucune route** (le grep sur toute la base ne trouve que la classe elle-même)
- `docs/features.md` affirme que la connexion est « limitée à 5 req/min » et l'inscription « limitée à 3 req/min » — en réalité inexistant
- Le rapport d'audit historique (`security-audit-2026-08-04.md`) marquait cet élément OK, car il ne vérifiait que la configuration sans valider le montage ; corrigé cette fois

**Impact** : les points de terminaison publics (connexion/inscription/mot de passe oublié/réinitialisation/codes de récupération/captcha) peuvent être forcés par force brute sans limitation (la connexion ne compte que sur le verrouillage par compte, ne protège pas contre le credential stuffing ni le bourrage au niveau IP).

**Correctif** : monter `RateLimitMiddleware` sur les routes publiques `/api/auth/*`, `/api/captcha/*` (montage possible dans le groupe global `''`, en différenciant par le paramètre `route`).

### P1-3. TOTP 2FA non imposé dans le flux de connexion
**Fichier** : `AuthService.php:64-97` (`login()`) + `AuthController.php` + `config/features.php`

`user->totp_enabled` n'est vérifié que dans `totpVerify/totpDisable/totpRecoveryCodes`, **`login()` ne le vérifie jamais**. Un utilisateur ayant activé la 2FA obtient quand même un access token valide avec le seul mot de passe — la 2FA est une coquille vide (`FEATURE_TOTP` activé par défaut).

**Correctif** : à la connexion, si `totp_enabled`, émettre un jeton temporaire et exiger le passage de la vérification TOTP avant l'émission du jeton définitif (ou exiger le paramètre du code totp).

### P1-4. Canaux de notification en implémentation factice — vérification d'email/réinitialisation de mot de passe inutilisables en production
**Fichier** : `app/Notification/Queue/EmailSender.php`、`SmsSender.php`、`PushSender.php`

Les trois consommateurs simulent l'envoi via `error_log()` et marquent `send_status` à `sent`. Conséquences :
- **flux de mot de passe oublié rompu** : `AuthController::forgotPassword()` génère un code et « envoie » un email, mais l'email n'arrive jamais → l'utilisateur ne peut pas réinitialiser son mot de passe seul
- la vérification d'email à l'inscription et l'alerte de connexion depuis une nouvelle IP sont pareillement inopérantes
- les 7 variables `SMTP_*`/`MAIL_FROM_*` de `.env.example` ne sont lues par aucun code (configuration morte)

**Correctif** : raccorder un envoi d'email réel (PHPMailer/SendGrid SDK), retirer le marquage trompeur `sent` ; ou marquer explicitement la fonctionnalité incomplète et retirer les engagements correspondants de la documentation.

---

## IV. Problèmes de sécurité (P2)

| ID | Fichier | Problème |
|----|------|------|
| P2-1 | `app/Controller/UploadController.php:23` | le paramètre `type` n'est pas validé par une liste blanche avant d'être concaténé dans le chemin `uploads/{$type}/...` → **traversée de chemin** possible hors du répertoire de téléversement (noms de fichiers aléatoires, pas de réécrasement possible, mais pollution du système de fichiers) ; recommandation de limiter type à une liste blanche énumérée et de protéger le répertoire de stockage avec `index.php`/`.htaccess` |
| P2-2 | idem | seule l'extension est vérifiée, sans détection de contenu MIME (un fichier polyglotte peut être exploité via cache/redirection) ; recommandation de vérifier le MIME réel avec `finfo` |
| P2-3 | `AuthController.php:131-158` | le code de réinitialisation de mot de passe à 6 chiffres est valide 600 s, sans limite de tentatives → force brute possible sur 1 million de combinaisons en 10 minutes ; `forgotPassword` sans limitation de fréquence → bombardement d'emails |
| P2-4 | `AuthController.php:333-348` | la génération/consultation des codes de récupération `totpRecoveryCodes` ne demande que la connexion, sans confirmation de mot de passe ; montage de `ConfirmationMiddleware` requis |
| P2-5 | `common/Auth/Middleware/AuthMiddleware.php:31` | la clé de vérification manuelle de la liste noire est `jwt_blacklist:{sha256(token)}`, incompatible avec le format `jwt_blacklist:{jti}` de la bibliothèque → code mort (la protection réelle est assurée par `decode()` interne de la bibliothèque, effective mais redondante), recommandation de supprimer ou d'utiliser l'interface de la bibliothèque |
| P2-6 | `OAuthService.php:67-94` | le paramètre `redirect` de `authorizeUrl` est stocké dans state puis jamais utilisé (paramètre mort) ; state non lié au fournisseur ; aucun nonce dans tout le flux OAuth (fournisseurs OIDC, profondeur de défense manquante, corrigé avec P0-1) |
| P2-7 | `OAuthService.php:31-37, 236-238` | l'API v2 de X (Twitter) `userinfo` ne renvoie pas d'email → la connexion X échoue forcément avec « Email not provided », défaut fonctionnel, à documenter ou à passer au point de terminaison `/2/email` |
| P2-8 | `AuthController.php:436-442` | `deviceFingerprint` tronque le segment IPv4 avec `strrpos($ip, '.')`, les clients IPv6 dégénèrent en chaîne vide → empreinte faible ; recommandation d'utiliser les 64 premiers bits ou le hash de toute l'IP |

---

## V. Intégralité de la configuration écologique

### 5.1 Variables manquantes dans .env.example (référencées par `getenv()` dans le code mais non définies) — 31

| Catégorie | Variables |
|------|------|
| **Identifiants OAuth (nouvelle fonctionnalité, totalement non documentée)** | `GOOGLE/APPLE/FACEBOOK/X/MICROSOFT/LINKEDIN/GITHUB_OAUTH_CLIENT_ID`、`_CLIENT_SECRET`、`_REDIRECT_URI` (21) |
| **Spécifique Apple** | `APPLE_TEAM_ID`、`APPLE_KEY_ID`、`APPLE_PRIVATE_KEY_PATH` |
| **Fonctionnalités clés** | `APP_URL` (les liens d'email de vérification en dépendent, leur absence rend les liens erronés)、`APP_ENV`、`APP_VERSION` |
| **Sécurité** | `INTERNAL_MONITOR_TOKEN` (protection des points de terminaison /health/*)、`MAINTENANCE_MODE`、`MAINTENANCE_ALLOWED_IPS`、`WEBHOOK_SECRET`、`JWT_LEEWAY` |
| **Cloud/stockage** | `AWS_ACCESS_KEY_ID`、`AWS_SECRET_ACCESS_KEY`、`BACKUP_S3_BUCKET`、`BACKUP_S3_REGION`、`DB_READ_HOST` |
| **Feature flags (8)** | `FEATURE_SSL_PRODUCT`、`FEATURE_OBJECT_STORAGE`、`FEATURE_USAGE_BILLING`、`FEATURE_PROMETHEUS`、`FEATURE_CDN_PRODUCT`、`FEATURE_SUPPLIER_RATING`、`FEATURE_AFFILIATE`、`FEATURE_GRAPHQL` |
| **Autres** | `METRICS_PORT`、`WS_PORT`、`GEOIP_DB_PATH` (commenté uniquement dans .env.example)、`SSL_STAGING`、`HASHIDS_ALPHABET`、`POSTER_IMAGE_DRIVER`、`EXCHANGE_RATE_API_URL`、`COUNTRY_SEASON_DEFAULT` |

### 5.2 Définies dans .env.example mais non utilisées par le code — 7

`SMTP_HOST`、`SMTP_PORT`、`SMTP_USERNAME`、`SMTP_PASSWORD`、`SMTP_ENCRYPTION`、`MAIL_FROM_ADDRESS`、`MAIL_FROM_NAME` (l'envoi d'email n'est pas implémenté, voir P1-4)

### 5.3 Incohérence de couverture i18n

| Langue | messages.php | billing | health | storage |
|------|:---:|:---:|:---:|:---:|
| en-US / zh-CN | 129 / 129 | 10 / 16 | 8 / 16 | 9 / 16 |
| ja-JP | 63 | 10 | 8 | 9 |
| ko-KR | 51 | 10 | 8 | 9 |
| fr-FR / de-DE / es-ES | 44 | 10 | 8 | 9 |

- Les langues non chinois/anglais manquent de plus de la moitié des clés de traduction ; zh-CN a 6-8 clés de plus que en-US sur billing/health/storage (le sens de synchronisation est inversé)
- **Toutes les clés de traduction OAuth sont absentes** (les messages d'erreur sont codés en dur en anglais)

### 5.4 Autres problèmes écologiques

| ID | Problème |
|----|------|
| E1 | `service/composer.lock` est ignoré par `.gitignore` et non committé — les dépendances applicatives ne sont pas verrouillées, le déploiement n'est pas reproductible (risque de déploiement) |
| E2 | `service/.phpunit.cache/` apparaît dans git status (non ignoré) |
| E3 | le port 8787 entre en conflit avec l'autre projet local erp-php, cloud-php ne peut pas démarrer localement (8787 confirmé occupé par le WorkerMan d'erp-php) |
| E4 | `docs/features.md` prétend une limitation de débit/des emails inexistants en pratique (voir P1-2 / P1-4), documentation à corriger |
| E5 | la dépendance `doctrine/annotations` est obsolète (signalé par composer audit), évaluation du retrait recommandée |

---

## VI. Suggestions d'optimisation (non bloquantes)

1. **Création de services DI** : le constructeur d'`AuthController` fait directement `new AuthService()/OAuthService()`, recommandation de passer par le conteneur (nativement supporté par webman), pour faciliter les tests et le remplacement.
2. **Renforcement du répertoire de téléversement** : placer un `index.html` dans le répertoire, désactiver l'exécution PHP (nginx `location ~ \.php { deny all; }`).
3. **Resserrement des regex WAF** : les `sqli_patterns` de `security.php` contiennent des motifs larges comme `\b(select|update|delete|...)\b` ; avec la limitation globale, ces mots dans les tickets utilisateurs/avis déclencheraient un 403 à tort ; recommandation de ne les appliquer qu'aux paramètres sensibles ou de resserrer les regex.
4. **Journalisation d'audit** : `AuditLogger::record('user_registered', ['user_id' => null])` n'enregistre pas le nouvel ID utilisateur, recommandation d'enregistrer l'ID réel.
5. **Couverture de tests OAuth** : `OAuthServiceTest` couvre la construction d'URL et l'échange de code, mais `resolveUser()` (chemin DB) et le chemin de vérification Apple n'ont pas de tests ; après le correctif P0, des cas de test d'échec de vérification sont obligatoires.
6. **Intégration CI** : le projet a un répertoire `.github`, recommandation d'ajouter GitHub Actions : `composer install && phpunit` + `composer audit`, pour prévenir les régressions.
7. **Contraintes de méthode HTTP** : l'enregistrement simultané du callback OAuth en GET/POST est raisonnable (nécessaire pour Apple) ; les autres écritures publiques sont explicitement en POST, OK.

---

## VII. Liste de priorités des correctifs

| Priorité | Élément | Charge |
|:---:|------|:---:|
| P0 | Vérification de signature id_token Apple (JWKS + iss/aud/exp/nonce) | Moyenne |
| P1 | Vérification `email_verified` sur tous les fournisseurs OAuth | Petite |
| P1 | Montage de RateLimitMiddleware sur les routes publiques | Petite |
| P1 | Imposition du TOTP dans le flux de connexion | Moyenne |
| P1 | Implémentation d'un envoi d'email réel (ou marquage incomplet) | Moyenne |
| P1 | .env.example : compléter les 31 variables manquantes + documentation de configuration OAuth | Petite |
| P2 | Liste blanche des types de téléversement + validation MIME | Petite |
| P2 | Limitation du code de réinitialisation/mot de passe oublié | Petite |
| P2 | Confirmation de mot de passe sur l'interface des codes de récupération | Petite |
| P2 | Commit de composer.lock, gitignore .phpunit.cache | Infime |
| P3 | Nettoyage du code mort de la liste noire, resserrement des regex WAF, complément i18n | Moyenne |

---

## VIII. État des correctifs (2026-08-06)

| Priorité | Élément | Statut |
|:---:|------|:---:|
| P0 | Vérification de signature id_token Apple (JWKS + iss/aud/exp/nonce) | ✅ corrigé |
| P1 | Vérification `email_verified` sur tous les fournisseurs OAuth (X avec repli /2/email) | ✅ corrigé |
| P1 | Montage de RateLimitMiddleware (routes auth/oauth/password/sms/captcha + 4 nouvelles règles) | ✅ corrigé |
| P1 | Imposition du TOTP dans le flux de connexion (verrouillage de 15 minutes après 5 erreurs, comptage indépendant anti-DoS) | ✅ corrigé |
| P1 | Envoi d'email réel (symfony/mailer SMTP ; état dev-stub si non configuré) | ✅ corrigé |
| P1 | .env.example : compléter les 31 variables manquantes + documentation de configuration OAuth | ✅ corrigé |
| P2 | Liste blanche des types de téléversement + détection de contenu MIME finfo | ✅ corrigé |
| P2 | Limitation du code de réinitialisation/mot de passe oublié (5 erreurs → 429 pendant 10 minutes) | ✅ corrigé |
| P2 | Confirmation de mot de passe sur l'interface des codes de récupération | ✅ corrigé |
| P2 | composer.lock désignoré et stagé, gitignore .phpunit.cache | ✅ corrigé |
| P3 | Nettoyage du code mort de la liste noire, resserrement des regex WAF (3 structurelles), complément i18n (réécriture du contenu erroné zh-CN billing/health/storage, implémentation de fallback_locale dans trans()) | ✅ corrigé |
| E3 | Port 8787 occupé par erp-php, démarrage impossible en local | ⚠️ problème d'environnement, aucun conflit dans l'environnement de déploiement |
| E5 | doctrine/annotations obsolète | ⚠️ conservé après évaluation (dépendance directe de hg/apidoc, son retrait casserait la génération de la documentation API) |

Tests complémentaires : 12 pour OAuth (dont paramètre nonce, vérification de signature, rejet email_verified, repli email X), 2 après resserrement du WAF. Base complète : **319/319 réussis (505 assertions)**.

*Méthode de génération du rapport : tests PHPUnit complets, `php -l` sur 287 fichiers, audit statique des routes/middlewares, comparaison des ensembles usage/définition des env, composer audit, exploration des ports et processus. Base de tests : 314/314 réussis.*
