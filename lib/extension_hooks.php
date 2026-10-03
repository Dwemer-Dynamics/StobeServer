<?php

/**
 * CHIM-compatible server extension hooks for StobeServer.
 *
 * Named hook files under ext/<plugin>/ are discovered once per request and
 * required once each, in byte-sorted path order. See docs/plugin-runtime.md.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'prompt_injections.php';

function stobeExtensionHookNames(): array
{
    return [
        'globals.php',
        'preprocessing.php',
        'prerequest.php',
        'prompts.php',
        'dialogue_prompt.php',
        'context_building.php',
        'json_response_custom.php',
        'context_pre.php',
        'context.php',
        'prepostrequest.php',
        'postrequest.php',
    ];
}

function stobeExtensionRoot(): string
{
    $root = $GLOBALS['STOBE_EXTENSION_ROOT'] ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ext');
    return rtrim(strval($root), '/\\');
}

// Built-in directories with their own explicit callers. Stobe's active
// relationship evaluator lives in lib/chat_helper_functions.php; the legacy
// ext/relationship_system hook files must never run as generic hooks.
function stobeExtensionReservedDirectories(): array
{
    return ['relationship_system'];
}

function stobeExtensionTopLevelEnabled(string $name, string $path): bool
{
    if (in_array(strtolower($name), stobeExtensionReservedDirectories(), true)) {
        return false;
    }
    if (str_ends_with(strtolower($name), '.disabled')) {
        return false;
    }
    return !file_exists($path . DIRECTORY_SEPARATOR . '.disabled');
}

function stobeExtensionScanDirectory(string $dir, array &$index, int $depth): void
{
    $entries = @scandir($dir, SCANDIR_SORT_NONE);
    if (!is_array($entries)) {
        return;
    }
    sort($entries, SORT_STRING);

    foreach ($entries as $entry) {
        // Dot entries cover ., .., hidden files and package/VCS metadata.
        if ($entry === '' || $entry[0] === '.') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_link($path)) {
            continue;
        }
        if (is_dir($path)) {
            if ($depth === 0 && !stobeExtensionTopLevelEnabled($entry, $path)) {
                continue;
            }
            if (in_array(strtolower($entry), ['private', 'staging'], true) || $depth >= 6) {
                continue;
            }
            stobeExtensionScanDirectory($path, $index, $depth + 1);
            continue;
        }
        // Files directly under ext/ are not plugins.
        if ($depth > 0 && str_ends_with($entry, '.php') && is_file($path)) {
            $index[$entry][] = $path;
        }
    }
}

/**
 * One traversal per root per request; later hook stages reuse the index.
 */
function stobeExtensionHookIndex(?string $root = null): array
{
    static $cache = [];
    $root = rtrim($root ?? stobeExtensionRoot(), '/\\');
    $key = realpath($root) ?: $root;
    if (!isset($cache[$key])) {
        $index = [];
        if ($root !== '' && is_dir($root) && !is_link($root)) {
            stobeExtensionScanDirectory($root, $index, 0);
        }
        $cache[$key] = $index;
    }
    return $cache[$key];
}

function stobeExtensionRequireOnce(string $file, string $hookName): bool
{
    static $loaded = [];
    $key = realpath($file) ?: $file;
    if (isset($loaded[$key])) {
        return false;
    }
    $loaded[$key] = true;

    // Same visible scope as CHIM's requireFilesRecursively(), without creating
    // a global $gameRequest on endpoints that have none.
    $gameRequest = null;
    if (array_key_exists('gameRequest', $GLOBALS)) {
        $gameRequest = &$GLOBALS['gameRequest'];
    }
    try {
        require_once $file;
    } catch (Throwable $exception) {
        error_log('[ExtensionHooks] ' . $hookName . ' failed in ' . $file . ': ' . $exception->getMessage());
    }
    return true;
}

/**
 * Runs one named hook stage. $requestView temporarily supplies a CHIM-style
 * $gameRequest to endpoints that have none; the caller's state is restored
 * and the view as left by the hooks is returned in $updatedView.
 *
 * @return string[] files executed by this call
 */
function stobeRunExtensionHook(
    string $hookName,
    ?array $requestView = null,
    ?string $root = null,
    ?array &$updatedView = null
): array {
    $updatedView = $requestView;
    $files = stobeExtensionHookIndex($root)[$hookName] ?? [];
    if (count($files) === 0) {
        return [];
    }

    $hadRequest = array_key_exists('gameRequest', $GLOBALS);
    $previousRequest = $hadRequest ? $GLOBALS['gameRequest'] : null;
    if ($requestView !== null) {
        $GLOBALS['gameRequest'] = $requestView;
    }

    $ran = [];
    try {
        foreach ($files as $file) {
            if (stobeExtensionRequireOnce($file, $hookName)) {
                $ran[] = $file;
            }
        }
    } finally {
        if ($requestView !== null) {
            if (is_array($GLOBALS['gameRequest'] ?? null)) {
                $updatedView = $GLOBALS['gameRequest'];
            }
            if ($hadRequest) {
                $GLOBALS['gameRequest'] = $previousRequest;
            } else {
                unset($GLOBALS['gameRequest']);
            }
        }
    }
    return $ran;
}

if (!function_exists('requireFilesRecursively')) {
    // CHIM signature. Uses the same cached index, exclusions and include-once rules.
    function requireFilesRecursively($dir, $name)
    {
        return stobeRunExtensionHook(strval($name), null, strval($dir));
    }
}

/**
 * Post-response stages. The client stream is already complete, so hook output
 * is discarded instead of being appended to the game protocol.
 */
function stobeRunPostResponseExtensionHooks(?array $requestView = null): void
{
    @flush();
    ob_start();
    try {
        stobeRunExtensionHook('prepostrequest.php', $requestView);
        stobeRunExtensionHook('postrequest.php', $requestView);
    } finally {
        ob_end_clean();
    }
}

/**
 * Applies extension edits to Stobe's prompt schema ($responseTemplate) and
 * provider response_format ($structuredOutputTemplate). As in CHIM,
 * json_response_custom.php loads once after a template exists, so its direct
 * edits reach the first template built in the request; HOOKS JSON_TEMPLATE
 * callbacks run for every template.
 */
function stobeApplyJsonTemplateHooks(array $responseTemplate, array $structuredOutputTemplate = []): array
{
    $GLOBALS['responseTemplate'] = $responseTemplate;
    $GLOBALS['structuredOutputTemplate'] = $structuredOutputTemplate;
    stobeRunExtensionHook('json_response_custom.php');
    $hooks = $GLOBALS['HOOKS']['JSON_TEMPLATE'] ?? [];
    foreach (is_array($hooks) ? $hooks : [] as $hook) {
        if (!is_callable($hook)) {
            continue;
        }
        try {
            call_user_func($hook);
        } catch (Throwable $exception) {
            error_log('[ExtensionHooks] JSON_TEMPLATE hook failed: ' . $exception->getMessage());
        }
    }

    $updatedResponse = is_array($GLOBALS['responseTemplate'] ?? null) ? $GLOBALS['responseTemplate'] : $responseTemplate;
    $updatedStructured = $GLOBALS['structuredOutputTemplate'] ?? null;
    if (count($structuredOutputTemplate) > 0 && !(is_array($updatedStructured) && isset($updatedStructured['type']))) {
        $updatedStructured = $structuredOutputTemplate;
    }
    return [$updatedResponse, is_array($updatedStructured) ? $updatedStructured : []];
}

/**
 * CHIM HOOKS BIOGRAPHY_BUILDER adapter. Stobe has no CHIM dynamic biography
 * fields, so builders start from an empty string; output joins <character>.
 */
function stobeApplyBiographyBuilders(string $biography, array $npcData): string
{
    $builders = $GLOBALS['HOOKS']['BIOGRAPHY_BUILDER'] ?? null;
    if (!is_array($builders)) {
        return $biography;
    }
    foreach ($builders as $builderName => $builder) {
        if (!is_callable($builder)) {
            continue;
        }
        try {
            $result = call_user_func_array($builder, [&$biography, $npcData]);
        } catch (Throwable $exception) {
            error_log('[ExtensionHooks] BIOGRAPHY_BUILDER ' . $builderName . ' failed: ' . $exception->getMessage());
            continue;
        }
        if (is_string($result) && trim($result) !== '') {
            $biography = $result;
        }
    }
    return $biography;
}

/**
 * Renders plugin prompt sections at the dialogue prompt callsites:
 * BIOGRAPHY_BUILDER + character_bottom inside <character>, prompt_bottom last.
 */
function stobeApplyExtensionPromptSections(
    string $systemPrompt,
    string $npcName,
    array $npcData,
    ?array $requestView = null
): string {
    $context = [
        'game_request' => $requestView ?? (is_array($GLOBALS['gameRequest'] ?? null) ? $GLOBALS['gameRequest'] : []),
        'herika_name' => $npcName,
        'npc_name' => $npcName,
        'narrator_name' => function_exists('stobeNarratorName') ? stobeNarratorName() : 'The Narrator',
        'player_name' => function_exists('getSetting') ? strval(getSetting('PLAYER_NAME', 'Drifter')) : '',
    ];

    $characterBlock = trim(stobeApplyBiographyBuilders('', $npcData))
        . stobeRenderPromptInjections('character_bottom', $context);
    if (trim($characterBlock) !== '') {
        $closePos = strpos($systemPrompt, '</character>');
        if ($closePos === false) {
            $systemPrompt .= "\n" . trim($characterBlock);
        } else {
            $systemPrompt = substr($systemPrompt, 0, $closePos) . rtrim($characterBlock) . "\n" . substr($systemPrompt, $closePos);
        }
    }

    return $systemPrompt . stobeRenderPromptInjections('prompt_bottom', $context);
}

/*
 * External actions: ExtCmd<Bridge>_<Action>. Only codes registered by a loaded
 * plugin reach the response schema, allowlist and ActionQueue dispatch.
 */
function stobeRegisterExtensionAction(string $codeName, string $description, array $options = []): bool
{
    $codeName = trim($codeName);
    if (strlen($codeName) > 64 || preg_match('/^ExtCmd[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*$/', $codeName) !== 1) {
        error_log('[ExtensionActions] Rejected invalid action code: ' . substr($codeName, 0, 80));
        return false;
    }
    $description = trim(preg_replace('/\s+/', ' ', $description) ?? '');
    $target = strtolower(trim(strval($options['target'] ?? 'optional')));
    if ($description === '' || !in_array($target, ['none', 'optional', 'required'], true)) {
        error_log('[ExtensionActions] Rejected incomplete action definition: ' . $codeName);
        return false;
    }
    foreach (array_keys(stobeExtensionActionRegistry()) as $existingCode) {
        if (strcasecmp($existingCode, $codeName) === 0 && $existingCode !== $codeName) {
            return false;
        }
    }

    $GLOBALS['STOBE_EXTENSION_ACTIONS'][$codeName] = [
        'code' => $codeName,
        'description' => substr($description, 0, 400),
        'target' => $target,
    ];
    return true;
}

function stobeExtensionActionRegistry(): array
{
    $registry = $GLOBALS['STOBE_EXTENSION_ACTIONS'] ?? [];
    return is_array($registry) ? $registry : [];
}

/**
 * Registered codes permitted by an explicit ACTIONS_ALLOWLIST setting.
 * An empty user allowlist leaves plugin registration as the gate.
 */
function stobeExtensionActionCodesForAllowlist(array $explicitAllowlist): array
{
    $codes = [];
    foreach (array_keys(stobeExtensionActionRegistry()) as $codeName) {
        if (count($explicitAllowlist) === 0 || in_array(strtoupper($codeName), $explicitAllowlist, true)) {
            $codes[] = $codeName;
        }
    }
    return $codes;
}

function stobeExtensionActionCodesForConfig(array $config): array
{
    $registry = stobeExtensionActionRegistry();
    $allowed = is_array($config['extension_actions'] ?? null)
        ? $config['extension_actions']
        : array_keys($registry);
    return array_values(array_filter($allowed, static fn($code): bool => is_string($code) && isset($registry[$code])));
}

function stobeNormalizeExtensionActionTag(string $rawCommand, string $argument, array $config = []): string
{
    $codeName = '';
    foreach (stobeExtensionActionCodesForConfig($config) as $allowedCode) {
        if (strcasecmp($allowedCode, trim($rawCommand)) === 0) {
            $codeName = $allowedCode;
            break;
        }
    }
    if ($codeName === '') {
        return '';
    }

    $spec = stobeExtensionActionRegistry()[$codeName];
    // '|' is the wire field delimiter and '@' the argument delimiter.
    $argument = trim(preg_replace('/[\r\n\t]+/', ' ', $argument) ?? '');
    $argument = trim(substr(str_replace(['[', ']', '@', '|'], '', $argument), 0, 120));
    if ($spec['target'] === 'none') {
        $argument = '';
    } elseif ($spec['target'] === 'required' && $argument === '') {
        return '';
    }
    return $codeName . '@' . $argument;
}
