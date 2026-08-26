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
     * Master key — 32 bytes, base64-encoded in ENCRYPTION_MASTER_KEY
     * (same format as service instance; decoded here for the factory).
     */
    'master_key' => base64_decode(env('ENCRYPTION_MASTER_KEY') ?: '', true),

];
