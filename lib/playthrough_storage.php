<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'logger.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'settings.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'playthrough_schema.php');

function stobePlaythroughDbConfig(): array
{
    return [
        'host' => getenv('STOBE_DB_HOST') ?: 'localhost',
        'port' => getenv('STOBE_DB_PORT') ?: '5432',
        'dbname' => getenv('STOBE_DB_NAME') ?: 'stobe',
        'user' => getenv('STOBE_DB_USER') ?: 'dwemer',
        'password' => getenv('STOBE_DB_PASSWORD') ?: 'dwemer',
    ];
}

function stobePlaythroughConnectAdmin()
{
    $cfg = stobePlaythroughDbConfig();
    $conn = @pg_connect(
        'host=' . $cfg['host']
        . ' port=' . $cfg['port']
        . ' dbname=' . $cfg['dbname']
        . ' user=' . $cfg['user']
        . ' password=' . $cfg['password']
    );
    if (!$conn) {
        stobeLogError('PLAYTHROUGH: Failed to connect admin PG session');
        return false;
    }
    return $conn;
}

function stobePlaythroughToBool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return intval($value) !== 0;
    }
    $normalized = strtolower(trim(strval($value)));
    return in_array($normalized, ['1', 'true', 'yes', 'on', 't'], true);
}

function stobePlaythroughDecodeJsonStringList(string $raw): array
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return [];
    }
    $decoded = json_decode($trimmed, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $entry) {
        if (!is_string($entry)) {
            continue;
        }
        $value = trim($entry);
        if ($value === '') {
            continue;
        }
        $items[] = $value;
    }
    return $items;
}

function stobePlaythroughNormalizeParticipantName(string $raw): string
{
    $name = trim($raw);
    if ($name === '') {
        return '';
    }
    $pipePos = strpos($name, '|');
    if ($pipePos !== false) {
        $name = substr($name, 0, $pipePos);
    }
    return trim($name);
}

function stobePlaythroughCollectCurrentPlayerFactionMembers(): array
{
    $squadNames = stobePlaythroughDecodeJsonStringList(getConfOpt('PLAYER_SQUADS', ''));
    $memberMap = [];

    foreach ($squadNames as $squadName) {
        $membersRaw = stobePlaythroughDecodeJsonStringList(getConfOpt($squadName, ''));
        foreach ($membersRaw as $entry) {
            $memberName = stobePlaythroughNormalizeParticipantName($entry);
            if ($memberName === '') {
                continue;
            }
            $key = strtolower($memberName);
            if (!isset($memberMap[$key])) {
                $memberMap[$key] = $memberName;
            }
        }
    }

    if (count($memberMap) === 0) {
        return [];
    }

    $members = array_values($memberMap);
    natcasesort($members);
    return array_values($members);
}

function stobePlaythroughEnsureMetaSchema($adminConn): bool
{
    if (!$adminConn) {
        return false;
    }

    $metaSchema = 'stobe_meta';
    $legacyMetaSchema = 'ch' . 'im_meta';
    $schemaExists = static function ($conn, string $schemaName): bool {
        $result = @pg_query_params(
            $conn,
            'SELECT 1 FROM information_schema.schemata WHERE schema_name = $1 LIMIT 1',
            [$schemaName]
        );

        return boolval($result && @pg_fetch_assoc($result));
    };

    if (!$schemaExists($adminConn, $metaSchema) && $schemaExists($adminConn, $legacyMetaSchema)) {
        @pg_query($adminConn, 'ALTER SCHEMA "' . $legacyMetaSchema . '" RENAME TO "' . $metaSchema . '"');
    }

    @pg_query($adminConn, 'CREATE SCHEMA IF NOT EXISTS ' . $metaSchema);

    $legacyIndexRenames = [
        ('idx_' . 'ch' . 'im_playthrough_profiles_created') => 'idx_stobe_playthrough_profiles_created',
        ('idx_' . 'ch' . 'im_playthrough_profiles_last_gamets') => 'idx_stobe_playthrough_profiles_last_gamets',
        ('idx_' . 'ch' . 'im_playthrough_profiles_is_active') => 'idx_stobe_playthrough_profiles_is_active',
    ];
    foreach ($legacyIndexRenames as $legacyIndexName => $newIndexName) {
        @pg_query(
            $adminConn,
            'ALTER INDEX IF EXISTS "' . $metaSchema . '"."' . $legacyIndexName . '" RENAME TO "' . $newIndexName . '"'
        );
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS stobe_meta.playthrough_profiles (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    size_bytes BIGINT NOT NULL DEFAULT 0,
    storage_format TEXT NOT NULL DEFAULT 'schema_clone',
    notes TEXT DEFAULT '',
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    player_name TEXT,
    player_faction_members TEXT DEFAULT '[]',
    game TEXT,
    eventlog_count BIGINT DEFAULT 0,
    oghma_count BIGINT DEFAULT 0,
    last_gamets BIGINT DEFAULT 0,
    schema_name TEXT,
    storage_type TEXT DEFAULT 'schema',
    rollback_delta_days INT DEFAULT 0,
    rollback_from_gamets BIGINT DEFAULT 0,
    rollback_to_gamets BIGINT DEFAULT 0
);

CREATE TABLE IF NOT EXISTS stobe_meta.playthrough_blobs (
    profile_id INT PRIMARY KEY REFERENCES stobe_meta.playthrough_profiles(id) ON DELETE CASCADE,
    dump_data TEXT,
    dump_lob OID
);

CREATE INDEX IF NOT EXISTS idx_stobe_playthrough_profiles_created ON stobe_meta.playthrough_profiles (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_stobe_playthrough_profiles_last_gamets ON stobe_meta.playthrough_profiles (last_gamets DESC);
CREATE INDEX IF NOT EXISTS idx_stobe_playthrough_profiles_is_active ON stobe_meta.playthrough_profiles (is_active);

ALTER TABLE stobe_meta.playthrough_profiles
    ADD COLUMN IF NOT EXISTS player_faction_members TEXT DEFAULT '[]';
SQL;

    $result = @pg_query($adminConn, $sql);
    if (!$result) {
        stobeLogError('PLAYTHROUGH: Failed ensuring playthrough metadata tables', [
            'error' => @pg_last_error($adminConn),
        ]);
        return false;
    }

    if (!pts_ensure_functions($adminConn)) {
        stobeLogError('PLAYTHROUGH: Failed ensuring schema clone functions');
        return false;
    }

    return true;
}

function stobePlaythroughEnsureMetaSchemaOnDemand(): bool
{
    $adminConn = stobePlaythroughConnectAdmin();
    if (!$adminConn) {
        return false;
    }
    try {
        return stobePlaythroughEnsureMetaSchema($adminConn);
    } finally {
        @pg_close($adminConn);
    }
}

function stobePlaythroughCountTableRows($adminConn, string $schemaName, string $tableName): int
{
    $result = @pg_query_params(
        $adminConn,
        'SELECT 1 FROM information_schema.tables WHERE table_schema = $1 AND table_name = $2 LIMIT 1',
        [$schemaName, $tableName]
    );
    if (!$result || !pg_fetch_assoc($result)) {
        return 0;
    }

    $query = sprintf(
        'SELECT COUNT(*)::bigint AS c FROM %s.%s',
        pg_escape_identifier($adminConn, $schemaName),
        pg_escape_identifier($adminConn, $tableName)
    );
    $countResult = @pg_query($adminConn, $query);
    if (!$countResult) {
        return 0;
    }
    $row = @pg_fetch_assoc($countResult);
    return intval($row['c'] ?? 0);
}

function stobePlaythroughCollectSchemaStats($adminConn, string $schemaName = 'public'): array
{
    $eventlogCount = stobePlaythroughCountTableRows($adminConn, $schemaName, 'eventlog');
    $worldKnowledgeCount = stobePlaythroughCountTableRows($adminConn, $schemaName, 'world_knowledge');

    $lastGamets = 0;
    $hasEventlog = @pg_query_params(
        $adminConn,
        'SELECT 1 FROM information_schema.tables WHERE table_schema = $1 AND table_name = $2 LIMIT 1',
        [$schemaName, 'eventlog']
    );
    if ($hasEventlog && @pg_fetch_assoc($hasEventlog)) {
        $query = sprintf(
            'SELECT COALESCE(MAX(gamets), 0)::bigint AS mx FROM %s.eventlog',
            pg_escape_identifier($adminConn, $schemaName)
        );
        $result = @pg_query($adminConn, $query);
        if ($result) {
            $row = @pg_fetch_assoc($result);
            $lastGamets = intval($row['mx'] ?? 0);
        }
    }

    return [
        'eventlog_count' => $eventlogCount,
        'oghma_count' => $worldKnowledgeCount,
        'last_gamets' => $lastGamets,
    ];
}

function stobePlaythroughBuildUniqueProfileName($adminConn, string $baseName): string
{
    $trimmed = trim($baseName);
    if ($trimmed === '') {
        $trimmed = 'Snapshot ' . gmdate('Y-m-d H:i:s') . ' UTC';
    }

    $name = $trimmed;
    $suffix = 1;
    while (true) {
        $exists = @pg_query_params(
            $adminConn,
            'SELECT 1 FROM stobe_meta.playthrough_profiles WHERE name = $1 LIMIT 1',
            [$name]
        );
        if (!$exists || !@pg_fetch_assoc($exists)) {
            break;
        }
        $name = $trimmed . ' #' . strval($suffix);
        $suffix++;
        if ($suffix > 9999) {
            $name = $trimmed . ' #' . substr(uniqid('', true), -6);
            break;
        }
    }

    return $name;
}

function stobePlaythroughBuildUniqueSchemaName($adminConn, string $profileName): string
{
    $base = pts_sanitize_profile_name($profileName);
    $schema = $base;
    $suffix = 1;
    while (pts_schema_exists($adminConn, $schema)) {
        $schema = substr($base, 0, 50) . '_' . strval($suffix);
        $suffix++;
        if ($suffix > 9999) {
            $schema = substr($base, 0, 44) . '_' . substr(uniqid('', true), -6);
            break;
        }
    }
    return $schema;
}

function stobePlaythroughCreateSchemaSnapshot(string $name, string $notes = '', array $options = []): array
{
    $adminConn = stobePlaythroughConnectAdmin();
    if (!$adminConn) {
        return ['success' => false, 'id' => 0, 'error' => 'db_connect_failed'];
    }

    try {
        if (!stobePlaythroughEnsureMetaSchema($adminConn)) {
            return ['success' => false, 'id' => 0, 'error' => 'meta_schema_failed'];
        }

        $finalName = stobePlaythroughBuildUniqueProfileName($adminConn, $name);
        $schemaName = stobePlaythroughBuildUniqueSchemaName($adminConn, $finalName);

        $clone = pts_clone_schema($adminConn, 'public', $schemaName);
        if (!boolval($clone['success'] ?? false)) {
            return [
                'success' => false,
                'id' => 0,
                'error' => 'clone_failed: ' . strval($clone['error'] ?? 'unknown'),
            ];
        }

        $sizeBytes = pts_get_schema_size($adminConn, $schemaName);
        $stats = stobePlaythroughCollectSchemaStats($adminConn, 'public');

        $markActive = stobePlaythroughToBool($options['mark_active'] ?? false);
        $playerName = trim(strval($options['player_name'] ?? getSetting('PLAYER_NAME', 'Player')));
        $playerFactionMembers = stobePlaythroughCollectCurrentPlayerFactionMembers();
        $playerFactionMembersJson = json_encode($playerFactionMembers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($playerFactionMembersJson) || $playerFactionMembersJson === '') {
            $playerFactionMembersJson = '[]';
        }
        $gameName = trim(strval($options['game'] ?? 'Kenshi'));
        $storageType = trim(strval($options['storage_type'] ?? 'schema'));
        if ($storageType === '') {
            $storageType = 'schema';
        }
        $rollbackDeltaDays = intval($options['rollback_delta_days'] ?? 0);
        $rollbackFromGamets = intval($options['rollback_from_gamets'] ?? 0);
        $rollbackToGamets = intval($options['rollback_to_gamets'] ?? 0);

        @pg_query($adminConn, 'BEGIN');
        if ($markActive) {
            @pg_query($adminConn, 'UPDATE stobe_meta.playthrough_profiles SET is_active = FALSE');
        }

        $insert = @pg_query_params(
            $adminConn,
            'INSERT INTO stobe_meta.playthrough_profiles (
                name, size_bytes, storage_format, notes, is_active,
                player_name, player_faction_members, game, eventlog_count, oghma_count, last_gamets,
                schema_name, storage_type,
                rollback_delta_days, rollback_from_gamets, rollback_to_gamets
            ) VALUES (
                $1, $2, $3, $4, $5,
                $6, $7, $8, $9, $10, $11,
                $12, $13,
                $14, $15, $16
            ) RETURNING id',
            [
                $finalName,
                strval(max(0, intval($sizeBytes))),
                'schema_clone',
                $notes,
                $markActive ? 't' : 'f',
                $playerName,
                $playerFactionMembersJson,
                $gameName,
                strval(intval($stats['eventlog_count'] ?? 0)),
                strval(intval($stats['oghma_count'] ?? 0)),
                strval(intval($stats['last_gamets'] ?? 0)),
                $schemaName,
                $storageType,
                strval(max(0, $rollbackDeltaDays)),
                strval(max(0, $rollbackFromGamets)),
                strval(max(0, $rollbackToGamets)),
            ]
        );

        if (!$insert) {
            @pg_query($adminConn, 'ROLLBACK');
            $drop = pts_drop_schema($adminConn, $schemaName);
            stobeLogWarn('PLAYTHROUGH: snapshot metadata insert failed, dropped schema', [
                'schema' => $schemaName,
                'drop_success' => boolval($drop['success'] ?? false),
            ]);
            return ['success' => false, 'id' => 0, 'error' => 'profile_insert_failed'];
        }

        $row = @pg_fetch_assoc($insert);
        $profileId = intval($row['id'] ?? 0);
        @pg_query($adminConn, 'COMMIT');

        stobeLogInfo('PLAYTHROUGH: Snapshot created', [
            'profile_id' => $profileId,
            'name' => $finalName,
            'schema_name' => $schemaName,
            'size_bytes' => intval($sizeBytes),
            'eventlog_count' => intval($stats['eventlog_count'] ?? 0),
            'last_gamets' => intval($stats['last_gamets'] ?? 0),
            'player_faction_members_count' => count($playerFactionMembers),
        ]);

        return [
            'success' => true,
            'id' => $profileId,
            'name' => $finalName,
            'schema_name' => $schemaName,
            'size_bytes' => intval($sizeBytes),
            'eventlog_count' => intval($stats['eventlog_count'] ?? 0),
            'oghma_count' => intval($stats['oghma_count'] ?? 0),
            'last_gamets' => intval($stats['last_gamets'] ?? 0),
            'player_faction_members' => $playerFactionMembers,
            'error' => '',
        ];
    } catch (Throwable $exception) {
        stobeLogException($exception, 'PLAYTHROUGH: Snapshot creation failed');
        return ['success' => false, 'id' => 0, 'error' => $exception->getMessage()];
    } finally {
        @pg_close($adminConn);
    }
}

function stobePlaythroughGetProfileById(int $profileId): array|false
{
    if ($profileId <= 0) {
        return false;
    }
    stobePlaythroughEnsureMetaSchemaOnDemand();
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    return $db->fetchOne(
        'SELECT id, name, created_at, size_bytes, storage_format, storage_type, schema_name, notes,
                is_active, player_name, player_faction_members, game, eventlog_count, oghma_count, last_gamets,
                rollback_delta_days, rollback_from_gamets, rollback_to_gamets
         FROM stobe_meta.playthrough_profiles
         WHERE id = $1
         LIMIT 1',
        [$profileId]
    );
}

function stobePlaythroughListProfiles(int $limit = 500): array
{
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 5000) {
        $limit = 5000;
    }

    stobePlaythroughEnsureMetaSchemaOnDemand();
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }

    return $db->fetchAll(
        'SELECT id, name, created_at, size_bytes, storage_format, storage_type, schema_name, notes,
                is_active, player_name, player_faction_members, game, eventlog_count, oghma_count, last_gamets,
                rollback_delta_days, rollback_from_gamets, rollback_to_gamets
         FROM stobe_meta.playthrough_profiles
         ORDER BY COALESCE(last_gamets, 0) DESC, created_at DESC
         LIMIT ' . intval($limit)
    );
}

function stobePlaythroughDeleteProfile(int $profileId): array
{
    if ($profileId <= 0) {
        return ['success' => false, 'error' => 'invalid_profile_id'];
    }

    $adminConn = stobePlaythroughConnectAdmin();
    if (!$adminConn) {
        return ['success' => false, 'error' => 'db_connect_failed'];
    }

    try {
        if (!stobePlaythroughEnsureMetaSchema($adminConn)) {
            return ['success' => false, 'error' => 'meta_schema_failed'];
        }

        $rowRes = @pg_query_params(
            $adminConn,
            'SELECT id, name, schema_name, storage_type FROM stobe_meta.playthrough_profiles WHERE id = $1 LIMIT 1',
            [strval($profileId)]
        );
        $row = $rowRes ? @pg_fetch_assoc($rowRes) : null;
        if (!$row) {
            return ['success' => false, 'error' => 'profile_not_found'];
        }

        $schemaName = trim(strval($row['schema_name'] ?? ''));
        $storageType = trim(strval($row['storage_type'] ?? 'schema'));
        if ($storageType === 'schema' && $schemaName !== '') {
            $drop = pts_drop_schema($adminConn, $schemaName);
            if (!boolval($drop['success'] ?? false)) {
                return ['success' => false, 'error' => 'schema_drop_failed: ' . strval($drop['error'] ?? '')];
            }
        }

        $delete = @pg_query_params(
            $adminConn,
            'DELETE FROM stobe_meta.playthrough_profiles WHERE id = $1',
            [strval($profileId)]
        );
        if (!$delete) {
            return ['success' => false, 'error' => 'profile_delete_failed'];
        }

        stobeLogInfo('PLAYTHROUGH: Snapshot deleted', [
            'profile_id' => $profileId,
            'name' => strval($row['name'] ?? ''),
            'schema_name' => $schemaName,
        ]);

        return ['success' => true, 'error' => ''];
    } catch (Throwable $exception) {
        stobeLogException($exception, 'PLAYTHROUGH: Snapshot delete failed', ['profile_id' => $profileId]);
        return ['success' => false, 'error' => $exception->getMessage()];
    } finally {
        @pg_close($adminConn);
    }
}

function stobePlaythroughSwitchToProfile(int $profileId, bool $autoSnapshotCurrent = true): array
{
    if ($profileId <= 0) {
        return ['success' => false, 'error' => 'invalid_profile_id'];
    }

    $adminConn = stobePlaythroughConnectAdmin();
    if (!$adminConn) {
        return ['success' => false, 'error' => 'db_connect_failed'];
    }

    try {
        if (!stobePlaythroughEnsureMetaSchema($adminConn)) {
            return ['success' => false, 'error' => 'meta_schema_failed'];
        }

        $targetRes = @pg_query_params(
            $adminConn,
            'SELECT id, name, schema_name, storage_type FROM stobe_meta.playthrough_profiles WHERE id = $1 LIMIT 1',
            [strval($profileId)]
        );
        $target = $targetRes ? @pg_fetch_assoc($targetRes) : null;
        if (!$target) {
            return ['success' => false, 'error' => 'profile_not_found'];
        }

        $storageType = trim(strval($target['storage_type'] ?? 'schema'));
        $schemaName = trim(strval($target['schema_name'] ?? ''));
        if ($storageType !== 'schema' || $schemaName === '') {
            return ['success' => false, 'error' => 'unsupported_storage_type'];
        }
        if (!pts_schema_exists($adminConn, $schemaName)) {
            return ['success' => false, 'error' => 'source_schema_missing'];
        }

        $autosaveId = 0;
        if ($autoSnapshotCurrent) {
            $autoName = 'AutoSave before switch to ' . strval($target['name'] ?? ('#' . strval($profileId))) . ' @ ' . gmdate('Y-m-d H:i:s') . ' UTC';
            $autoSnapshot = stobePlaythroughCreateSchemaSnapshot($autoName, 'Automatic snapshot before profile switch', [
                'mark_active' => false,
                'storage_type' => 'schema',
                'game' => 'Kenshi',
            ]);
            if (!boolval($autoSnapshot['success'] ?? false)) {
                return ['success' => false, 'error' => 'autosave_failed: ' . strval($autoSnapshot['error'] ?? '')];
            }
            $autosaveId = intval($autoSnapshot['id'] ?? 0);
        }

        if (!pg_query($adminConn, 'BEGIN')) {
            return ['success' => false, 'error' => 'begin_restore_failed'];
        }
        $runtimeViews = pts_capture_public_views($adminConn);

        if (!pts_recreate_public_schema($adminConn)) {
            @pg_query($adminConn, 'ROLLBACK');
            return ['success' => false, 'error' => 'recreate_public_failed'];
        }

        $clone = pts_clone_schema($adminConn, $schemaName, 'public');
        if (!boolval($clone['success'] ?? false)) {
            @pg_query($adminConn, 'ROLLBACK');
            return ['success' => false, 'error' => 'clone_to_public_failed: ' . strval($clone['error'] ?? '')];
        }

        pts_restore_public_views($adminConn, $runtimeViews);

        @pg_query($adminConn, 'UPDATE stobe_meta.playthrough_profiles SET is_active = FALSE');
        $mark = @pg_query_params(
            $adminConn,
            'UPDATE stobe_meta.playthrough_profiles SET is_active = TRUE WHERE id = $1',
            [strval($profileId)]
        );
        if (!$mark) {
            @pg_query($adminConn, 'ROLLBACK');
            return ['success' => false, 'error' => 'mark_active_failed'];
        }

        if (!pg_query($adminConn, 'COMMIT')) {
            throw new RuntimeException('Could not commit playthrough restore');
        }

        stobeLogInfo('PLAYTHROUGH: Switched active snapshot to profile', [
            'profile_id' => $profileId,
            'name' => strval($target['name'] ?? ''),
            'schema_name' => $schemaName,
            'autosave_id' => $autosaveId,
        ]);

        return [
            'success' => true,
            'error' => '',
            'autosave_id' => $autosaveId,
        ];
    } catch (Throwable $exception) {
        @pg_query($adminConn, 'ROLLBACK');
        stobeLogException($exception, 'PLAYTHROUGH: Profile switch failed', ['profile_id' => $profileId]);
        return ['success' => false, 'error' => $exception->getMessage()];
    } finally {
        @pg_close($adminConn);
    }
}

function stobePlaythroughCurrentActiveProfileName(): string
{
    stobePlaythroughEnsureMetaSchemaOnDemand();
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return '';
    }

    $row = $db->fetchOne(
        'SELECT name FROM stobe_meta.playthrough_profiles WHERE is_active = TRUE ORDER BY id DESC LIMIT 1'
    );
    return trim(strval($row['name'] ?? ''));
}

?>
