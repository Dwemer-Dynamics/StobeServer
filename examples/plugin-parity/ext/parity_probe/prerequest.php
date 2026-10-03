<?php

// Observes the STOBE client's ParityProbe events on the existing event route:
//   funcret      "command@ExtCmdParityProbe_Ping@<argument>@<completed|failed[: detail]>"
//   addon_state  "ParityProbe: <key>=<value>"
if (!function_exists('parityProbeObserveEvent')) {
    function parityProbeObserveEvent($request): ?array
    {
        $data = is_array($request) ? ($request[3] ?? null) : null;
        if (!is_string($data) || strlen($data) > 4096) {
            return null;
        }
        if (($request[0] ?? '') === 'funcret') {
            $parts = explode('@', $data, 4);
            if (count($parts) === 4 && $parts[0] === 'command' && $parts[1] === 'ExtCmdParityProbe_Ping') {
                return ['kind' => 'completion', 'argument' => trim($parts[2]), 'result' => trim($parts[3])];
            }
        } elseif (($request[0] ?? '') === 'addon_state' && str_starts_with($data, 'ParityProbe: ')) {
            return ['kind' => 'state', 'argument' => '', 'result' => trim(substr($data, 13))];
        }
        return null;
    }
}

$parityProbeEvent = parityProbeObserveEvent($gameRequest ?? null);
if ($parityProbeEvent !== null) {
    $GLOBALS['PLUGIN_PARITY_TRACE'][] = 'parity_probe:' . $parityProbeEvent['kind'] . ':' . $parityProbeEvent['argument'];
    error_log('[parity_probe] ' . $parityProbeEvent['kind'] . ': ' . substr($parityProbeEvent['result'], 0, 200));
}
