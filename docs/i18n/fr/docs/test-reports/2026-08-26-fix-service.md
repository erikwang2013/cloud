# Rapport de correction des défauts service 2026-08-26 (A/C/F)

## Conclusion

- Les 3 défauts sont tous corrigés et retestés de bout en bout (9/9 PASS)
- Régression PHPUnit complète : 672 tests / 1632 assertions / 15 skipped / 0 failures
- Aucune touche sur .env, app/grpc/Generated ou le schéma de base de données ; aucune nouvelle dépendance composer

## Défaut A : clé encryptable non décodée en base64 → inscription/connexion/rafraîchissement/adresses toutes en 500

### Cause racine (trois couches superposées)

1. `config/encryptable.php` transmet `ENCRYPTION_KEY` (base64, 16 octets après décodage, cipher=aes-128-ecb) telle quelle comme clé ; le contrôle de longueur de clé lève `MissingEncryptionKeyException`.
2. À l'exécution, le fichier réellement lu est `config/plugin/erikwang2013/encryptable/app.php` (contenant uniquement `enable`) : cette configuration de plugin ne contient pas de key du tout.
3. webman n'a pas de helper global `app()`, `Encryption::doResolve()` n'atteint pas le chemin du conteneur et retombe sur `EnvEncryptableConfig` (lit la chaîne env base64 brute, sans décodage) — même avec la config de plugin corrigée, le 500 persisterait.

### Correction

| Fichier | Modification |
|------|------|
| `service/config/encryptable.php` | `'key' => base64_decode(getenv('ENCRYPTION_KEY'), true) ?: ''` (chemin legacy, corrigé au passage) |
| `service/config/plugin/erikwang2013/encryptable/app.php` | Compléter `key` (décodée en base64) / `cipher` / `previous_keys` |
| `service/support/bootstrap.php` | `Encryption::setFallbackConfig(new WebmanPluginEncryptableConfig())`, pour que l'exécution passe par la config de plugin (clé décodée) |

### Bugs de même source découverts sur la chaîne (corrigés au passage)

Une fois le chiffrement corrigé, l'inscription/connexion/rafraîchissement ont échoué autrement que par 500 :

- **Login 401** : `User::where('email', $login)->orWhere('phone', $login)` en texte clair ne correspond jamais aux colonnes chiffrées. Correction : `where('email', Encryption::php()->encrypt($login))` (le chiffrement est déterministe, l'égalité des textes chiffrés suffit).
- **Refresh 401 "Device mismatch"** : deux niveaux de problème —
  - `RefreshToken::where('token_hash', hash(...))` en texte clair ne correspond pas non plus, remplacé par `encrypt(hash(...))` ;
  - le chemin d'inscription n'enregistre jamais l'empreinte d'appareil (`AuthService::register()` appelle `issueTokens(..., '')`), alors que le rafraîchissement vérifie l'empreinte → après une inscription, le rafraîchissement échoue toujours. Correction : `AuthController::register` passe `deviceFingerprint($request)`, `AuthService::register` reçoit un paramètre `$deviceFingerprint`.
- **Contrôle d'unicité email/téléphone à l'inscription** : `User::where('email', ...)->exists()` a le même bug, remplacé par une requête sur valeur chiffrée (`recordFailedLogin` corrigé au passage).

## Défaut C : modèles Searchable sans client ES → modification de profil/création de commande en 500

### Décision : driver webman-scout passé à `database` (plutôt que `null`)

`config/plugin/erikwang2013/webman-scout/app.php` : `'driver' => 'elasticsearch' → 'database'`.

Raison : le client elasticsearch/elasticsearch n'est pas installé et le driver elasticsearch lève une exception à la sauvegarde du modèle ; avec le moteur `database`, l'écriture est un no-op et la recherche passe par un SQL LIKE (la recherche de produits reste utilisable), alors que le moteur `null` fait que `search()` renvoie silencieusement un tableau vide, ce qui engloutirait les résultats de recherche de produits par mot-clé. La configuration soft delete reste par défaut.

## Défaut F : le détecteur dns_rebinding bloque en 403 les requêtes locales Host=127.0.0.1

### Décision : mode dns_rebinding passé à `log` (plutôt que whitelist_ips)

`config/plugin/erikwang2013/security-php/app.php` : `dns_rebinding.mode = 'block' → 'log'`.

Raison : `whitelist_ips` saute **tous** les détecteurs en fonction de l'IP client — dans cet environnement tout le trafic passe par le reverse proxy nginx et l'IP client est toujours la boucle, ce qui équivaudrait à désactiver la totalité des 31 détecteurs. La connexion locale directe (Host=127.0.0.1/localhost) est la norme en développement/test ; passer en log ne libère que ce détecteur, les 30 autres restent en block.

## Découverte supplémentaire : user_addresses.phone VARCHAR(20) ne contient pas le texte chiffré

Une fois le chiffrement actif, l'ajout d'adresse renvoie 500 (`SQLSTATE[22001] Data too long for column 'phone'`). Contrainte « ne pas modifier la base », correction côté code :

- `service/app/user/model/UserAddress.php` : `phone` sorti des casts Encryptable (0 ligne dans la table, aucun risque de migration de données existantes). `address` reste chiffré (VARCHAR(500) suffit).

**Compromis et suite** : le téléphone est une donnée personnelle (PII) et est désormais stocké en clair. Pour restaurer le chiffrement au repos, il faut étendre `user_addresses.phone` et `users.phone` (tous deux VARCHAR(20) + Encryptable, l'inscription par téléphone renverrait aussi 500) à VARCHAR(255) — nécessite une migration de schéma, hors de la contrainte « ne pas modifier la base » de cette session ; il est suggéré d'ouvrir un projet dédié.

## Suivi de revue : garde-fou de déterminisme du cipher (bloquage reviewer levé)

Le reviewer a signalé : la requête d'égalité sur texte chiffré repose sur un chiffrement déterministe (ECB sans IV aléatoire), alors que `.env.example` recommande aes-256-cbc (IV aléatoire) — un nouvel environnement déployé selon l'exemple « démarre correctement mais ne fait jamais correspondre login/refresh/contrôles d'unicité », une indisponibilité silencieuse du login.

Correction (garde-fou fail-fast contre la panne silencieuse) :

- `service/support/bootstrap.php` : après le câblage de la config encryptable, un garde-fou vérifie — si le `cipher()` de `PHPEncrypter(WebmanPluginEncryptableConfig)` n'est pas `aes-128-ecb`/`aes-256-ecb`, le démarrage lève immédiatement une `RuntimeException` : « le mode de requête déterministe ne supporte que ECB, changer de cipher exige une migration de réchiffrement ».
- `service/.env.example` : avertissements ajoutés en commentaire dans la section chiffrement (CBC/GCM lèveront une erreur au démarrage ; la requête déterministe ne supporte que ECB).

Vérification : le .env actuel (aes-128-ecb) passe le garde-fou ; après redémarrage du service, E2E 9/9 PASS ; phpunit 672/1632/15 skipped/0 failures.

## Incident d'environnement (hors code, à traiter côté environnement)

En cours de session, `/usr/local/php/conf.d/002-imagick.ini` (propriétaire root, mtime 2026-08-26 23:31) a été créé ; l'imagick.so qu'il charge plante dans le constructeur de libgomp → **tous les appels php CLI avec ini segfaultent** (phpunit, start.php et php -l plantent tous ; gdb confirme que dlopen d'imagick.so déclenche SIGSEGV, OMP_NUM_THREADS=1 sans effet). Sans droits root il est impossible de supprimer ce fichier ; cette session a contourné avec `PHP_INI_SCAN_DIR=/tmp/confd` (copie du répertoire de scan, imagick exclu), et le service comme phpunit tournent ainsi.

Suggestion côté environnement : supprimer ou commenter `/usr/local/php/conf.d/002-imagick.ini` (imagick.so lui-même est corrompu), et déterminer qui a créé ce fichier en cours de session.

## Liste des fichiers modifiés (tous sous service/)

- `config/encryptable.php`
- `config/plugin/erikwang2013/encryptable/app.php`
- `config/plugin/erikwang2013/webman-scout/app.php`
- `config/plugin/erikwang2013/security-php/app.php`
- `support/bootstrap.php` (avec le garde-fou de déterminisme du cipher)
- `.env.example` (commentaires uniquement, valeurs de .env non touchées)
- `app/user/service/AuthService.php`
- `app/user/controller/AuthController.php`
- `app/user/model/UserAddress.php`

## Registre de vérification

- E2E (`/tmp/verify_chain.php`, script temporaire non intégré au dépôt) : F (Host=127.0.0.1 ne renvoie plus 403), inscription → login → rafraîchissement → ajout d'adresse, modification de profil : 9/9 PASS.
- `vendor/bin/phpunit` : 672 tests / 1632 assertions / 15 skipped / 0 failures.
