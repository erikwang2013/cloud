<?php
/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * Here is your custom functions.
 */

use app\model\Admin;
use app\model\AdminRole;
use support\Response;

/**
 * 当前管理员id
 * @return integer|null
 */
function admin_id(): ?int
{
    return session('admin.id');
}

/**
 * 当前管理员
 * @param null|array|string $fields
 * @return array|mixed|null
 * @throws Exception
 */
function admin($fields = null)
{
    refresh_admin_session();
    if (!$admin = session('admin')) {
        return null;
    }
    if ($fields === null) {
        return $admin;
    }
    if (is_array($fields)) {
        $results = [];
        foreach ($fields as $field) {
            $results[$field] = $admin[$field] ?? null;
        }
        return $results;
    }
    return $admin[$fields] ?? null;
}


/**
 * 刷新当前管理员session
 * @param bool $force
 * @return void
 * @throws Exception
 */
function refresh_admin_session(bool $force = false)
{
    $admin_session = session('admin');
    if (!$admin_session) {
        return null;
    }
    $admin_id = $admin_session['id'];
    $time_now = time();
    // session在2秒内不刷新
    $session_ttl = 2;
    $session_last_update_time = session('admin.session_last_update_time', 0);
    if (!$force && $time_now - $session_last_update_time < $session_ttl) {
        return null;
    }
    $session = request()->session();
    $admin = Admin::find($admin_id);
    if (!$admin) {
        $session->forget('admin');
        return null;
    }
    $admin = $admin->toArray();
    $admin['password'] = md5($admin['password']);
    $admin_session['password'] = $admin_session['password'] ?? '';
    if ($admin['password'] != $admin_session['password']) {
        $session->forget('admin');
        return null;
    }
    // 账户被禁用
    if ($admin['status'] != 0) {
        $session->forget('admin');
        return;
    }
    $admin['roles'] = AdminRole::where('admin_id', $admin_id)->pluck('role_id')->toArray();
    $admin['session_last_update_time'] = $time_now;
    $session->set('admin', $admin);
}

function admin_error_401_script(): Response
{
  return response(<<<EOF
<script>top.location.href = '/app/admin';</script>
EOF
  );
}

/**
 * Encode a numeric ID to a hashid string.
 */
function hashids_encode(int $id): string
{
    return \support\Container::instance()->get(\Erikwang2013\Hashids\HashidsManager::class)->encode($id);
}

/**
 * Decode a hashid string back to the original numeric ID.
 * Returns 0 if decoding fails.
 */
function hashids_decode(string $hash): int
{
    $result = \support\Container::instance()->get(\Erikwang2013\Hashids\HashidsManager::class)->decode($hash);
    return $result[0] ?? 0;
}

/**
 * Recursively walk an array and encode all fields named 'id' or ending in '_id'
 * (or '_ids') that contain positive integers.
 */
function hashids_encode_ids(array $data): array
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = hashids_encode_ids($value);
        } elseif (($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_ids')) && is_numeric($value) && (int) $value > 0 && $value == (int) $value) {
            $data[$key] = hashids_encode((int) $value);
        }
    }
    return $data;
}

/**
 * Encrypt plaintext using the default encryptor from EncryptionManager.
 */
function encrypt_data(string $plaintext): string
{
    $container = \support\Container::instance();
    if (!$container->has(\Erikwang2013\Encryption\EncryptionManager::class)) {
        throw new \RuntimeException('Encryption not configured: set ENCRYPTION_MASTER_KEY in .env');
    }
    return $container->get(\Erikwang2013\Encryption\EncryptionManager::class)->encrypt($plaintext);
}

/**
 * Decrypt ciphertext using the default encryptor from EncryptionManager.
 */
function decrypt_data(string $ciphertext): string
{
    $container = \support\Container::instance();
    if (!$container->has(\Erikwang2013\Encryption\EncryptionManager::class)) {
        throw new \RuntimeException('Encryption not configured: set ENCRYPTION_MASTER_KEY in .env');
    }
    return $container->get(\Erikwang2013\Encryption\EncryptionManager::class)->decrypt($ciphertext);
}
