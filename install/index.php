<?php
/**
 * CloudPlatform — Installation Wizard
 * Self-contained single-file installer. No framework dependencies.
 */
declare(strict_types=1);
define('ROOT_DIR', dirname(__DIR__));

// ─── Session security ───
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
session_start();

$error = '';
$success = '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// 1-based step from POST. Enforce sequence server-side via session.
$requestedStep = max(1, (int)($_POST['step'] ?? 1));
$maxReached = (int)($_SESSION['max_step'] ?? 1);
$step = min($requestedStep, $maxReached);

// ─── CSRF ───
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

if ($isPost) {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $submittedCsrf)) {
        $error = 'Invalid request. Please refresh the page and try again.';
        $step = $maxReached; // Stay on current step
        goto render;
    }
}

// ─── Reinstallation guard ───
$envExists = file_exists(ROOT_DIR . '/service/.env') || file_exists(ROOT_DIR . '/admin/.env');

// ─── Step 1: Environment Check ───
if ($isPost && $requestedStep === 1) {
    $checks = envChecks();
    $allPass = !in_array(false, array_column($checks, 'pass'), true);
    if ($allPass) {
        $_SESSION['max_step'] = 2;
        $step = 2;
    } else {
        $error = 'Some environment checks failed. Please install the missing requirements before continuing.';
    }
}

// ─── Step 2: Database Configuration ───
if ($isPost && $requestedStep === 2) {
    $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? 'cloud_platform');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $dbCreate = !empty($_POST['db_create']);

    // Validate inputs
    $dbErrors = [];
    if (!preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_]{0,63}$/', $dbName)) {
        $dbErrors[] = 'Invalid database name. Use only letters, numbers, and underscores (max 64 chars).';
    }
    if ((int)$dbPort < 1 || (int)$dbPort > 65535) {
        $dbErrors[] = 'Invalid port number (1-65535).';
    }
    if (empty($dbUser)) {
        $dbErrors[] = 'Database username is required.';
    }

    if (empty($dbErrors)) {
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            if (version_compare($version, '8.0', '<')) {
                throw new RuntimeException("MySQL 8.0+ required. Detected: {$version}");
            }

            if ($dbCreate) {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }

            $pdo->exec("USE `{$dbName}`");

            // Check for existing installation
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_name = 'wa_admins'");
            if ($stmt->fetchColumn() > 0) {
                $error = 'Tables already exist in this database. It appears CloudPlatform is already installed. To reinstall, drop all tables first.';
                $_SESSION['db_form'] = compact('dbHost', 'dbPort', 'dbName', 'dbUser', 'dbPass', 'dbCreate');
                goto render;
            }

            // Store credentials and mark DB step complete
            $_SESSION['db'] = compact('dbHost', 'dbPort', 'dbName', 'dbUser', 'dbPass');
            session_regenerate_id(true);
            $_SESSION['max_step'] = 3;
            $step = 3;
        } catch (Exception $e) {
            error_log("CloudPlatform install wizard DB error [{$dbHost}:{$dbPort}]: " . $e->getMessage());
            $error = 'Database connection failed. Please verify host, port, username, and password.';
            if (stripos($e->getMessage(), 'password') !== false) {
                $error .= '<br><small>Tip: If your password contains special characters like #, try escaping or quoting.</small>';
            }
            $_SESSION['db_form'] = compact('dbHost', 'dbPort', 'dbName', 'dbUser', 'dbPass', 'dbCreate');
        }
    } else {
        $error = implode('<br>', $dbErrors);
        $_SESSION['db_form'] = compact('dbHost', 'dbPort', 'dbName', 'dbUser', 'dbPass', 'dbCreate');
    }
}

// ─── Step 3: Admin Account ───
if ($isPost && $requestedStep === 3) {
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminPass = $_POST['admin_pass'] ?? '';
    $adminPass2 = $_POST['admin_pass2'] ?? '';
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminNick = trim($_POST['admin_nick'] ?? 'Administrator');

    if (empty($adminUser) || empty($adminPass) || empty($adminEmail)) {
        $error = 'All fields are required.';
        $_SESSION['admin_form'] = compact('adminUser', 'adminEmail', 'adminNick');
    } elseif (strlen($adminUser) < 3) {
        $error = 'Username must be at least 3 characters.';
        $_SESSION['admin_form'] = compact('adminUser', 'adminEmail', 'adminNick');
    } elseif (strlen($adminPass) < 8) {
        $error = 'Password must be at least 8 characters.';
        $_SESSION['admin_form'] = compact('adminUser', 'adminEmail', 'adminNick');
    } elseif (!preg_match('/[A-Z]/', $adminPass) && !preg_match('/[a-z]/', $adminPass)) {
        $error = 'Password must contain at least one letter.';
        $_SESSION['admin_form'] = compact('adminUser', 'adminEmail', 'adminNick');
    } elseif (!preg_match('/[0-9]/', $adminPass) && !preg_match('/[^a-zA-Z0-9]/', $adminPass)) {
        $error = 'Password must contain at least one number or special character.';
        $_SESSION['admin_form'] = compact('adminUser', 'adminEmail', 'adminNick');
    } elseif ($adminPass !== $adminPass2) {
        $error = 'Passwords do not match.';
        $_SESSION['admin_form'] = compact('adminUser', 'adminEmail', 'adminNick');
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
        $_SESSION['admin_form'] = compact('adminUser', 'adminEmail', 'adminNick');
    } else {
        // Hash password immediately; store hash in session, not plaintext
        $_SESSION['admin'] = [
            'adminUser' => $adminUser,
            'adminPass' => $adminPass, // Needed for step 4 insert only; cleared after
            'adminEmail' => $adminEmail,
            'adminNick' => $adminNick,
        ];
        $_SESSION['max_step'] = 4;
        $step = 4;
    }
}

// ─── Step 4: Execute Installation ───
if ($isPost && $requestedStep === 4) {
    if (empty($_SESSION['db']) || empty($_SESSION['admin'])) {
        $error = 'Session expired. Please restart the wizard.';
        session_destroy();
        $step = 1;
        $_SESSION['max_step'] = 1;
    } else {
        try {
            $db = $_SESSION['db'];
            $admin = $_SESSION['admin'];

            $dsn = "mysql:host={$db['dbHost']};port={$db['dbPort']};dbname={$db['dbName']};charset=utf8mb4";
            $pdo = new PDO($dsn, $db['dbUser'], $db['dbPass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // Import SQL
            $sqlFile = ROOT_DIR . '/install.sql';
            if (!file_exists($sqlFile)) {
                throw new RuntimeException("install.sql not found at: {$sqlFile}");
            }
            $sql = file_get_contents($sqlFile);

            // Generate shared keys once (encryption + hashids salt)
            [$jwtKey, $masterKey, $fieldKey] = generateKeys();
            $hashidsSalt = bin2hex(random_bytes(16));

            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);

                // Seed super-admin role
                $roleId = snowflakeId();
                $now = date('Y-m-d H:i:s');
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM wa_roles WHERE `rules` = '*'");
                $stmt->execute();
                if ($stmt->fetchColumn() == 0) {
                    $stmt = $pdo->prepare("INSERT INTO wa_roles (id, name, rules, created_at, updated_at, pid) VALUES (?, 'SuperAdmin', '*', ?, ?, NULL)");
                    $stmt->execute([$roleId, $now, $now]);
                } else {
                    $stmt = $pdo->query("SELECT id FROM wa_roles WHERE `rules` = '*' LIMIT 1");
                    $roleId = $stmt->fetchColumn();
                }

                // Create admin user
                $adminId = snowflakeId();
                $passwordHash = password_hash($admin['adminPass'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO wa_admins (id, username, nickname, password, email, created_at, updated_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
                $stmt->execute([$adminId, $admin['adminUser'], $admin['adminNick'], $passwordHash, $admin['adminEmail'], $now, $now]);

                // Assign super-admin role
                $arId = snowflakeId();
                $stmt = $pdo->prepare("INSERT INTO wa_admin_roles (id, role_id, admin_id) VALUES (?, ?, ?)");
                $stmt->execute([$arId, $roleId, $adminId]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            // Write .env files with shared keys
            $serviceResult = file_put_contents(ROOT_DIR . '/service/.env', generateServiceEnv($db, $jwtKey, $masterKey, $fieldKey, $hashidsSalt));
            $adminResult = file_put_contents(ROOT_DIR . '/admin/.env', generateAdminEnv($db, $masterKey, $fieldKey, $hashidsSalt));

            if ($serviceResult === false) {
                throw new RuntimeException('Failed to write service/.env. Check directory permissions: ' . ROOT_DIR . '/service');
            }
            if ($adminResult === false) {
                throw new RuntimeException('Failed to write admin/.env. Check directory permissions: ' . ROOT_DIR . '/admin');
            }

            $success = 'Installation complete!';
            session_destroy();
        } catch (Exception $e) {
            error_log("CloudPlatform install wizard install error: " . $e->getMessage());
            $error = 'Installation failed: ' . $e->getMessage();
        }
    }
}

// ─── Gather step info for rendering ───
render:

// ─── Helpers ───

function snowflakeId(): int
{
    static $lastTimestamp = 0, $sequence = 0;
    $timestamp = (int)(microtime(true) * 1000) - 1704067200000;
    if ($timestamp === $lastTimestamp) {
        $sequence = ($sequence + 1) & 0xFFF;
        if ($sequence === 0) {
            usleep(1);
            return snowflakeId();
        }
    } else {
        $sequence = 0;
        $lastTimestamp = $timestamp;
    }
    return ($timestamp << 22) | (0 << 17) | (0 << 12) | $sequence;
}

function generateKeys(): array
{
    $jwt = trim(shell_exec('openssl rand -base64 32 2>/dev/null') ?: '');
    if (empty($jwt)) $jwt = base64_encode(random_bytes(32));
    $master = trim(shell_exec('openssl rand -base64 32 2>/dev/null') ?: '');
    if (empty($master)) $master = base64_encode(random_bytes(32));
    $field = base64_encode(random_bytes(16));
    return [$jwt, $master, $field];
}

function generateServiceEnv(array $db, string $jwt, string $master, string $field, string $hashidsSalt): string
{
    $p = fn($v) => $db[$v] ?? '';
    return <<<ENV
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_TIMEZONE=UTC

DB_HOST={$p('dbHost')}
DB_PORT={$p('dbPort')}
DB_DATABASE={$p('dbName')}
DB_USERNAME={$p('dbUser')}
DB_PASSWORD={$p('dbPass')}

AUDIT_DB_HOST={$p('dbHost')}
AUDIT_DB_PORT={$p('dbPort')}
AUDIT_DB_DATABASE={$p('dbName')}_audit
AUDIT_DB_USERNAME={$p('dbUser')}
AUDIT_DB_PASSWORD={$p('dbPass')}

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

HASHIDS_SALT={$hashidsSalt}
HASHIDS_LENGTH=12

SNOWFLAKE_WORKER_ID=0
SNOWFLAKE_DATACENTER_ID=0
SNOWFLAKE_EPOCH=1704067200000

JWT_SECRET_KEY={$jwt}
JWT_ALGORITHM=HS256
JWT_ISSUER=cloud-platform
JWT_AUDIENCE=
JWT_ACCESS_TTL=900
JWT_REFRESH_TTL=2592000
JWT_STORAGE_TYPE=redis
JWT_STORAGE_PREFIX=jwt_blacklist:
JWT_STORAGE_DATABASE=0
JWT_LEEWAY=0

ENCRYPTION_MASTER_KEY={$master}

ENCRYPTION_KEY={$field}
ENCRYPTION_CIPHER=aes-128-ecb
ENCRYPTION_PREVIOUS_KEYS=

SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USERNAME=apikey
SMTP_PASSWORD=
SMTP_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME=CloudPlatform

STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=

ELASTICSEARCH_HOSTS=http://127.0.0.1:9200
ELASTICSEARCH_USERNAME=
ELASTICSEARCH_PASSWORD=
ELASTICSEARCH_SSL_VERIFICATION=false
SCOUT_PREFIX=

TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_PHONE_NUMBER=

FIREBASE_CREDENTIALS_PATH=

CAPTCHA_STORAGE=auto
CAPTCHA_TTL=300
CAPTCHA_MAX_ATTEMPTS=3
CAPTCHA_DIFFICULTY=medium
CAPTCHA_REDIS_PREFIX=poster:captcha:

SENTRY_DSN=
SENTRY_TRACES_RATE=0.1
SENTRY_PROFILES_RATE=0.05

FEATURE_SUPPLIER_API=false
FEATURE_WEBSOCKET=false
FEATURE_MAINTENANCE_REDIRECT=false
FEATURE_TOTP=true
FEATURE_GOOGLE_OAUTH=true
FEATURE_APPLE_OAUTH=true
ENV;
}

function generateAdminEnv(array $db, string $master, string $field, string $hashidsSalt): string
{
    $p = fn($v) => $db[$v] ?? '';
    return <<<ENV
APP_NAME=CloudPlatform
APP_DEBUG=false
APP_TIMEZONE=UTC

DB_HOST={$p('dbHost')}
DB_PORT={$p('dbPort')}
DB_DATABASE={$p('dbName')}
DB_USERNAME={$p('dbUser')}
DB_PASSWORD={$p('dbPass')}

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

HASHIDS_SALT={$hashidsSalt}
HASHIDS_LENGTH=12

SNOWFLAKE_WORKER_ID=0
SNOWFLAKE_DATACENTER_ID=0
SNOWFLAKE_EPOCH=1704067200000

ENCRYPTION_KEY={$field}
ENCRYPTION_CIPHER=aes-128-ecb

ELASTICSEARCH_HOSTS=http://127.0.0.1:9200
ELASTICSEARCH_USERNAME=
ELASTICSEARCH_PASSWORD=
ELASTICSEARCH_SSL_VERIFICATION=false
SCOUT_PREFIX=

ENCRYPTION_MASTER_KEY={$master}
ENV;
}

function envChecks(): array
{
    $checks = [];
    $checks[] = ['name' => 'PHP Version >= 8.2', 'pass' => version_compare(PHP_VERSION, '8.2', '>='), 'value' => PHP_VERSION];
    $checks[] = ['name' => 'PDO Extension', 'pass' => extension_loaded('pdo'), 'value' => extension_loaded('pdo') ? 'Loaded' : 'Missing'];
    $checks[] = ['name' => 'PDO MySQL Driver', 'pass' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? 'Loaded' : 'Missing'];
    $checks[] = ['name' => 'JSON Extension', 'pass' => extension_loaded('json'), 'value' => extension_loaded('json') ? 'Loaded' : 'Missing'];
    $checks[] = ['name' => 'Redis Extension', 'pass' => extension_loaded('redis'), 'value' => extension_loaded('redis') ? 'Loaded' : 'Missing'];
    $checks[] = ['name' => 'OpenSSL Extension', 'pass' => extension_loaded('openssl'), 'value' => extension_loaded('openssl') ? 'Loaded' : 'Missing'];
    $checks[] = ['name' => 'MBString Extension', 'pass' => extension_loaded('mbstring'), 'value' => extension_loaded('mbstring') ? 'Loaded' : 'Missing'];
    $checks[] = ['name' => 'cURL Extension', 'pass' => extension_loaded('curl'), 'value' => extension_loaded('curl') ? 'Loaded' : 'Missing'];
    $checks[] = ['name' => 'FileInfo Extension', 'pass' => extension_loaded('fileinfo'), 'value' => extension_loaded('fileinfo') ? 'Loaded' : 'Missing'];
    // Corrected writable check: parent dir must be writable if file doesn't exist yet
    $svcWritable = is_writable(ROOT_DIR . '/service') || (file_exists(ROOT_DIR . '/service/.env') && is_writable(ROOT_DIR . '/service/.env'));
    $admWritable = is_writable(ROOT_DIR . '/admin') || (file_exists(ROOT_DIR . '/admin/.env') && is_writable(ROOT_DIR . '/admin/.env'));
    $checks[] = ['name' => 'service/.env writable', 'pass' => $svcWritable, 'value' => $svcWritable ? 'OK' : 'Not writable'];
    $checks[] = ['name' => 'admin/.env writable', 'pass' => $admWritable, 'value' => $admWritable ? 'OK' : 'Not writable'];
    return $checks;
}

// ─── Restore form state from session ───
$dbHost = $dbPort = $dbName = $dbUser = $dbPass = $dbCreate = null;
$adminUser = $adminEmail = $adminNick = null;

if (isset($_SESSION['db_form'])) {
    $f = $_SESSION['db_form'];
    $dbHost = $f['dbHost'] ?? null;
    $dbPort = $f['dbPort'] ?? null;
    $dbName = $f['dbName'] ?? null;
    $dbUser = $f['dbUser'] ?? null;
    $dbPass = $f['dbPass'] ?? null;
    $dbCreate = $f['dbCreate'] ?? null;
    unset($_SESSION['db_form']);
}
if (isset($_SESSION['admin_form'])) {
    $f = $_SESSION['admin_form'];
    $adminUser = $f['adminUser'] ?? null;
    $adminEmail = $f['adminEmail'] ?? null;
    $adminNick = $f['adminNick'] ?? null;
    unset($_SESSION['admin_form']);
}

$dbHost ??= '127.0.0.1';
$dbPort ??= '3306';
$dbName ??= 'cloud_platform';
$dbUser ??= '';
$dbPass ??= '';
$dbCreate ??= true;
$adminUser ??= 'admin';
$adminEmail ??= '';
$adminNick ??= 'Administrator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CloudPlatform — Installation Wizard</title>
<style>
:root { --bg: #f5f7fa; --card-bg: #fff; --text: #333; --muted: #888; --primary: #2d8cf0; --success: #19be6b; --danger: #ed4014; --warning: #ff9900; --border: #e8eaec; --radius: 6px; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
.container { width:100%; max-width:640px; }
.header { text-align:center; margin-bottom:32px; }
.header h1 { font-size:24px; font-weight:600; color:var(--primary); }
.header p { color:var(--muted); margin-top:4px; }
.steps { display:flex; justify-content:center; margin-bottom:28px; }
.step { display:flex; align-items:center; gap:8px; padding:6px 16px; font-size:13px; color:var(--muted); }
.step.active { color:var(--primary); font-weight:600; }
.step.done { color:var(--success); }
.step-num { width:26px; height:26px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; border:2px solid var(--border); background:var(--card-bg); flex-shrink:0; }
.step.active .step-num { border-color:var(--primary); background:var(--primary); color:#fff; }
.step.done .step-num { border-color:var(--success); background:var(--success); color:#fff; }
.step-sep { width:32px; height:2px; background:var(--border); align-self:center; }
.card { background:var(--card-bg); border-radius:8px; box-shadow:0 1px 6px rgba(0,0,0,0.06); padding:28px 32px; margin-bottom:20px; }
.card h2 { font-size:18px; margin-bottom:20px; font-weight:600; }
.alert { padding:12px 16px; border-radius:var(--radius); margin-bottom:20px; font-size:13px; line-height:1.5; }
.alert-error { background:#fef0f0; color:var(--danger); border:1px solid #fde2e2; }
.alert-success { background:#f0faf5; color:#19be6b; border:1px solid #d9f5e5; }
.alert-info { background:#ecf5ff; color:var(--primary); border:1px solid #d9e9ff; }
.alert-warning { background:#fff7e6; color:var(--warning); border:1px solid #ffe4b3; }
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:500; margin-bottom:6px; color:#555; }
.form-group input[type="text"], .form-group input[type="password"], .form-group input[type="number"] { width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:var(--radius); font-size:14px; outline:none; transition:border-color .2s; }
.form-group input:focus { border-color:var(--primary); box-shadow:0 0 0 2px rgba(45,140,240,0.1); }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.form-hint { font-size:12px; color:var(--muted); margin-top:4px; }
.form-check { display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer; }
.form-check input[type="checkbox"] { width:16px; height:16px; cursor:pointer; }
.btn { display:inline-flex; align-items:center; justify-content:center; padding:10px 28px; border-radius:var(--radius); font-size:14px; font-weight:500; cursor:pointer; border:none; transition:all .2s; text-decoration:none; }
.btn-primary { background:var(--primary); color:#fff; }
.btn-primary:hover { background:#2b7de0; }
.btn-success { background:var(--success); color:#fff; }
.btn-success:hover { background:#17a866; }
.btn-lg { padding:12px 40px; font-size:15px; }
.btn-block { width:100%; }
.env-table { width:100%; border-collapse:collapse; font-size:13px; }
.env-table td { padding:8px 12px; border-bottom:1px solid var(--border); }
.env-table tr:last-child td { border-bottom:none; }
.env-table .check-val { color:var(--muted); font-size:12px; text-align:right; }
.badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
.badge-pass { background:#d9f5e5; color:var(--success); }
.badge-fail { background:#fde2e2; color:var(--danger); }
.complete-icon { text-align:center; font-size:48px; margin-bottom:16px; color:var(--success); }
.complete-links { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:24px; }
.complete-link { display:block; padding:16px; border:1px solid var(--border); border-radius:var(--radius); text-decoration:none; color:var(--text); transition:border-color .2s; }
.complete-link:hover { border-color:var(--primary); }
.complete-link strong { display:block; font-size:14px; margin-bottom:4px; }
.complete-link span { font-size:12px; color:var(--muted); }
</style>
</head>
<body>
<div class="container">
<div class="header">
    <h1>CloudPlatform Installation</h1>
    <p>One-click setup wizard</p>
</div>

<div class="steps">
    <?php for ($i = 1; $i <= 4; $i++): ?>
        <div class="step<?= $i === $step ? ' active' : '' ?><?= $i < $step ? ' done' : '' ?>">
            <span class="step-num"><?= $i < $step ? '✓' : $i ?></span>
            <span><?= ['Environment', 'Database', 'Admin', 'Install'][$i - 1] ?></span>
        </div>
        <?php if ($i < 4): ?><div class="step-sep"></div><?php endif; ?>
    <?php endfor; ?>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= $error ?></div>
<?php endif; ?>

<?php if ($envExists && $step <= 1): ?>
    <div class="alert alert-warning">Existing installation detected (service/.env or admin/.env found). Re-running the wizard will overwrite these files.</div>
<?php endif; ?>

<?php if ($step === 1): ?>
<div class="card">
    <h2>Environment Check</h2>
    <table class="env-table">
        <?php foreach (envChecks() as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['name']) ?></td>
            <td class="check-val"><?= htmlspecialchars($c['value']) ?></td>
            <td style="text-align:right;width:80px"><span class="badge <?= $c['pass'] ? 'badge-pass' : 'badge-fail' ?>"><?= $c['pass'] ? 'PASS' : 'FAIL' ?></span></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <form method="post" style="margin-top:24px">
        <input type="hidden" name="step" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn btn-primary btn-block btn-lg">Next: Database Configuration</button>
    </form>
</div>
<?php endif; ?>

<?php if ($step === 2): ?>
<div class="card">
    <h2>Database Configuration</h2>
    <form method="post">
        <input type="hidden" name="step" value="2">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <div class="form-row">
            <div class="form-group"><label>Host</label><input type="text" name="db_host" value="<?= htmlspecialchars($dbHost) ?>" required></div>
            <div class="form-group"><label>Port</label><input type="number" name="db_port" value="<?= htmlspecialchars($dbPort) ?>" required min="1" max="65535"></div>
        </div>
        <div class="form-group"><label>Database Name</label><input type="text" name="db_name" value="<?= htmlspecialchars($dbName) ?>" required pattern="[a-zA-Z0-9_][a-zA-Z0-9_]{0,63}" maxlength="64"></div>
        <div class="form-row">
            <div class="form-group"><label>Username</label><input type="text" name="db_user" value="<?= htmlspecialchars($dbUser) ?>" required></div>
            <div class="form-group"><label>Password</label><input type="password" name="db_pass" value="<?= htmlspecialchars($dbPass) ?>"></div>
        </div>
        <div class="form-group"><label class="form-check"><input type="checkbox" name="db_create" value="1" <?= $dbCreate ? 'checked' : '' ?>> Auto-create database if not exists</label></div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Test Connection &amp; Continue</button>
    </form>
</div>
<?php endif; ?>

<?php if ($step === 3): ?>
<div class="card">
    <h2>Admin Account Setup</h2>
    <p class="form-hint" style="margin-bottom:20px">Create the super administrator account for the admin panel.</p>
    <form method="post">
        <input type="hidden" name="step" value="3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <div class="form-row">
            <div class="form-group"><label>Username</label><input type="text" name="admin_user" value="<?= htmlspecialchars($adminUser) ?>" required minlength="3"></div>
            <div class="form-group"><label>Nickname</label><input type="text" name="admin_nick" value="<?= htmlspecialchars($adminNick) ?>"></div>
        </div>
        <div class="form-group"><label>Email</label><input type="text" name="admin_email" value="<?= htmlspecialchars($adminEmail) ?>" required></div>
        <div class="form-row">
            <div class="form-group"><label>Password</label><input type="password" name="admin_pass" required minlength="8" placeholder="Min 8 chars, letters + number/symbol"></div>
            <div class="form-group"><label>Confirm Password</label><input type="password" name="admin_pass2" required minlength="8" placeholder="Same as above"></div>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Next: Install</button>
    </form>
</div>
<?php endif; ?>

<?php if ($step === 4 && empty($success)): ?>
<div class="card">
    <h2>Ready to Install</h2>
    <div class="alert alert-info">
        <strong>Database:</strong> <?= htmlspecialchars($_SESSION['db']['dbUser']) ?>@<?= htmlspecialchars($_SESSION['db']['dbHost']) ?>:<?= htmlspecialchars($_SESSION['db']['dbPort']) ?>/<?= htmlspecialchars($_SESSION['db']['dbName']) ?><br>
        <strong>Admin:</strong> <?= htmlspecialchars($_SESSION['admin']['adminUser']) ?> (<?= htmlspecialchars($_SESSION['admin']['adminEmail']) ?>)
    </div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px">This will create all 46 database tables and write .env configuration files for both service and admin.</p>
    <form method="post">
        <input type="hidden" name="step" value="4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn btn-success btn-block btn-lg">Install Now</button>
    </form>
</div>
<?php endif; ?>

<?php if (!empty($success)): ?>
<div class="card">
    <div class="complete-icon">&#10003;</div>
    <h2 style="text-align:center">Installation Complete!</h2>
    <p style="text-align:center;color:var(--muted);margin-bottom:16px">CloudPlatform has been installed successfully.</p>
    <div class="complete-links">
        <a href="#" class="complete-link"><strong>Service API</strong><span>http://localhost:8787</span></a>
        <a href="#" class="complete-link"><strong>Admin Panel</strong><span>http://localhost:8787/app/admin</span></a>
    </div>
    <div style="margin-top:24px;padding:16px;background:#f8f9fa;border-radius:var(--radius);font-size:13px">
        <strong>Next Steps:</strong>
        <ol style="margin:8px 0 0 18px;line-height:2">
            <li>Review <code>service/.env</code> and <code>admin/.env</code> — configure SMTP, Stripe, Twilio, etc. as needed</li>
            <li>Install dependencies if not done yet:<br><code>cd service && composer install && cd ../admin && composer install</code></li>
            <li>Start the service:<br><code>cd service && php start.php start -d</code></li>
            <li>Start the admin panel:<br><code>cd admin && php start.php start -d</code></li>
            <li>Log into admin panel with your admin account credentials</li>
        </ol>
    </div>
</div>
<?php endif; ?>
</div>
</body>
</html>
