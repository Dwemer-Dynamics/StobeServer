<?php

if (!class_exists('StobeLegacyLlmDriver')) {
    class StobeLegacyLlmDriver
    {
        private array $runtimeConfig;

        public function __construct(array $runtimeConfig)
        {
            $this->runtimeConfig = $runtimeConfig;
        }

        private function coerceMaxTokens(mixed $value, int $fallback): int
        {
            $raw = intval($value);
            return $raw > 0 ? $raw : $fallback;
        }

        private function coerceTemperature(mixed $value, float $fallback): float
        {
            if ($value === null || $value === '') {
                return $fallback;
            }
            return floatval($value);
        }

        public function fast_request(array $messages, array $params = [], string $context = ''): string|false
        {
            $runtime = $this->runtimeConfig;
            $runtime['max_tokens'] = $this->coerceMaxTokens(
                $params['MAX_TOKENS'] ?? $params['max_tokens'] ?? ($runtime['max_tokens'] ?? 250),
                intval($runtime['max_tokens'] ?? 250)
            );
            $runtime['temperature'] = $this->coerceTemperature(
                $params['TEMPERATURE'] ?? $params['temperature'] ?? ($runtime['temperature'] ?? 0.8),
                floatval($runtime['temperature'] ?? 0.8)
            );

            $meta = [];
            if ($context !== '') {
                $meta['event_type'] = $context;
            }
            $meta['__stobe_force_fast_log'] = true;

            return stobeCallLLM($messages, $runtime, $meta);
        }

        public function request(array $messages, array $params = [], string $context = ''): string|false
        {
            return $this->fast_request($messages, $params, $context);
        }
    }
}

if (!class_exists('LLMConnector')) {
    class LLMConnector
    {
        private function decodeConfig(mixed $raw): array
        {
            if (is_array($raw)) {
                return $raw;
            }
            $decoded = json_decode(strval($raw), true);
            return is_array($decoded) ? $decoded : [];
        }

        private function normalizeDriver(string $driver): string
        {
            $normalized = strtolower(trim($driver));
            $aliases = [
                'openrouter' => 'openrouterjson',
                'openrouterjson' => 'openrouterjson',
                'openai' => 'openaijson',
                'openaijson' => 'openaijson',
                'google' => 'google_openaijson',
                'google_openaijson' => 'google_openaijson',
                'groq' => 'groqjson',
                'groqjson' => 'groqjson',
                'koboldcpp' => 'koboldcppjson',
                'koboldcppjson' => 'koboldcppjson',
                'player2' => 'player2json',
                'player2json' => 'player2json',
            ];
            return $aliases[$normalized] ?? 'openaijson';
        }

        private function guessService(string $driver, string $baseUrl): string
        {
            $d = strtolower(trim($driver));
            $u = strtolower(trim($baseUrl));
            if ($d === 'player2json' || str_contains($u, '127.0.0.1:4315') || str_contains($u, 'localhost:4315')) {
                return 'player2';
            }
            if ($d === 'google_openaijson' || str_contains($u, 'generativelanguage.googleapis.com')) {
                return 'google';
            }
            if ($d === 'groqjson' || str_contains($u, 'groq.com')) {
                return 'groq';
            }
            if ($d === 'openrouterjson' || str_contains($u, 'openrouter.ai')) {
                return 'openrouter';
            }
            if ($d === 'openaijson' || str_contains($u, 'openai.com')) {
                return 'openai';
            }
            if ($d === 'koboldcppjson') {
                return 'custom';
            }
            return 'custom';
        }

        private function resolveApiKey(array $row): string
        {
            $directKey = trim(strval($row['api_key'] ?? ''));
            if ($directKey !== '') {
                return $directKey;
            }

            $badgeId = intval($row['api_badge_id'] ?? 0);
            if ($badgeId <= 0) {
                return '';
            }

            $badge = getApiBadgeById($badgeId);
            if (!$badge) {
                return '';
            }

            return trim(strval($badge['api_key'] ?? ''));
        }

        private function mapRow(array $row): array
        {
            $config = $this->decodeConfig($row['config'] ?? '{}');
            $driver = $this->normalizeDriver(strval($row['connector_type'] ?? ''));
            $baseUrl = trim(strval($row['base_url'] ?? ''));
            $service = trim(strval($config['service'] ?? ''));
            if ($service === '') {
                $service = $this->guessService($driver, $baseUrl);
            }

            $metadata = [];
            if (isset($config['metadata']) && is_array($config['metadata'])) {
                $metadata = $config['metadata'];
            }
            if (array_key_exists('remove_action_prompt', $config)) {
                $metadata['remove_action_prompt'] = boolval($config['remove_action_prompt']);
            }
            if (array_key_exists('extra_parameters_enabled', $config)) {
                $metadata['extra_parameters_enabled'] = boolval($config['extra_parameters_enabled']);
            }
            if (isset($config['extra_parameters']) && is_array($config['extra_parameters'])) {
                $metadata['extra_parameters'] = $config['extra_parameters'];
            }

            $mapped = $row;
            $mapped['label'] = strval($row['name'] ?? '');
            $mapped['url'] = $baseUrl;
            $mapped['driver'] = $driver;
            $mapped['service'] = $service;
            $mapped['provider'] = strval($config['provider'] ?? '');
            $mapped['reasoning_model'] = !empty($config['reasoning_model']) ? 1 : 0;
            $mapped['enforce_json'] = !empty($config['enforce_json']) ? 1 : 0;
            $mapped['json_schema'] = !empty($config['json_schema']) ? 1 : 0;
            $mapped['prefill_json'] = !empty($config['prefill_json']) ? 1 : 0;
            $mapped['presence_penalty'] = $config['presence_penalty'] ?? '';
            $mapped['frequency_penalty'] = $config['frequency_penalty'] ?? '';
            $mapped['repetition_penalty'] = $config['repetition_penalty'] ?? '';
            $mapped['top_p'] = $config['top_p'] ?? '';
            $mapped['top_k'] = $config['top_k'] ?? '';
            $mapped['min_p'] = $config['min_p'] ?? '';
            $mapped['top_a'] = $config['top_a'] ?? '';
            $mapped['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($mapped['metadata'])) {
                $mapped['metadata'] = '{}';
            }
            $mapped['api_key'] = $this->resolveApiKey($row);
            return $mapped;
        }

        private function runtimeConfigFromConnector(array $connector): array
        {
            $mapped = $this->mapRow($connector);
            $driver = $this->normalizeDriver(strval($mapped['driver'] ?? ''));
            $config = $this->decodeConfig($mapped['config'] ?? '{}');
            $config['service'] = strval($mapped['service'] ?? ($config['service'] ?? ''));
            if (!isset($config['provider']) && isset($mapped['provider'])) {
                $config['provider'] = $mapped['provider'];
            }

            return [
                'connector_type' => $driver,
                'api_key' => strval($mapped['api_key'] ?? ''),
                'base_url' => strval($mapped['url'] ?? ''),
                'model' => strval($mapped['model'] ?? ''),
                'max_tokens' => intval($mapped['max_tokens'] ?? 250),
                'temperature' => floatval($mapped['temperature'] ?? 0.8),
                'config' => $config,
            ];
        }

        public function create(array $payload): int
        {
            $fields = [
                'name' => trim(strval($payload['label'] ?? $payload['name'] ?? '')),
                'connector_type' => $this->normalizeDriver(strval($payload['driver'] ?? $payload['connector_type'] ?? '')),
                'api_badge_id' => (($payload['api_badge_id'] ?? '') === '') ? null : intval($payload['api_badge_id']),
                'api_key' => strval($payload['api_key'] ?? ''),
                'base_url' => strval($payload['url'] ?? $payload['base_url'] ?? ''),
                'model' => strval($payload['model'] ?? ''),
                'max_tokens' => intval($payload['max_tokens'] ?? 250),
                'temperature' => floatval($payload['temperature'] ?? 0.8),
                'is_default' => !empty($payload['is_default']),
                'config' => $payload['config'] ?? '{}',
            ];
            return saveLlmConnector($fields);
        }

        public function readAll(): array
        {
            $rows = getAllLlmConnectors();
            $mapped = [];
            foreach ($rows as $row) {
                $mapped[] = $this->mapRow($row);
            }
            return $mapped;
        }

        public function readOne(mixed $id): array|false
        {
            $row = getLlmConnectorById(intval($id));
            if (!$row) {
                return false;
            }
            return $this->mapRow($row);
        }

        public function getById(mixed $id): array|false
        {
            return $this->readOne($id);
        }

        public function update(mixed $id, array $payload): int
        {
            $existing = getLlmConnectorById(intval($id));
            if (!$existing) {
                return 0;
            }
            $fields = [
                'id' => intval($id),
                'name' => trim(strval($payload['label'] ?? $payload['name'] ?? ($existing['name'] ?? ''))),
                'connector_type' => $this->normalizeDriver(strval($payload['driver'] ?? $payload['connector_type'] ?? ($existing['connector_type'] ?? ''))),
                'api_badge_id' => array_key_exists('api_badge_id', $payload)
                    ? (($payload['api_badge_id'] === '' || $payload['api_badge_id'] === null) ? null : intval($payload['api_badge_id']))
                    : (($existing['api_badge_id'] ?? '') === '' ? null : intval($existing['api_badge_id'])),
                'api_key' => strval($payload['api_key'] ?? ($existing['api_key'] ?? '')),
                'base_url' => strval($payload['url'] ?? $payload['base_url'] ?? ($existing['base_url'] ?? '')),
                'model' => strval($payload['model'] ?? ($existing['model'] ?? '')),
                'max_tokens' => intval($payload['max_tokens'] ?? ($existing['max_tokens'] ?? 250)),
                'temperature' => floatval($payload['temperature'] ?? ($existing['temperature'] ?? 0.8)),
                'is_default' => array_key_exists('is_default', $payload)
                    ? !empty($payload['is_default'])
                    : !empty($existing['is_default']),
                'config' => $payload['config'] ?? ($existing['config'] ?? '{}'),
            ];
            return saveLlmConnector($fields);
        }

        public function delete(mixed $id): void
        {
            deleteLlmConnector(intval($id));
        }

        public function clone(mixed $id): int
        {
            $source = getLlmConnectorById(intval($id));
            if (!$source) {
                return 0;
            }

            $baseName = trim(strval($source['name'] ?? 'Connector'));
            if ($baseName === '') {
                $baseName = 'Connector';
            }
            $cloneName = $baseName . ' (Copy)';
            $all = getAllLlmConnectors();
            $used = [];
            foreach ($all as $row) {
                $used[strtolower(strval($row['name'] ?? ''))] = true;
            }
            if (isset($used[strtolower($cloneName)])) {
                $i = 2;
                while ($i < 5000) {
                    $candidate = $baseName . ' (Copy ' . $i . ')';
                    if (!isset($used[strtolower($candidate)])) {
                        $cloneName = $candidate;
                        break;
                    }
                    $i++;
                }
            }

            $source['name'] = $cloneName;
            $source['is_default'] = false;
            unset($source['id']);
            return saveLlmConnector($source);
        }

        public function getLastError(): string
        {
            $db = $GLOBALS['db'] ?? null;
            if (!$db || !method_exists($db, 'GetLastError')) {
                return '';
            }
            return strval($db->GetLastError());
        }

        public function setOldGlobals(array $currentConnectorData): void
        {
            $runtime = $this->runtimeConfigFromConnector($currentConnectorData);
            $driver = strval($runtime['connector_type'] ?? 'openaijson');

            if (!isset($GLOBALS['CONNECTOR']) || !is_array($GLOBALS['CONNECTOR'])) {
                $GLOBALS['CONNECTOR'] = [];
            }

            $entry = $runtime;
            $entry['url'] = strval($runtime['base_url'] ?? '');
            $entry['API_KEY'] = strval($runtime['api_key'] ?? '');
            $entry['ENFORCE_JSON'] = !empty($runtime['config']['enforce_json']);
            $entry['PREFILL_JSON'] = !empty($runtime['config']['prefill_json']);
            $entry['json_schema'] = !empty($runtime['config']['json_schema']);
            $entry['reasoning_model'] = !empty($runtime['config']['reasoning_model']);

            if (isset($runtime['config']['provider'])) {
                $entry['PROVIDER'] = $runtime['config']['provider'];
            }
            if (isset($runtime['config']['metadata']) && is_array($runtime['config']['metadata'])) {
                foreach ($runtime['config']['metadata'] as $metaKey => $metaValue) {
                    $entry[$metaKey] = $metaValue;
                }
            }

            $GLOBALS['CONNECTOR'][$driver] = $entry;
            $GLOBALS['CONNECTOR_ACTIVE'] = $entry;
        }

        public function getConnector(array $currentConnectorData): StobeLegacyLlmDriver
        {
            $runtime = $this->runtimeConfigFromConnector($currentConnectorData);
            return new StobeLegacyLlmDriver($runtime);
        }
    }
}

// Ensure the dispatcher stack is always available for runtime driver calls.
$enginePath = $GLOBALS['ENGINE_PATH'] ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$dispatcher = rtrim(strval($enginePath), '/\\') . DIRECTORY_SEPARATOR . 'connector' . DIRECTORY_SEPARATOR . 'llm_dispatcher.php';
if (file_exists($dispatcher)) {
    require_once $dispatcher;
}
