<?php
/**
 * BATCH RELATIONSHIP BUILD API
 *
 * Processes NPCs with stored relationships that haven't been copied to JSONB yet.
 * Called from the NPC master page for existing playthroughs.
 *
 * Endpoints:
 *   GET  ?action=status  - Get count of NPCs needing processing
 *   POST ?action=process - Process a batch of NPCs (returns progress)
 *   POST ?action=cancel  - Cancel ongoing processing (sets flag)
 */

header('Content-Type: application/json');

$enginePath = __DIR__ . "/../../";
require_once($enginePath . "lib/bootstrap.php");
require_once($enginePath . "lib/postgresql.class.php");
// Optional in Stobe: relationship builder does not depend on ApiBadge.
$apiBadgePath = $enginePath . "lib/core/api_badge.class.php";
if (file_exists($apiBadgePath)) {
    require_once($apiBadgePath);
}

if (!isset($GLOBALS['db']) || !($GLOBALS['db'] instanceof sql)) {
    $GLOBALS['db'] = new sql();
}
$GLOBALS['ENGINE_PATH'] = $enginePath;

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

switch ($action) {
    case 'status':
        echo json_encode(getStatus());
        break;

    case 'stats':
        // Stats for the modal - model info and counts
        echo json_encode(getStats());
        break;

    case 'list':
        // Get list of NPCs to process
        $force = intval($_GET['force'] ?? 0);
        echo json_encode(getNpcList($force));
        break;

    case 'process':
        // Process single NPC by id
        $npcId = intval($_GET['id'] ?? $_POST['id'] ?? 0);
        $force = intval($_GET['force'] ?? $_POST['force'] ?? 0);
        if ($npcId > 0) {
            echo json_encode(processSingleNpc($npcId, $force));
        } else {
            $batchSize = intval($_POST['batch_size'] ?? 5);
            echo json_encode(processBatch($batchSize));
        }
        break;

    case 'infer':
        // Run transitive inference
        echo json_encode(runInference());
        break;

    case 'cancel':
        // Set a cancel flag in the database
        try {
            $GLOBALS['db']->execQuery("DELETE FROM core_player WHERE id = 'rel_batch_cancel'");
            $GLOBALS['db']->insert('core_player', [
                'id' => 'rel_batch_cancel',
                'value' => '1'
            ]);
            echo json_encode(['ok' => true, 'cancelled' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

/**
 * Resolve relationship connector and display model for Stobe.
 * Priority:
 * 1) Explicit global RELLLM_CONNECTOR
 * 2) Default profile relationship_connector
 * 3) Default profile response_connector
 * 4) First available connector
 */
function resolveRelationshipConnectorMeta(): array {
    $db = $GLOBALS['db'];
    $connectorId = 0;

    if (!empty($GLOBALS['RELLLM_CONNECTOR']) && intval($GLOBALS['RELLLM_CONNECTOR']) > 0) {
        $connectorId = intval($GLOBALS['RELLLM_CONNECTOR']);
    }

    if ($connectorId <= 0) {
        $profileRow = $db->fetchOne("
            SELECT relationship_connector, response_connector
            FROM core_profiles
            WHERE COALESCE(is_default_npc, FALSE) = TRUE
            ORDER BY id ASC
            LIMIT 1
        ");
        if (is_array($profileRow)) {
            $rel = intval($profileRow['relationship_connector'] ?? 0);
            $resp = intval($profileRow['response_connector'] ?? 0);
            if ($rel > 0) {
                $connectorId = $rel;
            } elseif ($resp > 0) {
                $connectorId = $resp;
            }
        }
    }

    $connector = null;
    if ($connectorId > 0) {
        $connector = $db->fetchOne("SELECT id, name, model, connector_type FROM core_llm_connector WHERE id = " . intval($connectorId) . " LIMIT 1");
    }

    if (!is_array($connector) || empty($connector)) {
        $connector = $db->fetchOne("SELECT id, name, model, connector_type FROM core_llm_connector ORDER BY id ASC LIMIT 1");
    }

    if (is_array($connector) && !empty($connector)) {
        $label = trim(strval($connector['name'] ?? ''));
        $model = trim(strval($connector['model'] ?? ''));
        $type = trim(strval($connector['connector_type'] ?? ''));
        $display = $label !== '' ? $label : ($model !== '' ? $model : ($type !== '' ? $type : 'Configured'));
        return [
            'configured' => true,
            'connector_id' => intval($connector['id'] ?? 0),
            'model' => $display,
        ];
    }

    return [
        'configured' => false,
        'connector_id' => 0,
        'model' => 'Not configured',
    ];
}

/**
 * Get status of NPCs needing relationship processing
 */
function getStatus() {
    $db = $GLOBALS['db'];

    // Match HerikaServer's bulk scope: only migrate NPCs with stored relationships.
    $needsProcessing = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM core_npc_master
        WHERE COALESCE(TRIM(relationships), '') <> ''
          AND name != 'The Narrator'
          AND (extended_data IS NULL
               OR extended_data::text NOT LIKE '%\"relationships\"%'
               OR extended_data->>'relationships' IS NULL)
    ");

    // Count NPCs already processed
    $alreadyProcessed = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM core_npc_master
        WHERE extended_data IS NOT NULL
          AND extended_data::text LIKE '%\"relationships\"%'
          AND name != 'The Narrator'
    ");

    $relMeta = resolveRelationshipConnectorMeta();
    $relLlmConfigured = !empty($relMeta['configured']);
    $connectorInfo = null;
    if ($relLlmConfigured) {
        $connectorInfo = [
            'label' => strval($relMeta['model'] ?? 'Configured'),
            'model' => strval($relMeta['model'] ?? 'Configured'),
        ];
    }

    return [
        'ok' => true,
        'needs_processing' => intval($needsProcessing['count'] ?? 0),
        'already_processed' => intval($alreadyProcessed['count'] ?? 0),
        'relllm_configured' => $relLlmConfigured,
        'connector' => $connectorInfo
    ];
}

/**
 * Process a batch of NPCs
 */
function processBatch($batchSize = 5) {
    $db = $GLOBALS['db'];

    // Check for cancel flag
    $cancelCheck = $db->fetchOne("SELECT value FROM core_player WHERE id = 'rel_batch_cancel'");
    if ($cancelCheck && $cancelCheck['value'] === '1') {
        // Clear the flag
        $db->execQuery("DELETE FROM core_player WHERE id = 'rel_batch_cancel'");
        return ['ok' => true, 'cancelled' => true, 'processed' => 0];
    }

    require_once __DIR__ . "/relationship_llm.php";
    $relLlm = new RelationshipLLM();

    // Get batch of NPCs to process
    $npcs = $db->fetchAll("
        SELECT id, name AS npc_name, relationships
        FROM core_npc_master
        WHERE COALESCE(TRIM(relationships), '') <> ''
          AND name != 'The Narrator'
          AND (extended_data IS NULL
               OR extended_data::text NOT LIKE '%\"relationships\"%'
               OR extended_data->>'relationships' IS NULL)
        ORDER BY id
        LIMIT " . intval($batchSize)
    );

    if (empty($npcs)) {
        return [
            'ok' => true,
            'processed' => 0,
            'remaining' => 0,
            'complete' => true,
            'message' => 'All NPCs have been processed!'
        ];
    }

    $results = [];
    $processed = 0;
    $errors = 0;

    foreach ($npcs as $npc) {
        // Check cancel flag between each NPC
        $cancelCheck = $db->fetchOne("SELECT value FROM core_player WHERE id = 'rel_batch_cancel'");
        if ($cancelCheck && $cancelCheck['value'] === '1') {
            $db->execQuery("DELETE FROM core_player WHERE id = 'rel_batch_cancel'");
            return [
                'ok' => true,
                'cancelled' => true,
                'processed' => $processed,
                'results' => $results
            ];
        }

        try {
            $result = $relLlm->analyzeNpc($npc['id'], false);

            if ($result['ok'] && empty($result['skipped'])) {
                $processed++;
                $results[] = [
                    'npc' => $npc['npc_name'],
                    'status' => 'success',
                    'count' => $result['count'] ?? 0
                ];
            } elseif (!empty($result['skipped'])) {
                $results[] = [
                    'npc' => $npc['npc_name'],
                    'status' => 'skipped',
                    'reason' => $result['reason'] ?? 'Already processed'
                ];
            } else {
                $errors++;
                $results[] = [
                    'npc' => $npc['npc_name'],
                    'status' => 'error',
                    'error' => $result['error'] ?? 'Unknown error'
                ];
            }
        } catch (Exception $e) {
            $errors++;
            $results[] = [
                'npc' => $npc['npc_name'],
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }

        // Small delay to avoid rate limiting
        usleep(150000); // 150ms
    }

    // Get remaining count
    $remaining = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM core_npc_master
        WHERE COALESCE(TRIM(relationships), '') <> ''
          AND name != 'The Narrator'
          AND (extended_data IS NULL
               OR extended_data::text NOT LIKE '%\"relationships\"%'
               OR extended_data->>'relationships' IS NULL)
    ");

    return [
        'ok' => true,
        'processed' => $processed,
        'errors' => $errors,
        'remaining' => intval($remaining['count'] ?? 0),
        'complete' => intval($remaining['count'] ?? 0) === 0,
        'results' => $results
    ];
}

/**
 * Get stats for the modal display
 */
function getStats() {
    $db = $GLOBALS['db'];

    $relMeta = resolveRelationshipConnectorMeta();
    $model = strval($relMeta['model'] ?? 'Not configured');

    // Count already built
    $built = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM core_npc_master
        WHERE extended_data IS NOT NULL
          AND extended_data::text LIKE '%\"relationships\"%'
          AND name != 'The Narrator'
    ");

    // Count pending stored relationship maps, matching HerikaServer's bulk scope.
    $pending = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM core_npc_master
        WHERE COALESCE(TRIM(relationships), '') <> ''
          AND name != 'The Narrator'
          AND (extended_data IS NULL
               OR extended_data::text NOT LIKE '%\"relationships\"%'
               OR extended_data->>'relationships' IS NULL)
    ");

    return [
        'ok' => true,
        'model' => $model,
        'built' => intval($built['count'] ?? 0),
        'pending' => intval($pending['count'] ?? 0)
    ];
}

/**
 * Get list of NPCs to process
 */
function getNpcList($force = 0) {
    $db = $GLOBALS['db'];

    if ($force) {
        // All NPCs with stored relationships.
        $npcs = $db->fetchAll("
            SELECT id, name as name
            FROM core_npc_master
            WHERE COALESCE(TRIM(relationships), '') <> ''
              AND name != 'The Narrator'
            ORDER BY name
        ");
    } else {
        // Only NPCs needing processing
        $npcs = $db->fetchAll("
            SELECT id, name as name
            FROM core_npc_master
            WHERE COALESCE(TRIM(relationships), '') <> ''
              AND name != 'The Narrator'
              AND (extended_data IS NULL
                   OR extended_data::text NOT LIKE '%\"relationships\"%'
                   OR extended_data->>'relationships' IS NULL)
            ORDER BY name
        ");
    }

    return [
        'ok' => true,
        'npcs' => $npcs ?: []
    ];
}

/**
 * Process a single NPC by ID
 */
function processSingleNpc($npcId, $force = 0) {
    require_once __DIR__ . "/relationship_llm.php";
    $relLlm = new RelationshipLLM();

    try {
        $result = $relLlm->analyzeNpc($npcId, (bool)$force);
        if ($result['ok']) {
            return [
                'ok' => true,
                'count' => $result['count'] ?? 0
            ];
        } else {
            return [
                'ok' => false,
                'error' => $result['error'] ?? 'Analysis failed'
            ];
        }
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Run transitive inference on all NPCs with relationships
 */
function runInference() {
    require_once __DIR__ . "/relationship_llm.php";
    $relLlm = new RelationshipLLM();
    $db = $GLOBALS['db'];

    try {
        // Get all NPCs with JSONB relationships
        $npcs = $db->fetchAll("
            SELECT id, name AS npc_name
            FROM core_npc_master
            WHERE extended_data IS NOT NULL
              AND extended_data::text LIKE '%\"relationships\"%'
              AND name != 'The Narrator'
            ORDER BY name
        ");

        $totalInferred = 0;
        foreach ($npcs as $npc) {
            $result = $relLlm->inferTransitiveRelationships($npc['id']);
            if ($result['ok'] && isset($result['inferred'])) {
                $totalInferred += $result['inferred'];
            }
        }

        return [
            'ok' => true,
            'count' => $totalInferred,
            'npcs_processed' => count($npcs)
        ];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

