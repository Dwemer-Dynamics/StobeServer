<?php
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/settings_presets.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $token = stobePresetToken();
    $scope = $_GET['scope'] ?? 'global';
    if (!is_string($scope)) {
        throw new InvalidArgumentException('Unknown preset scope.');
    }
    $catalog = stobePresetCatalog($scope);
    $db = $GLOBALS['db'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($token, strval($_SERVER['HTTP_X_STOBE_PRESET_TOKEN'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Reload this page before saving presets.']);
            exit;
        }
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if (strlen($raw) > 16384) {
            throw new InvalidArgumentException('Preset files must be smaller than 16 KB.');
        }
        $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['name'] ?? null)) {
            throw new InvalidArgumentException('Invalid preset request.');
        }
        if (($input['action'] ?? '') === 'save') {
            if (($input['format'] ?? '') !== 'stobe-settings-preset' || ($input['version'] ?? null) !== 1 || ($input['scope'] ?? '') !== $scope) {
                throw new InvalidArgumentException('Choose a Stobe preset for this settings page.');
            }
            stobeSavePreset($db, $scope, $input['name'], $input['settings'] ?? null, ($input['overwrite'] ?? false) === true);
        } elseif (($input['action'] ?? '') === 'delete') {
            if ($db->exec('DELETE FROM stobe_settings_presets WHERE scope = $1 AND name = $2', [$scope, $input['name']]) === false) {
                throw new RuntimeException('Could not delete preset.');
            }
        } else {
            throw new InvalidArgumentException('Unknown preset action.');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit;
    }
    if (!$db->fetchOne("SELECT to_regclass('stobe_settings_presets') AS table_name")['table_name']) {
        throw new RuntimeException('Preset table is missing.');
    }
    $presets = stobeBuiltinPresets($scope);
    foreach ($db->fetchAll('SELECT name, settings FROM stobe_settings_presets WHERE scope = $1 ORDER BY lower(name) LIMIT 50', [$scope]) as $row) {
        $presets[] = ['name' => $row['name'], 'builtin' => false, 'description' => 'Saved settings preset.',
            'settings' => stobeNormalizePreset($scope, json_decode($row['settings'], true, 32, JSON_THROW_ON_ERROR))];
    }
    echo json_encode(['ok' => true, 'presets' => $presets, 'catalog' => $catalog], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException|JsonException $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error instanceof JsonException ? 'Invalid preset JSON.' : $error->getMessage()]);
} catch (Throwable $error) {
    stobeLogException($error, 'Settings preset request failed');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Presets are unavailable. Run database updates and reload this page.']);
}
