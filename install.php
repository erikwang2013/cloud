<?php
/**
 * CloudPlatform — One-Click Installation Wizard
 *
 * Usage: php install.php [--host=0.0.0.0] [--port=8888]
 * Then open http://localhost:8888 in your browser.
 */

$host = '0.0.0.0';
$port = 8888;

$opts = getopt('', ['host::', 'port::']);
if (isset($opts['host'])) $host = $opts['host'];
if (isset($opts['port'])) {
    $port = (int)$opts['port'];
    if ($port < 1 || $port > 65535) {
        fwrite(STDERR, "Error: port must be between 1 and 65535, got: {$opts['port']}\n");
        exit(1);
    }
}

if (empty($host)) {
    fwrite(STDERR, "Error: host cannot be empty\n");
    exit(1);
}

$addr = "$host:$port";
$url = str_starts_with($addr, '0.0.0.0') ? "http://localhost:$port" : "http://$addr";

echo "\n";
echo <<<'MASCOT'
                       _____
                     .'     '.
                    /  _   _  \
                   |  (o) (o)  |
                    \    ~    /
                 .-'''''''''''-.
               .'               '.
              (  ______________  )
               '-| [] [] [] [] |-'
                 '--------------'

MASCOT;
// 边框宽度固定，内容行按显示宽度补齐 —— URL 端口位数变化时右侧 │ 仍对齐
$boxInner = 50;
$boxLine = static function (string $text) use ($boxInner): string {
    return '  │' . $text . str_repeat(' ', max(0, $boxInner - mb_strwidth($text))) . "│\n";
};

echo '  ┌' . str_repeat('─', $boxInner) . "┐\n";
echo $boxLine('     CloudPlatform — Installation Wizard');
echo '  ├' . str_repeat('─', $boxInner) . "┤\n";
echo $boxLine("  Open $url in your browser");
echo $boxLine('  Press Ctrl+C to stop.');
echo '  └' . str_repeat('─', $boxInner) . "┘\n";
echo "\n";

$docroot = __DIR__ . '/install';
$router = __DIR__ . '/install/router.php';

// 清理上次异常退出可能残留的 router 文件
if (file_exists($router)) {
    unlink($router);
}

if (!is_dir($docroot)) {
    mkdir($docroot, 0755, true);
}

if (file_put_contents($router, '<?php
$_SERVER["SCRIPT_NAME"] = "/index.php";
$_SERVER["SCRIPT_FILENAME"] = __DIR__ . "/index.php";
return false;
') === false) {
    fwrite(STDERR, "Error: failed to write router file to: $router\n");
    exit(1);
}

passthru(sprintf(
    'PHP_CLI_SERVER_WORKERS=%d %s -S %s -t %s %s',
    PHP_OS_FAMILY === 'Windows' ? 1 : 4,
    PHP_BINARY,
    escapeshellarg($addr),
    escapeshellarg($docroot),
    escapeshellarg($router)
));

register_shutdown_function(function () use ($router) {
    if (file_exists($router)) {
        unlink($router);
    }
});
