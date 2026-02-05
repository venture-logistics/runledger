<?php
/**
 * Database Migration System
 * Runs SQL migration files from /install/migrations/
 */

function run_migrations(mysqli $conn): array {
    $results = [
        'applied' => [],
        'errors' => [],
        'skipped' => []
    ];

    $migrationsDir = __DIR__ . '/../install/migrations';

    if (!is_dir($migrationsDir)) {
        $results['errors'][] = 'Migrations directory not found';
        return $results;
    }

    // Ensure schema_migrations table exists (bootstrap)
    $conn->query("
        CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `migration` varchar(255) NOT NULL,
            `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Get already applied migrations
    $applied = [];
    $res = $conn->query("SELECT migration FROM schema_migrations");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $applied[] = $row['migration'];
        }
    }

    // Get all migration files
    $files = glob($migrationsDir . '/*.sql');
    sort($files); // Ensure order by filename

    foreach ($files as $file) {
        $filename = basename($file);

        // Skip if already applied
        if (in_array($filename, $applied)) {
            $results['skipped'][] = $filename;
            continue;
        }

        // Read and execute the migration
        $sql = file_get_contents($file);
        if ($sql === false) {
            $results['errors'][] = "Could not read: $filename";
            continue;
        }

        // Execute the migration
        try {
            // Handle multiple statements
            $conn->multi_query($sql);

            // Process all results to clear the connection
            do {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            } while ($conn->more_results() && $conn->next_result());

            // Check for errors
            if ($conn->error) {
                throw new Exception($conn->error);
            }

            // Record the migration
            $stmt = $conn->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");
            $stmt->bind_param("s", $filename);
            $stmt->execute();
            $stmt->close();

            $results['applied'][] = $filename;

        } catch (Exception $e) {
            $results['errors'][] = "$filename: " . $e->getMessage();
        }
    }

    return $results;
}

/**
 * Check if there are pending migrations
 */
function has_pending_migrations(mysqli $conn): int {
    $migrationsDir = __DIR__ . '/../install/migrations';

    if (!is_dir($migrationsDir)) {
        return 0;
    }

    // Get applied count
    $appliedCount = 0;
    $res = $conn->query("SELECT COUNT(*) as c FROM schema_migrations");
    if ($res) {
        $row = $res->fetch_assoc();
        $appliedCount = (int)($row['c'] ?? 0);
    }

    // Get total migration files
    $files = glob($migrationsDir . '/*.sql');
    $totalCount = count($files);

    return max(0, $totalCount - $appliedCount);
}
