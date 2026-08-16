<?php
return [
    'ok' => 'Succès',
    'Unauthorized' => 'Authentification requise',
    'Forbidden' => 'Accès interdit',
    'Too Many Requests' => 'Trop de requêtes, veuillez réessayer plus tard',
    'Authentication required' => 'Veuillez vous connecter',
    'auth.login_success' => 'Connexion réussie',
    'auth.login_failed' => 'Email ou mot de passe invalide',
    'auth.register_success' => 'Inscription réussie',
    'auth.token_expired' => 'Token expiré',
    'Email required' => 'Email requis',
    'Invalid email format' => 'Format email invalide',
    'Invalid credentials' => 'Identifiants invalides',
    'Account temporarily locked' => 'Compte temporairement verrouillé',
    'Password has been reset successfully' => 'Mot de passe réinitialisé avec succès',
    'Email verified successfully' => 'Email vérifié avec succès',
    'Verification code sent' => 'Code de vérification envoyé',
    'Session revoked' => 'Session révoquée',
    'Invalid TOTP code' => 'Code TOTP invalide',
    'Two-factor authentication has been enabled' => 'Authentification à deux facteurs activée',
    'Captcha verification failed' => 'Échec de la vérification captcha',
    'Product created' => 'Produit créé',
    'Product deleted' => 'Produit supprimé',
    'Search query required' => 'Terme de recherche requis',
    'Added to cart' => 'Ajouté au panier',
    'Removed from cart' => 'Retiré du panier',
    'Order created' => 'Commande créée',
    'Order cannot be paid' => 'Cette commande ne peut pas être payée',
    'Refund request submitted' => 'Demande de remboursement soumise',
    'Coupon not found' => 'Coupon introuvable',
    'Invoice generated' => 'Facture générée',
    'No file uploaded' => 'Aucun fichier téléchargé',
    'KYC approved' => 'Vérification d\'identité approuvée',
    'KYC rejected' => 'Vérification d\'identité rejetée',
    'Ticket created' => 'Ticket créé',
    'Reply sent' => 'Réponse envoyée',
    'Ticket closed' => 'Ticket fermé',
    'Supplier approved' => 'Fournisseur approuvé',
    'Settlement generated' => 'Règlement généré',
    'Withdrawal requested' => 'Demande de retrait soumise',
    'error.server_error' => 'Erreur interne du serveur',
    'order.paid' => 'Paiement effectué',
    'resource.created' => 'Ressource provisionnée',
    'resource.destroyed' => 'Ressource supprimée',
    'validation.required' => ':field est requis',

    // ── Général ──
    'Forbidden: admin access required' => 'Accès interdit : droits d\'administration requis',
    'Forbidden: insufficient permissions' => 'Accès interdit : permissions insuffisantes',
    'Request blocked by WAF' => 'Requête bloquée par le pare-feu',
    'Request body too large' => 'Corps de requête trop volumineux (max 10 Mo)',
    'URI too long' => 'URI de requête trop long',
    'Unsupported Content-Type' => 'Content-Type non pris en charge',
    'Password confirmation required for this operation' => 'Confirmation du mot de passe requise pour cette opération',
    'Password verification failed' => 'Échec de la vérification du mot de passe',
    'Too many confirmation attempts, try again later' => 'Trop de tentatives de confirmation, réessayez dans 15 minutes',

    // ── Authentification ──
    'Login and password required' => 'Veuillez saisir votre email/téléphone et votre mot de passe',
    'Email or phone required, and password required' => 'Veuillez saisir votre email ou téléphone et définir un mot de passe',
    'Password must be at least 8 characters' => 'Le mot de passe doit contenir au moins 8 caractères',
    'Phone number required' => 'Numéro de téléphone requis',
    'Invalid or expired reset code' => 'Code de réinitialisation invalide ou expiré',
    'If the email exists, a reset code has been sent' => 'Si l\'email existe, un code de réinitialisation a été envoyé',
    'Email, code, and password are required' => 'Veuillez saisir votre email, le code et un nouveau mot de passe',
    'Email already verified' => 'Email déjà vérifié',
    'Invalid or expired verification token' => 'Lien de vérification invalide ou expiré',
    'Verification token required' => 'Jeton de vérification requis',
    'Verification email sent' => 'Email de vérification envoyé',
    'Please wait before requesting another code' => 'Veuillez patienter avant de demander un autre code',
    'Too many SMS requests' => 'Trop de demandes SMS',
    'Account deleted. Data will be retained per our retention policy.' => 'Compte supprimé. Les données seront conservées conformément à notre politique de conservation',
    'Password verification required' => 'Vérification du mot de passe requise',
    'Password verification required to disable 2FA' => 'Vérification du mot de passe requise pour désactiver la 2FA',

    // ── OAuth ──
    'Authorization code required' => 'Code d\'autorisation requis',
    'Invalid state' => 'Paramètre d\'état invalide',
    'Failed to obtain access token' => 'Échec de l\'obtention du jeton d\'accès',
    'Failed to obtain ID token' => 'Échec de l\'obtention du jeton d\'identité',
    'Email not provided by Apple. User may need to re-authorize.' => 'Apple n\'a pas fourni d\'email. Une réautorisation peut être nécessaire',
    'Registration failed' => 'Échec de l\'inscription',

    // ── TOTP ──
    'TOTP code required' => 'Code TOTP requis',
    'No pending TOTP setup found. Call totp/setup first.' => 'Aucune configuration TOTP en attente. Appelez d\'abord totp/setup',
    'Two-factor authentication has been disabled' => 'Authentification à deux facteurs désactivée',
    'Store these codes securely. Each code can only be used once.' => 'Conservez ces codes en lieu sûr. Chaque code ne peut être utilisé qu\'une seule fois',
    'No recovery codes available. TOTP may not be enabled.' => 'Aucun code de récupération disponible. TOTP n\'est peut-être pas activé',
    'Invalid recovery code' => 'Code de récupération invalide',
    'Login, password, and recovery code required' => 'Veuillez saisir email/téléphone, mot de passe et code de récupération',
    'TOTP is not enabled' => 'TOTP n\'est pas activé',

    // ── CAPTCHA ──
    'Captcha generation failed' => 'Échec de la génération du captcha',

    // ── Produits ──
    'SKU created' => 'SKU créé',
    'Region price set' => 'Prix régional défini',

    // ── Commandes ──
    'Order cannot be refunded' => 'Cette commande ne peut pas être remboursée',

    // ── Coupons ──
    'Coupon code required' => 'Code promo requis',
    'Coupon is expired or has reached usage limit' => 'Le coupon a expiré ou a atteint sa limite d\'utilisation',

    // ── Factures ──
    'Invoice already exists' => 'La facture existe déjà',
    'Invoice can only be generated for paid/completed orders' => 'La facture ne peut être générée que pour les commandes payées ou terminées',

    // ── Paiement ──
    'Unsupported payment channel' => 'Moyen de paiement non pris en charge',

    // ── Ressources ──
    'Resource IDs required' => 'ID de ressource requis',

    // ── Téléversement de fichiers ──
    'File type not allowed: ' => 'Type de fichier non autorisé : ',
    'File too large. Max: ' => 'Fichier trop volumineux. Maximum : ',
    'filename' => 'nom de fichier',
    'errors' => 'erreurs',
    'imported' => 'importé',

    // ── KYC ──
    'KYC submitted for review' => 'Vérification d\'identité soumise pour examen',
    'KYC already submitted or approved' => 'Vérification d\'identité déjà soumise ou approuvée',

    // ── Tickets ──
    'Ticket assigned' => 'Ticket assigné',

    // ── DNS ──
    'Invalid priority' => 'Priorité invalide',

    // ── Notifications ──
    'Notification preferences updated' => 'Préférences de notification mises à jour',
    'Invalid preferences format' => 'Format de préférences invalide',

    // ── Fournisseur ──
    'All fields are required' => 'Tous les champs obligatoires doivent être remplis',
    'You already have a supplier application' => 'Vous avez déjà soumis une demande de fournisseur',
    'Product already assigned to this supplier' => 'Ce produit est déjà assigné à ce fournisseur',
    'Withdrawal approved' => 'Retrait approuvé',
    'Insufficient withdrawable balance' => 'Solde retirable insuffisant',
    'API key created. Store it securely.' => 'Clé API créée. Conservez-la en lieu sûr (affichée une seule fois)',
    'api_key' => 'Clé API',
    'prefix' => 'préfixe',
    'Missing or invalid API key format' => 'Format de clé API invalide',
    'Invalid or revoked API key' => 'Clé API invalide ou révoquée',
    'Invalid token' => 'Jeton invalide',
    'Invalid token type' => 'Type de jeton invalide',
    'Token revoked' => 'Jeton révoqué',

    // ── Webhook ──
    'Webhook registered' => 'Webhook enregistré',
    'Webhook removed' => 'Webhook supprimé',
    'Test webhook sent' => 'Webhook de test envoyé',
    'Valid URL required' => 'URL valide requise',

    // ── Administration ──
    'Config updated' => 'Configuration mise à jour',
    'CSV file required' => 'Veuillez télécharger un fichier CSV',
    'Invalid action. Allowed: ' => 'Action invalide. Autorisées : ',

    // ── Système ──
    'Access denied for your region' => 'Accès refusé pour votre région',
    'Transfer approved' => 'Transfert de domaine approuvé',
    'Unknown feature: ' => 'Fonctionnalité inconnue : ',
    'Unknown feature: {name}' => 'Fonctionnalité inconnue : {name}',
    'order.cancelled' => 'Commande annulée',
];
