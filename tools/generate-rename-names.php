<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/rename_name_generation_functions.php';

function generationFail(string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit(1);
}

function generationOptions(array $arguments): array
{
    $options = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            generationFail('Unexpected argument: ' . $argument);
        }
        $parts = explode('=', substr($argument, 2), 2);
        $options[$parts[0]] = $parts[1] ?? 'true';
    }
    return $options;
}

function generationQuotas(int $target): array
{
    if ($target < 3) {
        generationFail('Target must be at least 3.');
    }
    $base = intdiv($target, 3);
    $remainder = $target % 3;
    return [
        'male' => $base + ($remainder > 0 ? 1 : 0),
        'female' => $base + ($remainder > 1 ? 1 : 0),
        'neutral' => $base,
    ];
}

function generationWriteState(string $path, array $state): void
{
    $temporary = $path . '.tmp';
    $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($temporary, $encoded) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not persist generation state.');
    }
}

function generationRequest(string $endpoint, string $apiKey, array $payload, int $timeoutSeconds = 120): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required.');
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $lastError = 'unknown error';
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $handle = curl_init($endpoint);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Connection: close',
                'HTTP-Referer: https://github.com/Dwemer-Dynamics/StobeServer',
                'X-Title: Stobe rename name generator',
            ],
        ]);
        $body = curl_exec($handle);
        $status = intval(curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
        $curlError = curl_error($handle);
        curl_close($handle);

        if (is_string($body) && $status >= 200 && $status < 300) {
            return ['body' => $body, 'status' => $status];
        }
        $lastError = $curlError !== '' ? $curlError : 'HTTP ' . strval($status) . ': ' . substr(strval($body), 0, 300);
        if ($attempt < 4 && ($body === false || $status === 0 || $status === 408 || $status === 429 || $status >= 500)) {
            sleep($attempt * 2);
            continue;
        }
        break;
    }
    throw new RuntimeException('OpenRouter request failed: ' . $lastError);
}

function generationPrompt(string $gender, int $count, array $forbiddenRows): array
{
    $forbidden = array_map(static fn (array $row): string => strval($row['name'] ?? ''), $forbiddenRows);
    $examples = [
        'male' => 'Arin, Bram, Doran, Eryk, Garrik, Jarek, Marek, Soren, Tarn, Ulric',
        'female' => 'Aela, Bryn, Daya, Eris, Hesta, Ilya, Lyra, Rhea, Tessa, Zara',
        'neutral' => 'Ash, Cade, Dune, Flint, Grey, Haze, Kestrel, Moss, Slate, Zephyr',
    ];
    return [
        [
            'role' => 'system',
            'content' => 'You create concise NPC given names for Kenshi, a harsh arid post-apocalyptic setting. Return only data matching the provided JSON schema.',
        ],
        [
            'role' => 'user',
            'content' => "Generate exactly {$count} new {$gender} NPC names.\n"
                . "Style examples: {$examples[$gender]}.\n"
                . "Rules:\n"
                . "- Each name is one invented or name-like word, 3-10 ASCII letters.\n"
                . "- Capitalize only the first letter. No spaces, surnames, punctuation, digits, titles, or explanations.\n"
                . "- Match Kenshi's terse, rough naming style. Avoid modern celebrity names, jokes, franchise names, generic jobs, and profanity.\n"
                . "- Do not use or trivially respell any forbidden name below.\n"
                . 'Forbidden names: ' . implode(', ', $forbidden),
        ],
    ];
}

function generationReview(
    string $endpoint,
    string $apiKey,
    string $model,
    array $rows,
    string $rawPath,
    int $timeoutSeconds
): array {
    if (count($rows) === 0) {
        return ['approved' => [], 'rejected' => [], 'cost' => 0.0];
    }
    $names = array_map(static fn (array $row): string => strval($row['name'] ?? ''), $rows);
    $count = count($names);
    $schema = [
        'type' => 'object',
        'properties' => [
            'reviews' => [
                'type' => 'array',
                'minItems' => $count,
                'maxItems' => $count,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'enum' => $names],
                        'approved' => ['type' => 'boolean'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['name', 'approved', 'reason'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['reviews'],
        'additionalProperties' => false,
    ];
    $payload = [
        'model' => $model,
        'stream' => false,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a strict editorial reviewer for original Kenshi-style NPC names. Return only data matching the JSON schema.',
            ],
            [
                'role' => 'user',
                'content' => "Review every candidate exactly once: " . implode(', ', $names) . ".\n"
                    . "Approve terse, plausible names for a harsh arid post-apocalyptic world.\n"
                    . "Reject a candidate if it is strongly identified with a prominent character from another game, film, television, or book franchise; is a joke or profanity; is a generic job/title; or reads primarily as an ordinary modern full given name rather than the supplied Kenshi style.\n"
                    . "Neutral candidates may be short terrain, material, weather, animal, or tool words. Do not reject merely because a name is unfamiliar.",
            ],
        ],
        'temperature' => 0.1,
        'max_tokens' => 3000,
        'reasoning' => ['effort' => 'low', 'exclude' => true],
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => ['name' => 'stobe_name_reviews', 'strict' => true, 'schema' => $schema],
        ],
        'provider' => ['require_parameters' => true],
    ];
    $parsed = null;
    $attemptCost = 0.0;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $response = generationRequest($endpoint, $apiKey, $payload, $timeoutSeconds);
        $body = strval($response['body']);
        $attemptCost += stobeGeneratedNameApiReportedCost($body);
        $attemptPath = $attempt === 1 ? $rawPath : preg_replace('/\.json$/', '-attempt-' . strval($attempt) . '.json', $rawPath);
        file_put_contents(strval($attemptPath), $body);
        try {
            $parsed = stobeGeneratedNameParseApiPayload($body);
            break;
        } catch (RuntimeException $exception) {
            if ($attempt === 3) {
                throw $exception;
            }
        }
    }
    if (!is_array($parsed)) {
        throw new RuntimeException('OpenRouter did not return a usable review payload.');
    }
    $structured = $parsed['payload'];
    $reviews = $structured['reviews'] ?? (array_is_list($structured) ? $structured : null);
    if (!is_array($reviews)) {
        throw new RuntimeException('OpenRouter content did not match the review schema.');
    }

    $reviewed = [];
    foreach ($reviews as $review) {
        if (!is_array($review)) {
            continue;
        }
        $key = strtolower(trim(strval($review['name'] ?? '')));
        if ($key !== '' && !isset($reviewed[$key])) {
            $decision = strtolower(trim(strval($review['decision'] ?? '')));
            $reviewed[$key] = [
                'approved' => array_key_exists('approved', $review)
                    ? boolval($review['approved'])
                    : $decision === 'approve',
                'reason' => trim(strval($review['reason'] ?? 'editorial review rejected')),
            ];
        }
    }

    $approved = [];
    $rejected = [];
    foreach ($rows as $row) {
        $key = strtolower(strval($row['name'] ?? ''));
        $review = $reviewed[$key] ?? ['approved' => false, 'reason' => 'missing editorial review'];
        if (boolval($review['approved'] ?? false)) {
            $approved[] = $row;
        } else {
            $rejected[] = [
                'name' => strval($row['name'] ?? ''),
                'gender' => strval($row['gender'] ?? ''),
                'reason' => 'review: ' . strval($review['reason'] ?? 'rejected'),
            ];
        }
    }
    return ['approved' => $approved, 'rejected' => $rejected, 'cost' => $attemptCost];
}

$options = generationOptions($argv);
$root = dirname(__DIR__);
$model = trim(strval($options['model'] ?? 'z-ai/glm-5.2'));
$target = intval($options['target'] ?? 1000);
$batchSize = max(5, min(100, intval($options['batch-size'] ?? 50)));
$maxCost = max(0.01, floatval($options['max-cost-usd'] ?? 2.00));
$timeoutSeconds = max(30, min(300, intval($options['http-timeout'] ?? 120)));
$endpoint = trim(strval($options['endpoint'] ?? 'https://openrouter.ai/api/v1/chat/completions'));
$seedPath = strval($options['seed'] ?? ($root . '/data/rename_names_seed.csv'));
$blocklistPath = strval($options['blocklist'] ?? ($root . '/data/rename_name_blocklist.txt'));
$runId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
$runDirectory = strval($options['run-dir'] ?? ($root . '/tmp/rename-name-generation/' . $runId));
$apiKey = trim(strval(getenv('OPENROUTER_API_KEY') ?: ''));
if ($apiKey === '') {
    generationFail('OPENROUTER_API_KEY is not set.');
}
if ($model === '') {
    generationFail('Model cannot be blank.');
}
if (!is_dir($runDirectory) && !mkdir($runDirectory, 0775, true) && !is_dir($runDirectory)) {
    generationFail('Could not create run directory: ' . $runDirectory);
}

try {
    $seedRows = stobeGeneratedNameReadCsv($seedPath);
    $blocked = stobeGeneratedNameReadBlocklist($blocklistPath);
    $acceptedPath = $runDirectory . '/accepted.csv';
    $accepted = is_file($acceptedPath) ? stobeGeneratedNameReadCsv($acceptedPath) : [];
    $acceptedValidation = stobeGeneratedNameValidateRows($accepted, $seedRows, $blocked);
    if (count($acceptedValidation['rejected']) > 0) {
        throw new RuntimeException('Existing accepted.csv contains invalid rows; refusing to resume.');
    }
    $accepted = $acceptedValidation['accepted'];
    $rejected = [];
    $rejectedPath = $runDirectory . '/rejected.csv';
    if (is_file($rejectedPath)) {
        $handle = fopen($rejectedPath, 'rb');
        if ($handle !== false) {
            $header = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                $rejected[] = ['name' => strval($row[0] ?? ''), 'gender' => strval($row[1] ?? ''), 'reason' => strval($row[2] ?? '')];
            }
            fclose($handle);
        }
    }

    $quotas = generationQuotas($target);
    $statePath = $runDirectory . '/state.json';
    $state = [];
    if (is_file($statePath)) {
        $decodedState = json_decode(strval(file_get_contents($statePath)), true);
        if (!is_array($decodedState)) {
            throw new RuntimeException('Generation state is invalid; inspect state.json before resuming.');
        }
        $state = $decodedState;
        if (strval($state['model'] ?? '') !== $model || intval($state['target'] ?? 0) !== $target) {
            throw new RuntimeException('Generation state model or target does not match this command.');
        }
    }
    $reportedCost = floatval($state['reported_cost_usd'] ?? 0);
    $requestCount = intval($state['generation_requests'] ?? 0);
    $reviewCount = intval($state['review_requests'] ?? 0);
    $invocationRequestCount = 0;
    $maxRequests = max(12, intval(ceil($target / $batchSize)) * 6);
    while (count($accepted) < $target) {
        $counts = stobeGeneratedNameCounts($accepted);
        $gender = '';
        foreach (['male', 'female', 'neutral'] as $candidateGender) {
            if ($counts[$candidateGender] < $quotas[$candidateGender]) {
                $gender = $candidateGender;
                break;
            }
        }
        if ($gender === '') {
            break;
        }
        if (++$invocationRequestCount > $maxRequests) {
            throw new RuntimeException('Generation stopped after reaching the request limit. Resume the run or inspect rejected.csv.');
        }
        $requestCount++;
        if ($reportedCost >= $maxCost) {
            throw new RuntimeException('Generation stopped at the configured cost limit.');
        }

        $remaining = $quotas[$gender] - $counts[$gender];
        $requestSize = min($batchSize, max(5, intval(ceil($remaining * 1.15))));
        $allKnown = array_merge($seedRows, $accepted);
        $schema = [
            'type' => 'object',
            'properties' => [
                'names' => [
                    'type' => 'array',
                    'minItems' => $requestSize,
                    'maxItems' => $requestSize,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'gender' => ['type' => 'string', 'enum' => [$gender]],
                        ],
                        'required' => ['name', 'gender'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['names'],
            'additionalProperties' => false,
        ];
        $payload = [
            'model' => $model,
            'stream' => false,
            'messages' => generationPrompt($gender, $requestSize, $allKnown),
            'temperature' => 1.0,
            'max_tokens' => 4000,
            'reasoning' => ['effort' => 'low', 'exclude' => true],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'stobe_rename_names', 'strict' => true, 'schema' => $schema],
            ],
            'provider' => ['require_parameters' => true],
        ];

        fwrite(STDOUT, sprintf("Request %d: %s, need %d more\n", $requestCount, $gender, $remaining));
        $parsed = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = generationRequest($endpoint, $apiKey, $payload, $timeoutSeconds);
            $body = strval($response['body']);
            $reportedCost += stobeGeneratedNameApiReportedCost($body);
            $rawPath = sprintf(
                '%s/raw-%03d-%s%s.json',
                $runDirectory,
                $requestCount,
                $gender,
                $attempt === 1 ? '' : '-attempt-' . strval($attempt)
            );
            file_put_contents($rawPath, $body);
            try {
                $parsed = stobeGeneratedNameParseApiResponse($body);
                break;
            } catch (RuntimeException $exception) {
                if ($attempt === 3) {
                    throw $exception;
                }
            }
        }
        if (!is_array($parsed)) {
            throw new RuntimeException('OpenRouter did not return a usable generation payload.');
        }
        generationWriteState($statePath, [
            'model' => $model,
            'target' => $target,
            'generation_requests' => $requestCount,
            'review_requests' => $reviewCount,
            'reported_cost_usd' => $reportedCost,
            'updated_at' => gmdate(DATE_ATOM),
        ]);

        $validated = stobeGeneratedNameValidateRows($parsed['names'], $allKnown, $blocked);
        $reviewCount++;
        $review = generationReview(
            $endpoint,
            $apiKey,
            $model,
            $validated['accepted'],
            sprintf('%s/review-%03d-%s.json', $runDirectory, $reviewCount, $gender),
            $timeoutSeconds
        );
        $reportedCost += floatval($review['cost'] ?? 0);
        generationWriteState($statePath, [
            'model' => $model,
            'target' => $target,
            'generation_requests' => $requestCount,
            'review_requests' => $reviewCount,
            'reported_cost_usd' => $reportedCost,
            'updated_at' => gmdate(DATE_ATOM),
        ]);
        foreach ($review['approved'] as $row) {
            if (strval($row['gender']) !== $gender || stobeGeneratedNameCounts($accepted)[$gender] >= $quotas[$gender]) {
                $rejected[] = ['name' => strval($row['name']), 'gender' => strval($row['gender']), 'reason' => 'quota'];
                continue;
            }
            $accepted[] = $row;
        }
        foreach ($validated['rejected'] as $row) {
            $rejected[] = $row;
        }
        foreach ($review['rejected'] as $row) {
            $rejected[] = $row;
        }
        stobeGeneratedNameWriteCsv($acceptedPath, $accepted);
        stobeGeneratedNameWriteCsv($rejectedPath, $rejected, ['name', 'gender', 'reason']);
    }

    if (count($accepted) !== $target || stobeGeneratedNameCounts($accepted) !== $quotas) {
        throw new RuntimeException('Generation ended without satisfying the target quotas.');
    }
    $nearDuplicates = stobeGeneratedNameNearDuplicates(array_merge($seedRows, $accepted), count($seedRows));
    stobeGeneratedNameWriteCsv($runDirectory . '/near_duplicates.csv', $nearDuplicates, ['name', 'near_name', 'distance']);
    $manifest = [
        'run_id' => basename($runDirectory),
        'created_at' => gmdate(DATE_ATOM),
        'model' => $model,
        'target' => $target,
        'quotas' => $quotas,
        'accepted' => count($accepted),
        'rejected' => count($rejected),
        'near_duplicate_pairs' => count($nearDuplicates),
        'requests' => $requestCount,
        'review_requests' => $reviewCount,
        'reported_cost_usd' => round($reportedCost, 6),
    ];
    file_put_contents($runDirectory . '/run.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    fwrite(STDOUT, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    fwrite(STDOUT, 'Review artifacts: ' . $runDirectory . PHP_EOL);
} catch (Throwable $exception) {
    generationFail($exception->getMessage());
}
