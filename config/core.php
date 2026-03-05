<?php
declare(strict_types=1);

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            $value = trim($value, "\"'");

            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}

if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', dirname(__DIR__));
}

loadEnvFile(APP_BASE_PATH . '/.env');
loadEnvFile(APP_BASE_PATH . '/vendor/.env');

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Riyadh') ?? 'Asia/Riyadh');

$isDebug = strtolower(env('APP_DEBUG', 'false') ?? 'false') === 'true';
ini_set('display_errors', $isDebug ? '1' : '0');
ini_set('display_startup_errors', $isDebug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', APP_BASE_PATH . '/public');
}

if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', PUBLIC_PATH . '/uploads');
}

if (!defined('LOGS_PATH')) {
    define('LOGS_PATH', APP_BASE_PATH . '/logs');
}

$errorLogPath = env('APP_ERROR_LOG');
if ($errorLogPath === null || trim($errorLogPath) === '') {
    $errorLogPath = LOGS_PATH . '/error.log';
} else {
    $normalizedPath = str_replace('\\', '/', $errorLogPath);
    $isAbsolutePath = preg_match('#^(?:[A-Za-z]:/|/)#', $normalizedPath) === 1;
    if (!$isAbsolutePath) {
        $normalizedPath = APP_BASE_PATH . '/' . ltrim($normalizedPath, '/');
    }
    $errorLogPath = $normalizedPath;
}

$errorLogDirectory = dirname($errorLogPath);
if (!is_dir($errorLogDirectory)) {
    @mkdir($errorLogDirectory, 0775, true);
}

if (!is_file($errorLogPath)) {
    @touch($errorLogPath);
}

ini_set('error_log', $errorLogPath);

if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', env('JWT_SECRET', 'change-this-secret') ?? 'change-this-secret');
}

if (!defined('JWT_ACCESS_TTL_SECONDS')) {
    $accessTtl = env('JWT_ACCESS_TTL_SECONDS', env('JWT_TTL_SECONDS', '604800'));
    define('JWT_ACCESS_TTL_SECONDS', (int) ($accessTtl ?? '604800'));
}

if (!defined('JWT_TTL_SECONDS')) {
    define('JWT_TTL_SECONDS', JWT_ACCESS_TTL_SECONDS);
}

if (!defined('JWT_REFRESH_TTL_SECONDS')) {
    define('JWT_REFRESH_TTL_SECONDS', (int) (env('JWT_REFRESH_TTL_SECONDS', '2592000') ?? '2592000'));
}

if (!defined('CORS_ALLOW_ORIGIN')) {
    define('CORS_ALLOW_ORIGIN', env('CORS_ALLOW_ORIGIN', '*') ?? '*');
}

header('Access-Control-Allow-Origin: ' . CORS_ALLOW_ORIGIN);
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
