<?php

if (!function_exists('stobeNarratorName')) {
    function stobeNarratorName(): string
    {
        return Narrator::CANONICAL_NAME;
    }
}

if (!function_exists('stobeNarratorRoleplayName')) {
    function stobeNarratorRoleplayName(): string
    {
        $narrator = stobeGetNarrator();
        return $narrator ? $narrator->getRoleplayName() : Narrator::DEFAULT_ROLEPLAY_NAME;
    }
}

if (!function_exists('stobeNarratorDisplayNameHeaderValue')) {
    function stobeNarratorDisplayNameHeaderValue(): string
    {
        return base64_encode(stobeNarratorRoleplayName());
    }
}

if (!function_exists('stobeRenderNarratorDisplayText')) {
    function stobeRenderNarratorDisplayText(string $text): string
    {
        $displayName = stobeNarratorRoleplayName();
        if (strcasecmp($displayName, stobeNarratorName()) === 0) {
            return $text;
        }
        return str_ireplace(stobeNarratorName(), $displayName, $text);
    }
}

if (!function_exists('stobeIsNarratorName')) {
    function stobeIsNarratorName(string $name): bool
    {
        return strcasecmp(trim($name), stobeNarratorName()) === 0;
    }
}

if (!function_exists('stobeGetNarrator')) {
    function stobeGetNarrator(): ?Narrator
    {
        try {
            return new Narrator();
        } catch (Throwable $e) {
            stobeLogException($e, 'Failed to initialize Narrator helper');
            return null;
        }
    }
}

if (!function_exists('stobeBuildNarratorNpcData')) {
    function stobeBuildNarratorNpcData(): array
    {
        $name = stobeNarratorName();
        $defaults = [
            'name' => $name,
            'race' => 'Unknown',
            'faction' => 'Narrator',
            'gender' => 'male',
            'profile_id' => 1,
            'voiceid' => 'stobenarrator',
            'personality' => 'Laid-back, observant, and friendly; describes scenes with calm confidence.',
            'backstory' => "A guiding voice that describes the world, events, and transitions. He is not a character, but a voice within the player's mind.",
            'speechstyle' => 'Relaxed and conversational, with vivid scene descriptions in one or two concise sentences.',
            'goals' => '',
            'prompt_head' => '',
            'occupation' => 'Narrator',
            'metadata' => [],
            'extended_data' => [],
            'relationships' => '',
            'world_knowledge_tags' => 'knowall',
            'emote_moods' => '',
            'equipment' => '',
            'inventory' => '',
            'skills' => '',
            'is_animal' => 0,
            'character_state' => '',
        ];

        $narrator = stobeGetNarrator();
        if (!$narrator) {
            return $defaults;
        }

        $narrator->loadIntoGlobals();
        $settings = $narrator->getAll();
        $narratorData = $narrator->getNarratorData();

        $profileId = intval($narratorData['profile_id'] ?? 0);
        if ($profileId <= 0) {
            $profileId = 1;
        }
        $core = trim(strval($narratorData['core'] ?? ''));
        $background = trim(strval($narratorData['npc_static_bio'] ?? ''));
        $personality = trim(strval($narratorData['personality'] ?? ''));
        if ($personality === '' && $core !== '') {
            $personality = $core;
        }
        $backstory = $background;
        if ($backstory === '' && $core !== '') {
            $backstory = $core;
        }

        $defaults['name'] = $name;
        $defaults['roleplay_name'] = $narrator->getRoleplayName();
        $defaults['race'] = trim(strval($settings['race'] ?? '')) !== '' ? trim(strval($settings['race'])) : 'Unknown';
        $defaults['faction'] = trim(strval($settings['faction'] ?? '')) !== '' ? trim(strval($settings['faction'])) : 'Narrator';
        $defaults['gender'] = trim(strval($narratorData['gender'] ?? 'male'));
        $defaults['profile_id'] = $profileId;
        $defaults['voiceid'] = trim(strval($narratorData['voiceid'] ?? 'stobenarrator'));
        $defaults['personality'] = $personality !== '' ? $personality : $defaults['personality'];
        $defaults['backstory'] = $backstory !== '' ? $backstory : $defaults['backstory'];
        $defaults['speechstyle'] = trim(strval($narratorData['speechstyle'] ?? '')) !== ''
            ? trim(strval($narratorData['speechstyle']))
            : $defaults['speechstyle'];
        $defaults['goals'] = trim(strval($narratorData['goals'] ?? '')) !== ''
            ? trim(strval($narratorData['goals']))
            : $defaults['goals'];
        $defaults['prompt_head'] = trim(strval($narratorData['prompt_head'] ?? ''));
        $defaults['world_knowledge_tags'] = trim(strval($narratorData['oghma_knowledge_tags'] ?? '')) !== ''
            ? trim(strval($narratorData['oghma_knowledge_tags']))
            : 'knowall';

        return $defaults;
    }
}

if (!function_exists('stobeNarratorModeEnabled')) {
    function stobeNarratorModeEnabled(): bool
    {
        $narrator = stobeGetNarrator();
        if (!$narrator) {
            return true;
        }
        return $narrator->getBool('enabled', true);
    }
}

if (!function_exists('stobeExtractContextRowParticipants')) {
    function stobeExtractContextRowParticipants(array $row): array
    {
        $participants = [];
        $seen = [];
        $addParticipant = static function (string $name) use (&$participants, &$seen): void {
            $normalized = normalizeParticipantNameToken($name);
            if ($normalized === '') {
                return;
            }
            $key = strtolower($normalized);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $participants[] = $normalized;
        };

        $people = trim(strval($row['people'] ?? ''));
        if ($people !== '') {
            $tokens = preg_split('/[,;|]+/u', $people) ?: [];
            foreach ($tokens as $token) {
                $addParticipant(strval($token));
            }
        }

        $data = trim(strval($row['data'] ?? ''));
        if ($data !== '' && function_exists('parseDialogueEventData')) {
            $parsed = parseDialogueEventData($data);
            $addParticipant(strval($parsed['speaker'] ?? ''));
            $addParticipant(strval($parsed['target'] ?? ''));
        }

        return $participants;
    }
}

if (!function_exists('stobeContextRowInvolvesNarrator')) {
    function stobeContextRowInvolvesNarrator(array $row): bool
    {
        $participants = stobeExtractContextRowParticipants($row);
        foreach ($participants as $participant) {
            if (stobeIsNarratorName($participant)) {
                return true;
            }
        }

        $data = trim(strval($row['data'] ?? ''));
        if ($data === '') {
            return false;
        }
        if (preg_match('/^\s*' . preg_quote(stobeNarratorName(), '/') . '\s*:/iu', $data) === 1) {
            return true;
        }
        if (preg_match('/\(talking to:\s*' . preg_quote(stobeNarratorName(), '/') . '\s*\)/iu', $data) === 1) {
            return true;
        }

        return false;
    }
}

if (!function_exists('stobeIsPrivateNarratorConversationRow')) {
    function stobeIsPrivateNarratorConversationRow(array $row, string $speakerName = ''): bool
    {
        $participants = stobeExtractContextRowParticipants($row);
        if (count($participants) === 0) {
            return false;
        }

        $hasNarrator = false;
        $nonNarratorParticipants = [];
        foreach ($participants as $participant) {
            if (stobeIsNarratorName($participant)) {
                $hasNarrator = true;
                continue;
            }
            $nonNarratorParticipants[] = $participant;
        }

        if (!$hasNarrator) {
            return false;
        }

        $speaker = normalizeParticipantNameToken($speakerName);
        if ($speaker !== '' && !stobeIsNarratorName($speaker)) {
            if (count($nonNarratorParticipants) !== 1) {
                return false;
            }
            return strcasecmp($nonNarratorParticipants[0], $speaker) === 0;
        }

        return count($nonNarratorParticipants) <= 1;
    }
}

if (!function_exists('stobeIsNarratorContextExcludedType')) {
    function stobeIsNarratorContextExcludedType(string $eventType): bool
    {
        $type = strtolower(trim($eventType));
        return in_array($type, ['init'], true);
    }
}

if (!function_exists('stobeFilterNarratorPromptContextRows')) {
    function stobeFilterNarratorPromptContextRows(array $rows): array
    {
        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strval($row['type'] ?? '');
            if (stobeIsNarratorContextExcludedType($type)) {
                continue;
            }
            $filtered[] = $row;
        }
        return $filtered;
    }
}

if (!function_exists('stobeFilterNarratorRowsForContext')) {
    function stobeFilterNarratorRowsForContext(
        array $rows,
        string $targetNpc = '',
        string $dialogueMode = '',
        string $speakerName = ''
    ): array {
        $narratorMode = stobeIsNarratorName($targetNpc) || strcasecmp(trim($dialogueMode), 'narrator') === 0;
        if ($narratorMode) {
            // Narrator mode should have full recent-event context by default.
            return stobeFilterNarratorPromptContextRows($rows);
        }
        $filtered = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (stobeContextRowInvolvesNarrator($row)) {
                $preserveInline = coerceBoolean($GLOBALS['PRESERVE_INLINE_NARRATION_CONTEXT'] ?? false);
                if (!$preserveInline || strcasecmp(trim(strval($row['type'] ?? '')), 'inline_narration') !== 0) {
                    continue;
                }
            }
            $filtered[] = $row;
        }

        return $filtered;
    }
}

if (!function_exists('stobeTryTriggerRandomNarration')) {
    function stobeTryTriggerRandomNarration(
        int $gamets,
        string $previousSpeaker = '',
        string $previousMessage = '',
        string $previousTarget = '',
        string $trigger = 'unknown',
        string $playerName = '',
        int $timestamp = 0
    ): bool {
        $narrator = stobeGetNarrator();
        if (!$narrator) {
            return false;
        }
        if (!$narrator->getBool('enabled', true) || !$narrator->getBool('random_enabled', false)) {
            return false;
        }

        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            stobeLogWarn('Random narration skipped: missing database handle');
            return false;
        }
        $deliveryVisibilitySql = function_exists('stobeBuildEventlogDeliveryVisibilitySql')
            ? stobeBuildEventlogDeliveryVisibilitySql('eventlog')
            : '1=1';

        $lastNarrativeEvent = $db->fetchOne(
            "SELECT type
             FROM eventlog
             WHERE type IN ('chat', 'rechat', 'narration')
               AND {$deliveryVisibilitySql}
             ORDER BY rowid DESC
             LIMIT 1"
        );
        if (
            is_array($lastNarrativeEvent) &&
            strcasecmp(trim(strval($lastNarrativeEvent['type'] ?? '')), 'narration') === 0
        ) {
            stobeLogDebug('Random narration skipped: last event already narration', [
                'trigger' => $trigger,
            ]);
            return false;
        }

        $cooldownTurns = max(0, min(30, intval($narrator->getInt('random_cooldown', 10))));
        if ($cooldownTurns > 0) {
            $cooldownRow = $db->fetchOne(
                "SELECT COUNT(*) AS count
                 FROM eventlog
                 WHERE type IN ('chat', 'rechat', 'inputtext', 'inputtext_s')
                   AND {$deliveryVisibilitySql}
                   AND rowid > COALESCE(
                       (
                           SELECT MAX(rowid)
                           FROM eventlog
                           WHERE type = 'narration'
                       ),
                       0
                   )"
            );
            $eventsSinceNarration = intval($cooldownRow['count'] ?? 0);
            if ($eventsSinceNarration < $cooldownTurns) {
                stobeLogDebug('Random narration skipped: cooldown active', [
                    'events_since_narration' => $eventsSinceNarration,
                    'required_events' => $cooldownTurns,
                    'trigger' => $trigger,
                ]);
                return false;
            }
        }

        $chance = max(1, min(100, intval($narrator->getInt('random_chance', 15))));
        try {
            $roll = random_int(1, 100);
        } catch (Throwable $randomException) {
            $roll = mt_rand(1, 100);
        }
        if ($roll > $chance) {
            stobeLogDebug('Random narration skipped: chance roll failed', [
                'roll' => $roll,
                'chance' => $chance,
                'trigger' => $trigger,
            ]);
            return false;
        }

        $narratorName = stobeNarratorName();
        $speaker = normalizeParticipantNameToken($previousSpeaker);
        if ($speaker === '' || stobeIsNarratorName($speaker)) {
            $speaker = normalizeParticipantNameToken($previousTarget);
        }
        if ($speaker === '' || stobeIsNarratorName($speaker)) {
            $speaker = normalizeParticipantNameToken($playerName);
        }
        if ($speaker === '' || stobeIsNarratorName($speaker)) {
            $speaker = 'Drifter';
        }

        $priorPeopleScope = strval($GLOBALS['CACHE_PEOPLE'] ?? '');
        $GLOBALS['CACHE_PEOPLE'] = $speaker . '|' . $narratorName;

        $responseText = '';
        try {
            $narratorData = stobeBuildNarratorNpcData();
            $contextLimit = getNpcProfileIntegerSetting(
                $narratorData,
                ['CONTEXT_HISTORY'],
                '',
                80,
                10,
                250
            );
            $contextHistory = DataEventLog($contextLimit);
            $contextHistory = stobeFilterNarratorPromptContextRows($contextHistory);
            $historyMessages = stobeBuildRecentContextMessages($contextHistory, intval($gamets));

            $randomNarrationInstruction = trim(stobeGetPromptTemplateValue(
                'random_narration_prompt',
                "Describe the current scene visually using only details from context. Focus on characters present, body language, environment, and atmosphere in 1-2 concise sentences. Write in third person, use character names when relevant, and do not use second-person phrasing. Do not invent events or include action tags."
            ));
            if ($randomNarrationInstruction === '') {
                $randomNarrationInstruction = "Describe the current scene visually using only details from context. Focus on characters present, body language, environment, and atmosphere in 1-2 concise sentences. Write in third person, use character names when relevant, and do not use second-person phrasing. Do not invent events or include action tags.";
            }
            if (stripos($randomNarrationInstruction, '1-2') === false && stripos($randomNarrationInstruction, 'one or two') === false) {
                $randomNarrationInstruction = rtrim($randomNarrationInstruction, " \t\r\n.")
                    . '. Keep output to 1-2 sentences maximum.';
            }
            if (stripos($randomNarrationInstruction, 'third person') === false) {
                $randomNarrationInstruction = rtrim($randomNarrationInstruction, " \t\r\n.")
                    . ' Write strictly in third person.';
            }
            if (
                stripos($randomNarrationInstruction, 'second-person') === false
                && stripos($randomNarrationInstruction, 'you or your') === false
            ) {
                $randomNarrationInstruction = rtrim($randomNarrationInstruction, " \t\r\n.")
                    . ' Never use second-person pronouns like "you" or "your".';
            }

            $displayName = stobeNarratorRoleplayName();
            $systemPrompt = stobeBuildGameTimePromptBlock(intval($gamets), $speaker)
                . "\n\n"
                . buildSystemPrompt(
                    $narratorName,
                    $narratorData,
                    $speaker,
                    $randomNarrationInstruction,
                    false,
                    'narration',
                    intval($gamets)
                )
                . "\n\n<speech_mode>\n"
                . "  <mode>narrator</mode>\n"
                . "  <instruction>You are " . stobePromptXmlEscape($displayName) . " delivering a brief scene interjection in third-person storytelling style. The speaker is the focal subject, not a direct addressee. Do not emit action tags.</instruction>\n"
                . "</speech_mode>";

            $userContent = "<random_narration_event>\n"
                . "  <speaker>" . stobePromptXmlEscape($speaker) . "</speaker>\n"
                . "  <target>" . stobePromptXmlEscape($displayName) . "</target>\n"
                . "  <focus_subject>" . stobePromptXmlEscape($speaker) . "</focus_subject>\n"
                . "  <trigger>" . stobePromptXmlEscape($trigger) . "</trigger>\n"
                . "  <previous_speaker>" . stobePromptXmlEscape($previousSpeaker) . "</previous_speaker>\n"
                . "  <previous_target>" . stobePromptXmlEscape($previousTarget) . "</previous_target>\n"
                . "  <previous_message>" . stobePromptXmlEscape($previousMessage) . "</previous_message>\n"
                . "  <instruction>" . stobePromptXmlEscape($randomNarrationInstruction) . "</instruction>\n"
                . "</random_narration_event>";

            $messages = [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
            ];
            foreach ($historyMessages as $historyMessage) {
                $messages[] = $historyMessage;
            }
            $messages[] = [
                'role' => 'user',
                'content' => $userContent,
            ];
            $messages[] = [
                'role' => 'user',
                'content' => 'Narration turn for ' . $narratorName
                    . '. The focus subject is ' . $speaker
                    . '. Write scene prose in third person only, using names and clear third-person pronouns. Never address anyone as "you".',
            ];
            $messages[] = [
                'role' => 'user',
                'content' => 'Output contract: return narrator scene text only in third person, 1-2 sentences max, with no second-person pronouns and no action tags.',
            ];

            $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
            require_once($enginePath . 'connector/llm_dispatcher.php');

            $llmConfig = getLlmConfigForNpc($narratorData);
            if (trim(strval($llmConfig['api_key'] ?? '')) !== '') {
                $rawResponse = stobeCallLLM(
                    $messages,
                    $llmConfig,
                    [
                        'npc_name' => $narratorName,
                        'event_type' => 'narration',
                        'speaker' => $speaker,
                    ]
                );

                if (is_string($rawResponse) && trim($rawResponse) !== '') {
                    $structured = stobeParseStructuredDialogueResponse($rawResponse, 'chat');
                    $candidateText = trim(strval($structured['message'] ?? ''));
                    if ($candidateText === '') {
                        $candidateText = trim(strval($rawResponse));
                    }
                    $responseText = stobeStripParentheticalDialogueText(
                        sanitizeForKenshi($candidateText)
                    );
                }
            } else {
                stobeLogWarn('Random narration fallback: missing narrator API key', [
                    'trigger' => $trigger,
                ]);
            }

            if ($responseText === '') {
                $responseText = 'A hush settles over the moment as the wasteland watches in silence.';
            }

            streamResponse($narratorName, 'ScriptQueue', $responseText, $narratorData, [], 'narration', $speaker, intval($gamets));

            stobeLogInfo('Random narration triggered', [
                'roll' => $roll,
                'chance' => $chance,
                'speaker' => $speaker,
                'previous_speaker' => $previousSpeaker,
                'response_length' => strlen($responseText),
                'trigger' => $trigger,
            ]);

            return true;
        } catch (Throwable $exception) {
            stobeLogException($exception, 'Random narration generation failed');
            return false;
        } finally {
            $GLOBALS['CACHE_PEOPLE'] = $priorPeopleScope;
        }
    }
}
