<?php
return [
    'listen'               => 'http://0.0.0.0:8787',
    'transport'            => 'tcp',
    'context'              => [],
    'name'                 => 'CloudPlatform',
    'count'                => 4,
    'user'                 => '',
    'group'                => '',
    'reuse_port'           => true,
    'event_loop'           => '',
    'stop_timeout'         => 2,
    'pid_file'             => runtime_path() . '/webman.pid',
    'status_file'          => runtime_path() . '/webman.status',
    'stdout_file'          => runtime_path() . '/logs/stdout.log',
    'log_file'             => runtime_path() . '/logs/workerman.log',
    'max_package_size'     => 10 * 1024 * 1024,
];
