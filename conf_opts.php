<?php

/**
 * StobeServer - conf_opts endpoint.
 *
 * Lightweight runtime key/value storage (Herika-style).
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json');

$method = strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $id = trim(strval($_GET['id'] ?? ''));
    if ($id === '') {
        echo json_encode([
            "status" => "ok",
            "id" => "",
            "value" => ""
        ]);
        exit;
    }
    echo json_encode([
        "status" => "ok",
        "id" => $id,
        "value" => getConfOpt($id, '')
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    stobeLogWarn('conf_opts rejected: invalid JSON payload');
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

$id = trim(strval($input['id'] ?? ''));
if ($id === '') {
    stobeLogWarn('conf_opts rejected: missing id');
    http_response_code(400);
    echo json_encode(["error" => "Missing id"]);
    exit;
}

$value = strval($input['value'] ?? '');
$onlyIfChanged = true;
if (array_key_exists('only_if_changed', $input)) {
    $flag = strtolower(trim(strval($input['only_if_changed'])));
    $onlyIfChanged = in_array($flag, ['1', 'true', 'yes', 'on'], true);
}

$changed = setConfOpt($id, $value, $onlyIfChanged);
if ($changed) {
    stobeLogInfo('conf_opts updated', [
        'id' => $id,
        'value_len' => strlen($value),
    ]);
}

echo json_encode([
    "status" => "ok",
    "id" => $id,
    "value" => $value,
    "changed" => $changed
]);

