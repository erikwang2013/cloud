<?php
require_once __DIR__ . '/vendor/autoload.php';

// Define BASE_PATH constant used by webman
define('BASE_PATH', __DIR__);

// Run webman
support\App::run();
