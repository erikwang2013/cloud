# Rapport d'audit de sécurité — cloud-php

**Date :** 2026-08-04
**Périmètre :** projet complet (service + admin)
**Méthodologie :** revue de configuration, audit des middlewares, inspection du code

---

## Évaluation globale : **B+ (bon, 4 écarts à corriger)**

Le projet dispose d'une architecture de sécurité multicouche solide. Le plugin erikwang2013/security-php avec ses 31 détecteurs est l'élément remarquable. Voici le détail.

---

## 1. Défenses en place (vérifiées)

### Transport et chiffrement
| Mécanisme | Implémentation | Statut |
|-----------|---------------|--------|
| Chiffrement du transport API | AES-256-GCM via erikwang2013/encryption | OK |
| Chiffrement des champs DB | AES-128-ECB via erikwang2013/encryptable (déterministe, interrogeable) | OK |
| Rotation des clés | ENCRYPTION_PREVIOUS_KEYS, anciennes clés séparées par des virgules | OK |
| Obfuscation des ID | Hashids avec sel configurable et longueur minimale 12 | OK |
| Hachage des mots de passe | bcrypt cost=12, longueur minimale 8 | OK |

### Authentification et contrôle d'accès
| Mécanisme | Implémentation | Statut |
|-----------|---------------|--------|
| Authentification JWT | erikwang2013/jwt-webman, HS256, access TTL 900s + refresh 30j | OK |
| Liste noire JWT | Révocation de jeton basée sur Redis | OK |
| MFA/TOTP | 6 chiffres, période 30 s, compatible Google/MS Authenticator | OK |
| RBAC | middleware AccessControl admin + plugin\admin\api\Auth::canAccess() | OK |
| Stockage de session | Redis (db2) | OK |
| Captcha | captcha à texte à clic erikwang2013/poster-php pour connexion/inscription | OK |

### Détection d'attaques (WAF — double couche)
| Couche | Couverture | Statut |
|-------|----------|--------|
| WafMiddleware personnalisé | SQLi, XSS, CMDi, traversée de chemin, injection d'en-têtes, SSRF, NoSQLi, redirection ouverte | OK |
| Security Plugin (31 détecteurs) | Tout ce qui précède + XXE, désérialisation, LDAP, en-tête de courriel, SSTI, attaque JWT, en-tête Host, request smuggling, GraphQL, XPATH, JNDI/Log4Shell, SSI, injection CSV, fuite de données, prototype pollution, WebSocket, contournement CORS, DNS rebinding | OK |

### Limitation de débit (service uniquement)
| Route | Rate | Burst | Per | Statut |
|-------|------|-------|-----|--------|
| default | 60 | 10 | 60s | OK |
| login | 5 | 2 | 60s | OK |
| register | 3 | 0 | 60s | OK |
| pay | 10 | 3 | 60s | OK |
| upload | 10 | 2 | 60s | OK |
| supplier_api | 120 | 20 | 60s | OK |
| supplier_withdraw | 10 | 3 | 60s | OK |

### Autres protections
| Mécanisme | Implémentation | Statut |
|-----------|---------------|--------|
| Limites de taille de requête | body 10 Mo, URL 2 Ko | OK |
| Validation Content-Type | Liste blanche : JSON, multipart, form-urlencoded | OK |
| Requêtes préparées base de données | PDO::ATTR_EMULATE_PREPARES = false | OK |
| Séparation lecture/écriture DB | Écriture sur le maître, lecture sur la réplique, sessions sticky | OK |
| Journalisation d'audit | Base d'audit séparée, LogSanitizer masque les champs sensibles | OK |
| Mode maintenance | Les IP de la liste blanche passent, les autres reçoivent 503 + Retry-After | OK |
| Auto-bannissement IP | 5 violations en 60 s puis bannissement de 15 min | OK |
| Mode strict SQL | Empêche la troncature des données et la conversion implicite de types | OK |

---

## 2. Écarts et recommandations

### Écart 1 (Moyen) : le CORS renvoie n'importe quelle origine en miroir
**Fichier :** `service/common/security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

Ceci renvoie en miroir n'importe quelle Origine envoyée par le client, permettant effectivement à n'importe quel site de faire des requêtes inter-origines authentifiées. Le détecteur cors du plugin de sécurité peut attraper certaines injections d'en-têtes, mais le middleware lui-même n'a aucune liste blanche d'origines.

**Correctif :** ajouter une vérification de liste blanche. Si l'origine n'est pas dans la liste autorisée, répondre avec `Access-Control-Allow-Origin: null` ou omettre entièrement l'en-tête.

### Écart 2 (Moyen) : en-têtes de réponse de sécurité manquants
Ni service ni admin ne définissent les en-têtes de sécurité HTTP critiques :

| En-tête | Recommandé | Actuel |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | Manquant |
| X-Content-Type-Options | nosniff | Manquant |
| X-Frame-Options | DENY ou SAMEORIGIN | Manquant |
| Content-Security-Policy | Stratégie avec nonce/hash | Manquant |
| X-XSS-Protection | 1; mode=block | Manquant |
| Referrer-Policy | strict-origin-when-cross-origin | Manquant |
| Permissions-Policy | Restreindre caméra/micro/géolocalisation | Manquant |

**Recommandation :** ajouter un SecurityHeadersMiddleware aux piles de middlewares de service et d'admin. Correctif à fort impact, faible effort.

### Écart 3 (Faible) : admin/config/security.php sans limitation de débit
**Fichier :** `admin/config/security.php`

Le panneau d'administration n'a pas de configuration rate_limits. Le middleware WAF admin ne vérifie que les limites de taille de requête/Content-Type. Une attaque par force brute sur la connexion admin n'est pas limitée au niveau applicatif.

**Recommandation :** ajouter des rate_limits à admin/config/security.php ou appliquer RateLimitMiddleware aux routes admin.

### Écart 4 (Faible) : GeoBlockMiddleware défini mais non activé
**Fichier :** `service/common/security/GeoBlockMiddleware.php`

Le middleware existe et fonctionne, mais il n'est pas enregistré dans `service/config/middleware.php`. Si le blocage géographique est nécessaire, l'ajouter à la pile.

### Écart 5 (Info) : surcoût du double WAF
WafMiddleware (personnalisé, 40+ motifs regex) et SecurityMiddleware (plugin, 31 détecteurs) s'exécutent sur chaque requête. Leur couverture de motifs se chevauche significativement pour SQLi, XSS, injection de commandes, traversée de chemin, injection d'en-têtes, SSRF, NoSQLi et redirection ouverte.

**Recommandation :** le plugin de sécurité est plus complet (31 détecteurs contre 8 catégories) et dispose du bannissement IP, de listes blanches de champs et du dédoublonnage de journaux. Envisager de retirer le WafMiddleware personnalisé pour ne dépendre que du plugin, ou au minimum retirer les motifs chevauchants de WafMiddleware.

### Écart 6 (Info) : la classe Validator est minimale
**Fichier :** `service/common/helper/Validator.php`

Elle n'a que required(), email(), minLength(). Manquent : longueur maximale, validation numérique, assainissement de chaînes, validation d'URL, correspondance de motifs. Les contrôleurs qui n'utilisent pas la validation au niveau framework risquent d'accepter des entrées malformées.

---

## 3. Security Plugin — Statut des 31 détecteurs

| # | Détecteur | Mode | Notes |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF correspond au contenu textarea, doit rester en log |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }} correspond aux modèles Vue/Angular |
| 13 | nosql_injection | **log** | $ne/$gt correspond aux variables shell/LaTeX |
| 14 | open_redirect | block | |
| 15 | jwt_attack | block | |
| 16 | host_header | block | |
| 17 | request_smuggling | block | |
| 18 | graphql_injection | block | |
| 19 | xpath_injection | block | |
| 20 | jndi_injection | block | |
| 21 | ssi_injection | block | |
| 22 | csv_injection | block | |
| 23 | data_leak | block | |
| 24 | prototype_pollution | block | |
| 25 | websocket | block | |
| 26 | cors | block | |
| 27 | dns_rebinding | **log** | Host en boucle locale (127.0.0.1/localhost) plus en 403 (normal en développement/test, seulement journalisé) |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

Les 31 détecteurs sont activés. 4 en mode journalisation uniquement (risque de faux positif documenté). Configuration correcte.

---

## 4. Ordre d'exécution des middlewares (service)

```
1. VersionMiddleware          — analyse de l'en-tête de version API
2. CorsMiddleware              — en-têtes CORS (trop permissif, voir Écart 1)
3. ClientPlatformMiddleware    — détection OS/plateforme
4. WafMiddleware               — WAF personnalisé (40+ motifs regex)
5. SecurityMiddleware           — WAF plugin (31 détecteurs)
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — décodage des ID
8. MaintenanceMiddleware       — vérification du mode maintenance
```

---

## 5. Résumé

| Catégorie | Note | Problèmes clés |
|----------|-------|------------|
| Détection d'attaques | **A** | 31 détecteurs, double couche WAF (redondante mais complète) |
| Authentification | **A-** | bcrypt+MFA+liste noire JWT, limite de débit admin manquante |
| Sécurité du transport | **B+** | AES-256-GCM correct, en-têtes HSTS/CSP manquants |
| Validation des entrées | **B** | le WAF attrape les attaques, la validation applicative est mince |
| Contrôle d'accès | **A-** | RBAC + vérification de session, CORS trop permissif |
| Audit/journalisation | **A** | base d'audit séparée, masquage des champs sensibles |
| Limitation de débit | **B+** | bien configurée pour service, manquante pour admin |

**Ordre de priorité des correctifs :**
1. Ajouter les en-têtes de réponse de sécurité (HSTS, CSP, X-Frame-Options, etc.)
2. Restreindre le CORS à une liste blanche au lieu du miroir de n'importe quelle origine
3. Ajouter la limitation de débit au panneau admin
4. Activer GeoBlockMiddleware si le blocage géographique est nécessaire
5. Envisager de consolider les couches WAF pour réduire le surcoût regex par requête

---

## 6. Corrections appliquées (2026-08-04)

### Corrigé
| Écart | Correctif | Fichiers modifiés |
|-----|-----|---------------|
| CORS miroir de n'importe quelle origine | Mode liste blanche avec variable env `CORS_ALLOWED_ORIGINS`, prise en charge des jokers `*.example.com` et `*` pour tout | `service/common/security/CorsMiddleware.php` |
| En-têtes de sécurité manquants | Nouveau `SecurityHeadersMiddleware` ajouté aux piles de service et d'admin : X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection, Permissions-Policy, HSTS (opt-in via env) | `service/common/security/SecurityHeadersMiddleware.php`, `admin/app/middleware/SecurityHeadersMiddleware.php` |
| Admin sans limitation de débit | Ajout de la configuration `rate_limits` + `RateLimitMiddleware` au panneau admin (default 60/min, login 5/min) | `admin/config/security.php`, `admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock non activé | `GeoBlockMiddleware` enregistré dans la pile de middlewares service | `service/config/middleware.php` |

### Nouvelles variables d'environnement
| Variable | Objectif | Défaut |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | Origines autorisées séparées par des virgules | (vide = tout refuser) |
| `SECURITY_HSTS_ENABLE` | Activer l'en-tête HSTS | false |
| `SECURITY_HSTS_VALUE` | Valeur de l'en-tête HSTS | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | Valeur de X-Frame-Options | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | Codes de pays bloqués (ISO 3166-1) | (vide = désactivé) |
| `GEOIP_DB_PATH` | Chemin GeoLite2 .mmdb | storage_path('geoip/GeoLite2-Country.mmdb') |

### Pipeline de middlewares mis à jour

**Service :**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin :**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
