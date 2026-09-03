# Planification d'équipe CloudPlatform

> Version : 2026-08-17 (v2) ｜ v1 rédigée par le pipeline multi-agents (PASS_WITH_FIXES) ; v2 mise à jour par le Lead sur la base des résultats réels des Phases 0-2
> Références : v1 + tous les commits des Phases 0-2 (git 111 commits) + enregistrements de revue à deux + base de tests mesurée

## 1. État des lieux (2026-08-17)

### 1.1 Progression des phases

| Phase | Statut | Livrables clés |
|------|------|----------|
| Phase 0 — colmatage | ✅ 4/4 | Rendu réel des factures, 6 types de modèles de notification, rapprochement explicitement « unverified », en-têtes CSP / modèles d'environnement |
| Phase 1 — court terme | ✅ 8/8 | Panier par quantité, uniformisation du statut des avis, rapprochement réel (rapports Stripe + par jour), validation des conditions de remboursement (72h/5 jours + idempotence + index TOCTOU), 7 types de webhooks fournisseur, câblage des Feature Flags + panneau d'administration, synchronisation des docs, tests réels |
| Phase 2 — moyen terme | ✅ 8/8 | 4 gardes financiers, dette de tests service/admin, install.sql 31 tables, montage RbacMiddleware sur 57 routes, admin intégré à l'image + nginx 8788 + CI double, régression audit + chaîne complète login |
| Phase 3 — long terme | ✅ 9/9 | Passerelle + limitation de débit unifiée (P4.1), chaîne complète multidevise (P4.2), ingénierie HarmonyOS + CI (P4.3), déploiement ES (P4.4), digestion des points d'observation (P4.5), 4 divergences documentaires (P3.1), resserrement des permissions (P3.2), clés d'idempotence de commande (P3.3), validation des notes fournisseur (P3.4), i18n 7 langues (P3.6) ; revue indépendante reviewer-gate entièrement approuvée |

### 1.2 Base de qualité (mesurée, vérification en série après commits)

- Suite service : **568 tests / 1279 assertions**, 10 skips (tous dus à des manques d'environnement DB)
- Suite admin : **255 tests / 887 assertions**, 1 skip (chemin d'écriture DB)
- CI 6 jobs : PHP Syntax / Admin Tests / Service Tests / Flutter Build / HarmonyOS Project Check / (liés à docker)
- Fonds/sécurité tous revus à deux (conclusions indépendantes security-auditor + reviewer identiques) ; commits git groupés par tâche, arbre de travail propre
- Bonus : sérialisation masquée des identifiants de 9 modèles Encryptable (audit complet P1/P2)

## 2. Liste des reliquats et risques (revue du 2026-08-17)

### 2.1 Éléments bloquant le déploiement (priorité haute)

- **Manque d'environnement DB_PASSWORD** : service/.env avec chaîne vide → tous les points de terminaison DB en 500, cause racine des 9+1 tests skip. Problème non lié au code, nécessite de remplir la valeur côté exploitation (le modèle dans le .env.example racine existe déjà)
- **Échafaudage du projet HarmonyOS manquant** : apps/harmonyos ne contient que 3 .ets (LoginPage/AuthManager/ApiClient), toute la configuration de projet hvigor/DevEco est absente → impossible de construire ; le contrôle CI harmonyos-check échoue honnêtement (exit 1)

### 2.2 Divergences documentation-code (4 éléments P1 non résolus)

- Filtrage par statut GET /api/v1/orders non implémenté
- Événements de push WebSocket absents (les docs websocket_push les déclarent)
- Périmètre de déclenchement de ticket.updated non défini
- product_attributes est un schéma mort (aucun code ne l'utilise)

### 2.3 Points d'observation fonds/sécurité (enregistrements de revue à deux, niveau low)

- **Aucune clé d'idempotence de commande** : la soumission répétée du même panier peut générer deux commandes (medium, planification recommandée)
- Les notes fournisseur ne vérifient pas l'appartenance/le statut de la commande
- Troncature bcmath des frais (5e décimale, sens de sous-perception <0,0001/transaction ; conforme au routage, aucun écart de rapprochement)
- Le WAF lit encore le body brut multipart volumineux (scénario json couvert par $input, multipart = surface de défense supplémentaire)
- user_coupons sans contrainte d'unicité (sémantiquement un utilisateur peut avoir plusieurs commandes/lignes, à observer)
- nginx-admin sans CSP (admin est un front-end Layui contenant des scripts inline, statu quo conservé)

### 2.4 Incohérences du modèle de permissions (nouvelle découverte P2, à resserrer)

- 6 identifiants de permission uniquement en DB / 19 uniquement en Rbac / différences d'attribution de rôles (support/supplier)
- AdminRoleMiddleware exclut finance, alors que Rbac.php définit le rôle finance

### 2.5 Autres

- Les nouveaux fichiers de langue i18n sont des traductions anglaises à l'identique (T6), les 7 langues ne sont pas terminées
- Le contrôle de structure CI HarmonyOS sera mis à niveau vers une vraie construction hvigor une fois l'échafaudage complété

## 3. Feuille de route

Principe de priorité (inchangé) : **fonds/sécurité > fiabilité de livraison > boucle métier principale > expérience et extension**.

### Phase 3 — Clôture des reliquats (1 mois)

**Objectif** : fermer toutes les divergences et points d'observation, déploiement reproductible (tous les tests de la chaîne complète DB réellement verts).

| Tâche | Concerne | Rôle | Dépendances |
|------|------|------|------|
| Clôture des 4 divergences documentation-code (implémentation du filtre de statut orders / câblage du push WebSocket / correction de ticket.updated / suppression ou implémentation de product_attributes) | Order、WebSocket、Ticket、Product、docs | coder + researcher | Aucune |
| Convergence du modèle de permissions (alignement des différences DB/Rbac + seed des rôles + revue AdminRoleMiddleware) | Rbac、install.sql、admin | coder + security-auditor | Aucune |
| Clé d'idempotence de commande (panier→commande anti-double) | OrderService | coder | Aucune (revue à deux pour les fonds) |
| Validation de l'appartenance/du statut de la commande pour les notes fournisseur | Supplier、Review | coder | Aucune |
| Raccordement exploitation DB_PASSWORD + exécution réelle des 10 tests skip | exploitation、tests | security-auditor | coopération exploitation |
| Complément des traductions i18n 7 langues | fichiers i18n | coder | Aucune |

**Recette** : 4 divergences fermées ; matrice de permissions DB/code cohérente ; test de clé d'idempotence ; tous les tests de la chaîne complète DB réellement verts ; i18n au moins zh/en utilisables.

### Phase 4 — Évolution de l'architecture (1-3 mois)

**Objectif** : architecture en quatre couches aboutie, support de la croissance multi-plateformes et multidevise.

| Tâche | Concerne | Rôle | Dépendances |
|------|------|------|------|
| Passerelle API indépendante + montage de la limitation de débit unifiée (y compris manque graphql) | gateway、route | architect + coder | P3 |
| Cohérence de la chaîne complète multidevise (y compris stratégie d'arrondi des frais) | Payment、Billing | architect + performance-engineer | idem |
| Ingénierie HarmonyOS : échafaudage + vraie construction CI + connexion aboutie | apps/harmonyos | mobile-dev | Aucune |
| Mise en production de l'audit ES, remplacement de la solution de contournement | docker、recherche Product | coder | Aucune |
| Digestion en masse des points d'observation (WAF multipart / contrainte user_coupons / webhooks fournisseur de bout en bout) | Security、Order、Supplier | coder + tester | Aucune |

**Recette** : k6 vérifie que la limitation de débit est effective sur toutes les routes ; calcul multidevise sans écart ; package HarmonyOS passant le CI ; recherche ES réellement utilisable.

## 4. Répartition de l'équipe

Noyau fixe : Lead(planner) / architect / coder / tester / reviewer / researcher
Recrutés à la demande : mobile-dev / security-architect / security-auditor / performance-engineer

| Phase | Rôles recrutés | Description |
|------|----------|------|
| P3 | coder (principal)、researcher、security-auditor | Clôture principalement ; revue à deux des permissions/idempotence |
| P4 | architect、coder、mobile-dev、performance-engineer | Évolution de l'architecture ; security-architect en consultant permanent |

Le mode de collaboration est inchangé : pipeline CLAUDE.md (architect→coder→tester→reviewer), fan-out parallèle des tâches internes P3/P4 ; **les tâches fonds/sécurité imposent une revue à deux** ; ce document est mis à jour à la fin de chaque phase (cette v2 a été rédigée directement par le Lead, sans passer par le pipeline, revue possible).

## 5. Méthode de suivi des risques

- Cette liste est mise à jour à chaque fin de phase ; les nouvelles découvertes (comme l'incohérence du modèle de permissions P2, l'idempotence des commandes) sont intégrées immédiatement
- Les faibles priorités connues (webhooks fournisseur de bout en bout, body multipart) sont déjà intégrées au lot de digestion P4, pas de dispersion hors de la liste

## 6. Principales sources de preuves

- Commits : git log (111 commits, Phases 0-2 groupés par tâche)
- Base de tests : sorties mesurées des suites service/admin
- Enregistrements de revue : messages de revue à deux P1/P2 (gardes financiers, logout/WAF, RBAC, régression audit)
- Documentation : v1 (historique de docs/team-plan.md)、docs/audit-report-2026-08-06-v3.md、docs/api-reference.md
