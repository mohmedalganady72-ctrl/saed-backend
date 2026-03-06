<?php
declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = \env('DB_HOST', \env('MYSQLHOST', '127.0.0.1') ?? '127.0.0.1') ?? '127.0.0.1';
        $port = \env('DB_PORT', \env('MYSQLPORT', '3306') ?? '3306') ?? '3306';
        $name = \env('DB_NAME', \env('MYSQLDATABASE', 'university_app') ?? 'university_app') ?? 'university_app';
        $user = \env('DB_USER', \env('MYSQLUSER', 'root') ?? 'root') ?? 'root';
        $pass = \env('DB_PASS', \env('MYSQLPASSWORD', '') ?? '') ?? '';

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            self::$connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new PDOException('Database connection failed: ' . $exception->getMessage(), (int) $exception->getCode());
        }

        return self::$connection;
    }
}
