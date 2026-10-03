<?php

/**
 * Plugin parity probe for StobeServer. CLI only; uses no database or provider.
 *
 *   php examples/plugin-parity/probe.php [--scratch=<empty-or-new-dir>]
 *
 * Copies the example plugins plus small fixtures into a scratch ext/ root and
 * runs the real loader, prompt injection API and action normalizer against it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$probeFailures = 0;
$probeWarnings = [];
set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$probeWarnings): bool {
    if ((error_reporting() & $severity) !== 0) {
        $probeWarnings[] = "{$message} ({$file}:{$line})";
    }
    return true;
});

function probeCheck(bool $condition, string $label, $detail = null): void
{
    global $probeFailures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label;
    if (!$condition && $detail !== null) {
        echo ' :: ' . json_encode($detail, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
    if (!$condition) {
        $probeFailures++;
    }
}

function probeWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function probeRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            probeRemoveTree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

$serverRoot = dirname(__DIR__, 2);
$scratchArg = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--scratch=')) {
        $scratchArg = substr($argument, 10);
    }
}
$scratch = $scratchArg !== '' ? $scratchArg : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stobe-plugin-parity-' . bin2hex(random_bytes(4));
if (is_dir($scratch) && count(scandir($scratch)) > 2) {
    fwrite(STDERR, "Scratch directory must be new or empty: {$scratch}\n");
    exit(2);
}
$extRoot = $scratch . DIRECTORY_SEPARATOR . 'ext';
$trace = static fn(string $label): string => "<?php\n\$GLOBALS['PLUGIN_PARITY_TRACE'][] = '{$label}';\n";

foreach (['parity_probe', 'parity_probe_order'] as $plugin) {
    foreach (glob(__DIR__ . "/ext/{$plugin}/*.php") as $source) {
        probeWrite("{$extRoot}/{$plugin}/" . basename($source), file_get_contents($source));
    }
}
// Fixtures that must never execute.
probeWrite("{$extRoot}/relationship_system/context_pre.php", $trace('LEGACY relationship context_pre'));
probeWrite("{$extRoot}/relationship_system/postrequest.php", $trace('LEGACY relationship postrequest'));
probeWrite("{$extRoot}/disabled_marker/globals.php", $trace('DISABLED marker'));
probeWrite("{$extRoot}/disabled_marker/.disabled", '');
probeWrite("{$extRoot}/renamed.disabled/globals.php", $trace('DISABLED suffix'));
probeWrite("{$extRoot}/.hidden_plugin/globals.php", $trace('HIDDEN'));
probeWrite("{$extRoot}/parity_probe/private/globals.php", $trace('PRIVATE'));
probeWrite("{$extRoot}/parity_probe/staging/globals.php", $trace('STAGING'));
probeWrite("{$extRoot}/globals.php", $trace('ROOT FILE'));
// A failing plugin is logged and skipped; later plugins still load.
probeWrite("{$extRoot}/broken_plugin/globals.php", "<?php\n\$GLOBALS['PLUGIN_PARITY_TRACE'][] = 'broken_plugin:globals';\nthrow new RuntimeException('fixture failure');\n");
probeWrite("{$extRoot}/parity_probe_order/json_response_custom.php", "<?php\n\$GLOBALS['PLUGIN_PARITY_TRACE'][] = 'parity_probe_order:json_response_custom';\n\$GLOBALS['responseTemplate']['direct_edit'] = 'from custom file';\n\$GLOBALS['HOOKS']['JSON_TEMPLATE'][] = static function (): void { \$GLOBALS['responseTemplate']['parity_probe'] = 'optional marker'; };\n");
probeWrite("{$extRoot}/parity_probe_order/preprocessing.php", "<?php\n\$gameRequest[3] = str_replace(': hi', ': hello there', \$gameRequest[3]);\n");
$symlinkFixture = 'skipped: symlink() unavailable';
probeWrite("{$scratch}/outside/globals.php", $trace('SYMLINK'));
if (function_exists('symlink') && @symlink("{$scratch}/outside", "{$extRoot}/linked_plugin")) {
    $symlinkFixture = 'created';
}

$GLOBALS['STOBE_EXTENSION_ROOT'] = $extRoot;
$GLOBALS['PLUGIN_PARITY_TRACE'] = [];
ini_set('error_log', $scratch . DIRECTORY_SEPARATOR . 'probe-error.log');
require_once $serverRoot . '/lib/data_functions.php';
require_once $serverRoot . '/lib/chat_helper_functions.php';

echo "Scratch ext root: {$extRoot}\nSymlink fixture: {$symlinkFixture}\n";

// Loader: discovery, order, include-once, exclusions.
$hookIndex = stobeExtensionHookIndex();
$ran = stobeRunExtensionHook('globals.php');
$relative = array_map(static fn(string $path): string => str_replace('\\', '/', substr($path, strlen($extRoot) + 1)), $ran);
probeCheck($relative === ['broken_plugin/globals.php', 'parity_probe/globals.php', 'parity_probe_order/globals.php'], 'globals.php files in byte-sorted order', $relative);
probeCheck($GLOBALS['PLUGIN_PARITY_TRACE'] === ['broken_plugin:globals', 'parity_probe:globals', 'parity_probe_order:globals'], 'two plugins ran after a failing plugin', $GLOBALS['PLUGIN_PARITY_TRACE']);
probeCheck(stobeRunExtensionHook('globals.php') === [] && requireFilesRecursively($extRoot, 'globals.php') === [], 'second run and CHIM requireFilesRecursively() are include-once');
$excluded = array_filter($GLOBALS['PLUGIN_PARITY_TRACE'], static fn(string $item): bool => preg_match('/^[A-Z]/', $item) === 1);
probeCheck(count($excluded) === 0, 'disabled, hidden, private, staging, root and symlink fixtures excluded', $excluded);
probeCheck(($hookIndex['context_pre.php'] ?? []) === [] && ($hookIndex['postrequest.php'] ?? []) === [], 'legacy ext/relationship_system hooks are not discovered');
probeWrite("{$extRoot}/late_plugin/context.php", $trace('LATE'));
$contextRan = stobeRunExtensionHook('context.php', ['inputtext', '1', '2', 'Player: hi']);
probeCheck(count($contextRan) === 1 && !in_array('LATE', $GLOBALS['PLUGIN_PARITY_TRACE'], true), 'later stages reuse the one-time directory index');
probeCheck(in_array('parity_probe:context:inputtext', $GLOBALS['PLUGIN_PARITY_TRACE'], true) && !array_key_exists('gameRequest', $GLOBALS), 'request view reaches context.php and is removed afterwards');
probeCheck(stobeRunExtensionHook('globals.php', null, $scratch . '/missing-ext') === [], 'missing ext root is a no-op');
$requestView = ['inputtext', '1', '2', 'Player: hi'];
stobeRunExtensionHook('preprocessing.php', $requestView, null, $requestView);
probeCheck($requestView[3] === 'Player: hello there' && !array_key_exists('gameRequest', $GLOBALS), 'input-stage edits to a request view are returned (chat.php)', $requestView);

// Prompt injection and enrichment API.
$systemPrompt = "<character>\nRoleplay as Beep.\n</character>\n<general_instructions>x</general_instructions>";
$rendered = stobeApplyExtensionPromptSections($systemPrompt, 'Beep', []);
$markerPos = strpos($rendered, '<parity_probe>');
probeCheck($markerPos !== false && $markerPos < strpos($rendered, '</character>'), 'character_bottom renders inside <character>');
probeCheck(strpos($rendered, '<parity_probe_order/>') < $markerPos, 'injection priority (10 before 90) overrides load order');
probeCheck(str_ends_with($rendered, '<parity_probe_footer>Prompt built for Beep.</parity_probe_footer>'), 'prompt_bottom callback renders last with context');
probeCheck(chimRenderPromptInjections('missing_slot') === '' && chimRegisterPromptInjection(' ', 'x', 'y') === false, 'empty slots and invalid keys fail cleanly');
probeCheck(chimBuildActorProfileEnrichmentText('Beep', 'npc') === 'Parity probe sees Beep', 'actor profile enricher via CHIM name');
$GLOBALS['HOOKS']['BIOGRAPHY_BUILDER']['probe'] = static function (string &$bio, array $npcData): void { $bio .= 'Bio from builder.'; };
probeCheck(str_contains(stobeApplyExtensionPromptSections($systemPrompt, 'Beep', []), "Bio from builder.\n<parity_probe_order/>"), 'BIOGRAPHY_BUILDER by-reference output joins <character>');
unset($GLOBALS['HOOKS']['BIOGRAPHY_BUILDER']);
[$template] = stobeApplyJsonTemplateHooks(['message' => 'line']);
probeCheck(($template['parity_probe'] ?? '') === 'optional marker' && ($template['direct_edit'] ?? '') === 'from custom file' && in_array('parity_probe_order:json_response_custom', $GLOBALS['PLUGIN_PARITY_TRACE'], true), 'json_response_custom.php direct edit + HOOKS JSON_TEMPLATE on first template');
[, $format] = stobeApplyJsonTemplateHooks([], ['type' => 'json_schema', 'json_schema' => []]);
[$second] = stobeApplyJsonTemplateHooks(['message' => 'line']);
probeCheck(($format['type'] ?? '') === 'json_schema' && !isset($second['direct_edit']) && ($second['parity_probe'] ?? '') === 'optional marker', 'later templates get JSON_TEMPLATE hooks; custom file stays include-once');
// A callback sees both real templates and derives the provider schema from the prompt schema.
$GLOBALS['HOOKS']['JSON_TEMPLATE']['derive'] = static function (): void {
    $GLOBALS['PLUGIN_PARITY_TRACE'][] = 'json_template:derive';
    $GLOBALS['responseTemplate']['mood_hint'] = 'seen ' . count($GLOBALS['structuredOutputTemplate']['json_schema']['schema']['required'] ?? []) . ' required';
    $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties']['mood_hint'] = ['type' => 'string', 'description' => $GLOBALS['responseTemplate']['mood_hint']];
    $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['required'][] = 'mood_hint';
};
$deriveRuns = static fn(): int => count(array_keys($GLOBALS['PLUGIN_PARITY_TRACE'], 'json_template:derive', true));
$runsBefore = $deriveRuns();
$pairPrompt = stobeBuildStructuredDialogueSchemaPrompt('Beep', ['Talk'], ['default']);
$pairFormat = stobeBuildStructuredDialogueTemplates('Beep', ['Talk'], ['default'], '', 'chat', 1);
$derivedProperty = $pairFormat['json_schema']['schema']['properties']['mood_hint'] ?? [];
probeCheck(($pairPrompt['mood_hint'] ?? '') === 'seen 9 required' && ($derivedProperty['description'] ?? '') === 'seen 9 required' && in_array('mood_hint', $pairFormat['json_schema']['schema']['required'] ?? [], true) && $deriveRuns() - $runsBefore === 1, 'JSON_TEMPLATE callback sees both real templates; one hook run feeds prompt and response_format', [$pairPrompt['mood_hint'] ?? null, $derivedProperty, $deriveRuns() - $runsBefore]);
stobeBuildStructuredDialogueSchemaPrompt('Beep', ['Talk'], ['default']);
probeCheck($deriveRuns() - $runsBefore === 2, 'next build of a served template reruns hooks', $deriveRuns() - $runsBefore);
unset($GLOBALS['HOOKS']['JSON_TEMPLATE']['derive']);

// External action registration and validation.
probeCheck(isset(stobeExtensionActionRegistry()['ExtCmdParityProbe_Ping']), 'example registered ExtCmdParityProbe_Ping');
$invalidCodes = ['ExtCmd_Ping', 'Ping', 'ExtCmdA_B C', 'ExtCmdParity', 'WebCmdParity_Ping'];
probeCheck(count(array_filter($invalidCodes, static fn(string $code): bool => stobeRegisterExtensionAction($code, 'x'))) === 0 && !stobeRegisterExtensionAction('ExtCmdParity_NoDescription', ' '), 'invalid codes and empty descriptions rejected');
probeCheck(!stobeRegisterExtensionAction('EXTCMDPARITYPROBE_PING', 'case clash'), 'case-insensitive duplicate code rejected');
$actionCases = [
    ['ExtCmdParityProbe_Ping@Beep', [], 'ExtCmdParityProbe_Ping@Beep'],
    ['EXTCMDPARITYPROBE_PING@Beep', [], 'ExtCmdParityProbe_Ping@Beep'],
    ['ExtCmdParityProbe_Ping@', [], 'ExtCmdParityProbe_Ping@'],
    ["ExtCmdParityProbe_Ping@Be|ep\n[x]@y", [], 'ExtCmdParityProbe_Ping@Beep xy'],
    ['ExtCmdUnknown_Thing@Beep', [], ''],
    ['ExtCmdParityProbe_Ping@Beep', ['extension_actions' => []], ''],
    ['ExtCmdParityProbe_Ping@Beep', ['extension_actions' => ['ExtCmdUnknown_Thing']], ''],
    ['ExtCmdParityProbe_Ping', [], ''],
];
foreach ($actionCases as [$raw, $config, $expected]) {
    $actual = normalizeActionTagToken($raw, $config);
    probeCheck($actual === $expected, 'normalizeActionTagToken(' . json_encode($raw) . ')', ['expected' => $expected, 'actual' => $actual]);
}
$structured = normalizeActionTagToken(stobeBuildActionTagFromStructuredPayload('ExtCmdParityProbe_Ping', '', 'Beep', 'hello'));
probeCheck($structured === 'ExtCmdParityProbe_Ping@Beep', 'structured response action resolves through registry', $structured);
probeCheck(stobeTransformActionForDispatch('ExtCmdParityProbe_Ping@Beep') === 'ExtCmdParityProbe_Ping@Beep', 'ActionQueue dispatch keeps exact code');
probeCheck(stobeExtensionActionCodesForAllowlist(['FOLLOW']) === [] && stobeExtensionActionCodesForAllowlist(['EXTCMDPARITYPROBE_PING']) === ['ExtCmdParityProbe_Ping'], 'explicit ACTIONS_ALLOWLIST gates plugin actions');
probeCheck(normalizeActionTagToken('FOLLOW@Beep', ['allowlist' => []]) === 'FOLLOW@Beep', 'native action normalization unchanged');

// Observer: STOBE client funcret through the real prerequest hook, addon_state, malformed payloads.
$GLOBALS['gameRequest'] = ['funcret', '1', '2', 'command@ExtCmdParityProbe_Ping@Beep@completed: Beep heard the ping'];
stobeRunExtensionHook('prerequest.php');
probeCheck(in_array('parity_probe:completion:Beep', $GLOBALS['PLUGIN_PARITY_TRACE'], true), 'completion observer saw client funcret through prerequest.php');
probeCheck((parityProbeObserveEvent(['addon_state', '1', '2', 'ParityProbe: state=ready'])['result'] ?? '') === 'state=ready', 'addon_state "<Addon>: key=value" observed');
$malformed = [null, [], ['funcret'], ['funcret', '', '', ['array']], ['funcret', '', '', 'command@ExtCmdOther_Ping@x@y'], ['funcret', '', '', 'command@ExtCmdParityProbe_Ping@x'], ['inputtext', '', '', 'command@ExtCmdParityProbe_Ping@x@y'], ['addon_state', '', '', 'Other: a=b'], ['funcret', '', '', str_repeat('a', 5000)]];
probeCheck(count(array_filter(array_map('parityProbeObserveEvent', $malformed))) === 0, 'malformed or foreign events ignored');
unset($GLOBALS['gameRequest']);

probeCheck($probeWarnings === [], 'no PHP warnings or notices', $probeWarnings);
$errorLog = is_file($scratch . '/probe-error.log') ? file_get_contents($scratch . '/probe-error.log') : '';
probeCheck(str_contains($errorLog, 'fixture failure') && str_contains($errorLog, '[parity_probe] completion: completed: Beep heard the ping'), 'plugin failure and completion were logged');

if ($scratchArg === '') {
    probeRemoveTree($scratch);
}
echo $probeFailures === 0 ? "RESULT PASS\n" : "RESULT FAIL ({$probeFailures})\n";
exit($probeFailures === 0 ? 0 : 1);
