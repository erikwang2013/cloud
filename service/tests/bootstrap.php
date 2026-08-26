<?php

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    echo "vendor/autoload.php not found. Run composer install.\n";
    exit(1);
}
require_once $autoload;

// 进程级全局模型事件分发器：bootHasSnowflakeId 等 trait 在模型首次
// boot 时注册 creating 监听，若当时无 dispatcher 则永久丢失（后续测试
// 再 set 也不补），导致 save 后 getKey() 为 null。必须先于任何模型实例化。
\Illuminate\Database\Eloquent\Model::setEventDispatcher(
    new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container)
);

if (!function_exists('request_id')) {
    function request_id(): string {
        static $id = null;
        if ($id === null) {
            $id = bin2hex(random_bytes(8));
        }
        return $id;
    }
}

if (!function_exists('now')) {
    function now() {
        return date('Y-m-d H:i:s');
    }
}
