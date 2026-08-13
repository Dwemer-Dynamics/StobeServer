<?php

class STTConnector
{
    private string $table = 'core_stt_connector';

    public function getDriverOptions(): array
    {
        return ['parakeet', 'deepgram', 'whisper', 'localwhisper', 'gemini', 'azure', 'inworld', 'none'];
    }

    public function normalizeDriverValue(mixed $driver): string
    {
        $value = strtolower(trim(strval($driver)));
        $value = ['openai' => 'whisper', 'local_whisper' => 'localwhisper', 'disabled' => 'none'][$value] ?? $value;
        return in_array($value, $this->getDriverOptions(), true) ? $value : 'none';
    }

    public function getDisplayName(mixed $driver): string
    {
        return ['parakeet' => 'Parakeet', 'deepgram' => 'Deepgram', 'whisper' => 'OpenAI Whisper',
            'localwhisper' => 'Local Whisper', 'gemini' => 'Gemini', 'azure' => 'Azure',
            'inworld' => 'Inworld', 'none' => 'Disabled'][$this->normalizeDriverValue($driver)];
    }

    public function getDefaultUrlForDriver(mixed $driver): string
    {
        return ['parakeet' => 'http://127.0.0.1:8022/v1/audio/transcriptions',
            'localwhisper' => 'http://127.0.0.1:9876/api/v0/transcribe',
            'whisper' => 'https://api.openai.com/v1/audio/transcriptions',
            'deepgram' => 'https://api.deepgram.com/v1/listen',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'azure' => '', 'inworld' => 'https://api.inworld.ai/stt/v1/transcribe',
            'none' => ''][$this->normalizeDriverValue($driver)];
    }

    public function driverUsesApiBadge(mixed $driver): bool
    {
        return in_array($this->normalizeDriverValue($driver), ['deepgram', 'whisper', 'gemini', 'azure', 'inworld'], true);
    }

    public function driverSupportsEditableUrl(mixed $driver): bool
    {
        return in_array($this->normalizeDriverValue($driver), ['parakeet', 'localwhisper', 'whisper', 'deepgram'], true);
    }

    public function getDefaultApiBadgeLabel(mixed $driver): string
    {
        return ['deepgram' => 'Deepgram', 'whisper' => 'OpenAI', 'gemini' => 'Google',
            'azure' => 'Azure', 'inworld' => 'Inworld'][$this->normalizeDriverValue($driver)] ?? '';
    }

    public function getDefaultApiBadgeIdForDriver(mixed $driver): int
    {
        $label = $this->getDefaultApiBadgeLabel($driver);
        if ($label === '') return 0;
        $row = $GLOBALS['db']->fetchOne('SELECT id FROM core_api_badge WHERE LOWER(label) = LOWER($1) LIMIT 1', [$label]);
        return intval($row['id'] ?? 0);
    }

    public function getProviderFieldSchema(mixed $driver): array
    {
        $schemas = [
            'parakeet' => ['LANGUAGE' => ['type' => 'string', 'default' => 'en'], 'TRANSLATE' => ['type' => 'boolean', 'default' => false]],
            'localwhisper' => ['LANGUAGE' => ['type' => 'string', 'default' => 'en'], 'TRANSLATE' => ['type' => 'boolean', 'default' => false], 'FORMFIELD' => ['type' => 'string', 'default' => 'audio_file']],
            'whisper' => ['MODEL' => ['type' => 'string', 'default' => 'whisper-1'], 'LANGUAGE' => ['type' => 'string', 'default' => 'en'], 'TRANSLATE' => ['type' => 'boolean', 'default' => false]],
            'deepgram' => ['MODEL' => ['type' => 'string', 'default' => 'nova-3'], 'LANGUAGE' => ['type' => 'string', 'default' => 'en'], 'SMART_FORMAT' => ['type' => 'boolean', 'default' => true]],
            'gemini' => ['MODEL' => ['type' => 'string', 'default' => 'gemini-2.5-flash'], 'LANGUAGE' => ['type' => 'string', 'default' => 'en']],
            'azure' => ['REGION' => ['type' => 'string', 'default' => 'eastus'], 'LANGUAGE' => ['type' => 'string', 'default' => 'en-US'], 'PROFANITY' => ['type' => 'string', 'default' => 'raw']],
            'inworld' => ['MODEL_ID' => ['type' => 'string', 'default' => 'groq/whisper-large-v3'], 'LANGUAGE' => ['type' => 'string', 'default' => 'en-US']],
            'none' => [],
        ];
        $driver = $this->normalizeDriverValue($driver);
        $schema = $schemas[$driver] ?? [];
        if ($driver !== 'none') $schema['TIMEOUT'] = ['type' => 'integer', 'default' => 60];
        return $schema;
    }

    public function defaultsForDriver(mixed $driver): array
    {
        $values = [];
        foreach ($this->getProviderFieldSchema($driver) as $name => $definition) $values[$name] = $definition['default'] ?? '';
        return $values;
    }

    public function decodeMetadata(mixed $raw): array
    {
        if (is_array($raw)) return $raw;
        $decoded = json_decode(strval($raw), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function readAll(): array
    {
        return $GLOBALS['db']->fetchAll("SELECT * FROM {$this->table} ORDER BY id ASC");
    }

    public function getById(int $id): array|false
    {
        return $GLOBALS['db']->fetchOne("SELECT * FROM {$this->table} WHERE id = $1 LIMIT 1", [$id]);
    }

    public function getActive(): array|false
    {
        $id = intval(getSetting('GLOBAL_STT_CONNECTOR_ID', '0'));
        $row = $id > 0 ? $this->getById($id) : false;
        if (!$row) $row = $GLOBALS['db']->fetchOne("SELECT * FROM {$this->table} ORDER BY CASE WHEN driver = 'parakeet' THEN 0 ELSE 1 END, id ASC LIMIT 1");
        if ($row && intval($row['id'] ?? 0) !== $id) setSetting('GLOBAL_STT_CONNECTOR_ID', strval($row['id']), 'general', 'Active speech-to-text connector.');
        return $row;
    }

    public function saveGlobal(array $data): int
    {
        $active = $this->getActive();
        $driver = $this->normalizeDriverValue($data['driver'] ?? 'parakeet');
        $payload = ['driver' => $driver, 'label' => trim(strval($data['label'] ?? 'Global STT Connector')) ?: 'Global STT Connector',
            'metadata' => json_encode($data['metadata'] ?? $this->defaultsForDriver($driver), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'api_badge_id' => intval($data['api_badge_id'] ?? 0) ?: null,
            'url' => $this->driverSupportsEditableUrl($driver) ? (trim(strval($data['url'] ?? '')) ?: $this->getDefaultUrlForDriver($driver)) : $this->getDefaultUrlForDriver($driver)];
        if ($active) {
            $id = intval($active['id']);
            $GLOBALS['db']->updateRow($this->table, $payload, 'id = ' . $id);
        } else {
            $row = $GLOBALS['db']->fetchOne("INSERT INTO {$this->table} (driver,label,metadata,api_badge_id,url) VALUES ($1,$2,$3::jsonb,$4,$5) RETURNING id", [$payload['driver'],$payload['label'],$payload['metadata'],$payload['api_badge_id'],$payload['url']]);
            $id = intval($row['id'] ?? 0);
        }
        if ($id > 0) setSetting('GLOBAL_STT_CONNECTOR_ID', strval($id), 'general', 'Active speech-to-text connector.');
        return $id;
    }
}
