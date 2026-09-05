<?php

/**
 * Playthrough Schema Management Library
 * 
 * Fast schema-based playthrough system using PostgreSQL schema cloning.
 * Replaces slow pg_dump/restore with instant in-database operations.
 */

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'logger.php');

// Existing installations must refresh the stored SQL function, not just the source file.
function pts_clone_function_is_current($definition): bool {
    return is_string($definition)
        && str_contains($definition, 'OVERRIDING SYSTEM VALUE')
        && str_contains($definition, 'STOBE_TABLE_ONLY_PLAYTHROUGHS')
        && stripos($definition, 'CREATE OR REPLACE VIEW') === false;
}

/**
 * Ensure the clone_schema SQL functions exist in the database.
 * Safe to call multiple times (idempotent).
 */
function pts_ensure_functions($conn): bool {
    $checkQuery = "SELECT pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_namespace n ON p.pronamespace = n.oid WHERE p.proname = 'clone_schema' AND n.nspname = 'stobe_meta' AND p.proargtypes = '25 25'::oidvector LIMIT 1";
    $checkResult = @pg_query($conn, $checkQuery);
    if ($checkResult && pg_num_rows($checkResult) > 0) {
        $installed = pg_fetch_assoc($checkResult);
        if (pts_clone_function_is_current($installed['definition'] ?? null)) {
            return true;
        }
    }
    
    $sqlFile = __DIR__ . DIRECTORY_SEPARATOR . 'schema_clone_function.sql';
    if (!file_exists($sqlFile)) {
        Logger::error("schema_clone_function.sql not found at: " . $sqlFile);
        return false;
    }
    
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        Logger::error("Failed to read schema_clone_function.sql");
        return false;
    }
    
    // Execute the SQL to create functions
    $result = @pg_query($conn, $sql);
    if (!$result) {
        $error = pg_last_error($conn);
        Logger::error("Failed to create schema clone functions: " . $error);
        return false;
    }
    
    // Verify functions were created
    $verifyResult = @pg_query($conn, $checkQuery);
    $verified = $verifyResult ? pg_fetch_assoc($verifyResult) : false;
    if (!pts_clone_function_is_current($verified['definition'] ?? null)) {
        Logger::error("Schema clone functions were not created successfully");
        return false;
    }
    
    Logger::info("Schema clone functions installed successfully");
    return true;
}

// Capture runtime views in dependency order before public is replaced by saved tables.
function pts_capture_public_views($conn): array {
    $result = pg_query($conn, <<<'SQL'
WITH RECURSIVE dependencies AS (
    SELECT c.oid AS root, c.oid AS current_view, ARRAY[c.oid] AS path
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public' AND c.relkind = 'v'
    UNION
    SELECT deps.root, child.oid, deps.path || child.oid
    FROM dependencies deps
    JOIN pg_rewrite r ON r.ev_class = deps.current_view
    JOIN pg_depend d ON d.classid = 'pg_rewrite'::regclass AND d.objid = r.oid
    JOIN pg_class child ON d.refclassid = 'pg_class'::regclass AND child.oid = d.refobjid
    JOIN pg_namespace n ON n.oid = child.relnamespace
    WHERE n.nspname = 'public' AND child.relkind = 'v' AND NOT child.oid = ANY(deps.path)
)
SELECT c.relname, pg_get_viewdef(c.oid) AS definition, pg_get_userbyid(c.relowner) AS owner,
       COALESCE(array_to_string(c.reloptions, ', '), '') AS options,
       COALESCE((SELECT json_agg(grants) FROM (
           SELECT NULL::text AS column_name, a.grantee, a.grantor, a.privilege_type, a.is_grantable,
                  CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END AS role,
                  pg_get_userbyid(a.grantor) AS grantor_role
           FROM aclexplode(COALESCE(c.relacl, acldefault('r', c.relowner))) a
           UNION ALL
           SELECT col.attname, a.grantee, a.grantor, a.privilege_type, a.is_grantable,
                  CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END,
                  pg_get_userbyid(a.grantor)
           FROM pg_attribute col CROSS JOIN LATERAL aclexplode(col.attacl) a
           WHERE col.attrelid = c.oid AND col.attnum > 0
       ) grants), '[]'::json) AS grants
FROM dependencies deps JOIN pg_class c ON c.oid = deps.root
GROUP BY c.oid
ORDER BY MAX(cardinality(deps.path)), c.relname
SQL);
    if ($result === false) {
        throw new RuntimeException('Could not read runtime view definitions');
    }
    $views = pg_fetch_all($result);
    // Replaying delegated grants as the owner would change later REVOKE ... CASCADE behavior.
    foreach ($views as $view) {
        foreach (json_decode($view['grants'], true, 512, JSON_THROW_ON_ERROR) as $grant) {
            if (strval($grant['grantor_role'] ?? '') !== strval($view['owner'] ?? '')) {
                throw new RuntimeException(
                    'Delegated access grants are not supported for runtime view ' . strval($view['relname'] ?? '')
                );
            }
        }
    }
    return $views;
}

// Restore views and their access rules atomically with the playthrough's saved tables.
function pts_restore_public_views($conn, array $views): void {
    foreach ($views as $view) {
        $name = 'public.' . pg_escape_identifier($conn, $view['relname']);
        $options = $view['options'] !== '' ? ' WITH (' . $view['options'] . ')' : '';
        if (!pg_query($conn, 'CREATE VIEW ' . $name . $options . ' AS ' . $view['definition'])) {
            throw new RuntimeException('Could not recreate runtime view ' . $view['relname']);
        }
        // New views may inherit default grants that the previous view had revoked.
        $newGrants = pg_query_params($conn,
            "SELECT DISTINCT a.grantee, CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END AS role
             FROM pg_class c CROSS JOIN LATERAL aclexplode(COALESCE(c.relacl, acldefault('r', c.relowner))) a
             WHERE c.oid = $1::regclass", [$name]);
        if (!$newGrants) {
            throw new RuntimeException('Could not read recreated view access rules');
        }
        $statements = [];
        foreach (pg_fetch_all($newGrants) as $grant) {
            $role = intval($grant['grantee']) === 0 ? 'PUBLIC' : pg_escape_identifier($conn, $grant['role']);
            $statements[] = 'REVOKE ALL ON ' . $name . ' FROM ' . $role;
        }
        $statements[] = 'ALTER VIEW ' . $name . ' OWNER TO ' . pg_escape_identifier($conn, $view['owner']);
        foreach (json_decode($view['grants'], true, 512, JSON_THROW_ON_ERROR) as $grant) {
            $role = intval($grant['grantee']) === 0 ? 'PUBLIC' : pg_escape_identifier($conn, $grant['role']);
            $column = $grant['column_name'] !== null ? ' (' . pg_escape_identifier($conn, $grant['column_name']) . ')' : '';
            $statements[] = 'GRANT ' . $grant['privilege_type'] . $column . ' ON ' . $name . ' TO ' . $role
                . ($grant['is_grantable'] ? ' WITH GRANT OPTION' : '');
        }
        foreach ($statements as $statement) {
            if (!pg_query($conn, $statement)) {
                throw new RuntimeException('Could not restore runtime view access rules');
            }
        }
    }
}

/**
 * Sanitize a profile name to create a valid PostgreSQL schema name.
 * Rules: lowercase, alphanumeric + underscore only, max 63 chars
 */
function pts_sanitize_profile_name(string $name): string {
    // Convert to lowercase
    $name = strtolower($name);
    
    // Replace spaces and special chars with underscores
    $name = preg_replace('/[^a-z0-9_]/', '_', $name);
    
    // Remove consecutive underscores
    $name = preg_replace('/_+/', '_', $name);
    
    // Remove leading/trailing underscores
    $name = trim($name, '_');
    
    // Ensure it doesn't start with a number
    if (preg_match('/^[0-9]/', $name)) {
        $name = 'p_' . $name;
    }
    
    // Truncate to safe length (PostgreSQL allows 63, leave room for prefix)
    $name = substr($name, 0, 40);
    
    // Prefix with stobe_profile_
    return 'stobe_profile_' . $name;
}

/**
 * Check if a schema exists in the database.
 */
function pts_schema_exists($conn, string $schemaName): bool {
    $result = @pg_query_params(
        $conn,
        "SELECT 1 FROM information_schema.schemata WHERE schema_name = $1",
        [$schemaName]
    );
    
    if (!$result) {
        return false;
    }
    
    return pg_num_rows($result) > 0;
}

/**
 * Clone a schema (source) to another schema (destination).
 * Returns ['success' => bool, 'error' => string]
 */
function pts_clone_schema($conn, string $sourceSchema, string $destSchema): array {
    // Validate schema names
    if (empty($sourceSchema) || empty($destSchema)) {
        return ['success' => false, 'error' => 'Invalid schema names'];
    }
    
    if (!pts_schema_exists($conn, $sourceSchema)) {
        return ['success' => false, 'error' => "Source schema '{$sourceSchema}' does not exist"];
    }
    
    // Drop destination if it exists (functions are in stobe_meta schema)
    $dropResult = @pg_query(
        $conn,
        "SELECT stobe_meta.drop_schema_safe('" . pg_escape_string($conn, $destSchema) . "'::text)"
    );
    
    if (!$dropResult) {
        Logger::warn("Could not drop existing schema {$destSchema}: " . pg_last_error($conn));
    }
    
    // Clone the schema (functions are in stobe_meta schema)
    $result = @pg_query(
        $conn,
        "SELECT stobe_meta.clone_schema('" . pg_escape_string($conn, $sourceSchema) . "'::text, '" . pg_escape_string($conn, $destSchema) . "'::text)"
    );
    
    if (!$result) {
        $error = pg_last_error($conn);
        Logger::error("Schema clone failed: {$error}");
        return ['success' => false, 'error' => $error];
    }
    
    Logger::info("Successfully cloned schema {$sourceSchema} to {$destSchema}");
    return ['success' => true, 'error' => ''];
}

/**
 * Drop a schema safely (with protection for system schemas).
 * Returns ['success' => bool, 'error' => string]
 */
function pts_drop_schema($conn, string $schemaName): array {
    if (empty($schemaName)) {
        return ['success' => false, 'error' => 'Invalid schema name'];
    }
    
    // Additional PHP-level protection
    $protected = ['public', 'pg_catalog', 'information_schema', 'pg_toast', 'stobe_meta'];
    if (in_array(strtolower($schemaName), $protected)) {
        return ['success' => false, 'error' => 'Cannot drop protected schema'];
    }
    
    $result = @pg_query(
        $conn,
        "SELECT stobe_meta.drop_schema_safe('" . pg_escape_string($conn, $schemaName) . "'::text)"
    );
    
    if (!$result) {
        $error = pg_last_error($conn);
        Logger::error("Schema drop failed: {$error}");
        return ['success' => false, 'error' => $error];
    }
    
    $row = pg_fetch_assoc($result);
    if ($row && isset($row['drop_schema_safe']) && $row['drop_schema_safe'] === 't') {
        Logger::info("Successfully dropped schema {$schemaName}");
        return ['success' => true, 'error' => ''];
    }
    
    return ['success' => false, 'error' => 'Drop operation returned false'];
}

/**
 * Get the total size of a schema in bytes.
 * Returns 0 if schema doesn't exist or on error.
 */
function pts_get_schema_size($conn, string $schemaName): int {
    if (!pts_schema_exists($conn, $schemaName)) {
        return 0;
    }
    
    $result = @pg_query(
        $conn,
        "SELECT stobe_meta.get_schema_size('" . pg_escape_string($conn, $schemaName) . "'::text) as size"
    );
    
    if (!$result) {
        Logger::warn("Failed to get schema size: " . pg_last_error($conn));
        return 0;
    }
    
    $row = pg_fetch_assoc($result);
    if ($row && isset($row['size'])) {
        return (int)$row['size'];
    }
    
    return 0;
}

/**
 * Recreate the public schema cleanly with required extensions.
 * This is used before cloning a profile schema back to public.
 */
function pts_recreate_public_schema($conn): bool {
    $queries = [
        "DROP SCHEMA IF EXISTS public CASCADE",
        "CREATE SCHEMA public",
        // Extensions are database-level, not schema-level, so just ensure they exist
        "CREATE EXTENSION IF NOT EXISTS vector",
        "CREATE EXTENSION IF NOT EXISTS pg_trgm"
    ];
    
    foreach ($queries as $query) {
        $result = @pg_query($conn, $query);
        if (!$result) {
            Logger::error("Failed to execute: {$query} - " . pg_last_error($conn));
            return false;
        }
    }
    
    return true;
}

/**
 * Get metadata about a profile schema (table counts, record counts, etc.)
 * Returns array with stats or empty array on error.
 */
function pts_get_schema_metadata($conn, string $schemaName): array {
    if (!pts_schema_exists($conn, $schemaName)) {
        return [];
    }
    
    $metadata = [
        'player_name' => 'Unknown',
        'game' => 'Kenshi',
        'eventlog_count' => 0,
        'oghma_count' => 0,
        'last_gamets' => 0
    ];
    
    // Get eventlog count
    $result = @pg_query_params(
        $conn,
        "SELECT COUNT(*) as c FROM {$schemaName}.eventlog",
        []
    );
    if ($result && ($row = pg_fetch_assoc($result))) {
        $metadata['eventlog_count'] = (int)$row['c'];
    }
    
    // Check if world_knowledge table exists
    $result = @pg_query_params(
        $conn,
        "SELECT 1 FROM information_schema.tables WHERE table_schema = $1 AND table_name = 'world_knowledge'",
        [$schemaName]
    );
    if ($result && pg_num_rows($result) > 0) {
        $result = @pg_query_params($conn, "SELECT COUNT(*) as c FROM {$schemaName}.world_knowledge", []);
        if ($result && ($row = pg_fetch_assoc($result))) {
            $metadata['oghma_count'] = (int)$row['c'];
        }
    }
    
    // Get last gamets
    $result = @pg_query_params(
        $conn,
        "SELECT MAX(gamets) as mx FROM {$schemaName}.eventlog",
        []
    );
    if ($result && ($row = pg_fetch_assoc($result)) && !is_null($row['mx'])) {
        $metadata['last_gamets'] = (int)$row['mx'];
    }
    
    return $metadata;
}

