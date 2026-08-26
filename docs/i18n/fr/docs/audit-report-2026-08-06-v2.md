# Rapport d'audit CloudPlatform (deuxième passe, 2026-08-06)

> Périmètre : revérification après correction de tous les problèmes de la passe précédente (audit-report-2026-08-06.md).
> Base de tests : PHPUnit **319/319 réussis (505 assertions)** ; `php -l` sur 253 fichiers PHP **0 erreur de syntaxe**.

---

## I. Tests et contrôles statiques

| Élément | Résultat |
|------|------|
| PHPUnit complet | OK (319 tests, 505 assertions) |
| `php -l` (app/common/config) | Les 253 fichiers passent |
| composer audit | **Aucune vulnérabilité de sécurité** ; 1 package obsolète doctrine/annotations (dépendance directe de hg/apidoc, conservation évaluée) |
| composer.lock | Sous contrôle de version (staging A) |

---

## II. Vérification de la configuration écologique

### 2.1 Usage et définition des env — complets ✓

- Toutes les clés `getenv()` du code (y compris le motif dynamique `{PROVIDER}_OAUTH_*`) ont une définition dans `.env.example` ou une option configurable sous forme de commentaire (`#HASHIDS_ALPHABET`、`#POSTER_IMAGE_DRIVER`、`#EXCHANGE_RATE_API_URL`、`#COUNTRY_SEASON_DEFAULT`、`#SECURITY_HSTS_VALUE`)
- Éléments redondants du modèle (faible risque) : `MAIL_FROM_NAME` sans référence `getenv()` dans le code, conservé uniquement dans le modèle

### 2.2 Verrouillage des dépendances ✓

- `service/composer.lock` committé ; `.gitignore` ne l'exclut plus ; `service/.phpunit.cache/` est ignoré

### 2.3 Notes d'environnement

- Le port local 8787 est toujours occupé par erp-php, cloud-php ne peut pas démarrer localement (aucun conflit dans l'environnement de déploiement)
- `composer validate` échoue en fatal à cause du conflit entre l'Installer du plugin vendor `erikwang2013/security-php` et l'évaluation propre de composer (problème de package tiers, pas du code de ce projet)

---

## III. Vérification des protections de sécurité

### 3.1 Chaîne de middlewares globale (11 couches, couvre toutes les routes) ✓

```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock
→ WAF（SQLi/XSS）→ SecurityPlugin（détection de 31 types d'attaques）
→ Locale → Metrics → HashidRequest → Maintenance
```

### 3.2 Limitation de débit des routes publiques — 1 correctif cette passe

| Route | Middleware | Règle de débit |
|------|--------|---------|
| register / login / refresh | RateLimit | register 3/min、login 5/min |
| **forgot-password / reset-password** | **RateLimit（monté cette passe）** | password_reset 3/5min |
| oauthRedirect / oauthCallback（GET+POST） | RateLimit | oauth 10/60s |
| login/recovery | RateLimit | login 5/min |
| send-sms | RateLimit | sms 5/h |
| captcha/create | RateLimit | captcha 30/60s |

> **Correctif** : sur `forgot-password`/`reset-password`, la passe précédente avait défini la règle `password_reset` sans monter le middleware (surface de bombardement de courriels / cassage de code), monté cette passe.

### 3.3 Exposition des fichiers téléversés — 1 correctif cette passe (risque élevé)

**Problème** : la configuration nginx de `deployment.md` `location /storage/ { alias .../service/storage/; }` rend tout le répertoire storage public :

```
storage/
├── backups/    ← sauvegardes de base de données (.sql.gz) téléchargeables publiquement
├── apple/      ← clé privée AuthKey.p8 téléchargeable publiquement (peut signer des jetons Apple)
├── firebase/   ← identifiants de compte de service FCM (avec clés privées) téléchargeables publiquement
├── geoip/      ← base de données GeoLite2
└── uploads/    ← fichiers téléversés (exposition attendue)
```

**Correctif** : deployment.md et docker/nginx.conf utilisent désormais tous deux `location ^~ /storage/uploads/`, exposant uniquement le sous-répertoire uploads.

### 3.4 Autres vérifications ✓

- `verify-email` : jeton aléatoire à usage unique (nullifié après vérification), sans surface de force brute/énumération, pas de limitation requise
- Interface de téléversement : liste blanche de types + détection de contenu MIME finfo (corrigé à la passe précédente) ; uploads servis via l'alias statique nginx, sans exécution PHP
- JWT : HS256 + liste noire Redis (validation par jti en base) ; TOTP obligatoire à la connexion + verrouillage de 15 minutes après 5 échecs
- OAuth : vérification de signature JWKS + iss/aud/exp/nonce + `email_verified` obligatoire (corrigé à la passe précédente)
- Routes d'administration : AuthMiddleware + AdminRoleMiddleware + ConfirmationMiddleware

---

## IV. Recommandations restantes (non bloquantes)

| Niveau | Élément | Description |
|:---:|------|------|
| P3 | Répertoire obsolète redondant `service/service/` (28K) | Contient des copies obsolètes de Supplier/WebSocket, non chargées par PSR-4, non suivies, facilement modifiées par erreur ; suppression recommandée après confirmation manuelle |
| P3 | Redondance du modèle `MAIL_FROM_NAME` | Non utilisé par le code, peut être conservé comme configuration réservée pour le nom de l'expéditeur de courriel |
| P3 | doctrine/annotations obsolète | Dépendance directe de hg/apidoc, sa suppression nécessite de remplacer la génération de documentation API |
| P3 | Renforcement du répertoire de téléversement (deuxième recommandation) | Placer un `index.html` dans uploads, confirmer qu'aucune exécution PHP n'est possible au niveau du déploiement (l'alias nginx l'évite naturellement, attention au scénario serveur intégré webman) |

---

## V. Conclusion

Les 15 correctifs de la passe précédente sont tous confirmés efficaces après revérification, base de tests stable (319/505). Cette passe a trouvé et corrigé sur place 3 problèmes : **limitation de débit manquante sur les routes forgot/reset (P1)**、**la configuration nginx de deployment.md exposait sauvegardes et clés privées (P0)**、**nginx docker sans configuration statique uploads (P2)**. Après correction, la totalité des tests a été relancée avec succès.

*Méthode de génération du rapport : PHPUnit complet, php -l sur 253 fichiers, audit statique des routes/middlewares, audit des configurations nginx/docker, comparaison des ensembles usage/définition des env, composer audit.*
