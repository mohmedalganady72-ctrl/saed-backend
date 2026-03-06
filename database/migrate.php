<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/core.php';
require_once dirname(__DIR__) . '/config/database.php';

use App\Config\Database;

function ensureMigrationsTable(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `migration` VARCHAR(255) NOT NULL,
            `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_schema_migrations_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function appliedMigrations(PDO $db): array
{
    $stmt = $db->query('SELECT migration FROM schema_migrations');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    $applied = [];
    foreach ($rows as $name) {
        if (is_string($name) && $name !== '') {
            $applied[$name] = true;
        }
    }

    return $applied;
}

function migrationFiles(string $path): array
{
    $files = glob($path . '/*.sql');
    if ($files === false) {
        return [];
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

try {
    $db = Database::connection();
    ensureMigrationsTable($db);

    $applied = appliedMigrations($db);
    $files = migrationFiles(__DIR__ . '/migrations');

    if ($files === []) {
        fwrite(STDOUT, "No migration files found.\n");
        exit(0);
    }

    $ran = 0;
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            fwrite(STDOUT, "Skipping {$name} (already applied)\n");
            continue;
        }

        $sql = trim((string) file_get_contents($file));
        if ($sql === '') {
            fwrite(STDOUT, "Skipping {$name} (empty file)\n");
            continue;
        }

        $db->exec($sql);

        $insert = $db->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $insert->execute(['migration' => $name]);

        $ran++;
        fwrite(STDOUT, "Applied {$name}\n");
    }

    fwrite(STDOUT, "Done. Applied {$ran} migration(s).\n");
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, "Migration failed: " . $throwable->getMessage() . "\n");
    exit(1);
}
