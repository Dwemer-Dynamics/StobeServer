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
 * A folder other than the extension root is scanned as one plugin's folder
 * (CHIM's requireFilesRecursively(__DIR__, ...)): its own files count, and it
 * is skipped when its top-level ext/ folder is disabled or reserved.
 */
function stobeExtensionHookIndex(?string $root = null): array
{
    static $cache = [];
    $extRoot = stobeExtensionRoot();
    $root = rtrim($root ?? $extRoot, '/\\');
    $key = realpath($root) ?: $root;
    if (!isset($cache[$key])) {
        $index = [];
        $realExt = realpath($extRoot) ?: $extRoot;
        $startDepth = $key === $realExt ? 0 : 1;
        $enabled = true;
        if ($startDepth === 1 && str_starts_with($key, $realExt . DIRECTORY_SEPARATOR)) {
            $topLevel = explode(DIRECTORY_SEPARATOR, substr($key, strlen($realExt) + 1))[0];
            $enabled = stobeExtensionTopLevelEnabled($topLevel, $realExt . DIRECTORY_SEPARATOR . $topLevel);
        }
        if ($enabled && $root !== '' && is_dir($root) && !is_link($root)) {
            stobeExtensionScanDirectory($root, $index, $startDepth);
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
    // CHIM signature. Uses the same cached index, exclusions and include-once
    // rules; a plugin's own folder also includes its directly contained files.
    function requireFilesRecursively($dir, $name)
    {
        return stobeRunExtensionHook(strval($name), null, strval($dir));
    }
}

/*
 * Shared stage helpers for foreground model routes (processor/chat, rechat,
 * bored, Director, diary, narrator welcome). Each hook file still runs at most
 * once per request; a route calls only the stages its prompt shape supports.
 */
function stobeRunExtensionPromptStages(?array $requestView = null): void
{
    stobeRunExtensionHook('prompts.php', $requestView);
    stobeRunExtensionHook('dialogue_prompt.php', $requestView);
}

// context_building.php edits role/content history in $GLOBALS['CONTEXT_BUILDING_DATA'].
function stobeApplyExtensionContextBuilding(array $historyMessages, ?array $requestView = null): array
{
    $GLOBALS['CONTEXT_BUILDING_DATA'] = $historyMessages;
    if (stobeRunExtensionHook('context_building.php', $requestView) !== [] && is_array($GLOBALS['CONTEXT_BUILDING_DATA'])) {
        return array_values(array_filter($GLOBALS['CONTEXT_BUILDING_DATA'], 'is_array'));
    }
    return $historyMessages;
}

// context.php edits the complete $GLOBALS['messages'] list before the model call.
function stobeApplyExtensionContextHook(array $messages, ?array $requestView = null): array
{
    $GLOBALS['messages'] = $messages;
    if (stobeRunExtensionHook('context.php', $requestView) !== [] && is_array($GLOBALS['messages'])) {
        $updated = array_values(array_filter($GLOBALS['messages'], 'is_array'));
        if ($updated !== []) {
            return $updated;
        }
    }
    return $messages;
}

// main.php runs the post-response stages only when a route reaches this point.
function stobeMarkExtensionModelTurnCompleted(): void
{
    $GLOBALS['STOBE_EXTENSION_DIALOGUE_TURN'] = true;
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
 * BIOGRAPHY_BUILDER + character_bottom inside <character>, then the
 * <player_character> enrichment block, prompt_bottom last.
 */
function stobeExtensionPromptContext(string $npcName, ?array $requestView = null): array
{
    return [
        'game_request' => $requestView ?? (is_array($GLOBALS['gameRequest'] ?? null) ? $GLOBALS['gameRequest'] : []),
        'herika_name' => $npcName,
        'npc_name' => $npcName,
        'narrator_name' => function_exists('stobeNarratorName') ? stobeNarratorName() : 'The Narrator',
        'player_name' => function_exists('getSetting') ? strval(getSetting('PLAYER_NAME', 'Drifter')) : '',
    ];
}

function stobeApplyExtensionPromptSections(
    string $systemPrompt,
    string $npcName,
    array $npcData,
    ?array $requestView = null,
    string $turnSpeaker = ''
): string {
    $context = stobeExtensionPromptContext($npcName, $requestView);

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

    return $systemPrompt
        . stobeBuildExtensionPlayerCharacterBlock($turnSpeaker, $npcName)
        . stobeRenderPromptInjections('prompt_bottom', $context);
}

/*
 * Actor-profile enrichment context version 2 (docs/plugin-runtime.md).
 * Registered NPC rows are fetched in batch queries cached for the request, and
 * only when an enricher is registered; callbacks get a bounded projection.
 */
const STOBE_EXTENSION_ENRICHMENT_MAX_CHARS = 600;
const STOBE_EXTENSION_REGISTERED_NPC_BATCH = 32;

function stobeExtensionHasActorProfileEnrichers(): bool
{
    $enrichers = $GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS'] ?? null;
    return is_array($enrichers) && count($enrichers) > 0;
}

function stobeExtensionRegisteredNpcProjection(array $row): array
{
    $metadata = function_exists('normalizeNpcMetadataPayload')
        ? normalizeNpcMetadataPayload($row['metadata'] ?? [])
        : (is_array($row['metadata'] ?? null) ? $row['metadata'] : []);
    $text = static fn($value): string => mb_substr(trim(strval($value ?? '')), 0, 120);
    return [
        'id' => intval($row['id'] ?? 0),
        'name' => $text($row['name'] ?? ''),
        'original_name' => $text($row['original_name'] ?? ''),
        'storage_id' => $text($metadata['storage_id'] ?? ''),
        'race' => $text($row['race'] ?? ''),
        'gender' => $text($row['gender'] ?? ''),
        'faction' => $text($row['faction'] ?? ''),
        'faction_id' => $text($metadata['faction_id'] ?? ($metadata['factionID'] ?? '')),
        'profile_id' => intval($row['profile_id'] ?? 0),
        'in_player_faction' => function_exists('npcIsInPlayerFaction') && npcIsInPlayerFaction($row),
    ];
}

/**
 * @return array<string, array|null> lower-case name => projection, or null when unregistered
 */
function stobeExtensionRegisteredNpcs(array $names): array
{
    static $cache = [];
    $missing = [];
    $result = [];
    foreach ($names as $name) {
        $key = strtolower(normalizeParticipantNameToken(strval($name)));
        if ($key === '') {
            continue;
        }
        if (array_key_exists($key, $cache)) {
            $result[$key] = $cache[$key];
        } elseif (count($missing) < STOBE_EXTENSION_REGISTERED_NPC_BATCH) {
            $missing[$key] = true;
        }
    }
    $db = $GLOBALS['db'] ?? null;
    if ($missing === [] || !is_object($db) || !method_exists($db, 'fetchAll')) {
        return $result;
    }

    // Same row preference as the nearby appearance lookup: exact name, then saved identity, then newest.
    $rows = $db->fetchAll(
        "WITH requested AS (
            SELECT value AS lookup_name FROM jsonb_array_elements_text($1::jsonb)
         )
         SELECT requested.lookup_name, matched.*
         FROM requested
         JOIN LATERAL (
            SELECT n.id, n.name, n.original_name, n.race, n.gender, n.faction, n.profile_id, n.metadata
            FROM core_npc n
            WHERE LOWER(n.name) = requested.lookup_name
               OR LOWER(COALESCE(n.original_name, '')) = requested.lookup_name
            ORDER BY
                CASE WHEN LOWER(n.name) = requested.lookup_name THEN 0 ELSE 1 END,
                CASE WHEN COALESCE(n.metadata->>'storage_id', '') <> '' THEN 0 ELSE 1 END,
                n.gamets_last_updated DESC,
                n.updated_at DESC
            LIMIT 1
         ) AS matched ON TRUE",
        [json_encode(array_keys($missing), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
    );
    foreach ($missing as $key => $_) {
        $cache[$key] = null;
    }
    foreach (is_array($rows) ? $rows : [] as $row) {
        $key = strval($row['lookup_name'] ?? '');
        if (array_key_exists($key, $missing)) {
            $cache[$key] = stobeExtensionRegisteredNpcProjection($row);
        }
    }
    foreach ($missing as $key => $_) {
        $result[$key] = $cache[$key];
    }
    return $result;
}

// Kenshi has no single player actor: the player side is a faction plus named squads.
function stobeExtensionPlayerSide(string $name, ?bool $inPlayerFaction = null): array
{
    $membership = function_exists('getPlayerSquadMembershipSnapshot') ? getPlayerSquadMembershipSnapshot() : [];
    $squads = $membership['member_to_squads'][strtolower(normalizeParticipantNameToken($name))] ?? [];
    return [
        'player_faction' => boolval($inPlayerFaction) || (is_array($squads) && $squads !== []),
        'squads' => is_array($squads) ? array_slice(array_values($squads), 0, 8) : [],
    ];
}

function stobeExtensionActorEnrichmentText(string $name, string $type, array $context): string
{
    $text = stobeBuildActorProfileEnrichmentText($name, $type, $context + ['context_version' => 2]);
    return function_exists('truncatePromptValue')
        ? truncatePromptValue($text, STOBE_EXTENSION_ENRICHMENT_MAX_CHARS)
        : mb_substr($text, 0, STOBE_EXTENSION_ENRICHMENT_MAX_CHARS);
}

/**
 * The character the player speaks through: the turn speaker when it is
 * PLAYER_NAME or a member of a saved player squad, otherwise PLAYER_NAME.
 */
function stobeExtensionPlayerCharacterName(string $turnSpeaker): string
{
    $playerName = normalizeParticipantNameToken(function_exists('getSetting') ? strval(getSetting('PLAYER_NAME', 'Drifter')) : '');
    $speaker = normalizeParticipantNameToken($turnSpeaker);
    if ($speaker !== '' && ($playerName === '' || strcasecmp($speaker, $playerName) === 0
        || stobeExtensionPlayerSide($speaker)['squads'] !== [])) {
        return $speaker;
    }
    return $playerName;
}

// One 'player' enricher call per prompt; nothing is rendered without enricher text.
function stobeBuildExtensionPlayerCharacterBlock(string $turnSpeaker, string $promptNpc): string
{
    if (!stobeExtensionHasActorProfileEnrichers()) {
        return '';
    }
    $player = stobeExtensionPlayerCharacterName($turnSpeaker);
    if ($player === '' || strcasecmp($player, $promptNpc) === 0) {
        return '';
    }
    $playerKey = strtolower($player);
    $registered = stobeExtensionRegisteredNpcs([$player])[$playerKey] ?? null;
    $side = stobeExtensionPlayerSide($player, is_array($registered) ? $registered['in_player_faction'] : null);
    $squadMembers = [];
    $membership = function_exists('getPlayerSquadMembershipSnapshot') ? getPlayerSquadMembershipSnapshot() : [];
    foreach ($side['squads'] as $squadName) {
        foreach ($membership['squad_members'][$squadName] ?? [] as $memberKey => $memberName) {
            if ($memberKey !== $playerKey && count($squadMembers) < 32) {
                $squadMembers[$memberKey] = $memberName;
            }
        }
    }
    $text = stobeExtensionActorEnrichmentText($player, 'player', [
        'source' => 'player_character',
        'registered_npc' => $registered,
        'player_side' => $side,
        'squad_members' => array_values($squadMembers),
        'turn_speaker' => normalizeParticipantNameToken($turnSpeaker),
    ]);
    if ($text === '') {
        return '';
    }
    return "\n<player_character>\n## " . stobePromptXmlEscape($player) . ': ' . stobePromptXmlEscape($text) . "\n</player_character>";
}

/*
 * External actions: ExtCmd<Bridge>_<Action>. Only codes registered by a loaded
 * plugin reach the response schema, allowlist and ActionQueue dispatch.
 */
function stobeRegisterExtensionAction(string $codeName, string $description, array $options = []): bool
{
    $codeName = trim($codeName);
    if (strlen($codeName) > 64 || preg_match('/^ExtCmd[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9_]*$/', $codeName) !== 1) {
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
