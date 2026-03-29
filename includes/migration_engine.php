<?php
/**
 * Migration Engine for VMS Multi-Tenant
 * Automatically applies pending SQL migrations from the /migrations folder
 */

function applyMigrations($tenant_pdo, $tenant_key, $current_version)
{
    $migrations_dir = __DIR__ . '/../migrations/';
    $files = glob($migrations_dir . '*.sql');
    sort($files); // Ensure order (001, 002, ...)

    $applied_count = 0;
    $errors = [];

    foreach ($files as $file) {
        $filename = basename($file);
        $version = (int) explode('_', $filename)[0];

        if ($version > $current_version) {
            try {
                $sql = file_get_contents($file);

                // Split SQL by semicolon and execute each statement
                // Note: Simple explode is okay here as long as we don't have semicolons inside triggers/procs
                $queries = explode(';', $sql);
                foreach ($queries as $query) {
                    $query = trim($query);
                    if (!empty($query)) {
                        $tenant_pdo->exec($query);
                    }
                }

                $applied_count++;
                $current_version = $version;
            } catch (PDOException $e) {
                $errors[] = "Error in $filename: " . $e->getMessage();
                break; // Stop on error
            }
        }
    }

    return ['count' => $applied_count, 'new_version' => $current_version, 'errors' => $errors];
}

