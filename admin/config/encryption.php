<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * Application-level encryption configuration.
 *
 * Uses the erikwang2013/encryption package for encrypting/decrypting API data.
 * The EncryptionManager factory derives per-algorithm keys from the master key
 * using HMAC-SHA256, preventing key reuse across algorithms.
 *
 * Supported algorithms: aes-256-gcm, aes-256-cbc, sodium-xchacha20
 *
 * @see https://github.com/erikwang2013/encryption
 */
return [

    /**
     * Default encryptor algorithm identifier.
     */
    'default' => env('ENCRYPTION_DEFAULT', 'aes-256-gcm'),

    /**
     * Master key — must be exactly 32 bytes (64 hex characters).
     * Set via ENCRYPTION_MASTER_KEY environment variable.
     */
    'master_key' => env('ENCRYPTION_MASTER_KEY'),

];
