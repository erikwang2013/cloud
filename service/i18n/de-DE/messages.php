<?php
return [
    'ok' => 'Erfolg',
    'Unauthorized' => 'Authentifizierung erforderlich',
    'Forbidden' => 'Zugriff verboten',
    'Too Many Requests' => 'Zu viele Anfragen, bitte später erneut versuchen',
    'Authentication required' => 'Bitte melden Sie sich an',
    'auth.login_success' => 'Anmeldung erfolgreich',
    'auth.login_failed' => 'Ungültige E-Mail oder Passwort',
    'auth.register_success' => 'Registrierung erfolgreich',
    'auth.token_expired' => 'Token abgelaufen',
    'Email required' => 'E-Mail ist erforderlich',
    'Invalid email format' => 'Ungültiges E-Mail-Format',
    'Invalid credentials' => 'Ungültige Anmeldedaten',
    'Account temporarily locked' => 'Konto vorübergehend gesperrt',
    'Password has been reset successfully' => 'Passwort erfolgreich zurückgesetzt',
    'Email verified successfully' => 'E-Mail erfolgreich bestätigt',
    'Verification code sent' => 'Bestätigungscode gesendet',
    'Session revoked' => 'Sitzung beendet',
    'Invalid TOTP code' => 'Ungültiger TOTP-Code',
    'Two-factor authentication has been enabled' => 'Zwei-Faktor-Authentifizierung aktiviert',
    'Captcha verification failed' => 'Captcha-Überprüfung fehlgeschlagen',
    'Product created' => 'Produkt erstellt',
    'Product deleted' => 'Produkt gelöscht',
    'Search query required' => 'Suchbegriff erforderlich',
    'Added to cart' => 'Zum Warenkorb hinzugefügt',
    'Removed from cart' => 'Aus Warenkorb entfernt',
    'Order created' => 'Bestellung erstellt',
    'Order cannot be paid' => 'Bestellung kann nicht bezahlt werden',
    'Refund request submitted' => 'Rückerstattung beantragt',
    'Coupon not found' => 'Gutschein nicht gefunden',
    'Invoice generated' => 'Rechnung erstellt',
    'No file uploaded' => 'Keine Datei hochgeladen',
    'KYC approved' => 'Identitätsprüfung genehmigt',
    'KYC rejected' => 'Identitätsprüfung abgelehnt',
    'Ticket created' => 'Ticket erstellt',
    'Reply sent' => 'Antwort gesendet',
    'Ticket closed' => 'Ticket geschlossen',
    'Supplier approved' => 'Lieferant genehmigt',
    'Settlement generated' => 'Abrechnung erstellt',
    'Withdrawal requested' => 'Auszahlung beantragt',
    'error.server_error' => 'Interner Serverfehler',
    'order.paid' => 'Zahlung erfolgreich',
    'resource.created' => 'Ressource bereitgestellt',
    'resource.destroyed' => 'Ressource gelöscht',
    'validation.required' => ':field ist erforderlich',

    // ── Allgemein ──
    'Forbidden: admin access required' => 'Zugriff verboten: Administratorrechte erforderlich',
    'Forbidden: insufficient permissions' => 'Zugriff verboten: unzureichende Berechtigungen',
    'Request blocked by WAF' => 'Anfrage von der WAF blockiert',
    'Request body too large' => 'Anfragekörper zu groß (max. 10 MB)',
    'URI too long' => 'Anfrage-URI zu lang',
    'Unsupported Content-Type' => 'Content-Type wird nicht unterstützt',
    'Password confirmation required for this operation' => 'Für diesen Vorgang ist eine Passwortbestätigung erforderlich',
    'Password verification failed' => 'Passwortüberprüfung fehlgeschlagen',
    'Too many confirmation attempts, try again later' => 'Zu viele Bestätigungsversuche, versuchen Sie es in 15 Minuten erneut',

    // ── Authentifizierung ──
    'Login and password required' => 'Bitte E-Mail/Telefonnummer und Passwort eingeben',
    'Email or phone required, and password required' => 'Bitte E-Mail oder Telefonnummer und Passwort angeben',
    'Password must be at least 8 characters' => 'Das Passwort muss mindestens 8 Zeichen lang sein',
    'Phone number required' => 'Telefonnummer erforderlich',
    'Invalid or expired reset code' => 'Zurücksetzungscode ungültig oder abgelaufen',
    'If the email exists, a reset code has been sent' => 'Wenn die E-Mail existiert, wurde ein Zurücksetzungscode gesendet',
    'Email, code, and password are required' => 'Bitte E-Mail, Code und neues Passwort angeben',
    'Email already verified' => 'E-Mail bereits bestätigt',
    'Invalid or expired verification token' => 'Bestätigungslink ungültig oder abgelaufen',
    'Verification token required' => 'Bestätigungstoken erforderlich',
    'Verification email sent' => 'Bestätigungs-E-Mail gesendet',
    'Please wait before requesting another code' => 'Bitte warten, bevor Sie einen weiteren Code anfordern',
    'Too many SMS requests' => 'Zu viele SMS-Anfragen',
    'Account deleted. Data will be retained per our retention policy.' => 'Konto gelöscht. Daten werden gemäß unserer Aufbewahrungsrichtlinie aufbewahrt',
    'Password verification required' => 'Passwortüberprüfung erforderlich',
    'Password verification required to disable 2FA' => 'Zum Deaktivieren der 2FA ist eine Passwortüberprüfung erforderlich',

    // ── OAuth ──
    'Authorization code required' => 'Autorisierungscode erforderlich',
    'Invalid state' => 'Ungültiger Statusparameter',
    'Failed to obtain access token' => 'Zugriffstoken konnte nicht abgerufen werden',
    'Failed to obtain ID token' => 'ID-Token konnte nicht abgerufen werden',
    'Email not provided by Apple. User may need to re-authorize.' => 'Apple hat keine E-Mail bereitgestellt. Möglicherweise ist eine erneute Autorisierung erforderlich',
    'Registration failed' => 'Registrierung fehlgeschlagen',

    // ── TOTP ──
    'TOTP code required' => 'TOTP-Code erforderlich',
    'No pending TOTP setup found. Call totp/setup first.' => 'Keine ausstehende TOTP-Einrichtung gefunden. Rufen Sie zuerst totp/setup auf',
    'Two-factor authentication has been disabled' => 'Zwei-Faktor-Authentifizierung deaktiviert',
    'Store these codes securely. Each code can only be used once.' => 'Bewahren Sie diese Codes sicher auf. Jeder Code kann nur einmal verwendet werden',
    'No recovery codes available. TOTP may not be enabled.' => 'Keine Wiederherstellungscodes verfügbar. TOTP ist möglicherweise nicht aktiviert',
    'Invalid recovery code' => 'Ungültiger Wiederherstellungscode',
    'Login, password, and recovery code required' => 'Bitte E-Mail/Telefonnummer, Passwort und Wiederherstellungscode angeben',
    'TOTP is not enabled' => 'TOTP ist nicht aktiviert',

    // ── CAPTCHA ──
    'Captcha generation failed' => 'Captcha-Generierung fehlgeschlagen',

    // ── Produkte ──
    'SKU created' => 'SKU erstellt',
    'Region price set' => 'Regionalpreis festgelegt',

    // ── Bestellungen ──
    'Order cannot be refunded' => 'Diese Bestellung kann nicht erstattet werden',

    // ── Gutscheine ──
    'Coupon code required' => 'Gutscheincode erforderlich',
    'Coupon is expired or has reached usage limit' => 'Gutschein ist abgelaufen oder hat das Nutzungslimit erreicht',

    // ── Rechnungen ──
    'Invoice already exists' => 'Rechnung existiert bereits',
    'Invoice can only be generated for paid/completed orders' => 'Rechnungen können nur für bezahlte oder abgeschlossene Bestellungen erstellt werden',

    // ── Zahlung ──
    'Unsupported payment channel' => 'Nicht unterstützter Zahlungsweg',

    // ── Ressourcen ──
    'Resource IDs required' => 'Ressourcen-IDs erforderlich',

    // ── Datei-Upload ──
    'File type not allowed: ' => 'Dateityp nicht erlaubt: ',
    'File too large. Max: ' => 'Datei zu groß. Maximum: ',
    'filename' => 'Dateiname',
    'errors' => 'Fehler',
    'imported' => 'importiert',

    // ── KYC ──
    'KYC submitted for review' => 'Identitätsprüfung zur Überprüfung eingereicht',
    'KYC already submitted or approved' => 'Identitätsprüfung bereits eingereicht oder genehmigt',

    // ── Tickets ──
    'Ticket assigned' => 'Ticket zugewiesen',

    // ── DNS ──
    'Invalid priority' => 'Ungültige Priorität',

    // ── Benachrichtigungen ──
    'Notification preferences updated' => 'Benachrichtigungseinstellungen aktualisiert',
    'Invalid preferences format' => 'Ungültiges Einstellungsformat',

    // ── Lieferant ──
    'All fields are required' => 'Alle Pflichtfelder müssen ausgefüllt werden',
    'You already have a supplier application' => 'Sie haben bereits einen Lieferantenantrag eingereicht',
    'Product already assigned to this supplier' => 'Dieses Produkt ist diesem Lieferanten bereits zugewiesen',
    'Withdrawal approved' => 'Auszahlung genehmigt',
    'Insufficient withdrawable balance' => 'Unzureichendes auszahlbares Guthaben',
    'API key created. Store it securely.' => 'API-Schlüssel erstellt. Bewahren Sie ihn sicher auf (nur einmal angezeigt)',
    'api_key' => 'API-Schlüssel',
    'prefix' => 'Präfix',
    'Missing or invalid API key format' => 'Ungültiges API-Schlüsselformat',
    'Invalid or revoked API key' => 'API-Schlüssel ungültig oder widerrufen',
    'Invalid token' => 'Ungültiges Token',
    'Invalid token type' => 'Ungültiger Token-Typ',
    'Token revoked' => 'Token widerrufen',

    // ── Webhook ──
    'Webhook registered' => 'Webhook registriert',
    'Webhook removed' => 'Webhook entfernt',
    'Test webhook sent' => 'Test-Webhook gesendet',
    'Valid URL required' => 'Gültige URL erforderlich',

    // ── Administration ──
    'Config updated' => 'Konfiguration aktualisiert',
    'CSV file required' => 'Bitte eine CSV-Datei hochladen',
    'Invalid action. Allowed: ' => 'Ungültige Aktion. Zulässig: ',

    // ── System ──
    'Access denied for your region' => 'Zugriff für Ihre Region verweigert',
    'Transfer approved' => 'Domain-Transfer genehmigt',
    'Unknown feature: ' => 'Unbekannte Funktion: ',
    'Unknown feature: {name}' => 'Unbekannte Funktion: {name}',
    'order.cancelled' => 'Bestellung storniert',
];
