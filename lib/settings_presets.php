<?php

// Portable behavior settings only. Never infer safety from a setting's name or stored value.
function stobePresetCatalog(string $scope): array
{
    if ($scope === 'profile') {
        $defaults = getDefaultCoreProfileMetadata();
        $catalog = [];
        foreach (['DYNAMIC_PROFILE_ENABLED', 'MIDDLE_TERM_MEMORY_ENABLED', 'AUTO_DIARY_ENABLED', 'LATEST_DIARY_CONTEXT_ENABLED'] as $key) {
            $catalog[$key] = ['type' => 'bool', 'default' => $defaults[$key]];
        }
        foreach ([
            'RECHAT_RESPONSES' => [0, 10], 'RECHAT_PROBABILITY' => [0, 100],
            'BORED_EVENT_CHANCE' => [0, 100], 'RELATIONSHIP_UPDATE_CHANCE' => [0, 100],
            'CONTEXT_HISTORY' => [0, 300], 'CONTEXT_HISTORY_DIARY' => [0, 300],
            'CONTEXT_HISTORY_DYNAMIC_PROFILE' => [0, 300], 'DIARY_DAYS' => [0, 60],
            'AUTO_DIARY_MIN_EVENTS' => [1, 500], 'AUTO_DIARY_HOUR' => [0, 23],
            'DIARY_COOLDOWN' => [0, 3600],
        ] as $key => [$min, $max]) {
            $catalog[$key] = ['type' => 'int', 'default' => $defaults[$key], 'min' => $min, 'max' => $max];
        }
        $catalog['DYNAMIC_PROFILE_FIELDS'] = ['type' => 'set', 'default' => $defaults['DYNAMIC_PROFILE_FIELDS'],
            'choices' => ['personality', 'occupation', 'speechstyle', 'goals']];
        return $catalog;
    }
    if ($scope !== 'global') {
        throw new InvalidArgumentException('Unknown preset scope.');
    }
    return [
        'COMPACT_CHAT_HISTORY_ENABLED' => ['type' => 'bool', 'default' => false],
        'PROMPT_HEAD_MARKDOWN_ENABLED' => ['type' => 'bool', 'default' => true],
        'RECHAT_MODE' => ['type' => 'enum', 'default' => 'random', 'choices' => ['tight', 'conversational', 'group', 'random']],
        'SPEAKER_RECHAT' => ['type' => 'bool', 'default' => false],
        'ENFORCE_STRICT_RECHAT_RESPONSE' => ['type' => 'bool', 'default' => false],
        'MEMORY_ENABLED' => ['type' => 'bool', 'default' => true],
        'WORLD_KNOWLEDGE_ENABLED' => ['type' => 'bool', 'default' => true],
        'ALWAYS_INSERT_RACE' => ['type' => 'bool', 'default' => true],
        'ALWAYS_INSERT_LOCATION' => ['type' => 'bool', 'default' => true],
        'ALWAYS_INSERT_PEOPLE' => ['type' => 'bool', 'default' => true],
        'WORLD_KNOWLEDGE_AMOUNT' => ['type' => 'int', 'default' => 2, 'min' => 1, 'max' => 20],
        'WORLD_KNOWLEDGE_CONTEXT_HISTORY' => ['type' => 'int', 'default' => 16, 'min' => 1, 'max' => 300],
        'WORLD_KNOWLEDGE_CONTEXT_KEYWORDS' => ['type' => 'int', 'default' => 8, 'min' => 1, 'max' => 100],
        'DYNAMIC_PROFILE_INTERVAL_HOURS' => ['type' => 'int', 'default' => 24, 'min' => 1, 'max' => 720],
    ];
}

function stobeBuiltinPresets(string $scope): array
{
    $defaults = array_map(static fn(array $rule) => $rule['default'], stobePresetCatalog($scope));
    if ($scope === 'profile') {
        return [
            ['name' => 'Default', 'builtin' => true, 'description' => 'Restore Stobe behavior defaults. Profile roles and connectors stay unchanged.', 'settings' => $defaults],
            ['name' => 'Local LLM', 'builtin' => true, 'description' => 'Shorter context and fewer automatic calls. Does not configure a local model.',
                'settings' => array_replace($defaults, ['CONTEXT_HISTORY' => 25, 'CONTEXT_HISTORY_DIARY' => 50,
                    'CONTEXT_HISTORY_DYNAMIC_PROFILE' => 25, 'RECHAT_RESPONSES' => 1, 'RECHAT_PROBABILITY' => 25,
                    'BORED_EVENT_CHANCE' => 10, 'RELATIONSHIP_UPDATE_CHANCE' => 10])],
            ['name' => 'Follower', 'builtin' => true, 'description' => 'Enable diary, middle-term memory and dynamic profiles. Requires their task connectors; does not assign a follower or player-faction role.',
                'settings' => array_replace($defaults, ['DYNAMIC_PROFILE_ENABLED' => true, 'MIDDLE_TERM_MEMORY_ENABLED' => true,
                    'AUTO_DIARY_ENABLED' => true, 'LATEST_DIARY_CONTEXT_ENABLED' => true])],
            ['name' => 'Passive', 'builtin' => true, 'description' => 'Limit rechats to one reply; disable bored dialogue and automatic diary/profile updates. Direct conversations and inline relationship commands remain available.',
                'settings' => array_replace($defaults, ['RECHAT_RESPONSES' => 1, 'RECHAT_PROBABILITY' => 0,
                    'BORED_EVENT_CHANCE' => 0, 'RELATIONSHIP_UPDATE_CHANCE' => 0])],
        ];
    }
    return [
        ['name' => 'Default', 'builtin' => true, 'description' => 'Restore the portable settings to Stobe defaults.', 'settings' => $defaults],
        ['name' => 'Local LLM', 'builtin' => true, 'description' => 'Compact history and smaller knowledge context. Models and connectors stay unchanged.',
            'settings' => array_replace($defaults, [
                'COMPACT_CHAT_HISTORY_ENABLED' => true,
                'WORLD_KNOWLEDGE_AMOUNT' => 1,
                'WORLD_KNOWLEDGE_CONTEXT_HISTORY' => 8,
                'WORLD_KNOWLEDGE_CONTEXT_KEYWORDS' => 4,
                'DYNAMIC_PROFILE_INTERVAL_HOURS' => 48,
            ])],
    ];
}

// Reject unknown keys and invalid values before storing imported or edited presets.
function stobeNormalizePreset(string $scope, mixed $settings): array
{
    if (!is_array($settings) || $settings === [] || array_is_list($settings)) {
        throw new InvalidArgumentException('Preset settings must be a non-empty object.');
    }
    $catalog = stobePresetCatalog($scope);
    $result = [];
    foreach ($settings as $key => $value) {
        if (!isset($catalog[$key])) {
            throw new InvalidArgumentException('This preset contains unsupported settings.');
        }
        $rule = $catalog[$key];
        if ($rule['type'] === 'bool') {
            if (!in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                throw new InvalidArgumentException('Invalid boolean setting: ' . $key);
            }
            $value = in_array($value, [true, 1, '1', 'true'], true);
        } elseif ($rule['type'] === 'int') {
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^-?\d+$/D', (string)$value)
                || (float)$value < $rule['min'] || (float)$value > $rule['max']) {
                throw new InvalidArgumentException('Setting is outside its allowed range: ' . $key);
            }
            $value = (int)$value;
        } elseif ($rule['type'] === 'set') {
            if (!is_array($value) || $value === [] || !array_is_list($value)) {
                throw new InvalidArgumentException('Choose at least one dynamic profile field.');
            }
            foreach ($value as $field) {
                if (!is_string($field) || !in_array($field, $rule['choices'], true)) {
                    throw new InvalidArgumentException('Invalid dynamic profile field.');
                }
            }
            $value = array_values(array_unique($value));
        } elseif (!in_array($value, $rule['choices'], true)) {
            throw new InvalidArgumentException('Invalid choice: ' . $key);
        }
        $result[$key] = $value;
    }
    return $result;
}

function stobePresetToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['stobe_preset_token'])) {
        $_SESSION['stobe_preset_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['stobe_preset_token'];
}

// A single bounded store serves settings presets; it never writes runtime settings or profiles.
function stobeSavePreset(object $db, string $scope, string $name, mixed $settings, bool $overwrite): void
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
        throw new InvalidArgumentException('Use a preset name of 1–80 bytes without control characters.');
    }
    foreach (stobeBuiltinPresets($scope) as $builtin) {
        if (strcasecmp($name, $builtin['name']) === 0) {
            throw new InvalidArgumentException('Built-in preset names are reserved.');
        }
    }
    $settings = stobeNormalizePreset($scope, $settings);
    if ($db->exec('BEGIN') === false) {
        throw new RuntimeException('Could not start preset save.');
    }
    try {
        // Serialize the count check and insert so concurrent requests cannot exceed the cap.
        if ($db->exec("SELECT pg_advisory_xact_lock(hashtext('stobe_settings_presets'))") === false) {
            throw new RuntimeException('Could not lock preset store.');
        }
        $existing = $db->fetchOne('SELECT name FROM stobe_settings_presets WHERE scope = $1 AND lower(name) = lower($2)', [$scope, $name]);
        if ($existing && !$overwrite) {
            throw new InvalidArgumentException('That name already exists. Select it and use Overwrite.');
        }
        $count = $db->fetchOne('SELECT count(*) AS total FROM stobe_settings_presets WHERE scope = $1', [$scope]);
        if (!$count || (!$existing && (int)$count['total'] >= 50)) {
            throw new InvalidArgumentException('The preset limit is 50. Delete an unused preset first.');
        }
        if ($existing) {
            $ok = $db->exec('UPDATE stobe_settings_presets SET settings = $3::jsonb WHERE scope = $1 AND name = $2', [$scope, $existing['name'], json_encode($settings, JSON_THROW_ON_ERROR)]);
        } else {
            $ok = $db->exec('INSERT INTO stobe_settings_presets (scope, name, settings) VALUES ($1, $2, $3::jsonb)', [$scope, $name, json_encode($settings, JSON_THROW_ON_ERROR)]);
        }
        if ($ok === false || $db->exec('COMMIT') === false) {
            throw new RuntimeException('Could not save preset.');
        }
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}
