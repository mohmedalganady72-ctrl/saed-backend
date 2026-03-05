<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/core.php';
require_once dirname(__DIR__) . '/config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    if (str_starts_with($relative, 'Config\\')) {
        $file = APP_BASE_PATH . '/config/' . str_replace('\\', '/', substr($relative, 7)) . '.php';
    } else {
        $file = APP_BASE_PATH . '/src/' . str_replace('\\', '/', $relative) . '.php';
    }

    if (is_file($file)) {
        require_once $file;
    }
});

require_once APP_BASE_PATH . '/routes/api.php';
