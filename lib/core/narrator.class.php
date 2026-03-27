<?php

class Narrator
{
    private string $table = 'core_narrator';
    private sql $db;

    public function __construct()
    {
        if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
            throw new Exception("Database connection not initialized for Narrator.");
        }
        $this->db = $GLOBALS["db"];
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                id TEXT PRIMARY KEY,
                value TEXT
            )"
        );
    }

    public static function defaultSeedValues(int $profileId = 1): array
    {
        $safeProfileId = $profileId > 0 ? $profileId : 1;
        return [
            'profile_id' => strval($safeProfileId),
            'voiceid' => 'stobenarrator',
            'core' => "The Narrator is a male voice within the player's mind. His job is to help the player as they navigate the world of Tamriel. Provide unique insight and descriptions of what is going on in the world.",
            'background' => "A guiding voice that describes the world, events, and transitions. He is not a character, but a voice within the player's mind.",
            'personality' => 'Laid-back, observant, and friendly; describes scenes with calm confidence.',
            'speechstyle' => 'Relaxed and conversational, with vivid scene descriptions in one or two concise sentences.',
            'goals' => '',
            'oghma_knowledge' => 'knowall',
            'gender' => 'male',
            'prompt_head' => '',
        ];
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value);
    }

    public function get(string $key): ?string
    {
        $safe = $this->escape($key);
        $row = $this->db->fetchOne(
            "SELECT value FROM {$this->table} WHERE id = '{$safe}' LIMIT 1"
        );
        if (!$row || !array_key_exists('value', $row)) {
            return null;
        }
        return strval($row['value']);
    }

    public function getAll(): array
    {
        $rows = $this->db->fetchAll("SELECT id, value FROM {$this->table}");
        $result = [];
        foreach ($rows as $row) {
            $id = trim(strval($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $result[$id] = strval($row['value'] ?? '');
        }
        return $result;
    }

    public function set(string $key, string $value): bool
    {
        $safeKey = trim($key);
        if ($safeKey === '') {
            return false;
        }
        $this->db->exec(
            "INSERT INTO {$this->table} (id, value)
             VALUES ($1, $2)
             ON CONFLICT (id) DO UPDATE
             SET value = EXCLUDED.value",
            [$safeKey, $value]
        );
        return true;
    }

    public function setMultiple(array $values): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            if (!$this->set(strval($key), strval($value))) {
                $ok = false;
            }
        }
        return $ok;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $default;
        }
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        if ($value === null || trim($value) === '') {
            return $default;
        }
        return intval($value);
    }

    public function getProfileId(): ?int
    {
        $raw = $this->get('profile_id');
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $profileId = intval($raw);
        return $profileId > 0 ? $profileId : null;
    }

    public function loadIntoGlobals(): void
    {
        $all = $this->getAll();
        $keyMapping = [
            'enabled' => ['NARRATOR_TALKS', 'bool', true],
            'welcome_enabled' => ['NARRATOR_WELCOME', 'bool', false],
            'welcome_cooldown' => ['NARRATOR_WELCOME_COOLDOWN', 'int', 10],
            'random_enabled' => ['RANDOM_NARATION', 'bool', false],
            'random_chance' => ['RANDOM_NARATION_CHANCE', 'int', 15],
            'random_cooldown' => ['RANDOM_NARRATION_COOLDOWN', 'int', 10],
            'dynamic_profile' => ['DYNAMIC_PROFILE', 'bool', false],
            'connector_id' => ['NARRATOR_CONNECTOR_ID', 'int', null],
        ];

        foreach ($keyMapping as $dbKey => $config) {
            [$globalKey, $type, $default] = $config;
            if (array_key_exists($dbKey, $all)) {
                $raw = strval($all[$dbKey]);
                if ($type === 'bool') {
                    $GLOBALS[$globalKey] = in_array(
                        strtolower(trim($raw)),
                        ['1', 'true', 'yes', 'on'],
                        true
                    );
                } elseif ($type === 'int') {
                    $value = intval($raw);
                    if ($dbKey === 'random_cooldown') {
                        $value = max(0, min(30, $value));
                    }
                    $GLOBALS[$globalKey] = $value;
                } else {
                    $GLOBALS[$globalKey] = $raw;
                }
                continue;
            }
            if (!isset($GLOBALS[$globalKey])) {
                $GLOBALS[$globalKey] = $default;
            }
        }
    }

    public function getNarratorData(): array
    {
        $all = $this->getAll();
        $defaults = self::defaultSeedValues(1);
        $resolvedProfileId = $this->getProfileId();
        if ($resolvedProfileId === null || $resolvedProfileId <= 0) {
            $resolvedProfileId = intval($defaults['profile_id']);
        }
        return [
            'id' => 1,
            'npc_name' => 'The Narrator',
            'profile_id' => $resolvedProfileId,
            'voiceid' => $all['voiceid'] ?? $defaults['voiceid'],
            'core' => $all['core'] ?? $defaults['core'],
            'npc_static_bio' => $all['background'] ?? $defaults['background'],
            'personality' => $all['personality'] ?? $defaults['personality'],
            'speechstyle' => $all['speechstyle'] ?? $defaults['speechstyle'],
            'goals' => $all['goals'] ?? $defaults['goals'],
            'oghma_knowledge_tags' => $all['oghma_knowledge'] ?? $defaults['oghma_knowledge'],
            'gender' => $all['gender'] ?? $defaults['gender'],
            'prompt_head' => $all['prompt_head'] ?? $defaults['prompt_head'],
            'lock_profile' => 1,
            'npc_favorite' => 1,
            'md5' => md5('The Narrator'),
            'dynamic_profile' => $this->getBool('dynamic_profile', false) ? 1 : 0,
        ];
    }

    public function getDynamicProfileFields(): array
    {
        $raw = $this->get('dynamic_profile_fields');
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $valid = ['personality', 'speechstyle', 'goals'];
        $result = [];
        foreach ($decoded as $field) {
            $fieldKey = strtolower(trim(strval($field)));
            if ($fieldKey === '' || !in_array($fieldKey, $valid, true)) {
                continue;
            }
            if (!in_array($fieldKey, $result, true)) {
                $result[] = $fieldKey;
            }
        }
        return $result;
    }

    public function setDynamicProfileFields(array $fields): bool
    {
        $valid = ['personality', 'speechstyle', 'goals'];
        $result = [];
        foreach ($fields as $field) {
            $fieldKey = strtolower(trim(strval($field)));
            if ($fieldKey === '' || !in_array($fieldKey, $valid, true)) {
                continue;
            }
            if (!in_array($fieldKey, $result, true)) {
                $result[] = $fieldKey;
            }
        }
        $encoded = json_encode($result);
        if (!is_string($encoded)) {
            $encoded = '[]';
        }
        return $this->set('dynamic_profile_fields', $encoded);
    }
}
