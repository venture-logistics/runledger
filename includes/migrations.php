<?php
/**
 * Database Migration System
 * 
 * This system handles database updates in two ways:
 * 1. Parses schema.sql and creates any missing tables
 * 2. Runs individual migration files from /install/migrations/ (optional)
 */

/**
 * Parse schema.sql and create any missing tables
 */
function apply_schema_sql(mysqli $conn, string $schemaFile): array
{
    $results = [
        'applied' => [],
        'errors' => []
    ];

    // Read schema.sql
    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
        $results['errors'][] = 'Could not read schema.sql';
        return $results;
    }

    // Get list of existing tables
    $existingTables = [];
    $res = $conn->query("SHOW TABLES");
    if ($res) {
        while ($row = $res->fetch_array()) {
            $existingTables[] = $row[0];
        }
    }

    // Extract CREATE TABLE statements
    preg_match_all('/CREATE TABLE `?(\w+)`?\s*\((.*?)\)\s*ENGINE/is', $sql, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $tableName = $match[1];
        $fullStatement = $match[0];

        // Skip if table already exists
        if (in_array($tableName, $existingTables)) {
            continue;
        }

        // Find the complete CREATE TABLE statement (including all lines until ENGINE)
        $pattern = '/CREATE TABLE.*?`' . preg_quote($tableName, '/') . '`.*?ENGINE=\w+[^;]*;/is';
        if (preg_match($pattern, $sql, $fullMatch)) {
            $createStatement = $fullMatch[0];

            // Replace CREATE TABLE with CREATE TABLE IF NOT EXISTS for safety
            $createStatement = preg_replace('/CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $createStatement, 1);

            try {
                if ($conn->query($createStatement)) {
                    $results['applied'][] = "Created table: $tableName";
                } else {
                    $results['errors'][] = "Failed to create $tableName: " . $conn->error;
                }
            } catch (Exception $e) {
                $results['errors'][] = "Error creating $tableName: " . $e->getMessage();
            }
        }
    }

    return $results;
}

/**
 * Main migration runner
 */
function run_migrations(mysqli $conn): array
{
    $results = [
        'applied' => [],
        'errors' => [],
        'skipped' => []
    ];

    // STEP 1: Apply schema.sql if it exists
    $schemaFile = __DIR__ . '/../install/schema.sql';
    if (file_exists($schemaFile)) {
        $schemaResults = apply_schema_sql($conn, $schemaFile);
        $results['applied'] = array_merge($results['applied'], $schemaResults['applied']);
        $results['errors'] = array_merge($results['errors'], $schemaResults['errors']);
    }

    // STEP 2: Run individual migration files (optional, for custom migrations)
    $migrationsDir = __DIR__ . '/../install/migrations';

    if (!is_dir($migrationsDir)) {
        // If no migrations directory but we have schema.sql, that's OK
        if (file_exists($schemaFile)) {
            return $results;
        }
        $results['errors'][] = 'Neither schema.sql nor migrations directory found';
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
function has_pending_migrations(mysqli $conn): int
{
    // Check if schema.sql would create any new tables
    $schemaFile = __DIR__ . '/../install/schema.sql';
    $pendingCount = 0;

    if (file_exists($schemaFile)) {
        $sql = file_get_contents($schemaFile);

        // Get existing tables
        $existingTables = [];
        $res = $conn->query("SHOW TABLES");
        if ($res) {
            while ($row = $res->fetch_array()) {
                $existingTables[] = $row[0];
            }
        }

        // Count tables in schema.sql that don't exist
        preg_match_all('/CREATE TABLE `?(\w+)`?/i', $sql, $matches);
        if (!empty($matches[1])) {
            $schemaTables = array_unique($matches[1]);
            $missingTables = array_diff($schemaTables, $existingTables);
            $pendingCount += count($missingTables);
        }
    }

    // Also check individual migration files
    $migrationsDir = __DIR__ . '/../install/migrations';

    if (!is_dir($migrationsDir)) {
        return $pendingCount;
    }

    // Get applied count
    $appliedCount = 0;
    $res = $conn->query("SELECT COUNT(*) as c FROM schema_migrations");
    if ($res) {
        $row = $res->fetch_assoc();
        $appliedCount = (int) ($row['c'] ?? 0);
    }

    // Get total migration files
    $files = glob($migrationsDir . '/*.sql');
    $totalCount = count($files);

    $pendingCount += max(0, $totalCount - $appliedCount);

    return $pendingCount;
}